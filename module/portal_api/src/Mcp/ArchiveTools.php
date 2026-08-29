<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Mcp;

use Engelking\Webtrees\PortalApi\Services\ArchiveNotes;
use Engelking\Webtrees\PortalApi\Services\ArchiveReader;
use stdClass;

use function array_filter;
use function array_values;
use function implode;
use function in_array;
use function is_int;
use function is_string;
use function json_encode;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The seven questions an assistant may ask the family archive.
 *
 * A thin skin, on purpose: this class reads arguments, calls `ArchiveReader`
 * and formats an answer. It decides nothing about who may be named — that is
 * `DeceasedOnly`'s job, asked further in, on every record — and it reads no
 * genealogy data of its own.
 *
 * **The descriptions are part of the interface.** A model chooses a tool by
 * reading them, and a model that has not been told the archive holds only the
 * dead will cheerfully report that a search found nothing and conclude the
 * person does not exist. So each one says what it covers and what it does not,
 * in the plainest words available, and `withheld` is explained wherever it can
 * appear.
 *
 * **Answers go out twice.** `content` carries the JSON as text, which every
 * client can show and every model can read; `structuredContent` carries the
 * same object for clients that would rather have it parsed. No `outputSchema`
 * is declared, because the shapes here are the portal's own and change with
 * it — a schema that has to be kept in step with `RecordPresenter` is a
 * schema that will one day disagree with it.
 */
final class ArchiveTools
{
    /**
     * TEMPORARY — a fixed picture, for finding out whether images survive the
     * trip to a client at all.
     *
     * 448x88 PNG, 591 bytes, reading "SACK-4711" in black on white. It comes
     * from nowhere near the archive: no record, no file on disk, no privacy
     * decision. That is the point — it separates "this client can carry an
     * image" from every question about which picture anybody may see.
     *
     * Delete this constant, `imageProbe()` and the `debug_test_image` entry
     * together once the question is answered.
     */
    private const string PROBE_PNG =
        'iVBORw0KGgoAAAANSUhEUgAAAcAAAABYCAIAAADtBbC+AAAACXBIWXMAAA7EAAAOxAGVKw4bAAACAUlEQVR42u3dwY6CMBRA'
        . 'UTvh/3+Z2boQ09r3WmjPWc1iglTJTfOCUs7zfAHQ7s9bACCgAAIKIKAAAgqAgAIIKICAAggogIACIKAAAgogoAACCiCgAAgo'
        . 'gIACCCiAgAIgoAACCiCgAAIKsIsj46CllJDj9Dyz/uoceo4Ztcarc4g65+y1j9S6lqhrL+qaHPlZrPS524ECCCgAAgoQKGwG'
        . 'mjG/e/+75jg186/WY0atsWddI9//O8iYL488h+w57KzXwg4UQEABBBTgoY67nVD2XLJ1Fhk1W3z//6i5lbnnnudv7mkHCiCg'
        . 'AAIKQJPjiSfdM/u7mkVm3JtZcw4j1/7Ez/EOs8XW66rmmBnXj9moHSiAgAIIKAAfhc1Aa2aLPcfc2c6/7+m9xQ4UQEABEFCA'
        . 'Tin3gUbd5zjy3szdjHx2kLkndqAACCiAgAJMdKy0mFW/C5zx/f07zPuinjvU87wsc0/sQAEEFEBAAZa31Aw06nlK2aJml0+5'
        . 'Z3bW75+ae2IHCiCgAAIKwCtwBrrqvClq5pj9bBy/IbD2dYgdKICAAiCgAD/zTKQJa8xYl3noXK3XQMb9rRmvhR0ogIACCCjA'
        . 'Aoo5CIAdKICAAggogIACIKAAAgogoAACCiCgAAgogIACCCiAgAIIKAACCiCgAAIKIKAACCiAgAIIKICAAggoAF/9A7ekz+FL'
        . 'OJK2AAAAAElFTkSuQmCC';

