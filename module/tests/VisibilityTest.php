<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\InvitationAccept;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Engelking\Webtrees\PortalApi\Services\Diagnosis;
use Engelking\Webtrees\PortalApi\Services\DiagnosisCheck;
use Engelking\Webtrees\PortalApi\Services\InvitationService;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Engelking\Webtrees\PortalApi\Services\PortalTreeService;
use Fig\Http\Message\RequestMethodInterface;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\User;
use Fisharebest\Webtrees\Auth;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Phase 8: how much of the tree a member can see.
 *
 * A different question from "whom may I invite", and the one more likely to
 * be quietly wider than intended. `Individual::canShowByType()` applies
 * relationship privacy only to an account that has *both* a linked record and
 * a `RELATIONSHIP_PATH_LENGTH` above zero; with either missing it falls
 * through to "show living people to members only", which for somebody signed
 * in means everybody alive.
 *
 * Neither is set by default and there is no site-wide or tree-wide default to
 * inspect, so the state is invisible until something reports it. These tests
 * are about the module doing the reporting and the setting.
 */
#[CoversNothing]
class VisibilityTest extends PortalTestCase
{
    private MemberService $members;

    protected function setUp(): void
    {
        parent::setUp();

        $this->members = Registry::container()->get(MemberService::class);
        $this->module()->setPreference(PortalApiModule::SETTING_PORTAL_URL, 'https://portal.example.test');
    }

    private function pathLength(User $user): int
    {
        return (int) $this->tree->getUserPreference($user, UserInterface::PREF_TREE_PATH_LENGTH);
    }

    /**
     * The `Tree` the services use, which is not the one this test holds.
     *
     * `Tree::getUserPreference()` fetches every preference for a user once
     * and caches them on the instance, and `setUserPreference()` updates only
     * that instance's copy. Two `Tree` objects for the same tree therefore
     * disagree until the next request. Harmless in webtrees, where a request
     * has one of them; a trap here, where a test writes through its own and
     * then asks a service that holds another.
     */
    private function portalTree(): \Fisharebest\Webtrees\Tree
    {
        return Registry::container()->get(PortalTreeService::class)->tree();
    }

    private function check(string $key): DiagnosisCheck
    {
        $check = Registry::container()->get(Diagnosis::class)->run()
            ->first(static fn (DiagnosisCheck $c): bool => $c->key === $key);

        self::assertInstanceOf(DiagnosisCheck::class, $check);

        return $check;
    }

    // -----------------------------------------------------------------
    // Reporting the state
    // -----------------------------------------------------------------

    /**
     * The default, spelled out. Nothing in webtrees says this anywhere, which
     * is the whole reason the check exists.
     */
    public function testUnrestrictedVisibilityIsReported(): void
    {
        $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '0');

        $check = $this->check('visibility');

