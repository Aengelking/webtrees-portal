<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function is_file;
use function ltrim;
use function preg_match;
use function preg_match_all;
use function preg_quote;
use function preg_split;
use function sprintf;
use function str_contains;

/**
 * `NOTES.md` is referred to from the code, so its numbering is an interface.
 *
 * Roughly a hundred places — comments, docblocks, the API description, the
 * README — say "see §2.82" and expect that to land somewhere. Two ways it
 * stops landing, and this suite had neither:
 *
 * **A number used twice.** Every session appends a section and picks the next
 * number, and where two pieces of work were in flight the same number went on
 * both. It happened seven times before anybody looked. A reference then names
 * two sections, which is worse than naming none: it reads as though it
 * resolves. The convention for fixing it is a letter — `2.82a` sits where it
 * was written and moves nothing after it, because renumbering the tail would
 * break every reference in the file to buy tidiness.
 *
 * **A number that was never used.** One handler cited section 2.67 for two
 * months. There has never been a section 2.67; the one meant was §2.71.
 * Nothing anywhere said so, because nothing was looking. (Written without the
 * sign, or this docblock would fail its own test — which is the test working.)
 *
 * Neither is a matter of taste, so neither is left to attention. Note that CI
 * splits by what changed and runs this suite for `module/**`, so a commit that
 * touches nothing but `NOTES.md` does not reach it — the guard is for the
 * ordinary case, where notes and code move together.
 */
#[CoversNothing]
class NotesReferenceTest extends TestCase
{
    /** Where a reference may point: `NOTES.md`, and nothing else claims `§`. */
    private const string NOTES = __DIR__ . '/../../NOTES.md';

    /** What is read for references. `.webtrees` is a checkout, not ours. */
    private const array SOURCES = [
        __DIR__ . '/../../module/portal_api',
        __DIR__ . '/../../module/tests',
        __DIR__ . '/../../portal/src',
        __DIR__ . '/../../portal/e2e',
        __DIR__ . '/../../NOTES.md',
        __DIR__ . '/../../README.md',
        __DIR__ . '/../../openapi.yaml',
    ];

    private const array EXTENSIONS = ['php', 'phtml', 'ts', 'tsx', 'md', 'yaml'];

    /**
     * A section number belongs to one section.
     *
     * The failure this catches is silent by nature: both sections render, both
     * look right, and only a reader following a `§` finds out that it names
     * two places.
     */
    public function testNoSectionNumberIsUsedTwice(): void
    {
        $seen = [];

        foreach ($this->headings() as $line => $number) {
            self::assertArrayNotHasKey(
                $number,
                $seen,
                sprintf(
                    'NOTES.md line %d gives §%s a second section; line %d already has it. '
                        . 'A section written later takes a letter — §%sa — so that nothing after it moves.',
                    $line,
                    $number,
                    $seen[$number] ?? 0,
                    $number
                )
            );

            $seen[$number] = $line;
        }

        self::assertGreaterThan(50, count($seen), 'NOTES.md was read but almost nothing was found in it.');
    }

    /**
     * Every reference into `NOTES.md` points at a section that exists.
     *
     * **Only the numbered-with-a-dot ones.** A bare `§4` is not a reference
     * here — `InvitationAccept` cites "§4 of the handoff", meaning section
     * four of a different document, and `NOTES.md` happens to have a section
     * four of its own. Accepting bare numbers would resolve that citation
     * against the wrong file and call it checked, which is the failure this
     * test exists to prevent rather than commit.
     */
    public function testEveryReferenceLandsSomewhere(): void
    {
        $headings = $this->headings();
        $found    = 0;

        foreach ($this->files() as $path) {
            $contents = (string) file_get_contents($path);
            $matches  = [];

            preg_match_all('/§(\d+\.\d+[a-z]?)/', $contents, $matches);

            foreach ($matches[1] as $number) {
                self::assertContains(
                    $number,
                    $headings,
                    sprintf('%s cites §%s, and NOTES.md has no such section.', $this->shorten($path), $number)
                );

                $found++;
            }
        }

        self::assertGreaterThan(50, $found, 'No references were read, so this proved nothing.');
    }

    /**
     * Every heading in `NOTES.md`, as line number => section number.
     *
     * @return array<int,string>
     */
    private function headings(): array
    {
        self::assertFileExists(self::NOTES);

        $numbers = [];
        $lines   = preg_split('/\R/', (string) file_get_contents(self::NOTES)) ?: [];

        foreach ($lines as $index => $line) {
            $matches = [];

            if (preg_match('/^#{2,3} (\d+(?:\.\d+)?[a-z]?) /', $line, $matches) === 1) {
                $numbers[$index + 1] = $matches[1];
            }
        }

        return $numbers;
    }

    /**
     * The files that may carry a reference.
     *
     * @return array<int,string>
     */
    private function files(): array
    {
        $paths = [];

        foreach (self::SOURCES as $source) {
            if (is_file($source)) {
                $paths[] = $source;

                continue;
            }

            foreach ($this->descend($source) as $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return array<int,string> */
    private function descend(string $directory): array
    {
        $paths = [];

        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_dir($path)) {
                if (str_contains($path, 'node_modules')) {
                    continue;
                }

                foreach ($this->descend($path) as $deeper) {
                    $paths[] = $deeper;
                }

                continue;
            }

            foreach (self::EXTENSIONS as $extension) {
                if (preg_match('/\.' . preg_quote($extension, '/') . '$/', $path) === 1) {
                    $paths[] = $path;

                    break;
                }
            }
        }

        return $paths;
    }

    /** A path a person can read in a failure message. */
    private function shorten(string $path): string
    {
        $root    = __DIR__ . '/../../';
        $matches = [];

        return preg_match('/^' . preg_quote($root, '/') . '(.*)$/', $path, $matches) === 1
            ? ltrim($matches[1], '/')
            : $path;
    }
}
