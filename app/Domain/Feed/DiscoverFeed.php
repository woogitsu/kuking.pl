<?php

declare(strict_types=1);

namespace App\Domain\Feed;

use App\Models\Hide;
use App\Models\Post;
use App\Models\User;
use App\Support\KursorListy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * "Świeżo z Kuking" — to, co widzi ktoś, kto nikogo jeszcze nie obserwuje.
 *
 * To NIE jest ranking popularności. To chronologia z rotacją autorów:
 * najpierw najnowszy wpis każdej osoby, potem drugi każdej i tak dalej
 * (issue #1807). Bez tego jedna aktywna osoba zasłania cały serwis, a nowy
 * użytkownik odnosi wrażenie, że "tu jest tylko ta pani".
 *
 * Cold start bez tego ekranu nie działa: feed obserwowanych nowego
 * użytkownika jest z definicji pusty (docs/product/COLD_START.md).
 *
 * Propozycje osób do obserwowania mieszkają w App\Domain\Feed\DailyBoard
 * („kuKINGi na dziś"), bo tam mają kontekst: podgląd zdjęć i ewentualne
 * jedno zdanie od gospodarza.
 */
final class DiscoverFeed
{
    /** Sortowanie listy = kolumny kursora rotacji, w kolejności ORDER BY. */
    private const KOLUMNY_KURSORA = [
        'rotacja.runda' => KursorListy::LICZBA,
        'posts.published_at' => KursorListy::CZAS,
        'posts.id' => KursorListy::UUID,
    ];

    /**
     * Najdalszy wstecz „stan", jaki przyjmujemy z adresu. Kursor starszy niż
     * doba to zakładka, nie przeglądanie — wtedy lista zaczyna się od
     * początku (kursor też odpada, patrz `paginate()`).
     */
    private const STAN_NAJWYZEJ_SEKUND_WSTECZ = 86_400;

    /**
     * `$zWlasnymi` — tylko dla Startu osoby, która nikogo nie obserwuje
     * (issue #1318, decyzja właściciela z 24.09). Wtedy to jest feed
     * zastępczy JEJ strony głównej, a `FollowingFeed` celowo pokazuje
     * własne wpisy, żeby po publikacji nie było wrażenia, że nic się nie
     * zapisało. Bez tego własny wpis „tylko dla obserwujących" nie
     * pojawiał się na Starcie w ogóle (własny publiczny stał w rotacji
     * zawsze).
     *
     * WARUNEK STOI W PODZAPYTANIU ROTACJI, PRZED NUMERACJĄ (decyzja
     * właściciela z 26.09, D-276): własne wpisy widza podlegają tej samej
     * regule co wpisy każdej osoby — jego najnowszy wpis stoi w pierwszej
     * rundzie, drugi w drugiej. Doklejenie ich na zewnątrz dałoby widzowi
     * więcej miejsc niż innym. Jedno zapytanie, więc bez duplikatów,
     * chronologicznie, a kursor działa jak dotąd.
     *
     * `/discover` i strona dla gości wołają bez tej flagi: tam to jest
     * „Świeżo z Kuking" dla wszystkich, a wpis „tylko dla obserwujących"
     * nie ma prawa wyjść poza autora i obserwujących.
     *
     * @param  string|null  $stan  wartość parametru `stan` z adresu DALSZEJ
     *                             strony (patrz niżej); pierwsza strona podaje `null`
     * @return CursorPaginator<int, Post>
     */
    public function paginate(?User $viewer, ?int $perPage = null, ?string $stan = null, bool $zWlasnymi = false): CursorPaginator
    {
        $perPage ??= (int) config('kuking.feed.page_size');

        $wlasne = $zWlasnymi && $viewer !== null;

        // DALSZA STRONA = kursor rotacji I ważna chwila z `stan`, zawsze razem.
        // Kursor to pozycja w rundach policzonych na tę jedną chwilę — z inną
        // chwilą (zepsuty `stan`, brak `stan`, zakładka starsza niż doba) te
        // same rundy wyglądają inaczej i kursor po cichu gubiłby albo
        // powtarzał wpisy. Wtedy zaczynamy od początku (przegląd #1781, pkt 7).
        $chwilaZAdresu = $this->chwilaZAdresu($stan);
        $kursor = $chwilaZAdresu !== null ? $this->kursorRotacji() : null;
        $chwila = $kursor !== null ? $chwilaZAdresu : CarbonImmutable::now()->startOfSecond();

        // ROTACJA AUTORÓW (issue #1807, reguła doboru z AGENTS.md §8: „równość
        // autorów"). Najpierw najnowszy wpis każdej osoby, potem drugi każdej,
        // potem trzeci — i tak do końca.
        //
        // Wcześniej stało tu `DISTINCT ON (author_id)` (issue #940): jeden wpis
        // na autora w CAŁEJ sekwencji. Seria jednej osoby przestała zasłaniać
        // innych, ale głębokość Odkrywania równała się liczbie aktywnych
        // autorów — przy dziesięciu osobach dziesięć kart i koniec, a starsze
        // wpisy nigdy nie wracały. Rotacja zachowuje gwarancję z #940 (pierwsza
        // runda to dalej po jednym wpisie od osoby) i oddaje całą resztę.
        //
        // `runda` = `row_number()` w oknie autora, od najnowszego. To liczy
        // WPISY TEJ OSOBY, nie cudze reakcje — nikt nie trafia wyżej za to, ile
        // „Ugotowałem" zebrał (strażnik: `FeedNieSortujePoMierzeReakcjiTest`).
        //
        // WSZYSTKIE BRAMKI WIDOCZNOŚCI SĄ W PODZAPYTANIU, PRZED NUMERACJĄ.
        // Wpis odsiany dopiero na zewnątrz zostawiłby dziurę w rundach autora
        // (jego starszy wpis stałby w rundzie trzeciej zamiast drugiej).
        //
        // `published_at <= chwila` — ta sama chwila na każdej stronie jednego
        // przeglądania. Nowy wpis osoby opublikowany w trakcie przesunąłby
        // wszystkie jej starsze wpisy o rundę dalej, a przesunięty wpis
        // wróciłby na następnej stronie drugi raz. Chwila przechodzi między
        // stronami w parametrze `stan` (dokładany do odnośników niżej).
        $rundy = Post::query()
            ->select('posts.id')
            ->selectRaw('row_number() OVER (PARTITION BY posts.author_id ORDER BY posts.published_at DESC, posts.id DESC) AS runda')
            ->when(! $wlasne, fn ($query) => $query->publiclyVisible())
            ->when($wlasne, fn ($query) => $query
                ->enabledKinds()
                ->published()
                ->where(fn ($widocznosc) => $widocznosc
                    ->where('posts.visibility', Post::VISIBILITY_PUBLIC)
                    // Te same dwie widoczności, które `FollowingFeed` bierze
                    // z własnych wpisów — prywatne zostają w archiwum autora.
                    ->orWhere(fn ($moje) => $moje
                        ->where('posts.author_id', $viewer->getKey())
                        ->where('posts.visibility', Post::VISIBILITY_FOLLOWERS))))
            // Pierwsza strona nie ma granicy (widzi wszystko do teraz), dalsze
            // biorą wpisy do pełnej sekundy chwili pierwszej. Eloquent zapisuje
            // `published_at` z dokładnością do sekundy, więc zdublować się może
            // najwyżej wpis dodany w TEJ SAMEJ sekundzie co pierwsza strona.
            ->when($kursor !== null, fn ($query) => $query->where('posts.published_at', '<=', $chwila->format('Y-m-d H:i:sP')))
            // Konto autora musi być w pełni aktywne (audyt A5) — to jest
            // surowszy próg niż w Policy pojedynczego wpisu. Odkrywanie
            // aktywnie POLECA treść nieznajomym, więc zawieszenie (kara
            // czasowa, nie tylko ban) też ma tu wystarczyć do zdjęcia —
            // inaczej strona promowałaby konto będące właśnie pod sankcją.
            ->whereHas('author', fn ($query) => $query->where('status', User::STATUS_ACTIVE))
            // Punkt zaczepienia dla jawnych poleceń widza (AGENTS.md §8):
            // dziś blokady, od #1810 także ukrycia wpisów i osób.
            ->when($viewer !== null, fn ($query) => $this->bezUkrytychPrzez($query, $viewer))
            // WPIS WSKAZUJĄCY PRZEPIS WYCHODZI TYLKO Z WIDOCZNYM PRZEPISEM
            // (issue #368). Widoczność liczy się Z PRZEPISU, nie z kopii na
            // wpisie — patrz `Post::scopeZWidocznymPrzepisem()`. Wpis
            // z własną treścią idzie za własną widocznością (issue #1377).
            //
            // POSTAĆ BEZ KORELACJI (issue #2288, wzorzec z #599). Skorelowane
            // `EXISTS` przepisu i zdjęcia w alternatywie `OR` planer nalicza za
            // KAŻDY kandydujący wpis — przy kilku tysiącach szacunek przekraczał
            // `jit_above_cost` i PostgreSQL kompilował to zapytanie przez JIT
            // przy każdym wejściu (1,3–2,5 s z 1,4–2,8 s). Ta sama reguła,
            // inny plan: `OdkrywanieKosztPlanuTest` porównuje listę ze starą.
            ->zWidocznymPrzepisemAlboWlasnaTresciBezKorelacji($viewer);

        $strona = Post::query()
            ->select('posts.*', 'rotacja.runda')
            ->joinSub($rundy, 'rotacja', 'rotacja.id', '=', 'posts.id')
            // Relacje karty, licznik komentarzy i zapisów — jeden kontrakt
            // `Post::scopeDlaKarty()` (#1037), ten sam na każdej liście wpisów.
            ->dlaKarty($viewer)
            // Runda, w niej czas, a remis czasu rozstrzyga identyfikator —
            // ta sama trójka, którą kursor zapisuje i odtwarza.
            ->orderBy('rotacja.runda')
            ->orderByDesc('posts.published_at')
            ->orderByDesc('posts.id')
            // Kursor już sprawdzony wyżej (`kursorRotacji()`); `strona()`
            // pilnuje jeszcze, że kolumny to naprawdę to sortowanie.
            ->pipe(fn ($zapytanie) => KursorListy::strona($zapytanie, $perPage, self::KOLUMNY_KURSORA, zAdresu: $kursor !== null));

        // Karta nie pokazuje tytułu ani zdjęcia przepisu, którego widz nie
        // zobaczy (issue #1377) — wpis z własną treścią zostaje bez nich.
        Post::ukryjNiedostepnePrzepisy($strona->items(), $viewer);

        return $strona->appends('stan', (string) $chwila->getTimestamp());
    }

    /**
     * Kursor z adresu — tylko jeśli opisuje pozycję W ROTACJI.
     *
     * Kursor sprzed rotacji (#940: `published_at` + `id`, np. z zakładki albo
     * z otwartej karty w chwili wdrożenia) nie ma `rotacja.runda`, a Laravel
     * na brakujący parametr rzuca wyjątkiem — czyli 500 na `/odkryj`. Taki
     * kursor nie mówi, gdzie w rundach jesteśmy, więc zaczynamy od początku.
     */
    private function kursorRotacji(): ?Cursor
    {
        // Klucze I typy wartości (#2308): kursor z właściwymi kluczami, ale
        // z napisem zamiast UUID-u albo czasu, dawał 500 z Postgresa.
        return KursorListy::zAdresu(self::KOLUMNY_KURSORA);
    }

    /**
     * Chwila pierwszej strony z `stan` dalszej strony — albo `null`, gdy jej
     * nie ma albo nie da się jej przyjąć.
     *
     * Wartość z adresu jest tylko POZYCJĄ w czasie — nie otwiera niczego, czego
     * widz nie mógłby zobaczyć (bramki widoczności liczą się zawsze od teraz).
     * Przyszłość, śmieci i wartości starsze niż doba dają `null`, a `null`
     * odrzuca też kursor: lista zaczyna się od początku, zamiast łączyć stary
     * kursor z nową chwilą.
     */
    private function chwilaZAdresu(?string $stan): ?CarbonImmutable
    {
        if ($stan === null || preg_match('/^\d{1,12}$/', $stan) !== 1) {
            return null;
        }

        $teraz = CarbonImmutable::now()->startOfSecond();
        $chwila = CarbonImmutable::createFromTimestamp((int) $stan, $teraz->getTimezone());

        if ($chwila->greaterThan($teraz)
            || $chwila->lessThan($teraz->subSeconds(self::STAN_NAJWYZEJ_SEKUND_WSTECZ))) {
            return null;
        }

        return $chwila;
    }

    /**
     * Blokady w obie strony. Zwrotnej („ktoś zablokował mnie") widz nie
     * widzi nigdzie w interfejsie — liczy się tylko do odsiania.
     *
     * @param  Builder<Post>  $query
     */
    private function bezUkrytychPrzez($query, User $viewer): void
    {
        $query->whereNotIn('posts.author_id', $this->hiddenAuthorIdsFor($viewer))
            // Prywatne ukrycia (#1810, D-278): wpis i osoba.
            ->bezUkrytychWpisow($viewer)
            // `NOT IN` z `IS NOT NULL` zamiast skorelowanego `NOT EXISTS`
            // (#2288, jak w `FollowingFeed`) — ten sam wynik, naliczany raz.
            ->bezUkrytychOsob($viewer, bezKorelacji: true);
    }

    /**
     * Ile rzeczy widz sam ukrywa: blokady i aktywne ukrycia wpisów i osób (#1810). Pusty stan Odkrywania mówi
     * wtedy „część ukrywasz" i prowadzi do listy, na której da się to cofnąć
     * (AGENTS.md §8: jawne polecenie widza zawsze z listą do cofnięcia).
     *
     * Tylko blokady ZROBIONE PRZEZ widza — blokady, którymi ktoś odciął jego,
     * nie są jego decyzją i pusty stan nie może ich zdradzać.
     */
    public function ileUkrywa(User $viewer): int
    {
        return $viewer->blocking()->count() + Hide::query()->aktywne()->where('user_id', $viewer->getKey())->count();
    }

    /** @return list<string> */
    private function hiddenAuthorIdsFor(User $viewer): array
    {
        return array_values(array_unique([
            ...$viewer->blocking()->pluck('users.id')->all(),
            ...$viewer->blockedBy()->pluck('users.id')->all(),
        ]));
    }
}
