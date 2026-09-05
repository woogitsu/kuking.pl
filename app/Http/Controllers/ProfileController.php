<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Publiczny profil: /@basia
 *
 * Zakładki: Wszystko | Przepisy | Ugotowane. Archiwum jest chronologiczne
 * i pogrupowane po miesiącach — celowo jak stary fotoblog, bo to jest
 * emocjonalny powód, żeby wracać po latach.
 */
class ProfileController extends Controller
{
    public function show(Request $request, string $username): View
    {
        $profile = Profile::query()
            ->where('username', $username)
            ->with(['user', 'avatar'])
            ->firstOrFail();

        $owner = $profile->user;

        $this->authorize('viewProfile', $owner);

        $tab = in_array($request->query('zakladka'), ['przepisy', 'ugotowane'], true)
            ? $request->query('zakladka')
            : 'wszystko';

        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->getKey() === $owner->getKey();

        return view('pages.profile.show', [
            'profile' => $profile,
            'owner' => $owner,
            'isOwner' => $isOwner,
            'isFollowing' => $viewer !== null && ! $isOwner && $viewer->isFollowing($owner),
            'hasBlocked' => $viewer !== null && ! $isOwner && $viewer->hasBlocked($owner),
            'tab' => $tab,
            'posts' => $tab === 'wszystko' ? $this->postsFor($owner, $viewer, $isOwner) : null,
            'recipes' => $tab === 'przepisy'
                ? $owner->recipes()->published()->with('heroMedia')->latest('published_at')->paginate(12)->withQueryString()
                : null,
            'cookedEvents' => $tab === 'ugotowane'
                ? $owner->cookedEvents()->with(['recipe.author.profile', 'media'])->paginate(12)->withQueryString()
                : null,
            'stats' => [
                'posts' => $owner->posts()->published()->count(),
                'recipes' => $owner->recipes()->published()->count(),
                'cooked' => $owner->cookedEvents()->count(),
                'followers' => $owner->followers()->count(),
                'following' => $owner->following()->count(),
            ],
        ]);
    }

    /** @return Paginator<int, Post> */
    private function postsFor($owner, $viewer, bool $isOwner)
    {
        return $owner->posts()
            ->published()
            ->when(! $isOwner, function ($query) use ($owner, $viewer): void {
                // Wpisy "tylko dla obserwujących" widzi obserwujący; prywatne
                // widzi wyłącznie autor.
                $visibilities = [Post::VISIBILITY_PUBLIC];

                if ($viewer !== null && $viewer->isFollowing($owner)) {
                    $visibilities[] = Post::VISIBILITY_FOLLOWERS;
                }

                $query->whereIn('visibility', $visibilities);
            })
            ->with(['media', 'author.profile.avatar'])
            ->withCount('comments')
            ->latest('published_at')
            ->paginate(12)
            ->withQueryString();
    }
}
