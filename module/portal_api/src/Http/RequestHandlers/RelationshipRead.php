<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Http\RequestHandlers;

use Engelking\Webtrees\PortalApi\Http\Json;
use Engelking\Webtrees\PortalApi\Services\SackRelationship;
use Fisharebest\Webtrees\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_map;
use function mb_substr;
use function trim;

/**
 * GET /api/v1/relationship — two archive numbers in, a relationship out.
 *
 * The family's own calculator, which has existed since 2009 as a page of its
 * own, brought inside the portal. It is the one screen here that touches no
 * records at all: an SB number *is* an ancestral path (see `SackNumbers`), so
 * this is arithmetic on two strings and nothing else.
 *
 * **Which is exactly why it has no privacy rule of its own.** There is nothing
 * to disclose. Nobody is named, no record is read, and the endpoint cannot be
 * used to find out whether a number belongs to anybody — it answers the same
 * way for a number the archive has never issued. Signed-in, because everything
 * in this API is, and no further.
 *
 * It answers about two people who need not be in the tree at all, which is the
 * point: at a family gathering the number is what somebody has, written on the
 * back of a photograph or read off a cousin's card.
 */
class RelationshipRead implements RequestHandlerInterface
{
    /** Longer than any archive number, short enough not to be a payload. */
    private const int MAX_LENGTH = 40;

    public function __construct(private readonly SackRelationship $sack)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $first  = $this->number($request, 'a');
        $second = $this->number($request, 'b');

        $all      = $first === '' || $second === '' ? [] : $this->sack->relations($first, $second);
        $relation = $all[0] ?? null;

        return Json::response([
            'a' => $first,
            'b' => $second,
            // What is wrong, when something is — so the screen can point at
            // the field that needs fixing rather than saying "no result".
            'problem' => match (true) {
                $first === '' || $second === ''      => 'incomplete',
                !$this->sack->isNumber($first)       => 'invalid_a',
                !$this->sack->isNumber($second)      => 'invalid_b',
                $relation !== null
                    && $relation['kind'] === 'self'  => 'identical',
                default                              => null,
            },
            'relationship' => $relation === null || $relation['kind'] === 'self'
                ? null
                : $this->sack->describe($relation),
            // Every way these two are related, nearest first — a person whose
            // ancestors married within the family has more than one number,
            // and each measures a different distance. `relationship` is the
            // first of these; it stays because it is the answer to the
            // question as it is usually asked.
            'relationships' => $relation === null || $relation['kind'] === 'self'
                ? []
                : array_map(fn (array $one): string => $this->sack->describe($one), $all),
            // The working, for anybody who wants to check it: which shape it
            // is, how many generations apart, and how far from the ancestor
            // they share.
            'detail' => $relation,
        ]);
    }

    private function number(ServerRequestInterface $request, string $name): string
    {
        return mb_substr(trim(Validator::queryParams($request)->string($name, '')), 0, self::MAX_LENGTH);
    }
}
