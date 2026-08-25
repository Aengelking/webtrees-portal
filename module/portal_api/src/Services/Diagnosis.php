<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use Throwable;

use function count;
use function method_exists;
use function time;
use function trim;

/**
 * Everything that has to be true for the portal to work, checked in one place.
 *
 * Each of these has already been the cause of a confusing morning at least
 * once: a tree setting still pointing at a test import, an upload that landed
 * but whose migrations had not run, a `boot()` that threw and took the API
 * with it while webtrees carried on looking perfectly healthy.
 *
 * The value of putting them together is that they fail in similar-looking
 * ways from the outside. "The portal says 503" is the same sentence for a
 * missing tree, a missing table and a module that did not start, and the
 * three have nothing to do with each other.
 *
 * A check never throws. A diagnosis screen that breaks on a broken
 * installation is no use to anybody.
 */
class Diagnosis
{
    public const string OK      = 'ok';
    public const string WARNING = 'warning';
    public const string PROBLEM = 'problem';

    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
        private readonly MemberService $members,
        private readonly ErrorLog $errors,
        private readonly DistributionLists $lists,
    ) {
    }

    /**
     * @return Collection<int,DiagnosisCheck>
     */
    public function run(): Collection
    {
        return new Collection([
            $this->tree(),
            $this->schema(),
            $this->routes(),
            $this->portalAddress(),
            $this->proxySecret(),
            $this->ownRegistration(),
            $this->unlinkedAccounts(),
            $this->visibility(),
            $this->livingNames(),
            $this->mailingLists(),
            $this->recentErrors(),
        ]);
    }

    private function tree(): DiagnosisCheck
    {
        try {
            $tree = $this->trees->tree();

            return new DiagnosisCheck(
                'tree',
                self::OK,
                I18N::translate('Family tree'),
                $tree->title() . ' (' . $tree->name() . ')',
                // Said even when it is fine, because "fine" here means "the
                // portal is serving whichever tree this is" — and after a
                // rehearsal on a test import, that is the setting most likely
                // to be quietly wrong.
                I18N::translate('Members see records from this tree and no other. Check that it is the right one, especially after testing against a second tree.')
            );
        } catch (Throwable $exception) {
            return new DiagnosisCheck(
                'tree',
                self::PROBLEM,
                I18N::translate('Family tree'),
                $exception->getMessage(),
                I18N::translate('Every endpoint answers 503 until this is set. Choose the tree in the module preferences.')
            );
        }
    }

    private function schema(): DiagnosisCheck
    {
        $expected  = $this->module->schemaVersion();
        $installed = $this->module->installedSchemaVersion();

        if ($installed === $expected) {
            return new DiagnosisCheck(
                'schema',
                self::OK,
                I18N::translate('Database tables'),
                I18N::translate('Version %s', I18N::number($installed)),
                ''
            );
        }

        return new DiagnosisCheck(
            'schema',
            self::PROBLEM,
            I18N::translate('Database tables'),
            I18N::translate('The code expects version %1$s, the database has version %2$s.', I18N::number($expected), I18N::number($installed)),
            // The distinction that matters: new files can be on the server
            // while their tables are not, and from the outside that looks
            // like a deployment that worked.
            $installed < $expected
                ? I18N::translate('The migrations have not run. Open any page of this website once; if that does not help, the module could not start — see below.')
                : I18N::translate('The database is newer than the code. An older copy of the module has been uploaded over a newer one.')
        );
    }

    /**
     * Did `boot()` finish?
     *
     * This is the check that is hard to make any other way. `boot()` catches
     * everything it throws, on purpose, so that a broken module cannot take
     * the family's genealogy site down with it — which means a module that
     * failed to start looks exactly like one that started, from every page of
     * webtrees except this one. Its routes are simply absent.
     */
    private function routes(): DiagnosisCheck
    {
        try {
            Registry::routeFactory()->routeMap()->getRoute(MeRead::class);

            return new DiagnosisCheck(
                'routes',
                self::OK,
                I18N::translate('API routes'),
                I18N::translate('Registered.'),
                ''
            );
        } catch (Throwable) {
            return new DiagnosisCheck(
                'routes',
                self::PROBLEM,
                I18N::translate('API routes'),
                I18N::translate('Not registered — the module did not start.'),
                I18N::translate('webtrees itself is unaffected, which is why nothing else looks wrong. The reason is in the server’s error log, on a line beginning “portal_api:”.')
            );
        }
    }

    private function portalAddress(): DiagnosisCheck
    {
        $url = $this->module->getPreference(PortalApiModule::SETTING_PORTAL_URL, '');

        if ($url !== '') {
            return new DiagnosisCheck('portal_url', self::OK, I18N::translate('Portal address'), $url, '');
        }

        return new DiagnosisCheck(
            'portal_url',
            self::PROBLEM,
            I18N::translate('Portal address'),
            I18N::translate('Not set.'),
            I18N::translate('Password reset emails, invitation links and connection codes all need it, and all three are switched off without it.')
        );
    }

    private function proxySecret(): DiagnosisCheck
    {
        $secret = $this->module->getPreference(PortalApiModule::SETTING_PROXY_SECRET, '');

        if ($secret !== '') {
            return new DiagnosisCheck(
                'proxy_secret',
                self::OK,
                I18N::translate('Proxy secret'),
                I18N::translate('Set.'),
                ''
            );
        }

        return new DiagnosisCheck(
            'proxy_secret',
            self::WARNING,
            I18N::translate('Proxy secret'),
            I18N::translate('Not set.'),
            I18N::translate('The API accepts requests from anywhere, not only from the portal. Fine while developing; set it once the portal is live, with the same value on the Cloudflare Worker.')
        );
    }

    /**
     * webtrees' own registration page is a second front door that this module
     * knows nothing about: an account created there is not linked to anybody
     * and was vouched for by no one.
     */
    private function ownRegistration(): DiagnosisCheck
    {
        if (Site::getPreference('USE_REGISTRATION_MODULE') !== '1') {
            return new DiagnosisCheck(
                'registration',
                self::OK,
                I18N::translate('webtrees’ own registration'),
                I18N::translate('Switched off.'),
                ''
            );
        }

        return new DiagnosisCheck(
            'registration',
            self::WARNING,
            I18N::translate('webtrees’ own registration'),
            I18N::translate('Switched on.'),
            I18N::translate('Anybody can request an account there, and this module knows nothing about it. With invitations in place there is no reason to leave it on.')
        );
    }

    private function unlinkedAccounts(): DiagnosisCheck
    {
        try {
            $tree  = $this->trees->tree();
            $count = $this->members->accountsWithoutRecord($tree)->count();
        } catch (Throwable) {
            // The tree check above already says what is wrong; saying it
            // twice would only make the screen harder to read.
            return new DiagnosisCheck(
                'unlinked',
                self::OK,
                I18N::translate('Accounts with no linked record'),
                I18N::translate('Cannot be counted without a family tree.'),
                ''
            );
        }

        if ($count === 0) {
            return new DiagnosisCheck(
                'unlinked',
                self::OK,
                I18N::translate('Accounts with no linked record'),
                I18N::translate('None.'),
                ''
            );
        }

        return new DiagnosisCheck(
            'unlinked',
            self::WARNING,
            I18N::translate('Accounts with no linked record'),
            I18N::plural('%s account', '%s accounts', $count, I18N::number($count)),
            I18N::translate('They can sign in, but the portal has nothing of their own to show them. They are listed on the invitations screen.')
        );
    }

    /**
     * What a member can see — which is not the same question as what they may
     * invite, and is the one most likely to be quietly wider than intended.
     *
     * webtrees applies relationship privacy only when an account has both a
     * linked record and a `RELATIONSHIP_PATH_LENGTH` above zero. Neither is
     * set by default, so out of the box every member sees every living person
     * in the tree. Nothing anywhere says so, which is the reason this check
     * exists: the setting is per user, there is no default to inspect, and
     * the symptom is invisible.
     *
     * Note what a limit does *not* do: the dead are checked before the
     * relationship test in `Individual::canShowByType()` and stay visible, so
     * the genealogy remains whole.
     *
     * With one caveat worth knowing before switching it on: "dead" is
     * `Individual::isDead()`, which is a guess. It is true for a death event,
     * for a dated event more than `MAX_ALIVE_AGE` years old, or by inference
     * from relatives' dates — and false for a record with a name and nothing
     * else. So a limit hides *thin* records, not recent ones.
     */
    private function visibility(): DiagnosisCheck
    {
        $configured = $this->module->memberPathLength();

        if ($configured === 0) {
            return new DiagnosisCheck(
                'visibility',
                self::WARNING,
                I18N::translate('What a member can see'),
                I18N::translate('Every living person in the family tree.'),
                I18N::translate('webtrees only limits this for accounts that have both a linked record and a relationship limit, and neither is set by default. Set “How much of the tree a member sees” in the module preferences to restrict it. Everybody webtrees knows has died is unaffected either way.')
            );
        }

        try {
            $tree      = $this->trees->tree();
            $unlimited = $this->members->accountsWithUnlimitedVisibility($tree)->count();
        } catch (Throwable) {
            return new DiagnosisCheck(
                'visibility',
                self::OK,
                I18N::translate('What a member can see'),
                I18N::translate('Cannot be counted without a family tree.'),
                ''
            );
        }

        if ($unlimited === 0) {
            return new DiagnosisCheck(
                'visibility',
                self::OK,
                I18N::translate('What a member can see'),
                I18N::plural('Relatives within %s step, and everybody who has died.', 'Relatives within %s steps, and everybody who has died.', $configured, I18N::number($configured)),
                ''
            );
        }

        return new DiagnosisCheck(
            'visibility',
            self::WARNING,
            I18N::translate('What a member can see'),
            I18N::plural('%s member account still sees every living person.', '%s member accounts still see every living person.', $unlimited, I18N::number($unlimited)),
            I18N::translate('The limit applies to accounts created by invitation from now on. Existing accounts keep what they had until it is applied to them — there is a button below. An account with no linked record cannot be limited at all, because the limit is measured from that record.')
        );
    }

    /**
     * What *webtrees* names, which is wider than what the portal names.
     *
     * A member opens a deceased relative's page in webtrees and reads the
     * names of the living people in that family — people the portal shows as
     * an unnamed placeholder. Not a fault: `canShowName()` is an **or**,
     *
     * ```php
     * (int) $tree->getPreference('SHOW_LIVING_NAMES') >= $access_level || $this->canShow($access_level)
     * ```
     *
     * and `SHOW_LIVING_NAMES` defaults to `Auth::PRIV_USER`. webtrees does
     * this on purpose — its own help text says so, "the names (but no other
     * details)" — because a chart of forty boxes reading "Private" is not a
     * chart. It is simply a different answer from the portal's, and nothing
     * in either program says the two disagree.
     *
     * **Which matters because the portal hands every member the door.** There
     * is a link into webtrees at the foot of every person's page, so the
     * placeholder discipline of the pedigree (§2.75) holds only as far as this
     * setting lets it. That is the whole reason for this check: like the
     * relationship path length above, the state is real, it is invisible, and
     * nobody would think to go and look.
     *
     * **`SHOW_PRIVATE_RELATIONSHIPS` is reported and never complained about.**
     * It decides whether a hidden relative's row is listed as "Private" or
     * left off the family page altogether — and since §2.75 the portal's own
     * pedigree says the same thing as the first of those: somebody stands
     * here. Either value agrees with the portal, so it is a fact for the
     * administrator rather than a finding.
     */
    private function livingNames(): DiagnosisCheck
    {
        $label = I18N::translate('Names of living people in webtrees');

        try {
            $tree = $this->trees->tree();
        } catch (Throwable) {
            return new DiagnosisCheck('living_names', self::OK, $label, I18N::translate('Cannot be read without a family tree.'), '');
        }

        $names  = (int) $tree->getPreference('SHOW_LIVING_NAMES');
        $levels = Auth::accessLevelNames();

        // Both settings, by the names and values webtrees itself gives them,
        // so that what is on this row can be found on that screen.
        $detail = I18N::translate('Show names of private individuals') . ': ' . ($levels[$names] ?? (string) $names)
            . ' · ' . I18N::translate('Show private relationships') . ': '
            . ($tree->getPreference('SHOW_PRIVATE_RELATIONSHIPS') === '1' ? I18N::translate('yes') : I18N::translate('no'));

        $where = I18N::translate('Control panel') . ' → ' . I18N::translate('Family trees')
            . ' → ' . I18N::translate('Preferences') . ' → ' . I18N::translate('Privacy');

        $fix = I18N::translate(
            'Set “%1$s” to “%2$s” under %3$s to make webtrees agree with the portal. “%4$s” needs no change either way — whether a hidden relative is listed as private or left out, the portal now says the same thing.',
            I18N::translate('Show names of private individuals'),
            $levels[Auth::PRIV_NONE],
            $where,
            I18N::translate('Show private relationships')
        );

        if ($names <= Auth::PRIV_NONE) {
            return new DiagnosisCheck(
                'living_names',
                self::OK,
                $label,
                $detail,
                I18N::translate('webtrees withholds these names as the portal does. A member who follows the link out of a person’s page sees no more there than here.')
            );
        }

        if ($names >= Auth::PRIV_PRIVATE && !$this->requiresAuthentication($tree)) {
            return new DiagnosisCheck(
                'living_names',
                self::PROBLEM,
                $label,
                $detail,
                I18N::translate('This tree does not require signing in, so the names of living people are readable by anybody who finds its address.') . ' ' . $fix
            );
        }

        $mismatch = I18N::translate('Every member reads these names in webtrees, while the portal shows an unnamed placeholder — and there is a link into webtrees at the foot of every person’s page.');

        // "Visitors" on a tree that cannot be read without signing in reaches
        // exactly the people "members" reaches, so it is the same finding with
        // one more sentence — not a quieter one, and not a louder one.
        if ($names >= Auth::PRIV_PRIVATE) {
            $mismatch = I18N::translate('Set for visitors, though this tree requires signing in — so in practice, every member.') . ' ' . $mismatch;
        }

        return new DiagnosisCheck('living_names', self::WARNING, $label, $detail, $mismatch . ' ' . $fix);
    }

    /**
     * Whether the tree can be read without signing in.
     *
     * Asked through two doors, because webtrees moved it between them.
     * **2.2.6** took `REQUIRE_AUTHENTICATION` out of `gedcom_setting` and made
     * it a column with `Tree::private()` in front of it; reading it as a
     * preference still works there and raises a deprecation notice while doing
     * so. Same reasoning, and the same `method_exists` shape, as
     * `PortalTreeService::treeFromRow()` — neither version's answer is assumed
     * and each is asked for by name.
     */
    private function requiresAuthentication(Tree $tree): bool
    {
        if (method_exists($tree, 'private')) {
            return $tree->private();
        }

        return $tree->getPreference('REQUIRE_AUTHENTICATION') === '1';
    }

    /**
     * Are the family's mailing lists being kept in step with Exchange?
     *
     * Deliberately answered from this database and not from Exchange. A
     * diagnosis screen that opens three connections to somebody else's cloud
     * takes half a minute to load on the day Exchange is the thing that is
     * broken — and it would be answering the wrong question anyway. What an
     * administrator needs to know is whether members' decisions are arriving,
     * and an outstanding row already carries Exchange's own complaint about
     * why the last one did not.
     */
    private function mailingLists(): DiagnosisCheck
    {
        $label = I18N::translate('Mailing lists');

        if ($this->module->getPreference(PortalApiModule::SETTING_MAILING_LISTS, '0') !== '1') {
            return new DiagnosisCheck('mailing_lists', self::OK, $label, I18N::translate('Not offered to members.'), '');
        }

        if (!$this->lists->enabled()) {
            return new DiagnosisCheck(
                'mailing_lists',
                self::PROBLEM,
                $label,
                I18N::translate('Switched on, but not usable.'),
                I18N::translate('The tenant, the application and at least one list all have to be filled in before a member can be offered anything. See the module preferences.')
            );
        }

        $overview = $this->lists->overview();
        $failed   = $overview['failed'];
        $waiting  = (int) $overview['outstanding'];

        if ($failed !== []) {
            return new DiagnosisCheck(
                'mailing_lists',
                self::PROBLEM,
                $label,
                I18N::plural('%s change could not be applied.', '%s changes could not be applied.', count($failed), I18N::number(count($failed)))
                    . ' ' . (string) $failed[0]['error'],
                I18N::translate('Members’ decisions are recorded and are not reaching Exchange. The usual cause is an expired client secret or a role the application no longer has. Fix that, then use the button below to try again.')
            );
        }

        if ($waiting > 0) {
            return new DiagnosisCheck(
                'mailing_lists',
                self::WARNING,
                $label,
                I18N::plural('%s change is on its way to Exchange.', '%s changes are on their way to Exchange.', $waiting, I18N::number($waiting)),
                I18N::translate('Nothing to do. An outstanding change is applied the next time the member opens the portal, or when the button below is used.')
            );
        }

        $subscribers = 0;

        foreach ($overview['members'] as $count) {
            $subscribers += (int) $count;
        }

        return new DiagnosisCheck(
            'mailing_lists',
            self::OK,
            $label,
            I18N::plural('%s subscription, all of them applied.', '%s subscriptions, all of them applied.', $subscribers, I18N::number($subscribers)),
            ''
        );
    }

    private function recentErrors(): DiagnosisCheck
    {
        $day = $this->errors->countSince(time() - 86400);

        if ($day === 0) {
            return new DiagnosisCheck(
                'errors',
                self::OK,
                I18N::translate('Errors in the last 24 hours'),
                I18N::translate('None.'),
                ''
            );
        }

        return new DiagnosisCheck(
            'errors',
            self::PROBLEM,
            I18N::translate('Errors in the last 24 hours'),
            I18N::plural('%s error', '%s errors', $day, I18N::number($day)),
            I18N::translate('Each one is a request that failed for a member. They are listed below.')
        );
    }

    /**
     * The worst status among the checks, for a one-line answer at the top.
     */
    /**
     * Every member of the directory, with the numbers a search can find them
     * by — which is the only honest answer to "why does the SB number find
     * nobody?".
     *
     * Three different causes look identical from the form: the person has no
     * portal account, or has one and stayed out of the directory, or is
     * listed but carries the number somewhere other than a `REFN`. This table
     * tells them apart at a glance, and shows the numbers exactly as the
     * search reads them — at a member's access level, so a number a `RESN`
     * hides is absent here too.
     *
     * @return array<int,array{name:string,xref:string|null,numbers:array<int,string>}>
     */
    public function directoryNumbers(): array
    {
        try {
            $tree = $this->trees->tree();
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ($this->members->allVisible() as $member) {
            $individual = $this->trees->linkedIndividual($tree, $member->user);
            $numbers    = [];

            if ($individual instanceof Individual) {
                foreach ($individual->facts(['REFN'], false, Auth::PRIV_HIDE) as $fact) {
                    if (!$fact->canShow(Auth::PRIV_USER)) {
                        continue;
                    }

                    $type   = trim($fact->attribute('TYPE'));
                    $number = trim($fact->value());

                    if ($number !== '') {
                        $numbers[] = $type === '' ? $number : $type . ' ' . $number;
                    }
                }
            }

            $rows[] = [
                'name'    => $member->display_name,
                'xref'    => $individual instanceof Individual ? $individual->xref() : null,
                'numbers' => $numbers,
            ];
        }

        return $rows;
    }

    public function worst(Collection $checks): string
    {
        foreach ([self::PROBLEM, self::WARNING] as $status) {
            if ($checks->contains(static fn (DiagnosisCheck $check): bool => $check->status === $status)) {
                return $status;
            }
        }

        return self::OK;
    }
}
