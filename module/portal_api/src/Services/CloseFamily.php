<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Services\RelationshipService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;

use function array_filter;
use function array_values;
use function count;
use function in_array;
use function max;
use function min;

/**
 * Who counts as a member's close family — computed here, not read off webtrees.
 *
 * This exists because of a trap in webtrees' privacy code that makes the
 * obvious rule wrong. "The people I can see" sounds like it means close
 * family, and for a member it usually does not. `Individual::canShowByType()`
 * applies relationship privacy **only** when that user has a per-user
 * `RELATIONSHIP_PATH_LENGTH` greater than zero *and* a linked record. With
 * either missing — which is the default for a new account — it falls through
 * to its last line:
 *
 *     // No restriction found - show living people to members only:
 *     return Auth::PRIV_USER >= $access_level;
 *
 * That is: every member sees every living person in the tree. Scoping "whom
 * may I invite" to "whom can I see" would therefore hand most members the
 * whole family, and it would do so silently — the rule would look right and
 * be wrong, and it would change meaning again the moment somebody edited a
 * tree preference.
 *
 * So the distance is measured here, by walking, and the limit is this
 * module's own setting. `canShow()` still applies on top: a relative the
 * member may not see is not a candidate, whatever the distance.
 *
 * The walk is `RelationshipNamer`'s, with the same two substitutions that
 * class documents at length — families filtered on their own `RESN`, people
 * filtered on `canShow()` rather than trusting `Family::children()`. See
 * `RelationshipNamer::families()` and `::members()` for why both are
 * necessary.
 */
class CloseFamily
{
    /** The furthest the setting may reach. Beyond this it is not "close". */
    public const int MAX_STEPS = 3;

    public const int DEFAULT_STEPS = 2;

    public function __construct(
        private readonly RelationshipService $relationships,
        private readonly UserService $user_service,
    ) {
    }

    /**
     * Everyone within `$steps` of `$viewer` whom `$viewer` may see.
     *
     * Returned as `[xref => ['individual' => Individual, 'relationship' => string|null]]`,
     * nearest first, because the caller wants to show "Ihre Mutter" and not a
     * bare name.
     *
     * @return array<string,array{individual:Individual,relationship:string|null}>
     */
    public function within(Individual $viewer, int $access_level, int $steps): array
    {
        $steps = max(1, min(self::MAX_STEPS, $steps));

        $found   = [];
        $visited = [$viewer->xref() => true];
        $paths   = [[$viewer]];

        for ($step = 0; $step < $steps; $step++) {
            $next = [];

            foreach ($paths as $path) {
                $last = $path[count($path) - 1];

                if (!$last instanceof Individual) {
                    continue;
                }

                foreach ($this->families($last, $access_level) as $family) {
                    foreach ($this->members($family, $access_level) as $relative) {
                        if (isset($visited[$relative->xref()])) {
                            continue;
                        }

                        $visited[$relative->xref()] = true;

                        $extended   = $path;
                        $extended[] = $family;
                        $extended[] = $relative;

                        $found[$relative->xref()] = [
                            'individual'   => $relative,
                            // Named from the path we already have, so this
                            // costs nothing extra and cannot disagree with
                            // the walk that produced it.
                            'relationship' => $this->nameFor($extended),
                        ];

                        $next[] = $extended;
                    }
                }
            }

            $paths = $next;
        }

        return $found;
    }

    /**
     * The subset of `within()` that it makes sense to invite.
     *
     * Three exclusions, and one of them is a judgement call:
     *
     *  - **the dead**, because inviting them is nonsense;
     *  - **anyone already linked to an account**, because a second account for
     *    the same person is a support call, not a feature;
     *  - **anyone with an invitation already outstanding**, for the same
     *    reason.
     *
     * The last two are applied *silently* — the person is simply not in the
     * list, and the member is not told why. Saying "your sister already has an
     * account" would disclose something the portal otherwise treats as hers to
     * share: §2.7's whole point is that appearing in the directory is consent,
     * and this would route around it. The handoff's rule for an unclear
     * disclosure is to omit and write it down, so that is what happens here.
     *
     * @return array<string,array{individual:Individual,relationship:string|null}>
     */
    public function invitable(
        Individual $viewer,
        Tree $tree,
        int $access_level,
        int $steps,
        InvitationService $invitations
    ): array {
        $linked  = $this->linkedXrefs($tree);
        $pending = $invitations->outstanding($tree)
            ->map(static fn (Invitation $invitation): string => (string) $invitation->xref)
            ->all();

        return array_filter(
            $this->within($viewer, $access_level, $steps),
            static function (array $candidate) use ($linked, $pending): bool {
                $xref = $candidate['individual']->xref();

                return !$candidate['individual']->isDead()
                    && !isset($linked[$xref])
                    && !in_array($xref, $pending, true);
            }
        );
    }

    /**
     * Whether this member may invite this person, asked again at the moment it
     * matters.
     *
     * The candidate list is a convenience for the screen. This is the check —
     * a client that posts an XREF it was never offered gets the same answer as
     * one that posts a stranger's.
     */
    public function mayInvite(
        Individual $viewer,
        Tree $tree,
        int $access_level,
        int $steps,
        InvitationService $invitations,
        string $xref
    ): bool {
        return isset($this->invitable($viewer, $tree, $access_level, $steps, $invitations)[$xref]);
    }

    /** Whether somebody in this tree already has an account of their own. */
    public function hasAccount(Tree $tree, string $xref): bool
    {
        return isset($this->linkedXrefs($tree)[$xref]);
    }

    /**
     * Every XREF that already belongs to an account, in this tree.
     *
     * @return array<string,true>
     */
    private function linkedXrefs(Tree $tree): array
    {
        $linked = [];

        foreach ($this->user_service->all() as $user) {
            $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

            if ($xref !== '') {
                $linked[$xref] = true;
            }
        }

        return $linked;
    }

    /**
     * @param array<int,Individual|Family> $path
     */
    private function nameFor(array $path): string|null
    {
        $name = $this->relationships->nameFromPath($path, I18N::language());

        return $name === '' ? null : $name;
    }

    /**
     * @return array<int,Family>
     */
    private function families(Individual $individual, int $access_level): array
    {
        $families = [
            ...$individual->childFamilies($access_level)->all(),
            ...$individual->spouseFamilies($access_level)->all(),
        ];

        return array_values(array_filter(
            $families,
            static fn (Family $family): bool => $family->facts(['RESN'], false, Auth::PRIV_HIDE)->isEmpty()
        ));
    }

    /**
     * @return array<int,Individual>
     */
    private function members(Family $family, int $access_level): array
    {
        $people = [
            ...$family->spouses($access_level)->all(),
            ...$family->children($access_level)->all(),
        ];

        return array_values(array_filter(
            $people,
            static fn (Individual $individual): bool => $individual->canShow($access_level)
        ));
    }
}
