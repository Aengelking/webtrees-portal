<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;

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
 */
class SearchConsent
{
    /**
     * The xrefs of every living person who has agreed to be listed.
     *
     * Read once and kept for the request. A search page checks this against a
     * few hundred records, and the answer cannot change while it does.
     *
     * @var array<string,true>|null
     */
    private array|null $listed = null;

    public function __construct(
        private readonly MemberService $members,
        private readonly PortalTreeService $trees,
    ) {
    }

    /** May this person appear in a search result or an index? */
    public function mayFind(Individual $individual): bool
    {
        if ($individual->isDead()) {
            return true;
        }

        return isset($this->listedXrefs()[$individual->xref()]);
    }

    /**
     * @return array<string,true>
     */
    private function listedXrefs(): array
    {
        if ($this->listed !== null) {
            return $this->listed;
        }

        $tree   = $this->trees->tree();
        $listed = [];

        foreach ($this->members->allVisible() as $member) {
            $xref = $this->linkedXref($tree, $member->user);

            if ($xref !== '') {
                $listed[$xref] = true;
            }
        }

        return $this->listed = $listed;
    }

    /**
     * The record webtrees links to an account, as an xref rather than a
     * record.
     *
     * `PortalTreeService::linkedIndividual()` would build the Individual, and
     * this needs only the string it is keyed by — for a directory of a hundred
     * members that is a hundred records fetched to compare names of.
     */
    private function linkedXref(Tree $tree, UserInterface $user): string
    {
        return $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);
    }
}
