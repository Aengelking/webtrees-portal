<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\Middleware\ApiEnvelope;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\IndividualRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MediaRead;
use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Fisharebest\Webtrees\Contracts\UserInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Phase 4: photographs.
 *
 * Anna (X1) carries two: M1, which anybody in the family may see, and M2,
 * which is `1 RESN confidential`. Every test here is about the difference.
 */
#[CoversNothing]
class PhotoTest extends PortalTestCase
{
    private function signInAsAnna(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', UserInterface::ROLE_MEMBER, 'X1'));
    }

    public function testARecordCarriesItsVisiblePhotographs(): void
    {
        $this->signInAsAnna();

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

    public function testAConfidentialPhotographIsAbsentEntirely(): void
    {
        $this->signInAsAnna();

        $response = $this->api(MeRead::class);

        self::assertStringNotContainsString('M2', $this->raw($response));
        self::assertStringNotContainsString('vertraulich', $this->raw($response));
        self::assertStringNotContainsString('Nicht fuer alle', $this->raw($response));
    }

    /**
     * The list shapes carry a portrait too, so a name in the directory or in a
     * relative list can have a face beside it without a second request each.
     */
    public function testAPortraitTravelsWithEveryReference(): void
    {
        $this->signInAsAnna();

        $individual = $this->json($this->api(MeRead::class))['individual'];

        self::assertNotNull($individual['portrait']);
        self::assertSame($individual['photos'][0]['id'], $individual['portrait']['id']);

        // Someone with no photograph says so, rather than being left out.
        $mother = $this->json($this->api(IndividualRead::class, attributes: ['xref' => 'X2']));

        self::assertNull($mother['portrait']);
        self::assertSame([], $mother['photos']);
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
