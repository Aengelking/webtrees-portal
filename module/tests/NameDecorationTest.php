<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use Engelking\Webtrees\PortalApi\Http\RequestHandlers\MeRead;
use Fisharebest\Webtrees\Factories\IndividualFactory;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * A webtrees installation is a webtrees installation *plus its other modules*,
 * and some of them decorate how a name is displayed.
 *
 * The Vesta "Classic Look & Feel" module overrides `Individual::fullName()` to
 * put a badge in front of the name — a reference number, in the installation
 * this was found on — and can append the XREF after it. Both are reasonable on
 * a webtrees page and wrong in a JSON field called `name`: a badge is not part
 * of anyone's name, and this API publishes no XREFs at all.
 *
 * So the presenter reads the structured name instead of the display string.
 * This test stands in for Vesta by decorating `fullName()` the same way.
 */
#[CoversNothing]
class NameDecorationTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Registry::individualFactory(new class extends IndividualFactory {
            public function new(string $xref, string $gedcom, string|null $pending, Tree $tree): Individual
            {
                return new class ($xref, $gedcom, $pending, $tree) extends Individual {
                    public function fullName(): string
                    {
                        return '<span class="badge">SB 4711</span> ' . parent::fullName() . ' (' . $this->xref() . ')';
                    }
                };
            }
        });
    }

    protected function tearDown(): void
    {
        Registry::individualFactory(new IndividualFactory());

        parent::tearDown();
    }

    public function testADecoratedDisplayNameDoesNotReachTheApi(): void
    {
        $this->login($this->createUser('anna', 'Anna Beispiel', 'geheim', 'member', 'X1'));

        $response = $this->api(MeRead::class);
        $me       = $this->json($response);

        self::assertSame('Anna Beispiel', $me['individual']['name']);

        // Not in a relative's name either, and not anywhere in the payload —
        // including the XREF the decoration appends.
        // Her nickname is a `2 NICK` subtag and belongs in the name; the
        // module's own decoration does not.
        self::assertSame('Bertha "Betty" Beispiel', $me['individual']['parents'][1]['name']);
        self::assertStringNotContainsString('SB 4711', $this->raw($response));
        self::assertStringNotContainsString('(X1)', $this->raw($response));
    }

    /**
     * The decoration is only a display concern, so the record itself still
     * reads exactly as it did — this is a name fix, not a name rewrite.
     */
    public function testTheNameIsOtherwiseUnchanged(): void
    {
        $this->login($this->createUser('emil', 'Emil Beispiel', 'geheim', 'member', 'X5'));

        $me = $this->json($this->api(MeRead::class));

        self::assertSame('Emil Beispiel', $me['individual']['name']);
        self::assertNull($me['individual']['name_alternative']);
    }
}
