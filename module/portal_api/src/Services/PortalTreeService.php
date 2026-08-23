<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\Http\ApiException;
use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
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
            //
            // "Missing" has two very different causes, and saying the wrong
            // one sends an administrator to the wrong screen. `$trees` is
            // webtrees' list *as this user sees it*: on a tree with
            // `REQUIRE_AUTHENTICATION`, somebody whose role on that tree is
            // still `visitor` — webtrees' default for an account made by hand
            // in the control panel — gets an empty list and the tree looks
            // deleted. It is not; they simply cannot see it, and the fix is a
            // role, not a setting in this module.
            throw $this->notConfigured(
                $this->treeExists($configured)
                    ? 'the configured tree "' . $configured . '" exists but is not visible to '
                        . (Auth::check()
                            ? 'the signed-in user "' . Auth::user()->userName() . '" — check their role on that tree'
                            : 'a visitor — the tree requires authentication')
                        . '.'
                    : 'the configured tree "' . $configured . '" does not exist. Available: ' . $this->treeNames()
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
     * Is the portal configured at all? Asked without asking who wants to know.
     *
     * `tree()` cannot answer this, and the reason is the one written out under
     * `configuredTreeName()` below: it resolves through `TreeService::all()`,
     * which is filtered by the signed-in user. That filtering is exactly right
     * for an API request — every one of those is authenticated, and a caller
     * with no access to the tree has no business getting data out of it — and
     * exactly wrong for `GET /health`, which is unauthenticated **on purpose**
     * (a health check that needs credentials is a health check nobody runs).
     *
     * The two collided in the configuration this portal is designed for. On a
     * tree with `REQUIRE_AUTHENTICATION`, a visitor's tree list is empty, so
     * health answered `not_configured` for an installation with nothing at all
     * wrong with it — and since the deployment uses that endpoint, a correct
     * portal looked broken to the one check meant to prove it was not.
     *
     * So this asks the configuration question and only that, against the
     * `gedcom` table rather than a filtered collection. It still proves the
     * whole chain a health check exists to prove — Worker, proxy secret, PHP,
     * webtrees' bootstrap, this module's `boot()`, the database — because a
     * query is what answers it.
     *
     * The rules are `tree()`'s, kept in the same order: the configured name,
     * then the site default, then whichever tree exists.
     *
     * @throws ApiException when nothing is configured and nothing can be guessed.
     */
    public function checkConfiguration(): void
    {
        $configured = $this->module->getPreference(PortalApiModule::SETTING_TREE, '');

        if ($configured !== '') {
            if ($this->treeExists($configured)) {
                return;
            }

            throw $this->notConfigured(
                'the configured tree "' . $configured . '" does not exist. Available: ' . $this->treeNames()
            );
        }

        $default = Site::getPreference('DEFAULT_GEDCOM');

        if ($default !== '' && $this->treeExists($default)) {
            return;
        }

        // `tree()`'s last resort: no setting, no usable default, so whichever
        // tree comes first. Only "none at all" is a failure here.
        if (DB::table('gedcom')->where('gedcom_id', '>', 0)->exists()) {
            return;
        }

        throw $this->notConfigured('this webtrees installation has no family trees.');
    }

    private function treeExists(string $name): bool
    {
        return DB::table('gedcom')
            ->where('gedcom_id', '>', 0)
            ->where('gedcom_name', '=', $name)
            ->exists();
    }

    /** For the administrator's log line, never for a response. */
    private function treeNames(): string
    {
        $names = DB::table('gedcom')
            ->where('gedcom_id', '>', 0)
            ->pluck('gedcom_name')
            ->implode(', ');

        return $names === '' ? '(none)' : $names;
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
     * The portal's tree itself, resolved without asking who wants it.
     *
     * `configuredTreeName()` above answers the same question and hands back a
     * name. This hands back the tree, for the callers that need the record
     * rather than a URL — and it is the third time this class has had to say
     * the same thing, so it is worth saying plainly:
     *
     * **`tree()` cannot serve a visitor.** It resolves through
     * `TreeService::all()`, which is filtered by whoever is asking: on a tree
     * with `REQUIRE_AUTHENTICATION` — the setting a private family portal is
     * built on — a signed-out reader's list is empty, so the configured tree
     * looks deleted and `tree()` refuses with `not_configured`.
     *
     * That is the right answer for every authenticated endpoint. It is the
     * wrong answer for the two that run *precisely* when nobody is signed in:
     * `POST /invitation/preview` and `POST /invitation/accept`. The whole
     * point of an invitation is that the person holding it has no account
     * yet, so measuring their access to the tree before reading the token
     * refuses everybody, always. The invitee sees "this invitation is no
     * longer valid" and the invitation is perfectly good.
     *
     * Nothing is granted by resolving it this way. A `Tree` is an id, a name
     * and a title; every record read through it is still privacy filtered at
     * `Auth::accessLevel()`, and the only two callers read no records at all.
     * What opens an invitation is the token, which is a credential, and it is
     * checked immediately afterwards.
     *
     * @throws ApiException when no tree is configured and none can be guessed.
     */
    public function configuredTree(): Tree
    {
        $name  = $this->configuredTreeName();
        $query = DB::table('gedcom')->where('gedcom_id', '>', 0);

        if ($name !== '') {
            $query->where('gedcom_name', '=', $name);
        } else {
            // `tree()`'s last resort, in its own order: no setting and no site
            // default, so whichever tree exists.
            $query->orderBy('gedcom_id');
        }

        $row = $query->first();

        if ($row === null) {
            throw $this->notConfigured(
                $name === ''
                    ? 'this webtrees installation has no family trees.'
                    : 'the configured tree "' . $name . '" does not exist. Available: ' . $this->treeNames()
            );
        }

        // The title is a tree setting rather than a column, and a tree that
        // has never been given one still has to be usable.
        $title = (string) DB::table('gedcom_setting')
            ->where('gedcom_id', '=', $row->gedcom_id)
            ->where('setting_name', '=', 'title')
            ->value('setting_value');

        return new Tree((int) $row->gedcom_id, $row->gedcom_name, $title === '' ? $row->gedcom_name : $title);
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
