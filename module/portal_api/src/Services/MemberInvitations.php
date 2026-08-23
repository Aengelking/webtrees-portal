<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Log;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Fig\Http\Message\StatusCodeInterface;

use function array_merge;
use function count;
use function max;
use function min;
use function gmdate;
use function rawurlencode;
use function rtrim;
use function trim;

/**
 * A member inviting their own close family.
 *
 * The whole of this class is about one asymmetry. An administrator issuing an
 * invitation has already decided who is family; a member issuing one is being
 * *trusted* to, and an invitation creates an account with member access to
 * the tree. So a member's invitation is hedged by three things an
 * administrator's is not: it may only name somebody within a few steps of
 * them, they may only have a handful outstanding at once, and the whole
 * facility can be switched off.
 *
 * What is deliberately *not* hedged: the member sees the link and sends it
 * themselves. Having the module send the email would not be safer — the
 * member still types the address — and it would add a mail server to the
 * list of things that can break. It is also what the administrator's screen
 * already does, for the reason written there: guessing the wrong address
 * means mailing a working credential to a stranger.
 */
class MemberInvitations
{
    public const int DEFAULT_QUOTA = 3;
    public const int MAX_QUOTA     = 20;

    /**
     * How many people the screen is handed at once when the whole tree is
     * eligible.
     *
     * Only the *list* is capped. Whether a particular person may be invited is
     * asked of the rule, not of this list — so somebody who is not among the
     * first two hundred is still invitable, they are just reached by searching
     * rather than by scrolling. A wheel with four thousand names in it is not
     * a way of choosing anybody.
     */
    public const int LISTED = 200;

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
        private readonly InvitationService $invitations,
        private readonly CloseFamily $close_family,
        private readonly RecordPresenter $presenter,
    ) {
    }

    public function enabled(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MEMBER_INVITES, '1') === '1';
    }

    public function steps(): int
    {
        return max(1, min(
            CloseFamily::MAX_STEPS,
            (int) $this->module->getPreference(PortalApiModule::SETTING_MEMBER_INVITE_STEPS, (string) CloseFamily::DEFAULT_STEPS)
        ));
    }

    public function quota(): int
    {
        return max(0, min(
            self::MAX_QUOTA,
            (int) $this->module->getPreference(PortalApiModule::SETTING_MEMBER_INVITE_QUOTA, (string) self::DEFAULT_QUOTA)
        ));
    }

    /**
     * Whether this reader is one of the people who keep the tree.
     *
     * **The three hedges in the class comment above are about a member**, and
     * an editor is not one. They already choose who is family: they open the
     * control panel and issue an invitation to anybody in it, with no distance
     * and no quota. Applying either here would not stop them doing it — it
     * would only stop them doing it from the screen they were already looking
     * at, having just found the person on it.
     *
     * So an editor may invite anybody they can see, as many as they like. It
     * is the same line `SearchConsent` draws, and the same one webtrees draws
     * with "editors are never restricted".
     */
    public function keepsTheTree(Tree $tree): bool
    {
        return Auth::isEditor($tree);
    }

    /**
     * Everything the invite screen needs, in one shape.
     *
     * When the facility is off this still answers, with `enabled: false` and
     * empty lists. A screen that can say "your family has this switched off"
     * is better than one that 403s at a member who did nothing wrong.
     *
     * @return array<string,mixed>
     */
    public function overview(UserInterface $user): array
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $viewer       = $this->trees->linkedIndividual($tree, $user);
        $mine         = $this->mine($tree, $user);

        if (!$this->enabled() || !$viewer instanceof Individual) {
            return [
                'enabled'    => $this->enabled(),
                // Distinguished from "enabled but nobody to invite": an
                // account with no linked record cannot invite anybody, and
                // the screen should say that rather than show an empty list.
                'linked'     => $viewer instanceof Individual,
                'quota'      => $this->quota(),
                'remaining'  => 0,
                'scope'      => 'close_family',
                'candidates' => [],
                'invitations' => $this->presentAll($mine),
            ];
        }

        $anyone     = $this->keepsTheTree($tree);
        $candidates = $anyone
            ? $this->everybodyInvitable($tree, $access_level)
            : $this->close_family->invitable($viewer, $tree, $access_level, $this->steps(), $this->invitations);

        return [
            'enabled'     => true,
            'linked'      => true,
            'quota'       => $this->quota(),
            // An editor has no quota — see `keepsTheTree()`. `remaining` is
            // what the screen decides on, so it is told the truth rather than
            // a number that would stop it offering anything.
            'remaining'   => $anyone ? self::LISTED : max(0, $this->quota() - count($mine)),
            // Which of the two screens to draw: a wheel of close family, or a
            // search over everybody. The client cannot work this out from the
            // list, because a short list is also what a small family looks
            // like.
            'scope'       => $anyone ? 'anyone' : 'close_family',
            'candidates'  => $this->presentCandidates($candidates, $access_level, $viewer),
            'invitations' => $this->presentAll($mine),
        ];
    }

    /**
     * Issue one, and return the link exactly once.
     *
     * Every rule is checked again here rather than trusted from the screen
     * that offered the list: a client can post any XREF it likes.
     *
     * @return array<string,mixed>
     */
    public function create(UserInterface $user, string $xref, string $email): array
    {
        if (!$this->enabled()) {
            throw new ApiException(
                'not_allowed',
                StatusCodeInterface::STATUS_FORBIDDEN,
                I18N::translate('Members cannot send invitations in this family tree.')
            );
        }

        // Without it the link would be a bare path and useless. Refused
        // rather than handed over broken: a member cannot tell a useless link
        // from a working one until the person they sent it to says so.
        if ($this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, '') === '') {
            throw ApiException::notConfigured(
                I18N::translate('The member portal is not configured correctly. Please contact an administrator.')
            );
        }

        $tree   = $this->trees->tree();
        $viewer = $this->trees->linkedIndividual($tree, $user);
        $anyone = $this->keepsTheTree($tree);

        // An editor needs no record of their own: what they may invite is not
        // measured from anywhere. A member's is.
        if (!$anyone && !$viewer instanceof Individual) {
            throw new ApiException(
                'no_linked_record',
                StatusCodeInterface::STATUS_FORBIDDEN,
                I18N::translate('Your account is not linked to anybody in the family tree yet.')
            );
        }

        if (!$anyone && count($this->mine($tree, $user)) >= $this->quota()) {
            throw new ApiException(
                'quota_reached',
                StatusCodeInterface::STATUS_CONFLICT,
                I18N::translate('You have as many invitations outstanding as you may have at once. Withdraw one, or wait until it is used.')
            );
        }

        $access_level = $this->trees->accessLevel($tree);
        $individual   = $this->invitable($user, $xref);

        if (!$individual instanceof Individual) {
            // One answer for "too distant", "already has an account",
            // "already invited", "no such person" and "hidden from you". A
            // member who posts an XREF they were not offered learns nothing
            // about whether it exists.
            throw new ApiException(
                'not_allowed',
                StatusCodeInterface::STATUS_FORBIDDEN,
                I18N::translate('You cannot invite this person.')
            );
        }

        $token = $this->invitations->create(
            $tree,
            $individual->xref(),
            $this->presenter->plainName($individual),
            $email,
            $user,
            $this->validityDays()
        );

        Log::addAuthenticationLog('Portal invitation issued by member ' . $user->userName() . ' for ' . $individual->xref());

        $invitation = $this->invitations->outstanding($tree)
            ->first(static fn (Invitation $candidate): bool => $candidate->created_by === $user->id() && $candidate->xref === $individual->xref());

        return [
            // Shown once and never recoverable — the module stores only a
            // hash of it. See Schema/Migration1.php.
            'link'       => $this->link($token),
            'invitation' => $invitation instanceof Invitation ? $this->present($invitation) : null,
        ];
    }

    /** Withdraw one of the member's own invitations. */
    public function revoke(UserInterface $user, int $id): void
    {
        $tree = $this->trees->tree();
        $mine = $this->mine($tree, $user);

        foreach ($mine as $invitation) {
            if ($invitation->id === $id) {
                $this->invitations->revoke($id, $tree);

                return;
            }
        }

        // Somebody else's, or already spent. Not found either way: a member
        // has no business learning which.
        throw ApiException::notFound();
    }

    /**
     * The member's own outstanding invitations.
     *
     * @return array<int,Invitation>
     */
    private function mine(Tree $tree, UserInterface $user): array
    {
        return $this->invitations->outstanding($tree)
            ->filter(static fn (Invitation $invitation): bool => $invitation->created_by === $user->id())
            ->values()
            ->all();
    }

    /**
     * The one rule, asked about one person.
     *
     * Every screen that offers an invitation and the endpoint that issues one
     * ask this and nothing else, so the offer and the answer cannot disagree.
     * It returns the record rather than a boolean because every caller wants
     * it next, and looking it up twice is how the two get out of step.
     *
     * **A list is never the rule.** For an editor the eligible set is the
     * whole tree, and the screen is handed the first `LISTED` of it — asking
     * "is this person in that list" would refuse number two hundred and one
     * for no reason anybody could explain.
     */
    public function invitable(UserInterface $user, string $xref): Individual|null
    {
        if (!$this->enabled()) {
            return null;
        }

        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);

        if (!$this->keepsTheTree($tree)) {
            $viewer = $this->trees->linkedIndividual($tree, $user);

            if (!$viewer instanceof Individual) {
                return null;
            }

            // The quota belongs here as much as the distance does. An offer
            // that the endpoint would refuse is worse than no offer: the
            // member acts on it, and learns what they could have been told
            // before they tried.
            if (count($this->mine($tree, $user)) >= $this->quota()) {
                return null;
            }

            $candidates = $this->close_family->invitable(
                $viewer,
                $tree,
                $access_level,
                $this->steps(),
                $this->invitations
            );

            return $candidates[$xref]['individual'] ?? null;
        }

        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$individual instanceof Individual || !$individual->canShow($access_level)) {
            return null;
        }

        return $this->openToInvitation($tree, $individual) ? $individual : null;
    }

    /**
     * Whether an invitation would mean anything for this person.
     *
     * Three ways it would not, and they are the same three for everybody: the
     * dead cannot accept one, somebody with an account does not need one, and
     * somebody already invited has one waiting.
     */
    private function openToInvitation(Tree $tree, Individual $individual): bool
    {
        if ($individual->isDead()) {
            return false;
        }

        if ($this->close_family->hasAccount($tree, $individual->xref())) {
            return false;
        }

        return !$this->invitations->outstanding($tree)
            ->contains(static fn (Invitation $invitation): bool => (string) $invitation->xref === $individual->xref());
    }

    /**
     * Everybody in the tree an editor could invite, capped for the screen.
     *
     * Read rather than queried, for the same reason the surname index is:
     * "living", "visible" and "has no account yet" are three questions no
     * single SQL statement answers, and two of them are privacy questions that
     * a query would have to ignore.
     *
     * @return array<string,array{individual:Individual,relationship:string|null}>
     */
    private function everybodyInvitable(Tree $tree, int $access_level): array
    {
        $rows = DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->select(['individuals.*'])
            ->get();

        $mapper = Registry::individualFactory()->mapper($tree);
        $found  = [];

        foreach ($rows as $row) {
            $individual = $mapper($row);

            if (!$individual->canShow($access_level) || !$this->openToInvitation($tree, $individual)) {
                continue;
            }

            $found[$individual->xref()] = ['individual' => $individual, 'relationship' => null];

            if (count($found) >= self::LISTED) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param array<string,array{individual:Individual,relationship:string|null}> $candidates
     *
     * @return array<int,array<string,mixed>>
     */
    private function presentCandidates(array $candidates, int $access_level, Individual|null $viewer): array
    {
        $presented = [];

        foreach ($candidates as $candidate) {
            $reference = $this->presenter->individualRef($candidate['individual'], $access_level, $viewer);

            // Null would mean the presenter disagrees with the walk about
            // visibility. It should not happen — both ask canShow() at the
            // same level — but a candidate the presenter will not describe is
            // not one to offer.
            if ($reference === null) {
                continue;
            }

            // `array_merge`, not `+`. The reference shape names the
            // relationship itself, and where this walk has an answer it is
            // the one to keep: it is the same question asked from the
            // inviting member's side, which is whose screen this is. An
            // editor's list has no walk behind it, so the shape's own answer
            // — the tree, then the archive number — stands.
            $presented[] = $candidate['relationship'] === null
                ? $reference
                : array_merge($reference, ['relationship' => $candidate['relationship']]);
        }

        return $presented;
    }

    /**
     * @param array<int,Invitation> $invitations
     *
     * @return array<int,array<string,mixed>>
     */
    private function presentAll(array $invitations): array
    {
        $presented = [];

        foreach ($invitations as $invitation) {
            $presented[] = $this->present($invitation);
        }

        return $presented;
    }

    /**
     * @return array<string,mixed>
     */
    private function present(Invitation $invitation): array
    {
        return [
            'id'         => $invitation->id,
            'name'       => $invitation->invited_name,
            'email'      => $invitation->email,
            'expires_at' => gmdate('c', $invitation->expires_at),
        ];
    }

    private function validityDays(): int
    {
        return max(1, min(
            InvitationService::MAX_VALIDITY_DAYS,
            (int) $this->module->getPreference(PortalApiModule::SETTING_INVITATION_DAYS, (string) InvitationService::DEFAULT_VALIDITY_DAYS)
        ));
    }

    private function link(string $token): string
    {
        $base = rtrim(trim($this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, '')), '/');

        return $base . '/invitation?token=' . rawurlencode($token);
    }
}
