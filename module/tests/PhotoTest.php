<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MediaRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Phase 4: photographs.
 *
 * Anna (X1) carries two: M1, which webtrees would let anybody in the family
 * see, and M2, which is `1 RESN confidential`.
 *
 * **And since Phase 15 neither of them is shown**, because Anna is alive and
 * did not put them there. The rule and the argument are in
 * `Schema/Migration9.php`; what is tested here is that it holds in both
 * directions — a living person's tree photographs are gone until they upload
 * one themselves, and a dead person's (Bertha, X2, M3) are untouched, because
 * the family archive is what a portal like this is for.
 */
#[CoversNothing]
class PhotoTest extends PortalTestCase
{
    private function signInAsAnna(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1'));
    }

    /**
     * What an upload records: this member put this photograph there.
     *
     * Written directly rather than through the endpoint, because these tests
     * are about what is *shown*. `PhotoUploadTest` covers the putting.
     */
    private function consentTo(string $media_xref): void
    {
        DB::table('portal_photo')->insert([
            'wt_user_id' => Auth::id(),
            'media_xref' => $media_xref,
            'created_at' => time(),
        ]);
    }

    /**
     * The archive: somebody who died in 1976, whose photograph the family put
     * in the tree. Nobody can consent on her behalf and nobody needs to.
     */
    public function testTheDeadKeepTheirPhotographs(): void
    {
        $this->signInAsAnna();

        $mother = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X2']));

        self::assertCount(1, $mother['photos']);
        self::assertSame('Bertha 1935', $mother['photos'][0]['title']);
        self::assertNotNull($mother['portrait']);
    }

    /**
     * The rule, in the direction that costs something: Anna's own record
     * carries a photograph webtrees would show, and the portal does not,
     * because she never agreed to it.
     */
    public function testALivingPersonsTreePhotographIsNotShown(): void
    {
        $this->signInAsAnna();

        $individual = $this->json($this->api(MeRead::class))['individual'];

        self::assertSame([], $individual['photos']);
        self::assertNull($individual['portrait']);
    }

    public function testARecordCarriesItsVisiblePhotographs(): void
    {
        $this->signInAsAnna();
        $this->consentTo('M1');

        $individual = $this->json($this->api(MeRead::class))['individual'];

        self::assertCount(1, $individual['photos'], 'Only the one that may be seen.');
        self::assertSame('Anna im Garten', $individual['photos'][0]['title']);

        // The URLs are the portal's own, not webtrees'. A webtrees URL would
        // arrive at the other host without the session cookie and come back a
        // grey "forbidden" box.
        self::assertStringStartsWith('/api/v1/media/M1/', $individual['photos'][0]['thumbnail_url']);
        self::assertStringEndsWith('/thumbnail', $individual['photos'][0]['thumbnail_url']);
        self::assertStringEndsWith('/image', $individual['photos'][0]['image_url']);
    }

    /**
     * Consent does not override webtrees. M2 is `RESN confidential`, and a row
     * in the portal's table is permission from the member — not permission
     * from the family's own restriction, which is a separate answer to a
     * separate question and still no.
     */
    public function testAConfidentialPhotographStaysHiddenEvenWithConsent(): void
    {
        $this->signInAsAnna();
        $this->consentTo('M2');

        $individual = $this->json($this->api(MeRead::class))['individual'];

        self::assertSame([], $individual['photos']);
    }

    public function testAConfidentialPhotographIsAbsentEntirely(): void
    {
        $this->signInAsAnna();
        $this->consentTo('M1');

        $response = $this->api(MeRead::class);

        // `raw()` drops the CSRF token, which is what makes this safe: it is
        // 32 random characters, and one run in a hundred or so contains "M2"
        // by chance. Seen in the wild here, with a token beginning
        // "PN5iTM2QXkr". See §2.105.
        $body = $this->raw($response);

        self::assertStringNotContainsString('M2', $body);
        self::assertStringNotContainsString('vertraulich', $body);
        self::assertStringNotContainsString('Nicht fuer alle', $body);
    }

    /**
     * The list shapes carry a portrait too, so a name in the directory or in a
     * relative list can have a face beside it without a second request each.
     */
    public function testAPortraitTravelsWithEveryReference(): void
    {
        $this->signInAsAnna();
        $this->consentTo('M1');

        $individual = $this->json($this->api(MeRead::class))['individual'];

        self::assertNotNull($individual['portrait']);
        self::assertSame($individual['photos'][0]['id'], $individual['portrait']['id']);

        // Someone with no photograph says so, rather than being left out.
        $nobody = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X4']));

        self::assertNull($nobody['portrait']);
        self::assertSame([], $nobody['photos']);
    }

    public function testAConfidentialPhotographIsNotServedEither(): void
    {
        $this->signInAsAnna();

        // Even knowing the xref, and even with a plausible fact id.
        $response = $this->api(MediaRead::class, attributes: [
            'xref' => 'M2',
            'fact' => str_repeat('a', 32),
            'size' => 'thumbnail',
        ]);

        self::assertSame(404, $response->getStatusCode());

        // Byte-identical to a media record that does not exist at all.
        $missing = $this->api(MediaRead::class, attributes: [
            'xref' => 'M999',
            'fact' => str_repeat('a', 32),
            'size' => 'thumbnail',
        ]);

        self::assertSame(404, $missing->getStatusCode());
        self::assertSame($this->raw($missing), $this->raw($response));
    }

    public function testPhotographsNeedASession(): void
    {
        $response = $this->api(MediaRead::class, attributes: [
            'xref' => 'M1',
            'fact' => str_repeat('a', 32),
            'size' => 'thumbnail',
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * The one response in this API that a browser may keep — and only a
     * browser. webtrees' own header on these is `public, max-age=31536000`,
     * which is a year in any cache that will have it: wrong on the far side of
     * a CDN, where "any cache" includes one serving every member.
     */
    public function testAPhotographMayBeKeptByABrowserAndNoOneElse(): void
    {
        $this->signInAsAnna();
        $this->consentTo('M1');

        $photo = $this->json($this->api(MeRead::class))['individual']['photos'][0];

        [$xref, $fact] = array_slice(explode('/', $photo['thumbnail_url']), 4, 2);

        $response = $this->api(MediaRead::class, attributes: [
            'xref' => $xref,
            'fact' => $fact,
            'size' => 'thumbnail',
        ]);

        self::assertSame(
            'private, max-age=' . MediaRead::CACHE_SECONDS,
            $response->getHeaderLine('Cache-Control')
        );
        self::assertStringNotContainsString('public', $response->getHeaderLine('Cache-Control'));
        self::assertSame('', $response->getHeaderLine(ApiEnvelope::PRIVATE_CACHE_HEADER), 'The marker is removed.');
    }

    public function testEverythingElseIsStillNeverStored(): void
    {
        $this->signInAsAnna();

        self::assertSame('private, no-store', $this->api(MeRead::class)->getHeaderLine('Cache-Control'));
    }
}
