<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\SessionCreate;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Not everything the portal shows is translated in the browser.
 *
 * Fact labels, formatted dates and the placeholders webtrees uses where a name
 * may not be shown all come from webtrees' own translations, server-side. A
 * German portal with English fact labels in it is the bug these tests are here
 * to keep fixed.
 */
#[CoversNothing]
class LanguageTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts from the language the bootstrap set up, whatever
        // the previous test asked for. I18N *and* the element factory are
        // static, and in a real installation both are built fresh for every
        // request — so reset both, or one test's language leaks into the next.
        I18N::init('en-US');
        Registry::container()->get(Gedcom::class)->registerTags(Registry::elementFactory(), true);
    }

    private function events(array $individual): array
    {
        $labels = [];

        foreach ($individual['events'] as $event) {
            $labels[$event['tag']] = $event['label'];
        }

        return $labels;
    }

    public function testFactLabelsFollowTheRequestedLanguage(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $me = $this->json($this->api(MeRead::class, headers: ['Accept-Language' => 'de']));

        $labels = $this->events($me['individual']);

        self::assertSame('Geburt', $labels['INDI:BIRT']);
        self::assertSame('Beruf', $labels['INDI:OCCU']);
        self::assertSame('Geburt', $me['individual']['birth']['label']);
    }

    public function testEnglishIsStillEnglish(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $me     = $this->json($this->api(MeRead::class, headers: ['Accept-Language' => 'en']));
        $labels = $this->events($me['individual']);

        self::assertSame('Birth', $labels['INDI:BIRT']);
        self::assertSame('Occupation', $labels['INDI:OCCU']);
    }

    /**
     * Month names are the other half of this: a date rendered by webtrees is
     * "12. März 1985" or "March 12, 1985", never a mix.
     *
     * One language per process, because webtrees caches the translated month
     * names in a function-level `static` — harmless in production, where a
     * request is a process, but it means the English half of this belongs in
     * a process of its own.
     */
    #[RunInSeparateProcess]
    public function testDatesAreFormattedInTheRequestedLanguage(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $birth = $this->json($this->api(MeRead::class, headers: ['Accept-Language' => 'de']))['individual']['birth'];

        self::assertSame('12. März 1985', $birth['date']['display']);

        // The machine-readable form does not move, because clients compare it.
        self::assertSame('12 MAR 1985', $birth['date']['gedcom']);
        self::assertSame(1985, $birth['date']['year']);
    }

    #[RunInSeparateProcess]
    public function testEnglishDatesAreEnglish(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $birth = $this->json($this->api(MeRead::class, headers: ['Accept-Language' => 'en']))['individual']['birth'];

        self::assertSame('March 12, 1985', $birth['date']['display']);
    }

    public function testARegionalTagFallsBackToThePlainLanguage(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        // "de-AT" is not an installed webtrees language; "de" is.
        $me = $this->json($this->api(MeRead::class, headers: ['Accept-Language' => 'de-AT,de;q=0.9,en;q=0.5']));

        self::assertSame('Geburt', $this->events($me['individual'])['INDI:BIRT']);
    }

    public function testTheHighestQualityWins(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $me = $this->json($this->api(MeRead::class, headers: ['Accept-Language' => 'fr;q=0.2,de;q=0.9']));

        self::assertSame('Geburt', $this->events($me['individual'])['INDI:BIRT']);
    }

    public function testAnUnknownLanguageChangesNothing(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $me = $this->json($this->api(MeRead::class, headers: ['Accept-Language' => 'xx-YY']));

        self::assertSame('Birth', $this->events($me['individual'])['INDI:BIRT']);
    }

    public function testNoHeaderChangesNothing(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $me = $this->json($this->api(MeRead::class));

        self::assertSame('Birth', $this->events($me['individual'])['INDI:BIRT']);
    }

    /**
     * Logging in returns the member's record, so the login response has fact
     * labels in it like any other.
     */
    public function testTheLoginResponseUsesTheRequestedLanguage(): void
    {
        $user = $this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1');
        $user->setPreference(UserInterface::PREF_LANGUAGE, 'en-US');

        $me = $this->json($this->api(
            SessionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['username' => 'anna', 'password' => 'geheim'],
            headers: $this->csrfHeader() + ['Accept-Language' => 'de'],
        ));

        self::assertSame('Geburt', $this->events($me['individual'])['INDI:BIRT']);
    }

    /**
     * With no opinion from the client, the account's own webtrees preference
     * is what a member gets — the same as signing in to webtrees itself.
     */
    public function testLoginFallsBackToTheAccountLanguage(): void
    {
        $user = $this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1');
        $user->setPreference(UserInterface::PREF_LANGUAGE, 'de');

        $me = $this->json($this->api(
            SessionCreate::class,
            RequestMethodInterface::METHOD_POST,
            body: ['username' => 'anna', 'password' => 'geheim'],
            headers: $this->csrfHeader(),
        ));

        self::assertSame('Geburt', $this->events($me['individual'])['INDI:BIRT']);
    }

    public function testTheResponseSaysItVariesByLanguage(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $response = $this->api(MeRead::class, headers: ['Accept-Language' => 'de']);

        self::assertSame('Cookie, Accept-Language', $response->getHeaderLine('Vary'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }
}
