<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Tree;

/**
 * Builds the `Me` payload, which both GET /me and POST /session return.
 */
class MeAssembler
{
    public function __construct(
        private readonly PortalTreeService $trees,
        private readonly RecordPresenter $presenter,
        private readonly MemberService $members,
        private readonly Inbox $inbox,
        private readonly Connections $connections,
        private readonly Conversations $conversations,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function assemble(UserInterface $user): array
    {
        $tree         = $this->trees->tree();
        $access_level = $this->trees->accessLevel($tree);
        $individual   = $this->trees->linkedIndividual($tree, $user);

        return [
            'user' => [
                'id'        => $user->id(),
                'username'  => $user->userName(),
                'real_name' => $user->realName(),
                'email'     => $user->email(),
                'language'  => $user->getPreference(UserInterface::PREF_LANGUAGE, ''),
                'role'      => $this->role($tree, $user),
            ],
            'profile'    => $this->members->profileForUser($user),
            // own_record: unlocks the member's own contact details, which are
            // not published on anyone else's record, and tells them whether an
            // edit of theirs is still waiting for approval.
            'individual' => $individual instanceof Individual
                ? $this->presenter->individualDetail($individual, $access_level, true)
                : null,
            'tree' => [
                'name'  => $tree->name(),
                'title' => $tree->title(),
            ],
            // Carried here rather than fetched separately, because the
            // navigation bar shows it on every screen and `/me` is already
            // the request every screen makes.
            'unread_messages' => $this->inbox->unreadCount($user),
            // Counted apart from the inbox rather than folded into it: they
            // are two lists on the screen and a member who has one of each
            // should not be told "2 unread" and then find one message.
            'unread_conversations' => $this->conversations->unreadCount($user),
            // The other thing the navigation bar has to be able to show
            // without asking: somebody is waiting for this member to say
            // whether they know them.
            'connection_requests' => $this->connections->pendingCount($user),
            'csrf_token'      => Session::getCsrfToken(),
        ];
    }

    private function role(Tree $tree, UserInterface $user): string
    {
        return match (true) {
            Auth::isAdmin($user)              => 'administrator',
            Auth::isManager($tree, $user)     => 'manager',
            Auth::isModerator($tree, $user)   => 'moderator',
            Auth::isEditor($tree, $user)      => 'editor',
            Auth::isMember($tree, $user)      => 'member',
            default                           => 'visitor',
        };
    }
}
