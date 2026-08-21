<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;

/**
 * Which tree does the portal cover?
 *
 * Phase 1 serves exactly one tree, named in the module's settings. The
 * question of whether the portal should ever span several trees is open (see
 * NOTES.md); resolving it means changing this class and adding a tree
 * parameter to the routes, not unpicking the rest of the module.
 */
class PortalTreeService
{
    public function __construct(
        private readonly PortalApiModule $module,
        private readonly TreeService $tree_service
    ) {
    }

    /**
     * The tree the portal serves.
     *
     * @throws ApiException when no tree is configured and none can be guessed.
     */
    public function tree(): Tree
    {
        $configured = $this->module->getPreference(PortalApiModule::SETTING_TREE, '');
        $trees      = $this->tree_service->all();

        if ($configured !== '') {
            $tree = $trees->get($configured);

            if ($tree instanceof Tree) {
                return $tree;
            }

            // Configured but missing: refuse rather than quietly serving a
            // different family's data.
            throw $this->notConfigured(
                'the configured tree "' . $configured . '" does not exist. Available: ' .
                ($trees->isEmpty() ? '(none)' : $trees->keys()->implode(', '))
            );
        }

        $default = Site::getPreference('DEFAULT_GEDCOM');
        $tree    = $trees->get($default) ?? $trees->first();

        if ($tree instanceof Tree) {
            return $tree;
        }

        throw $this->notConfigured(
            $trees->isEmpty()
                ? 'this webtrees installation has no family trees.'
                : 'no tree is configured and the site default "' . $default . '" does not exist.'
        );
    }

    /**
     * The *name* of the tree the portal is configured for, whoever is asking.
     *
     * `tree()` above cannot answer this, and the difference is what broke the
     * link out of the portal for signed-out readers. It resolves the tree
     * through `TreeService::all()`, which is **filtered by the current user**:
     * on a tree with `REQUIRE_AUTHENTICATION` the collection is empty for a
     * visitor, `get()` returns null, and `tree()` reports the tree as missing.
     * That is the right answer for an API request — every one of those is
     * authenticated, and a caller with no access to the tree has no business
     * getting data out of it. It is the wrong answer for `IndividualLink`,
     * which runs *precisely* when nobody is signed in, and whose whole job is
     * to send that person to the sign-in page.
     *
     * So this asks the configuration question and only that. A name is not
     * access: it goes into a URL that webtrees then enforces on arrival, which
     * it would do just the same for an address typed by hand.
     *
     * No `first()` fallback, unlike `tree()` — picking "some tree" out of a
     * list needs the list, and the list is the thing that cannot be trusted
     * here. An installation with neither a configured tree nor a site default
     * gets an empty string, and the caller decides what to do about it.
     */
    public function configuredTreeName(): string
    {
        $configured = $this->module->getPreference(PortalApiModule::SETTING_TREE, '');

        if ($configured !== '') {
            return $configured;
        }

        return Site::getPreference('DEFAULT_GEDCOM');
    }

    /**
     * Refuse, telling the member nothing and the administrator everything.
     *
     * The member-facing message stays generic — it is shown to whoever is
     * signed in, and the shape of the installation is not their business. The
     * real reason goes to the server's error log, which is where an
     * administrator will look when the portal answers 503 and the message does
     * not say why.
     */
    private function notConfigured(string $reason): ApiException
    {
        error_log('portal_api: cannot serve any tree — ' . $reason);

        return ApiException::notConfigured(
            I18N::translate('The member portal is not configured correctly. Please contact an administrator.')
        );
    }

    /**
     * The access level to apply to every record in this request.
     *
     * This is the one number the whole response depends on. It must be the
     * access level of the *session* user: webtrees' own privacy code consults
     * `Auth::user()` in places (for instance, to let you see your own record),
     * so passing some other user's level would produce answers that are
     * neither this user's nor that one's.
     */
    public function accessLevel(Tree $tree): int
    {
        return Auth::accessLevel($tree);
    }

    /**
     * The individual record webtrees links to a user account, if any.
     *
     * The link is webtrees' own per-tree user setting. The portal does not
     * copy it into its own tables — an XREF is not a stable key.
     */
    public function linkedIndividual(Tree $tree, UserInterface $user): Individual|null
    {
        $xref = $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);

        if ($xref === '') {
            return null;
        }

        return Registry::individualFactory()->make($xref, $tree);
    }
}
