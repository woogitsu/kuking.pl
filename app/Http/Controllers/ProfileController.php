<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Profile;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
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
        // Adres profilu bez rozróżniania wielkości liter (audyt A25).
        //
        // Logowanie szukało nazwy bez rozróżniania, a profil publiczny —
        // z rozróżnianiem, więc ta sama nazwa znaczyła tu i tam co innego.
        // Po zamknięciu rejestracji na „Basia" obok „basia" nie ma powodu,
        // żeby /@Basia oddawało 404: to jest jedno konto, a link mógł zostać
        // przepisany ręcznie albo poprawiony przez autokorektę telefonu.
        //
        // Zapytanie trafia w unikalny indeks funkcyjny `lower(username)`,
        // więc nie jest to skan tabeli.
        $profile = Profile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
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
                ? $owner->recipes()
                    ->published()
                    ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))
                    ->with('heroMedia')
                    ->latest('published_at')
                    ->paginate(12)
                    ->withQueryString()
                : null,
            'cookedEvents' => $tab === 'ugotowane'
                ? $owner->cookedEvents()
                    ->tap(fn ($query) => $this->tylkoZWidocznychPrzepisow($query, $owner, $viewer, $isOwner))
                    ->with(['recipe.author.profile', 'media'])
                    ->paginate(12)
                    ->withQueryString()
                : null,
            'stats' => [
                'posts' => $owner->posts()->published()
                    ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))->count(),
                'recipes' => $owner->recipes()->published()
                    ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))->count(),
                'cooked' => $owner->cookedEvents()
                    ->tap(fn ($query) => $this->tylkoZWidocznychPrzepisow($query, $owner, $viewer, $isOwner))->count(),
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
            ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))
            ->with(['media', 'author.profile.avatar'])
            ->withCount(['comments' => fn ($q) => $q->widoczneDla($viewer)])
            ->latest('published_at')
            ->paginate(12)
            ->withQueryString();
    }

    /**
     * Filtr widoczności wspólny dla wpisów i przepisów (issue #41).
     *
     * Wcześniej filtrowane były WYŁĄCZNIE wpisy. Zakładka „Przepisy" i liczniki
     * pokazywały wszystko, co opublikowane — więc przepis oznaczony jako
     * `private` albo `followers` był widoczny dla każdego, kto wszedł na profil.
     *
     * Policy tego nie łapała, bo Policy pilnuje WEJŚCIA NA ADRES treści, a nie
     * zapytania budującego listę. To są dwie różne drogi i naprawienie jednej
     * nie naprawia drugiej — dlatego macierz z issue #41 testuje je osobno.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function tylkoWidoczne($query, $owner, $viewer, bool $isOwner): void
    {
        if ($isOwner) {
            return;
        }

        // „Tylko dla obserwujących" widzi obserwujący; prywatne — wyłącznie autor.
        $widocznosci = ['public'];

        if ($viewer !== null && $viewer->isFollowing($owner)) {
            $widocznosci[] = 'followers';
        }

        $query->whereIn('visibility', $widocznosci);
    }

    /**
     * Wykonania („Ugotowałem") nie mają własnej widoczności — idą za przepisem.
     *
     * Samo wykonanie nie jest tajne, ale ujawnia TYTUŁ przepisu. Lista wykonań
     * bez tego filtra zdradzała tytuły przepisów prywatnych, mimo że sam przepis
     * był nie do otwarcia. Wyciek przez tytuł to nadal wyciek.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function tylkoZWidocznychPrzepisow($query, $owner, $viewer, bool $isOwner): void
    {
        if ($isOwner) {
            return;
        }

        $query->whereHas('recipe', function ($sub) use ($owner, $viewer): void {
            $this->tylkoWidoczne($sub, $owner, $viewer, false);
        });
    }
}
