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
