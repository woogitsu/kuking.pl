<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    /** Lista osób, które obserwują dany profil: /@{username}/obserwujacy */
    public function followers(Request $request, string $username): Response
    {
        return $this->connections($request, $username, 'followers', 'Obserwujący');
    }

    /** Lista osób, które dany profil obserwuje: /@{username}/obserwowani */
    public function following(Request $request, string $username): Response
    {
        return $this->connections($request, $username, 'following', 'Obserwowani');
    }

    /**
     * Wspólna logika obu list relacji.
     *
     * Blokada w KTÓRĄKOLWIEK stronę chowa osobę z listy — stąd
     * `hasBlockRelationWith()`, a nie sam `blocking()`. Blokada między
     * obserwującym a obserwowanym zwykle i tak kasuje follow (patrz
     * BlockUser), ale to nie zwalnia z filtrowania: na liście może się
     * znaleźć ktoś, kogo zablokował akurat OSOBA OGLĄDAJĄCA listę, a nie
     * właściciel profilu.
     */
    private function connections(Request $request, string $username, string $relation, string $title): Response
    {
        $target = $this->findUser($username);
        $this->authorize('viewProfile', $target);

        $viewer = $request->user();

        /** @var LengthAwarePaginator $paginator */
        $paginator = $target->{$relation}()
            ->with('profile.avatar')
            ->orderByPivot('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        if ($viewer !== null) {
            $paginator->setCollection(
                $paginator->getCollection()->reject(
                    fn (User $person): bool => $viewer->hasBlockRelationWith($person),
                )->values(),
            );
        }

        $profile = $target->profile;

        return response()->view('pages.profile.connections', [
            'title' => $title,
            'relation' => $relation,
            'profile' => $profile,
            'people' => $paginator,
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function findUser(string $username): User
    {
        return Profile::where('username', $username)->firstOrFail()->user;
    }
}
