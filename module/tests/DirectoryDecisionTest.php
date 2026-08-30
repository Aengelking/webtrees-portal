<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\ProfileUpdate;
use Engelking\Webtrees\PortalApi\Schema\Migration20;
use Engelking\Webtrees\PortalApi\Services\MemberService;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\User;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Whether this member has ever been asked about the directory.
 *
 * The switch was always there and nothing ever asked, so nobody was in the
 * directory — not because they had declined, but because the question had
 * never been put. The portal now puts it, on the member's own profile, until
 * it is answered.
 *
 * "Until it is answered" is the whole design, and it is why this is a column
 * rather than a flag in a browser: the members who need asking signed in
 * months ago, on a telephone whose local storage says nothing, and the same
 * person on a second device must not be asked twice.
 *
 * What is pinned here is that the two questions stay apart. *Are you listed?*
 * is `visible_in_directory` and may change as often as the member likes.
 * *Were you asked?* is answered once and never taken back — including when
 * the answer was "no thank you", which is exactly the answer a portal that
 * kept asking would be ignoring.
 */
#[CoversNothing]
class DirectoryDecisionTest extends PortalTestCase
{
    private User $anna;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', 'Anna Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X1');
        $this->login($this->anna);
    }

    private function decide(bool $visible): array
    {
        return $this->json($this->api(
            ProfileUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            body: ['visible_in_directory' => $visible],
            headers: $this->csrfHeader(),
        ));
    }

    private function row(): object
    {
        $row = DB::table(MemberService::TABLE)->where('wt_user_id', '=', $this->anna->id())->first();

        self::assertNotNull($row);

        return $row;
    }

    public function testAMemberWhoHasNeverBeenAskedSaysSo(): void
    {
        $this->createProfile($this->anna, false);

        $body = $this->json($this->api(MeRead::class));

        self::assertFalse($body['profile']['directory_decided']);
        self::assertFalse($body['profile']['visible_in_directory']);
    }

    public function testSayingYesListsThemAndCountsAsAnAnswer(): void
    {
        $this->createProfile($this->anna, false);

        $profile = $this->decide(true);

        self::assertTrue($profile['visible_in_directory']);
        self::assertTrue($profile['directory_decided']);
        self::assertNotNull($profile['consent_recorded_at']);
    }

    /**
     * The answer that would otherwise be invisible. "No thank you" leaves the
     * row exactly as it was — not listed, no consent — so without a column of
     * its own there would be nothing to tell it apart from never having been
     * asked, and the card would come back tomorrow.
     */
    public function testSayingNoChangesNothingExceptThatItWasAsked(): void
    {
        $this->createProfile($this->anna, false);

        $profile = $this->decide(false);

        self::assertFalse($profile['visible_in_directory']);
        self::assertNull($profile['consent_recorded_at']);
        self::assertTrue($profile['directory_decided']);
    }

    /**
     * Leaving the directory is changing an answer, not withdrawing one. The
     * consent timestamp goes, because it says "since when is this person
     * listed"; the decision stays, because they were asked and they answered.
     */
    public function testLeavingTheDirectoryDoesNotUnAskTheQuestion(): void
    {
        $this->createProfile($this->anna, false);

        $this->decide(true);
        $profile = $this->decide(false);

        self::assertFalse($profile['visible_in_directory']);
        self::assertNull($profile['consent_recorded_at']);
        self::assertTrue($profile['directory_decided']);
    }

    /** The moment is recorded once and not moved by a later change. */
    public function testTheMomentOfTheAnswerIsNotRewritten(): void
    {
        $this->createProfile($this->anna, false);

        $this->decide(true);
        $answered = $this->row()->directory_decided_at;

        self::assertNotNull($answered);

        $this->decide(false);

        self::assertSame($answered, $this->row()->directory_decided_at);
    }

    /**
     * An account that has never touched anything has no profile row at all,
     * and /me says `null` for it. That is the emptiest possible "never
     * answered", and answering has to work from there — which means writing
     * the row.
     */
    public function testAnAccountWithNoProfileRowCanStillAnswer(): void
    {
        $body = $this->json($this->api(MeRead::class));

        self::assertNull($body['profile']);

        $profile = $this->decide(false);

        self::assertTrue($profile['directory_decided']);
        self::assertFalse($profile['visible_in_directory']);
    }

    /**
     * Nothing else on this endpoint answers the question. A member renaming
     * themselves in the directory has not said anything about being in it.
     */
    public function testChangingSomethingElseIsNotAnAnswer(): void
    {
        $this->createProfile($this->anna, false);

        $profile = $this->json($this->api(
            ProfileUpdate::class,
            RequestMethodInterface::METHOD_PATCH,
            body: ['display_name_override' => 'Anna B.'],
            headers: $this->csrfHeader(),
        ));

        self::assertSame('Anna B.', $profile['display_name_override']);
        self::assertFalse($profile['directory_decided']);
    }
}

/**
 * The rows that existed before the question did.
 *
 * The point of asking in the first place is the members who signed in months
 * ago, so the migration has to say something about every row already there —
 * and it has to say the *narrow* thing: whoever is plainly in the directory
 * decided to be, everybody else gets asked.
 */
#[CoversNothing]
class DirectoryDecisionBackfillTest extends PortalTestCase
{
    private function backfill(): void
    {
        (new Migration20())->upgrade();
    }

    private function decidedFor(User $user): string|null
    {
        $row = DB::table(MemberService::TABLE)->where('wt_user_id', '=', $user->id())->first();

        self::assertNotNull($row);

        return $row->directory_decided_at;
    }

    private function forget(User $user): void
    {
        DB::table(MemberService::TABLE)
            ->where('wt_user_id', '=', $user->id())
            ->update(['directory_decided_at' => null]);
    }

    public function testSomebodyAlreadyInTheDirectoryIsNotAskedAgain(): void
    {
        $user = $this->createUser('bertha', 'Bertha Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X2');
        $this->createProfile($user, true);
        $this->forget($user);

        $this->backfill();

        // Their consent is when they answered, so that is the moment recorded.
        self::assertSame('2026-01-01 00:00:00', $this->decidedFor($user));
    }

    /**
     * And everybody else is asked — including the member who switched the
     * directory off by hand, whose row is indistinguishable from one that was
     * never asked. Being asked once more is the cost of not being able to tell
     * them apart; asking nobody was the alternative.
     */
    public function testSomebodyNotInTheDirectoryIsAsked(): void
    {
        $user = $this->createUser('dieter', 'Dieter Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X4');
        $this->createProfile($user, false);
        $this->forget($user);

        $this->backfill();

        self::assertNull($this->decidedFor($user));
    }

    /** Run twice, it must not overwrite an answer given in between. */
    public function testItDoesNotTouchARowThatHasSinceAnswered(): void
    {
        $user = $this->createUser('emil', 'Emil Beispiel', 'pw', UserInterface::ROLE_MEMBER, 'X5');
        $this->createProfile($user, false);
        $this->forget($user);

        DB::table(MemberService::TABLE)
            ->where('wt_user_id', '=', $user->id())
            ->update(['visible_in_directory' => 1, 'directory_decided_at' => '2026-08-01 09:00:00']);

        $this->backfill();

        self::assertSame('2026-08-01 09:00:00', $this->decidedFor($user));
    }
}
