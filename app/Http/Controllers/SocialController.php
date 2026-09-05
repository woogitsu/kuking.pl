<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SocialController extends Controller
{
    public function __construct(
        private readonly FollowUser $followUser,
        private readonly UnfollowUser $unfollowUser,
        private readonly BlockUser $blockUser,
        private readonly UnblockUser $unblockUser,
    ) {}

    public function follow(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);
        $this->authorize('follow', $target);

        try {
            $followed = $this->followUser->handle($request->user(), $target);
        } catch (RuntimeException $e) {
            return back()->withErrors(['follow' => $e->getMessage()]);
        }

        return back()->with('status', $followed
            ? 'Obserwujesz '.$target->displayName().'. Nowe wpisy pojawią się na Twojej stronie głównej.'
            : 'Już obserwujesz tę osobę.');
    }

    public function unfollow(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        $this->unfollowUser->handle($request->user(), $target);

        return back()->with('status', 'Nie obserwujesz już '.$target->displayName().'.');
    }

    public function block(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        try {
            $this->blockUser->handle($request->user(), $target, $request->ip());
        } catch (RuntimeException $e) {
            return back()->withErrors(['block' => $e->getMessage()]);
        }

        return redirect()->route('home')->with('status',
            $target->displayName().' została zablokowana. Nie zobaczycie już wzajemnie swoich treści.',
        );
    }

    public function unblock(Request $request, string $username): RedirectResponse
    {
        $target = $this->findUser($username);

        $this->unblockUser->handle($request->user(), $target, $request->ip());

        return back()->with('status', 'Blokada zdjęta.');
    }

    private function findUser(string $username): User
    {
        return Profile::where('username', $username)->firstOrFail()->user;
    }
}
