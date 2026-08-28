<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Individual;

/**
 * The one rule the MCP server is built around: the archive's dead, and nobody
 * else.
 *
 * **Why a rule of its own, when webtrees already has privacy settings.**
 * Everything else in this portal is read by a person who is themselves in the
 * family, on a screen, one record at a time. The MCP server is read by a
 * program, on somebody else's behalf, and what it hands over goes into a
 * language model's context — which is to say, off this server, into a system
 * that keeps its own copies and answers its own questions with them. That is a
 * different disclosure from the one every other endpoint makes, and it is
 * worth being unfashionably strict about.
 *
 * The living are the part of a family tree that people are entitled to have
 * opinions about. The dead are the part that a family archive exists to keep.
 * So the line is drawn there, and drawn once: this class is asked about every
 * record on its way out, and there is no path through the MCP server that does
 * not go past it.
 *
 * **This narrows, it never widens.** It runs *after* webtrees' own access
 * level has had its say, exactly as `SearchConsent` does — a record the token's
 * account may not see was gone before this class was asked. The worst it can
 * do is hide somebody the tree would have shown, and where `Individual::isDead()`
 * cannot tell (no death event, no dates, no datable relatives) it answers
 * "alive", which is the direction worth being wrong in.
 *
 * **It is stricter than `SearchConsent`, on purpose.** That rule lets a living
 * member be found if they put themselves in the family directory; they
 * consented to be listed to *the family*, which is not the same as consenting
 * to be summarised by somebody's assistant. There is no equivalent consent
 * here, so there is no equivalent exception — and unlike the search rule, this
 * one has no exemption for editors either. An editor who wants the living has
 * webtrees, where the record is, and where reading it is an act with their
 * name on it.
 *
 * **The dead stay reachable through the living.** Where a living person stands
 * between the reader and an ancestor, this hides the person and not the line:
 * the pedigree walks past them as an unnamed rung, exactly as the portal's own
 * pedigree does (§2.75). A rule that stopped at the first living parent would
 * hide the whole archive behind two generations of it.
 */
class DeceasedOnly
{
    /**
     * May this record leave through the MCP server at all?
     *
     * Both halves, in this order: what webtrees says at the token's access
     * level, and then whether the person is dead. Neither is sufficient on its
     * own — a dead person on a record the account may not read is still not
     * this caller's to read.
     */
    public function mayRead(Individual $individual, int $access_level): bool
    {
        return $individual->canShow($access_level) && $individual->isDead();
    }
}
