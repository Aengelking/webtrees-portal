<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Individual;

/**
 * Who may be *found* by searching, which is not the same as who may be seen.
 *
 * Every other screen in this portal reaches a person because the reader was
 * already somewhere: their own record, a relative's, a member they know. A
 * search is the first screen that starts from nobody and returns a list of
 * people the reader had no particular reason to be looking at. That is a
 * different disclosure, and it needs its own rule.
 *
 * **The rule.** Somebody dead is findable. Somebody living is findable only if
 * they are a portal member who put themselves in the directory.
 *
 * The two halves come from the same place the rest of this module does. For
 * the dead, there is nobody left to ask and the family archive is what a
 * portal like this is *for* — the same sentence `Schema/Migration9.php` writes
 * about photographs. For the living, the portal has exactly one recorded
 * answer to "may this person be listed to every member": the directory
 * consent in `portal_member_profile`. Reusing it means a member turns up in
 * one search because they turned up in the other, and switching the directory
 * off in Settings takes them out of both. One switch, one meaning — rather
 * than a second consent question that says almost the same thing and can
 * disagree with the first.
 *
 * **This narrows, it never widens.** Everything here runs *after* webtrees'
 * own access level has already had its say: a record the reader may not see
 * was gone before this class was asked. So the worst this can do is hide
 * somebody the tree would have shown, which is the direction worth being
 * wrong in — and it is why a member who is not in the directory can still be
 * reached by walking the family from a relative, exactly as before. The
 * search does not become a second, quieter way of enumerating the living.
 *
 * **And it applies to members, not to the people who keep the tree.** See
 * `keepsTheTree()`: an editor already has all of it, one click away, in the
 * software this portal is a front door to.
 */
class SearchConsent
{
    /** Whether this reader maintains the tree, asked once. */
    private bool|null $keeper = null;

    public function __construct(
        private readonly MemberService $members,
        private readonly PortalTreeService $trees,
    ) {
    }

    /** May this person appear in a search result or an index? */
    public function mayFind(Individual $individual): bool
    {
        if ($this->keepsTheTree()) {
            return true;
        }

        if ($individual->isDead()) {
            return true;
        }

        return isset($this->listedXrefs()[$individual->xref()]);
    }

    /**
     * Whether the reader is one of the people who maintain the tree.
     *
     * **They are exempt, and the rule is no weaker for it.** This narrows what
     * a *member* can enumerate. An editor already has the whole tree: they
     * open it in webtrees, they change it, they can export the GEDCOM. Hiding
     * a living cousin from their search protects nobody and costs them the
     * one screen that would have made the portal usable for the work they
     * actually do — finding the record that needs fixing.
     *
     * It is also the line webtrees itself draws, and the one this module
     * already draws twice: the "what members can see" limit never touches
     * these roles, and `IndividualView` offers them the editing link.
     *
     * Read once. `Auth::isEditor()` is a per-tree preference lookup, and this
     * is asked once per record in a scan of the whole archive.
     */
    private function keepsTheTree(): bool
    {
        return $this->keeper ??= Auth::isEditor($this->trees->tree());
    }

    /**
     * Everybody who has agreed to be listed, keyed by xref.
     *
     * Kept in `MemberService` rather than here, and read once per request
     * there. A search page checks this against a few hundred records and the
     * pedigree checks it against a few dozen; one answer serves both, and two
     * copies of "who consented" is exactly the pair that drifts apart.
     *
     * @return array<string,Member>
     */
    private function listedXrefs(): array
    {
        return $this->members->listedByXref($this->trees->tree());
    }
}
