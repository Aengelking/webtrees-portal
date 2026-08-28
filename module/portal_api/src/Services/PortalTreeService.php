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
        $name = $this->configuredTreeName();

        // Ask webtrees first. Wherever the caller *can* see the tree — a
        // public one, an editor, an administrator — webtrees' own object is
        // the one to use, and building another would be a second answer to a
        // question that already has one.
        $tree = $name === '' ? null : $this->tree_service->all()->get($name);

        if ($tree instanceof Tree) {
            return $tree;
        }

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

        return $this->treeFromRow($row);
    }

    /**
     * Whether this tree may only be read by somebody signed in.
     *
     * Asked through two doors, because webtrees moved it between them.
     * **2.2.6** took `REQUIRE_AUTHENTICATION` out of `gedcom_setting` and made
     * it a column with `Tree::private()` in front of it; reading it as a
     * preference still works there, and raises a deprecation notice while
     * doing so. Same reasoning and the same shape as `treeFromRow()` below:
     * neither version's answer is assumed, and each is asked for by name.
     *
     * It lives here rather than in the three places that want it, because
     * three copies of a version shim is three places to forget when the next
     * release moves it again.
     */
    public function requiresAuthentication(Tree $tree): bool
    {
        if (method_exists($tree, 'private')) {
            return $tree->private();
        }

        return $tree->getPreference('REQUIRE_AUTHENTICATION') === '1';
    }

    /**
     * Build the tree object the way *this* webtrees builds it.
     *
     * `new Tree(...)` is not an option, and the reason is worth writing down
     * because it cost a live portal an evening. In **2.2.6** the constructor
     * went from three arguments to nine, and the title, the media folder and
     * the members-only flag moved out of `gedcom_setting` into columns of
     * `gedcom`. A module that calls the constructor itself is broken by that
     * release with an `ArgumentCountError` — which is exactly what happened,
     * on a host running 2.2.6 while this was written against 2.2.1.
     *
     * So neither version's shape is assumed. Each has a factory of its own
     * and it is asked for by name; the row is shaped the way that version's
     * `TreeService::all()` shapes it, because that is the code these two
     * factories exist to serve.
     *
     * The next such move will break this again, and visibly — a fatal on one
     * endpoint, with the version named in this comment. That is the trade for
     * a portal that a visitor can be invited into at all; see
     * `configuredTree()` above for why there is no third way.
     */
    private function treeFromRow(object $row): Tree
    {
        if (method_exists(Tree::class, 'fromDB')) {
            // 2.2.6 and later. Everything the object needs is in its own row,
            // which is why this is handed the whole of it.
            return Tree::fromDB($row);
        }

        // 2.2.5 and earlier: three fields, and the title is a setting. A tree
        // that has never been given one still has to be usable.
        $title = (string) DB::table('gedcom_setting')
            ->where('gedcom_id', '=', $row->gedcom_id)
            ->where('setting_name', '=', 'title')
            ->value('setting_value');

        $mapper = Tree::rowMapper();

        return $mapper((object) [
            'tree_id'    => (int) $row->gedcom_id,
            'tree_name'  => $row->gedcom_name,
            'tree_title' => $title === '' ? $row->gedcom_name : $title,
        ]);
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
