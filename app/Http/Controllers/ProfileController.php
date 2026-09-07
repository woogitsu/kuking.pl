<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Profile;
use App\Support\Czas;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        // Rok z adresu, ale tylko jeśli wygląda na rok. `?rok=cokolwiek`
        // ma dać całe archiwum, a nie pustą stronę ani błąd.
        $rok = (int) $request->query('rok', 0);
        $rok = $rok >= 1990 && $rok <= 2999 ? $rok : null;

        return view('pages.profile.show', [
            'profile' => $profile,
            'owner' => $owner,
            'isOwner' => $isOwner,
            'isFollowing' => $viewer !== null && ! $isOwner && $viewer->isFollowing($owner),
            'hasBlocked' => $viewer !== null && ! $isOwner && $viewer->hasBlocked($owner),
            'tab' => $tab,
            'posts' => $tab === 'wszystko' ? $this->postsFor($owner, $viewer, $isOwner, $rok) : null,
            // Nawigacja po latach w archiwum (issue #34). Lista lat pochodzi
            // z BAZY, nie z zakresu „od pierwszego wpisu do dziś": rok bez
            // ani jednego wpisu byłby linkiem do pustej strony.
            'lata' => $tab === 'wszystko' ? $this->lataZWpisami($owner, $viewer, $isOwner) : collect(),
            'rok' => $rok,
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
                    ->tap(fn ($query) => $this->tylkoZWidocznychPrzepisow($query, $viewer, $isOwner))
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
                    ->tap(fn ($query) => $this->tylkoZWidocznychPrzepisow($query, $viewer, $isOwner))->count(),
                'followers' => $this->liczbaPolaczen($owner, 'followers', $viewer),
                'following' => $this->liczbaPolaczen($owner, 'following', $viewer),
            ],
        ]);
    }

    /** @return Paginator<int, Post> */
    private function postsFor($owner, $viewer, bool $isOwner, ?int $rok = null)
    {
        return $owner->posts()
            ->published()
            ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))
            // `at time zone`, a nie samo `extract(year from …)`. `published_at`
            // jest kolumną `timestamptz`, więc gołe `extract()` czyta rok w UTC,
            // a człowiek widzi przy tym wpisie datę lokalną (`App\Support\Czas`).
            // Wpis z sylwestrowej nocy — 1 stycznia 00:30 czasu polskiego, czyli
            // 31 grudnia 23:30 UTC — lądował w archiwum pod poprzednim rokiem,
            // z kartą pokazującą „1 stycznia" pod nagłówkiem roku wcześniejszego.
            // Ten sam błąd co we `Wspomnieniach`, tylko o rok zamiast o dobę.
            ->when($rok !== null, fn ($query) => $query->whereRaw(
                'extract(year from published_at at time zone ?) = ?',
                [Czas::strefa(), $rok],
            ))
            ->with(['media', 'author.profile.avatar'])
            ->withCount(['comments' => fn ($q) => $q->widoczneDla($viewer)])
            ->latest('published_at')
            ->paginate(12)
            ->withQueryString();
    }

    /**
     * Lata, w których ta osoba coś opublikowała — widziane oczami OGLĄDAJĄCEGO.
     *
     * Ten sam filtr widoczności co lista wpisów (issue #41), bo inaczej rok,
     * w którym są wyłącznie wpisy prywatne, byłby dla obcej osoby linkiem
     * prowadzącym donikąd — i zdradzałby, że coś tam jednak jest.
     *
     * @return Collection<int, int>
     */
    private function lataZWpisami($owner, $viewer, bool $isOwner)
    {
        return $owner->posts()
            ->published()
            ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))
            // Ta sama strefa co filtr w `postsFor()` — inaczej lista lat i lista
            // wpisów odpowiadałyby na to samo pytanie inaczej, i rok kliknięty
            // z listy potrafiłby nie mieć ani jednego wpisu.
            ->selectRaw(
                'distinct extract(year from published_at at time zone ?)::int as rok',
                [Czas::strefa()],
            )
            ->orderByRaw('rok desc')
            ->pluck('rok')
            ->map(fn ($rok): int => (int) $rok);
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
     * I DOKŁADNIE TO ZDANIE STAŁO TU, GDY FILTR BYŁ NIEPEŁNY.
     * Reguła była uznana, a sprawdzenie obejmowało WYŁĄCZNIE kolumnę
     * `visibility`. Zmierzone: obcy na cudzym profilu widział tytuł przepisu
     * ukrytego przez moderację ORAZ tytuł przepisu autora zbanowanego, mimo
     * że adres wykonania i adres przepisu dawały mu 403. Trzeci przypadek był
     * subtelniejszy: filtr dostawał jako „właściciela" KUCHARZA, a
     * `visibility: followers` dotyczy relacji z AUTOREM PRZEPISU — kto
     * obserwował kucharza, ale nie autora, widział tytuł przepisu „tylko dla
     * obserwujących" tego autora.
     *
     * DLATEGO RĘCZNY FILTR ZNIKA, A NIE ZOSTAJE ROZBUDOWANY.
     * `Recipe::scopeWidoczneDla()` odpowiada na dokładnie to pytanie i ma
     * własną macierz testów: widoczność liczoną względem autora przepisu,
     * blokady w obie strony, status przepisu. `tylkoWidoczne()` w tym
     * kontrolerze było DRUGĄ implementacją tej samej reguły — czyli tym, co
     * w tym repozytorium pęka najczęściej. Zostaje jeden zakres plus granica
     * polityki `dostepnyJakoAutor()`, której ten zakres celowo nie zawiera
     * (patrz komentarz przy `User::scopeDostepnyJakoAutor`: to są dwie różne
     * granice i obie są potrzebne).
     *
     * `$owner` nie jest już potrzebny i dlatego go tu nie ma — parametr,
     * który wygląda na używany, a nie jest, to zaproszenie do pomyłki.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function tylkoZWidocznychPrzepisow($query, $viewer, bool $isOwner): void
    {
        if ($isOwner) {
            return;
        }

        $query->whereHas('recipe', function ($sub) use ($viewer): void {
            $sub->widoczneDla($viewer)
                ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor());
        });
    }

    /**
     * Licznik obserwujących/obserwowanych — POLICZONY DOKŁADNIE TAK, JAK
     * WYGLĄDA LISTA POD TYM LICZNIKIEM (`SocialController::connections()`).
     *
     * Licznik na profilu jest oracle'em istnienia (ta sama klasa co zamknięte
     * W7-05): jeśli mówi „12", a lista pod spodem pokazuje 10, to te dwa
     * brakujące wiersze zdradzają widzowi, że coś tam jednak jest, mimo że
     * nie wolno mu tego zobaczyć. Dwa warunki muszą się więc zgadzać z listą:
     *
     *  - `widocznyJakoOsoba()` — konto zamknięte (zbanowane, kasujące się
     *    albo już wymazane, D-022) nie ma prawa stać ani na liście, ani
     *    w liczniku nad nią (ten sam błąd, zmierzony
     *    `ProfilListyRelacjiUkrywajaZbanowaneKontaTest`). MUSI to być ta sama
     *    granica co w `SocialController::connections()`, bo licznik i lista
     *    odpowiadają na to samo pytanie;
     *  - blokada MIĘDZY WIDZEM A OSOBĄ NA LIŚCIE (nie: między widzem
     *    a właścicielem profilu — to osobna reguła, `UserPolicy::viewProfile`).
     *    `SocialController::connections()` filtruje to samo w zapytaniu
     *    budującym listę; bez tego samego warunku tutaj widz zablokowałby
     *    kogoś i zobaczyłby licznik, który się nie zgadza z tym, co klika.
     *
     * @param  'followers'|'following'  $relation
     */
    private function liczbaPolaczen($owner, string $relation, $viewer): int
    {
        return $owner->{$relation}()
            ->widocznyJakoOsoba()
            ->when($viewer !== null, function ($query) use ($viewer): void {
                $widzId = $viewer->getKey();

                $query->whereNotExists(function ($sub) use ($widzId): void {
                    $sub->selectRaw('1')
                        ->from('blocks')
                        ->where(function ($w) use ($widzId): void {
                            $w->where('blocks.blocker_id', $widzId)
                                ->whereColumn('blocks.blocked_id', 'users.id');
                        })
                        ->orWhere(function ($w) use ($widzId): void {
                            $w->whereColumn('blocks.blocker_id', 'users.id')
                                ->where('blocks.blocked_id', $widzId);
                        });
                });
            })
            ->count();
    }
}