    public function __construct(
        private readonly ArchiveReader $archive,
        private readonly ArchiveNotes $notes,
    ) {
    }

    /**
     * What `tools/list` answers.
     *
     * @return array<int,array<string,mixed>>
     */
    public function definitions(): array
    {
        // An installation that does not publish the family's prose does not
        // offer a tool for searching it. Hiding it is better than offering it
        // and answering nothing: a model told a tool exists will keep trying
        // it, and will read an empty answer as "the archive has no notes about
        // her" rather than "this archive does not hand its notes out".
        return array_values(array_filter($this->all(), fn (array $tool): bool =>
            $tool['name'] !== 'search_notes' || $this->notes->published()));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function all(): array
    {
        return [
            [
                'name'        => 'search_people',
                'title'       => 'Search people',
                'description' =>
                    'Find people in the family archive by name, nickname, or archive reference '
                    . '("SB") number. Names and places in this archive are German. '
                    . 'Only people who have died are in the archive: a living person is never '
                    . 'returned and never mentioned, so an empty result means "not among the '
                    . 'archive\'s dead", not "no such person". Returns each person\'s id, which '
                    . 'is what get_person, get_ancestors, get_descendants and get_relationship take. '
                    . '"total" is how many matched altogether; where that is more than the limit, only '
                    . 'the first are returned and "truncated" says so.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'A name, part of a name, a nickname, or an archive reference number such as "4712" or "10/1335.21".',
                        ],
                        'limit' => $this->limitSchema(),
                    ],
                    'required'             => ['query'],
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
            [
                'name'        => 'get_person',
                'title'       => 'Read one person',
                'description' =>
                    'Everything the archive holds about one dead person: names, archive reference '
                    . 'numbers and the branch of the family they belong to, dated and placed events, '
                    . 'the notes the family wrote about them, and their parents, siblings, spouses '
                    . 'and children. Living relatives are not listed; the "withheld" counts say how '
                    . 'many of each kind were left out, so absence from a list does not mean the '
                    . 'person had none.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['id' => $this->idSchema()],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
            [
                'name'        => 'get_ancestors',
                'title'       => 'Walk up the family line',
                'description' =>
                    'The line above one person, in Ahnentafel order: position 1 is the person, 2 '
                    . 'their father, 3 their mother, and each parent is at twice their child\'s '
                    . 'position. A rung whose person is living or otherwise not readable comes back '
                    . 'with "person": null, and the walk carries on above them — so the archive\'s '
                    . 'older generations stay reachable through relatives who are still alive.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => $this->idSchema(),
                        'generations' => $this->generationsSchema('How many generations above the person to walk.'),
                    ],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
            [
                'name'        => 'get_descendants',
                'title'       => 'Walk down the family line',
                'description' =>
                    'The line below one person, one generation at a time. Most descendants of anyone '
                    . 'born in the last hundred and fifty years are alive, so expect the "withheld" '
                    . 'count on each generation to be large and do not read a short list as a small '
                    . 'family.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'          => $this->idSchema(),
                        'generations' => $this->generationsSchema('How many generations below the person to walk.'),
                    ],
                    'required'             => ['id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
            [
                'name'        => 'get_relationship',
                'title'       => 'How two people are related',
                'description' =>
                    'Names the relationship between two people in the archive — "great-grandfather", '
                    . '"first cousin" — in the archive\'s own language. Both people must be among the '
                    . 'archive\'s dead. The answer is null when there is no relationship within four '
                    . 'steps, which is not the same as no relationship at all.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'from_id' => $this->idSchema('The person the relationship is named from.'),
                        'to_id'   => $this->idSchema('The person the relationship is named to.'),
                    ],
                    'required'             => ['from_id', 'to_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
            [
                'name'        => 'search_notes',
                'title'       => 'Search the family\'s notes',
                'description' =>
                    'Search the prose the family wrote into the archive — what somebody did for a '
                    . 'living, why a family moved, which of two people a photograph shows, why a date '
                    . 'is a guess. This is where the archive\'s history is, as opposed to its dates. '
                    . 'Each result is a note together with the dead person it belongs to; a note that '
                    . 'belongs to nobody this server may name is not returned. The notes are in German. '
                    . '"total" and "truncated" mean what they do in search_people.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [
                            'type'        => 'string',
                            'description' => 'Text to look for inside the notes. Matched literally, not as a pattern.',
                        ],
                        'limit' => $this->limitSchema(),
                    ],
                    'required'             => ['query'],
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
            [
                'name'        => 'list_index',
                'title'       => 'Surnames and places in the archive',
                'description' =>
                    'Every surname the archive\'s dead are filed under, and every place an event of '
                    . 'theirs happened in, each with how many people it covers. Useful for getting '
                    . 'one\'s bearings before searching, and for spelling a name the way this archive '
                    . 'spells it. The counts are of the dead only, and "truncated" is true if the '
                    . 'archive was too large to read in one request.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'kind' => [
                            'type'        => 'string',
                            'enum'        => ['surnames', 'places', 'both'],
                            'default'     => 'both',
                            'description' => 'Which index to return.',
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
            [
                'name'        => 'debug_test_image',
                'title'       => 'Test image (temporary)',
                'description' =>
                    'TEMPORARY DIAGNOSTIC. Returns one small fixed picture with a word written on '
                    . 'it, to find out whether this client can carry an image at all. It reads '
                    . 'nothing from the family archive and takes no arguments. If asked to use it, '
                    . 'call it and report the word you can read in the picture.',
                'inputSchema' => [
                    'type' => 'object',
                    // An *object* with no properties. PHP would encode `[]`
                    // as a JSON array and a client validating this schema
                    // would reject the tool list — the same trap that broke
                    // `capabilities.tools`; see NOTES.md §2.85.
                    'properties'           => new stdClass(),
                    'additionalProperties' => false,
                ],
                'annotations' => $this->readOnly(),
            ],
        ];
    }

    /**
     * Run one tool.
     *
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed> A `tools/call` result.
     *
     * @throws McpException for a tool nobody has, or arguments that are not
     *                      the shape the schema says. Those are the client's
     *                      mistakes and belong in a JSON-RPC error; everything
     *                      that is merely a disappointing answer comes back as
     *                      a result the model can read.
     */
    public function call(string $name, array $arguments): array
    {
        return match ($name) {
            'search_people'    => $this->ok($this->archive->searchPeople(
                $this->string($arguments, 'query'),
                $this->int($arguments, 'limit', ArchiveReader::DEFAULT_RESULTS),
            )),
            'get_person'       => $this->found(
                $this->archive->person($this->string($arguments, 'id')),
                'No such person in this archive, or not one it may name. The archive holds only people who have died.',
            ),
            'get_ancestors'    => $this->found(
                $this->archive->ancestors(
                    $this->string($arguments, 'id'),
                    $this->int($arguments, 'generations', ArchiveReader::DEFAULT_GENERATIONS),
                ),
                'No such person in this archive, or not one it may name.',
            ),
            'get_descendants'  => $this->found(
                $this->archive->descendants(
                    $this->string($arguments, 'id'),
                    $this->int($arguments, 'generations', ArchiveReader::DEFAULT_GENERATIONS),
                ),
                'No such person in this archive, or not one it may name.',
            ),
            'get_relationship' => $this->found(
                $this->archive->relationship(
                    $this->string($arguments, 'from_id'),
                    $this->string($arguments, 'to_id'),
                ),
                'One or both of those people are not in this archive, or not ones it may name.',
            ),
            'search_notes'     => $this->notes->published()
                ? $this->ok($this->archive->searchNotes(
                    $this->string($arguments, 'query'),
                    $this->int($arguments, 'limit', ArchiveReader::DEFAULT_RESULTS),
                ))
                : throw McpException::unknownTool('search_notes'),
            'list_index'       => $this->ok($this->index($this->enum($arguments, 'kind', ['surnames', 'places', 'both'], 'both'))),
            'debug_test_image' => $this->imageProbe(),
            default            => throw McpException::unknownTool($name),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function index(string $kind): array
    {
        $index = $this->archive->index();

        return match ($kind) {
            'surnames' => ['surnames' => $index['surnames'], 'truncated' => $index['truncated']],
            'places'   => ['places' => $index['places'], 'truncated' => $index['truncated']],
            default    => $index,
        };
    }

    // -----------------------------------------------------------------
    // Answers
    // -----------------------------------------------------------------

    /**
     * TEMPORARY — see `PROBE_PNG`.
     *
     * The image block first, then the text: a client that drops the image
     * still shows the sentence, which makes the failure legible instead of
     * silent.
     *
     * @return array<string,mixed>
     */
    private function imageProbe(): array
    {
        return [
            'content' => [
                ['type' => 'image', 'data' => self::PROBE_PNG, 'mimeType' => 'image/png'],
                ['type' => 'text', 'text' => 'Temporary diagnostic. An image block should precede this sentence. Report the word written in it.'],
            ],
            'isError' => false,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     *
     * @return array<string,mixed>
     */
    private function ok(array $payload): array
    {
        return [
            'content'           => [['type' => 'text', 'text' => $this->text($payload)]],
            'structuredContent' => $payload,
            'isError'           => false,
        ];
    }

    /**
     * Nothing found, said in a way the model can act on.
     *
     * `isError` rather than an empty object, because "there is no such person"
     * is something the model needs to notice and reason about rather than
     * summarise as a blank record. It is not a protocol error: the request was
     * perfectly well formed and the server answered it.
     *
     * **One sentence for "no such record" and for "not yours to read"**, as
     * everywhere else in this module: an answer that told the two apart would
     * be a way of proving that somebody exists.
     *
     * @param array<string,mixed>|null $payload
     *
     * @return array<string,mixed>
     */
    private function found(array|null $payload, string $refusal): array
    {
        if ($payload === null) {
            return [
                'content' => [['type' => 'text', 'text' => $refusal]],
                'isError' => true,
            ];
        }

        return $this->ok($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function text(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    // -----------------------------------------------------------------
    // Arguments
    // -----------------------------------------------------------------

    /**
     * @param array<string,mixed> $arguments
     */
    private function string(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw McpException::invalidParams('"' . $key . '" is required and must be a non-empty string.');
        }

        return trim($value);
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function int(array $arguments, string $key, int $default): int
    {
        $value = $arguments[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_int($value)) {
            throw McpException::invalidParams('"' . $key . '" must be a whole number.');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $arguments
     * @param array<int,string>   $allowed
     */
    private function enum(array $arguments, string $key, array $allowed, string $default): string
    {
        $value = $arguments[$key] ?? null;

        if ($value === null) {
            return $default;
        }

        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw McpException::invalidParams('"' . $key . '" must be one of: ' . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    private function idSchema(string $description = 'The archive identifier of a person, as returned by search_people.'): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * @return array<string,mixed>
     */
    private function limitSchema(): array
    {
        return [
            'type'        => 'integer',
            'minimum'     => 1,
            'maximum'     => ArchiveReader::MAX_RESULTS,
            'default'     => ArchiveReader::DEFAULT_RESULTS,
            'description' => 'How many results to return, at most ' . ArchiveReader::MAX_RESULTS . '.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function generationsSchema(string $description): array
    {
        return [
            'type'        => 'integer',
            'minimum'     => 1,
            'maximum'     => ArchiveReader::MAX_GENERATIONS,
            'default'     => ArchiveReader::DEFAULT_GENERATIONS,
            'description' => $description,
        ];
    }

    /**
     * Every tool here reads and none of them writes. Said out loud so that a
     * client can offer them without a confirmation prompt each time, and so
     * that the day somebody adds a tool that does write, the omission is
     * visible.
     *
     * @return array<string,bool>
     */
    private function readOnly(): array
    {
        return [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ];
    }
}
