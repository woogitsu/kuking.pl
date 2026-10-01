<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Search\FrazaWUgotowanych;
use App\Domain\Sharing\KartaZKodemQr;
use App\Domain\Users\DawneNazwyProfilu;
use App\Http\Requests\Profile\ProfilRequest;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Support\Czas;
use App\Support\KanonicznyAdresStrony;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
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
    public function show(Request $request, ProfilRequest $wejscie, string $username): View|RedirectResponse
    {
        // `$request` to żądanie z kontenera, które czyta układ strony
        // (canonical); `ProfilRequest` jest jego kopią, więc atrybutów
        // ustawianych na kopii układ nie zobaczy (`KanonicznyAdresStrony`).
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
            ->first();

        // Dawna nazwa → 301 na aktualny profil (karty z kodem QR, stare
        // linki). Dopiero gdy pod nazwą nie ma żywego profilu; Policy tak
        // samo jak niżej, odmowa to 404 (`DawneNazwyProfilu`).
        if ($profile === null) {
            $przekierowanie = (new DawneNazwyProfilu)->przekierowanie($request, $request->user(), $username, 'profile.show');

            abort_if($przekierowanie === null, 404);

            return $przekierowanie;
        }

        $owner = $profile->user;
        $owner->setRelation('profile', $profile);

        $this->authorize('viewProfile', $owner);

        // Dopiero po autoryzacji: canonical z zapisaną pisownią nazwy (#1311).
        KanonicznyAdresStrony::ustawSciezke($request, route('profile.show', $profile->username, false));

        $tab = $wejscie->zakladka();

        $viewer = $request->user();
        $isOwner = $viewer !== null && $viewer->getKey() === $owner->getKey();

        // Rok z adresu, ale tylko jeśli wygląda na rok. `?rok=cokolwiek`
        // ma dać całe archiwum, a nie pustą stronę ani błąd.
        $rok = $wejscie->rok();

        $zeszytySzyny = $this->zeszytyDoSzyny($owner, $viewer, $isOwner);
        $tagiSzyny = $isOwner ? collect() : $this->tagiDoSzyny($owner, $viewer, $isOwner);
        // Zdjęcia uzupełniają wyłącznie pustą szynę cudzego profilu. Ten sam
        // filtr co archiwum chroni treści prywatne i dla obserwujących.
        $zdjeciaSzyny = ! $isOwner && $zeszytySzyny->isEmpty() && $tagiSzyny->isEmpty()
            ? $owner->posts()->published()
                ->tap(fn ($query) => $this->tylkoWidoczneWpisy($query, $owner, $viewer, $isOwner))
                ->whereHas('media', fn ($query) => $query->where('status', Media::STATUS_READY))
                ->with(['media' => fn ($query) => $query->where('status', Media::STATUS_READY)])
                ->latest('published_at')->latest('id')->limit(3)->get()
            : collect();

        // Issue #1394: na WŁASNEJ zakładce „Ugotowane" lista nie jest
        // filtrowana, więc wykonanie przepisu osoby, z którą właściciel
        // ma blokadę, zostaje (to jego zdjęcie i notatka). Karta ma wtedy
        // nie pokazywać tytułu ani adresu przepisu. Jedno zapytanie na
        // stronę zamiast `hasBlockRelationWith()` na każdą kartę. Obcy
        // widz tego nie potrzebuje: `tylkoZWidocznychPrzepisow()` wycina
        // mu takie wykonania już na liście. Ta sama lista zawęża frazę
        // „Szukaj w moich wykonaniach" (#2070), żeby wynik nie zdradzał
        // tytułu, który karta chowa.
        $autorzyZaBlokada = $tab === 'ugotowane' && $isOwner
            ? $this->osobyZBlokada($owner)
            : [];

        // Fraza działa WYŁĄCZNIE na własnej zakładce (#2070). Na cudzym
        // profilu `?szukaj=` w adresie jest ignorowane: publiczny profil
        // wygląda tak samo jak przed tą zmianą.
        $frazaUgotowanych = FrazaWUgotowanych::zAdresu($tab === 'ugotowane' && $isOwner ? $wejscie->szukaj() : null);

        $cookedEvents = $tab === 'ugotowane'
            ? $this->cookedEventsDlaProfilu($owner, $viewer, $isOwner, $frazaUgotowanych, $autorzyZaBlokada)
            : null;

        $stats = [
            'posts' => $owner->posts()->published()
                ->tap(fn ($query) => $this->tylkoWidoczneWpisy($query, $owner, $viewer, $isOwner))->count(),
            'recipes' => $owner->recipes()->published()
                ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))->count(),
            'cooked' => $owner->cookedEvents()
                ->tap(fn ($query) => $this->tylkoZWidocznychPrzepisow($query, $viewer, $isOwner))->count(),
            'followers' => $this->liczbaPolaczen($owner, 'followers', $viewer),
            'following' => $this->liczbaPolaczen($owner, 'following', $viewer),
        ];

        $profilDoIndeksu = $owner->jestDostepnyJakoAutor()
            && ($stats['posts'] > 0 || $stats['recipes'] > 0 || $stats['cooked'] > 0);

        return view('pages.profile.show', [
            'profile' => $profile,
            'owner' => $owner,
            'isOwner' => $isOwner,
            'isFollowing' => $viewer !== null && ! $isOwner && $viewer->isFollowing($owner),
            'hasBlocked' => $viewer !== null && ! $isOwner && $viewer->hasBlocked($owner),
            'tab' => $tab,
            // Liczba wpisów jest już policzona w `$stats` — ta sama reguła
            // widoczności, ten sam zakres. Paginator dostaje ją gotową zamiast
            // liczyć drugi raz (P3 W4). Z filtrem roku zakres jest węższy, więc
            // tam paginator liczy sam.
            'posts' => $tab === 'wszystko'
                ? $this->postsFor($owner, $viewer, $isOwner, $rok, $rok === null ? $stats['posts'] : null)
                : null,
            // Nawigacja po latach w archiwum (issue #34). Lista lat pochodzi
            // z BAZY, nie z zakresu „od pierwszego wpisu do dziś": rok bez
            // ani jednego wpisu byłby linkiem do pustej strony.
            'lata' => $tab === 'wszystko' ? $this->lataZWpisami($owner, $viewer, $isOwner) : collect(),
            'rok' => $rok,
            // PRAWA SZYNA PROFILU (issue #205) — dwie listy, obie policzone
            // TUTAJ, nie w widoku. Filtr widoczności jest regułą domenową
            // i musi stać w jednym miejscu z filtrem list wyżej; przeniesiony
            // do Blade byłby drugą implementacją tej samej granicy.
            'zeszytySzyny' => $zeszytySzyny,
            'tagiSzyny' => $tagiSzyny,
            'zdjeciaSzyny' => $zdjeciaSzyny,
            'recipes' => $tab === 'przepisy'
                ? $owner->recipes()
                    ->published()
                    ->tap(fn ($query) => $this->tylkoWidoczne($query, $owner, $viewer, $isOwner))
                    ->with(Recipe::RELACJE_KARTY)
                    ->latest('published_at')
                    ->latest('id')
                    ->paginate(12)
                    ->withQueryString()
                : null,
            'cookedEvents' => $cookedEvents,
            'przepisyWidoczneNaKartach' => $this->przepisyWidoczneNaKartach($cookedEvents, $viewer, $isOwner),
            'autorzyZaBlokada' => $autorzyZaBlokada,
            'frazaUgotowanych' => $frazaUgotowanych,
            'stats' => $stats,
            // Jedna reguła dla `noindex` i JSON-LD profilu (#2235, #2236):
            // autor dostępny (`jestDostepnyJakoAutor()` — ta sama bramka co
            // `UserPolicy::viewProfile()` i mapa strony) i co najmniej jedna
            // treść widoczna dla tego widza — wpis, przepis ALBO wykonanie
            // z zakładki „Ugotowane". Wszystkie trzy liczniki liczą tym samym
            // zakresem co listy, więc prywatne, ukryte i zablokowane nie
            // odblokują indeksowania.
            //
            // Konto `erased` z zachowanymi treściami JEST indeksowane
            // (decyzja właściciela z 30.09, D-333) — NIE `jestWidocznyJakoOsoba()`.
            // Jego profil to adres, pod który prowadzą podpisy „Użytkownik
            // usunięty" pod treściami, które D-018/D-022 obiecały zostawić.
            'profilDoIndeksu' => $profilDoIndeksu,
            'kartaQrDostepna' => app(KartaZKodemQr::class)->profilDostepny($profile),
            // `Person.image` (#2231): ta sama bramka co `og:image`
            // (`isReady()`) i dodatkowo pytanie, czy zdjęcie otworzy się
            // GOŚCIOWI — dane strukturalne czyta robot bez konta.
            // Liczone tylko wtedy, gdy JSON-LD w ogóle powstaje.
            'obrazOsoby' => $profilDoIndeksu ? $this->obrazOsoby($profile) : null,
        ]);
    }

    /**
     * Adres awatara do `Person.image` w JSON-LD profilu albo `null` (#2231).
     *
     * Ten sam warunek co `og:image` w `components/layout.blade.php` —
     * `isReady()`, bo `Media::url()` dla zdjęcia bez wariantów podstawia
     * znak serwisu — i do tego `DostepDoZdjecia::moze(null, …)`: adres
     * w danych strukturalnych czyta robot bez konta, więc ma się otworzyć
     * gościowi. Adres bezwzględny, jak w karcie do udostępniania.
     */
    private function obrazOsoby(Profile $profile): ?string
    {
        $avatar = $profile->avatar;

        if ($avatar === null || ! $avatar->isReady() || ! app(DostepDoZdjecia::class)->moze(null, $avatar)) {
            return null;
        }

        $adres = $avatar->url('large');

        return str_starts_with($adres, 'http') ? $adres : url($adres);
    }

    /**
     * Zeszyty pokazywane w prawej szynie profilu (issue #205).
     *
     * WŁASNY PROFIL: wszystkie zeszyty, także prywatne — to są dane tej samej
     * osoby, która patrzy.
     *
     * CUDZY PROFIL: wyłącznie zeszyty PUBLICZNE i wyłącznie wtedy, gdy zeszyt
     * tej osoby w ogóle wolno otworzyć. Warunki są dokładnie te, które ma
     * `CollectionPolicy::view()` — konto dostępne jako autor, brak blokady
     * w którąkolwiek stronę, `visibility = public`. Powtarzamy je tutaj nie
     * dlatego, że Policy nie działa, tylko dlatego, że Policy pilnuje WEJŚCIA
     * NA ADRES zeszytu, a nie zapytania budującego listę — to są dwie różne
     * drogi i naprawienie jednej nie naprawia drugiej (ta sama uwaga co przy
     * `tylkoWidoczne()` wyżej). Bez tego szyna wypisywałaby nazwy zeszytów,
     * które po kliknięciu dają 403.
     *
     * GOŚĆ NIE DOSTAJE NICZEGO, bo `/zeszyt/{id}` leży za `auth` — lista
     * odnośników prowadzących na ekran logowania jest gorsza niż jej brak.
     *
     * `limit(5)` i `->get()`: koszt tej szyny nie rośnie z liczbą zeszytów.
     *
     * @return Collection<int, \App\Models\Collection>
     */
    private function zeszytyDoSzyny($owner, $viewer, bool $isOwner): Collection
    {
        if ($viewer === null) {
            return collect();
        }

        if (! $isOwner && (! $owner->jestDostepnyJakoAutor() || $viewer->hasBlockRelationWith($owner))) {
            return collect();
        }

        return $owner->collections()
            ->when(! $isOwner, fn ($query) => $query->where('visibility', 'public'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->limit(5)
            ->get();
    }

    /**
     * Tagi z wpisów tej osoby — odpowiedź na „co ona właściwie gotuje"
     * (issue #205, prawa szyna cudzego profilu).
     *
     * WIDOCZNOŚĆ LICZY SIĘ TAK SAMO JAK PRZY LIŚCIE WPISÓW. Tag jest
     * etykietą wpisu, więc lista tagów policzona bez filtra zdradzałaby
     * ZAWARTOŚĆ wpisów prywatnych — dokładnie ten sam kształt wycieku co
     * tytuł przepisu w liście wykonań (patrz `tylkoZWidocznychPrzepisow()`).
     * Dlatego podzapytanie przechodzi przez `published()` i przez ten sam
     * `tylkoWidoczneWpisy()`, którym idzie archiwum obok.
     *
     * BEZ SORTOWANIA PO LICZBIE WPISÓW, alfabetycznie. „Najczęstszy tag tej
     * osoby" jest miarą aktywności, a `AGENTS.md` §12 nie chce liczników
     * aktywności wyeksponowanych w interfejsie — a przy okazji sortowanie
     * po liczniku wymagałoby agregatu, którego ta szyna nie potrzebuje.
     *
     * Jedno zapytanie, `limit(6)` — koszt nie rośnie z liczbą wpisów.
     *
     * @return Collection<int, Tag>
     */
    private function tagiDoSzyny($owner, $viewer, bool $isOwner): Collection
    {
        return Tag::query()
            ->aktywne()
            ->whereHas('posts', function ($query) use ($owner, $viewer, $isOwner): void {
                $query->where('posts.author_id', $owner->getKey())->published();

                $this->tylkoWidoczneWpisy($query, $owner, $viewer, $isOwner);
            })
            ->orderBy('name')
            ->limit(6)
            ->get();
    }

    /** @return Paginator<int, Post> */
    private function postsFor($owner, $viewer, bool $isOwner, ?int $rok = null, ?int $lacznie = null)
    {
        return $owner->posts()
            ->published()
            ->tap(fn ($query) => $this->tylkoWidoczneWpisy($query, $owner, $viewer, $isOwner))
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
            // Relacje karty, licznik komentarzy i zapisów — jeden kontrakt
            // `Post::scopeDlaKarty()` (#1037), ten sam na każdej liście wpisów.
            ->dlaKarty($viewer)
            ->latest('published_at')
            ->latest('id')
            ->paginate(12, total: $lacznie)
            ->withQueryString()
            ->tap(fn ($strona) => Post::ukryjNiedostepnePrzepisy($strona->items(), $viewer));
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
            ->tap(fn ($query) => $this->tylkoWidoczneWpisy($query, $owner, $viewer, $isOwner))
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
     * DRUGA GRANICA, OSOBNA OD POWYŻSZEJ: WIDOCZNOŚĆ PRZEPISU (#368).
     * Warunek `whereIn('visibility', …)` niżej pyta o WPIS. Wpis zapowiadający
     * przepis ma `visibility = 'public'` na stałe
     * (`WpisWskazujacyPrzepis::dopisz()`) i nie jest to jego widoczność, tylko
     * brak własnego zawężenia — bramką ma być PRZEPIS. Sam filtr po widoczności
     * wpisu przepuszczał więc zapowiedź przepisu w KAŻDYM stanie, a karta
     * rysuje z relacji `$post->recipe` tytuł, zdjęcie główne i odnośnik,
     * w którym slug niesie ten sam tytuł zapisany inaczej.
     *
     * BRAMKA STOI TUTAJ, A NIE W `postsFor()`, I TO JEST CAŁA RZECZ.
     * Ten filtr jest wspólny dla SZEŚCIU zapytań tego ekranu: archiwum, listy
     * lat, obu liczników, szyny tematów i szyny zdjęć. Każde z nich ma w tym
     * pliku komentarz mówiący, że musi odpowiadać na to samo pytanie co
     * archiwum — bo licznik niezgodny z listą i rok prowadzący do pustej
     * strony są oracle'ami istnienia treści (ta sama klasa błędu co W7-05,
     * opisana przy `liczbaPolaczen()`). Bramka wstawiona w samo `postsFor()`
     * zrobiłaby dokładnie ten rozjazd: tytuł zniknąłby z listy, a licznik nad
     * nią dalej by go liczył.
     *
     * DLACZEGO NIE `tylkoZWidocznychPrzepisow()` Z TEGO SAMEGO PLIKU.
     * Bo ona robi `whereHas('recipe', …)` BEZ gałęzi na `recipe_id IS NULL`.
     * Na wykonaniach jest to poprawne — każde wykonanie ma przepis. Tutaj
     * większość wierszy przepisu NIE MA, więc ten warunek skasowałby z profilu
     * całe zwykłe archiwum. `Post::scopeZWidocznymPrzepisem($widz)` tę gałąź
     * ma, jest tym samym zakresem, którym bramkują się wszystkie strumienie,
     * i sam liczy „własny przepis widza" — dlatego wolno go wywołać po
     * `$isOwner`, nie zamiast.
     *
     * Dla wpisów wołaj `tylkoWidoczneWpisy()` — ta metoda sama zna tylko
     * widoczność względem autora, wspólną dla obu rodzajów treści. Podział
     * na dwie metody (issue #1731) zastąpił `instanceof Post` na modelu
     * zapytania: tamto sprawdzenie działało dopiero w czasie wykonania,
     * a typ `Builder<Post>` zapisuje tę samą granicę jawnie w sygnaturze
     * (od poziomu 5 PHPStan sprawdza ją przy każdym wywołaniu).
     *
     * @param  Builder<Post>|Builder<Recipe>  $query
     */
    private function tylkoWidoczne($query, $owner, $viewer, bool $isOwner): void
    {
        if ($isOwner) {
            // ZAPOWIEDŹ USUNIĘTEGO PRZEPISU (#1395). Właściciel omija bramkę
            // przepisu niżej, bo własne przepisy — także prywatne i ukryte —
            // otwiera (`RecipePolicy::view()`). Wyjątkiem jest przepis
            // usunięty miękko: relacja `recipe` go nie pobiera, karta traciła
            // tytuł i zdjęcie, a odnośnik prowadził do 403 z `PostPolicy::view()`.
            // Wiersz wpisu i jego komentarze ZOSTAJĄ w bazie (moderacja,
            // odzyskanie) — znikają tylko z listy, lat i licznika naraz.
            // Wpis z własną treścią albo zdjęciem nie jest zapowiedzią
            // (`Post::czyJestZapowiedziaPrzepisu()`) i zostaje widoczny.
            //
            // BEZ SKORELOWANYCH PODZAPYTAŃ (audyt wydajności, P3 W4): dwa
            // `EXISTS` w alternatywie `OR` planer nalicza za KAŻDY wpis autora,
            // więc przy tysiącach wpisów szacunek przekraczał `jit_above_cost`.
            // `IN (podzapytanie)` zwraca te same wiersze, a liczy się raz
            // (patrz `Post::scopeZWidocznymPrzepisemAlboWlasnaTresciBezKorelacji()`).
            if ($query->getModel() instanceof Post) {
                $query->where(fn ($w) => $w->whereNull('posts.recipe_id')
                    ->orWhereIn('posts.recipe_id', Recipe::query()->select('recipes.id'))
                    ->orWhereRaw("posts.body ~ '[^[:space:]]'")
                    ->orWhereIn('posts.id', fn ($zeZdjeciem) => $zeZdjeciem
                        ->select('post_media.post_id')->from('post_media')));
            }

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
     * `tylkoWidoczne()` plus bramki, które istnieją tylko dla WPISÓW: włączone
     * rodzaje wpisów i widoczność PRZEPISU, na który wpis wskazuje — opis obu
     * granic przy `tylkoWidoczne()`. Tym idzie każde z sześciu zapytań
     * o wpisy na tym ekranie.
     *
     * @param  Builder<Post>  $query
     */
    private function tylkoWidoczneWpisy($query, $owner, $viewer, bool $isOwner): void
    {
        $query->enabledKinds();

        $this->tylkoWidoczne($query, $owner, $viewer, $isOwner);

        if ($isOwner) {
            return;
        }

        // Bramka PRZEPISU — patrz akapit w opisie `tylkoWidoczne()`. Tylko dla
        // wpisów: zakładka „Przepisy" pyta wprost o `Recipe`, jej granicą jest
        // `visibility` samego przepisu, a `recipes.recipe_id` nie istnieje.
        // Wpis z własną treścią idzie za WŁASNĄ widocznością, jak na
        // swojej stronie (issue #1377); przepis zdejmuje z karty
        // `Post::ukryjNiedostepnePrzepisy()` w `postsFor()`.
        //
        // Wersja BEZ KORELACJI (#599, #2288): profil autora z ~2000 wpisów
        // przekraczał przy skorelowanych `EXISTS` próg JIT (audyt wydajności,
        // P3 W4). Ta sama reguła, ten sam wynik — inny zapis.
        $query->zWidocznymPrzepisemAlboWlasnaTresciBezKorelacji($viewer);

        // BRAMKA AUTORA PRZEPISU, OSOBNA OD BRAMKI WYŻEJ (ustalenie W5-08).
        //
        // `zWidocznymPrzepisem()` schodzi do `Recipe::widoczneDla()`, a ten
        // zakres CELOWO nie zna statusu konta — mówi o tym wprost komentarz
        // przy `User::scopeDostepnyJakoAutor()`. Filtr `whereIn('visibility')`
        // wyżej pyta o WPIS, czyli o autora WPISU, a nie o autora PRZEPISU.
        // To są dwie różne osoby: wpis użytkownika A może wskazywać przepis
        // użytkownika B. Gdy B zostanie zbanowany albo oznaczony do
        // usunięcia, jego przepis znika z własnego profilu i daje 403 pod
        // swoim adresem — ale wpis A dalej rysował kartę z tytułem tego
        // przepisu, jego zdjęciem głównym i odnośnikiem, w którym slug
        // niesie ten sam tytuł. Obie bramki wyżej przepuszczały ten wiersz,
        // bo obie pytały o kogo innego.
        //
        // Gałąź na `recipe_id IS NULL` jest obowiązkowa: większość wierszy
        // archiwum profilu NIE MA przepisu i samo `whereHas('recipe.author')`
        // skasowałoby całe zwykłe archiwum. Idiom jest już w repozytorium —
        // `App\Domain\Tags\PodpowiedziTagow` liczy tak samo.
        //
        // Zapisane przez `IN`, nie przez `whereHas` (P3 W4): `recipes` bez
        // usuniętych miękko (zakres modelu) i z autorem, któremu wolno
        // pokazywać treść — to samo, co `whereHas('recipe.author', …)`.
        $query->where(fn ($w) => $w->whereNull('posts.recipe_id')
            ->orWhereRaw("posts.body ~ '\\S'")
            ->orWhereIn('posts.id', fn ($zeZdjeciem) => $zeZdjeciem
                ->select('post_media.post_id')->from('post_media'))
            ->orWhereIn('posts.recipe_id', Recipe::query()
                ->select('recipes.id')
                ->whereIn('recipes.author_id', User::query()->select('users.id')->dostepnyJakoAutor())));
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
     * @param  Builder<CookedEvent>  $query
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
     * Identyfikatory osób związanych z `$user` blokadą w którąkolwiek stronę.
     *
     * @return list<string>
     */
    private function osobyZBlokada(User $user): array
    {
        return Block::query()
            ->where('blocker_id', $user->getKey())
            ->orWhere('blocked_id', $user->getKey())
            ->get(['blocker_id', 'blocked_id'])
            ->flatMap(fn (Block $blokada) => [$blokada->blocker_id, $blokada->blocked_id])
            ->reject(fn ($id) => $id === $user->getKey())
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Wykonania kucharza w zakładce profilu (issue #735, #736).
     *
     * 1. Jawny porządek `cooked_at DESC, id DESC` gwarantuje stabilną paginację
     *    bez gubienia i dublowania wierszy przy remisach czasu (#735).
     * 2. Związanie znanego $owner z każdym wierszem wykonania eliminuje
     *    powtarzane zapytania o kucharza i jego profil/awatar na każdej karcie (#736).
     * 3. `$fraza` zawęża listę właściciela do wykonań przepisu o danym
     *    tytule (#2070) — tylko zawęża, nic nie dokłada (`FrazaWUgotowanych`).
     *
     * @param  list<string>  $autorzyZaBlokada
     */
    private function cookedEventsDlaProfilu(User $owner, ?User $viewer, bool $isOwner, FrazaWUgotowanych $fraza, array $autorzyZaBlokada): LengthAwarePaginator
    {
        // OSOBA, KTÓRA GOTOWAŁA, JEST TU TREŚCIĄ GŁÓWNĄ — i to ona była
        // źródłem wachlarza zapytań. Karta wykonania
        // (`components/cooked-card.blade.php`) czyta `$event->user` (awatar,
        // nazwa) i `$event->user->profile->username`, a doładowywany był
        // tylko autor przepisu. Zmierzone przed naprawą (`scripts/pomiar-n1.php`,
        // 10 000 wpisów, po `ANALYZE`): 52 zapytania na dwunastu kartach,
        // z czego 22 to para `profiles` + `users` powtórzona na każdą kartę.
        //
        // Na zakładce profilu gotował ZAWSZE właściciel profilu, więc nie ma
        // po co dociągać `user.profile.avatar` osobno dla każdej karty:
        // wystarczy raz doczytać profil właściciela i podstawić tę samą
        // relację w każde wykonanie. Koszt jest stały, niezależny od liczby
        // kart — tego pilnuje `ProfilUgotowaneBezWachlarzaZapytanTest`.
        $owner->loadMissing('profile.avatar');

        $paginator = $owner->cookedEvents()
            ->tap(fn ($query) => $this->tylkoZWidocznychPrzepisow($query, $viewer, $isOwner))
            // Fraza tylko ZAWĘŻA tę samą listę — porządek, paginacja
            // i `withQueryString()` (niesie `szukaj` do „Pokaż więcej")
            // zostają te same (#2070).
            ->tap(fn ($query) => $fraza->zawez($query, $autorzyZaBlokada))
            ->latest('cooked_at')
            ->latest('id')
            ->with(['recipe.author.profile', 'media'])
            ->paginate(12)
            ->withQueryString();

        $paginator->getCollection()->each(function (CookedEvent $event) use ($owner): void {
            $event->setRelation('user', $owner);
        });

        return $paginator;
    }

    /**
     * Które przepisy z kart „Ugotowane” patrzący może otworzyć (issue #766).
     *
     * Karta wykonania pokazuje odnośnik do przepisu tylko wtedy, gdy
     * `RecipePolicy::view` go wpuści — inaczej kucharz na własnym profilu
     * klikał w przepis, który autor zrobił prywatnym albo moderacja ukryła,
     * i dostawał 403. Pytanie polityki na każdej karcie osobno to jednak
     * zapytanie o blokadę na kartę, a tego pilnuje
     * `ProfilUgotowaneBezWachlarzaZapytanTest`.
     *
     * Stąd jedno zapytanie na stronę, tym samym zakresem co
     * `tylkoZWidocznychPrzepisow()`:
     *  - `null` — cudzy profil: lista jest już przefiltrowana tym zakresem,
     *    więc każdy przepis na niej jest widoczny;
     *  - lista id — własny profil (lista bez filtra): przepis spoza niej
     *    karta sprawdza polityką sama, bo polityka bywa szersza od zakresu
     *    (moderator). Tych kart jest mało i tylko one kosztują zapytanie.
     *
     * @return list<string>|null
     */
    private function przepisyWidoczneNaKartach(?LengthAwarePaginator $cookedEvents, ?User $viewer, bool $isOwner): ?array
    {
        if ($cookedEvents === null || ! $isOwner) {
            return null;
        }

        $idPrzepisow = collect($cookedEvents->items())->pluck('recipe_id')->filter()->unique()->values();

        if ($idPrzepisow->isEmpty()) {
            return [];
        }

        return Recipe::query()
            ->whereKey($idPrzepisow->all())
            ->widoczneDla($viewer)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
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