        self::assertSame(Diagnosis::WARNING, $check->status);
        self::assertStringContainsString('living person', $check->detail);
    }

    public function testAnAccountThatStillHasNoLimitIsCounted(): void
    {
        $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '2');

        self::assertSame(Diagnosis::WARNING, $this->check('visibility')->status);

        // Written through the same instance the diagnosis will read from —
        // see portalTree().
        $this->members->applyPathLength($this->portalTree(), 2);

        self::assertSame(Diagnosis::OK, $this->check('visibility')->status);
    }

    /**
     * An account with no linked record cannot be limited at all — webtrees
     * measures the distance *from* that record, and its own UserEditAction
     * forces the value back to zero. It stays counted, and the "accounts with
     * no linked record" list is where it gets fixed.
     */
    public function testAnAccountWithNoRecordCannotBeLimited(): void
    {
        $lonely = $this->createUser('lonely', 'Ohne Datensatz', 'irgendwas', UserInterface::ROLE_MEMBER);
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '2');

        self::assertSame(0, $this->members->applyPathLength($this->tree, 2));
        self::assertSame(0, $this->pathLength($lonely));
        self::assertSame(Diagnosis::WARNING, $this->check('visibility')->status);
    }

    // -----------------------------------------------------------------
    // Applying it
    // -----------------------------------------------------------------

    public function testApplyingTheLimitTouchesMembersAndNobodyElse(): void
    {
        $member  = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $editor  = $this->createUser('edith', 'Edith Editor', 'irgendwas', UserInterface::ROLE_EDITOR, 'X2');
        $manager = $this->createUser('mona', 'Mona Managerin', 'irgendwas', UserInterface::ROLE_MANAGER, 'X4');

        self::assertSame(1, $this->members->applyPathLength($this->tree, 2));

        self::assertSame(2, $this->pathLength($member));

        // The people who maintain the tree need all of it to do that.
        self::assertSame(0, $this->pathLength($editor));
        self::assertSame(0, $this->pathLength($manager));
    }

    public function testApplyingTwiceChangesNothingTheSecondTime(): void
    {
        $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');

        self::assertSame(1, $this->members->applyPathLength($this->tree, 2));
        self::assertSame(0, $this->members->applyPathLength($this->tree, 2));
    }

    public function testAnAdministratorIsNeverRestricted(): void
    {
        $admin = $this->createUser('chef', 'Die Chefin', 'irgendwas', UserInterface::ROLE_MEMBER, 'X1');
        $admin->setPreference(UserInterface::PREF_IS_ADMINISTRATOR, '1');

        self::assertSame(0, $this->members->applyPathLength($this->tree, 2));
        self::assertSame(0, $this->pathLength($admin));
    }

    // -----------------------------------------------------------------
    // New accounts
    // -----------------------------------------------------------------

    public function testAnInvitedAccountIsLimitedOnArrival(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '2');

        $token = (new InvitationService())->create($this->tree, 'X4', 'Dieter Beispiel', '', null);

        $response = $this->api(
            InvitationAccept::class,
            RequestMethodInterface::METHOD_POST,
            body: [
                'token'     => $token,
                'username'  => 'dieter',
                'real_name' => 'Dieter Beispiel',
                'email'     => 'dieter@example.test',
                'password'  => 'correct-horse',
            ],
            headers: $this->csrfHeader(),
        );

        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        $user = Registry::container()->get(\Fisharebest\Webtrees\Services\UserService::class)->findByUserName('dieter');

        self::assertInstanceOf(User::class, $user);
        self::assertSame(2, $this->pathLength($user));
    }

    /**
     * Zero means the administrator chose not to restrict. Writing a zero and
     * writing nothing are the same to webtrees, but only one of them reads as
     * a decision — so the module writes nothing.
     */
    public function testAnInvitedAccountIsNotLimitedWhenTheSettingIsZero(): void
    {
        $this->module()->setPreference(PortalApiModule::SETTING_MEMBER_PATH_LENGTH, '0');

        $token = (new InvitationService())->create($this->tree, 'X4', 'Dieter Beispiel', '', null);

        $this->api(
            InvitationAccept::class,
            RequestMethodInterface::METHOD_POST,
            body: [
                'token'     => $token,
                'username'  => 'dieter',
                'real_name' => 'Dieter Beispiel',
                'email'     => 'dieter@example.test',
                'password'  => 'correct-horse',
            ],
            headers: $this->csrfHeader(),
        );

        $user = Registry::container()->get(\Fisharebest\Webtrees\Services\UserService::class)->findByUserName('dieter');

        self::assertInstanceOf(User::class, $user);
        self::assertSame(0, $this->pathLength($user));
    }

    // -----------------------------------------------------------------
    // What "only living people" actually means
    // -----------------------------------------------------------------

    /**
     * The claim this whole phase rests on: a limit hides living relatives and
     * leaves the genealogy alone.
     *
     * Anna is limited to one step. Her grandfather Konrad is two steps away
     * and died in 1929 — he stays visible, because `canShowByType()` checks
     * the dead *first* and returns before the relationship test is reached.
     * Fritz is alive, connected to nobody, and therefore beyond any limit —
     * he disappears.
     *
     * Runs in its own process: `Individual::isRelated()` keeps its results in
     * a function-level `static` that is keyed by neither user nor tree, so a
     * second test in the same process would be answered from the first one's
     * cache.
     */
    #[RunInSeparateProcess]
    public function testALimitHidesLivingPeopleAndLeavesTheDeadAlone(): void
    {
        $anna = $this->createUser('anna', 'Anna Beispiel', 'correct-horse', UserInterface::ROLE_MEMBER, 'X1');
        $this->tree->setUserPreference($anna, UserInterface::PREF_TREE_PATH_LENGTH, '1');
        $this->login($anna);

        $konrad = Registry::individualFactory()->make('X10', $this->tree);
        $fritz  = Registry::individualFactory()->make('X6', $this->tree);

        self::assertTrue($konrad->isDead(), 'The fixture grandfather should read as deceased.');
        self::assertFalse($fritz->isDead(), 'The fixture Fritz should read as living.');

        self::assertTrue(
            $konrad->canShow(Auth::PRIV_USER),
            'A grandfather two steps away, dead since 1929, was hidden by a one-step limit.'
        );

        self::assertFalse(
            $fritz->canShow(Auth::PRIV_USER),
            'A living person related to nobody was visible despite a one-step limit.'
        );
    }

    /**
     * The caveat, pinned so it is not forgotten: "living" is a *guess*.
     *
     * `Individual::isDead()` says yes for a death event, for any dated event
     * more than `MAX_ALIVE_AGE` (120) years ago, or by inference from
     * relatives' dates. A record with a name and nothing else satisfies none
     * of those, so webtrees treats it as living — and a relationship limit
     * will hide it, however obviously historical the person is.
     *
     * That is the practical cost of switching a limit on: thin records, not
     * recent ones, are what disappear.
     */
    public function testARecordWithNoDatesAtAllCountsAsLiving(): void
    {
        $undated = Registry::individualFactory()->new(
            'X900',
            "0 @X900@ INDI\n1 NAME Ohne /Daten/",
            null,
            $this->tree
        );

        self::assertFalse(
            $undated->isDead(),
            'A record with no dates is treated as living, so a relationship limit will hide it.'
        );
    }

    /**
     * The other caveat. `KEEP_ALIVE_YEARS_DEATH` makes webtrees go on treating
     * somebody as living for N years after they died — and a person "kept
     * alive" falls through to the relationship test like anybody else. It is
     * empty by default, so this is off unless the tree sets it, but it is the
     * one setting that can make a limit reach the recently deceased.
     */
    public function testKeepAliveYearsPullTheRecentlyDeadBackIntoTheLimit(): void
    {
        // Asked of a tree that has never been given the setting, because the
        // point is webtrees' default rather than the fixture's. Built the way
        // the module builds one — `new Tree(...)` took three arguments until
        // 2.2.6 and nine after it, and a test that pins the old shape is a
        // test that fails on the version the host actually runs.
        DB::table('gedcom_setting')
            ->where('gedcom_id', '=', $this->tree->id())
            ->where('setting_name', '=', 'KEEP_ALIVE_YEARS_DEATH')
            ->delete();

        Registry::cache()->array()->forget('all-trees');

        $tree = Registry::container()->get(PortalTreeService::class)->configuredTree();

        self::assertSame('', $tree->getPreference('KEEP_ALIVE_YEARS_DEATH', ''));
    }

    // -----------------------------------------------------------------
    // What webtrees names, which the portal does not
    // -----------------------------------------------------------------

    /**
     * The setting that makes the two programs disagree.
     *
     * `Individual::canShowName()` is an **or**: `SHOW_LIVING_NAMES` alone is
     * enough, whatever `canShow()` says. Its default is `Auth::PRIV_USER`, so
     * a member reading a deceased relative's page in webtrees sees the names
     * of the living people in that family — the people the portal shows as an
     * unnamed placeholder. Nothing in either program says so, and there is a
     * link into webtrees at the foot of every person's page in the portal.
     */
    public function testWebtreesNamingLivingPeopleToMembersIsReported(): void
    {
        // PortalTestCase spells webtrees' own default out; this is it.
        $check = $this->check('living_names');

        self::assertSame(Diagnosis::WARNING, $check->status);
        self::assertStringContainsString(Auth::accessLevelNames()[Auth::PRIV_USER], $check->detail);
        self::assertStringContainsString('placeholder', $check->advice);
    }

    public function testWithholdingThemInWebtreesIsReportedAsAgreement(): void
    {
        // Written through the instance the services will read from — see
        // portalTree().
        $this->portalTree()->setPreference('SHOW_LIVING_NAMES', (string) Auth::PRIV_NONE);

        $check = $this->check('living_names');

        self::assertSame(Diagnosis::OK, $check->status);
        self::assertStringContainsString(Auth::accessLevelNames()[Auth::PRIV_NONE], $check->detail);
    }

    /**
     * "Show to visitors" on a tree anybody can open is a different order of
     * problem: the names of living people are then readable by whoever finds
     * the address, with no account at all.
     */
    public function testNamesShownToVisitorsOnAPublicTreeAreAProblem(): void
    {
        $this->portalTree()->setPreference('SHOW_LIVING_NAMES', (string) Auth::PRIV_PRIVATE);

        self::assertSame(Diagnosis::PROBLEM, $this->check('living_names')->status);
    }

    /**
     * And the same setting on a tree that requires signing in is not that.
     *
     * A visitor cannot open the tree at all there, so "visitors" behaves as
     * "members" — worth reporting, not worth calling a problem. A diagnosis
     * screen that shouts about something which discloses nothing is a screen
     * that gets ignored when it is right.
     *
     * Signed in as a manager because that is who reads this screen, and
     * because `TreeService::all()` hides a private tree from everybody else —
     * including, otherwise, from the check itself.
     *
     * `requireAuthentication()` writes the flag where *this* version of
     * webtrees keeps it; `Diagnosis::requiresAuthentication()` reads it
     * through the matching pair of doors, for the same reason.
     */
    public function testATreeThatRequiresSigningInIsNotCalledAProblem(): void
    {
        $mia = $this->createUser('mia', 'Mia Verwalterin', 'correct-horse', UserInterface::ROLE_MANAGER, 'X1');

        $this->requireAuthentication();

        // A fresh request: `login()` installs a new cache, so the tree is
        // rebuilt from the row that was just changed.
        $this->login($mia);

        $this->portalTree()->setPreference('SHOW_LIVING_NAMES', (string) Auth::PRIV_PRIVATE);

        self::assertSame(Diagnosis::WARNING, $this->check('living_names')->status);
    }

    /**
     * The second setting is reported and never complained about.
     *
     * `SHOW_PRIVATE_RELATIONSHIPS` decides whether a hidden relative's row is
     * listed as "Private" or left off the family page altogether. Since §2.72
     * the portal's own pedigree says the first of those — somebody stands
     * here — so either value agrees with it. It belongs on the screen as a
     * fact, not as a finding.
     */
    public function testTheRelationshipSettingIsReportedButNeverComplainedAbout(): void
    {
        $tree = $this->portalTree();
        $tree->setPreference('SHOW_LIVING_NAMES', (string) Auth::PRIV_NONE);

        foreach (['1', '0'] as $value) {
            $tree->setPreference('SHOW_PRIVATE_RELATIONSHIPS', $value);

            $check = $this->check('living_names');

            self::assertSame(Diagnosis::OK, $check->status, 'Relationships set to ' . $value);
            self::assertStringContainsString('Show private relationships', $check->detail);
        }
    }
}
