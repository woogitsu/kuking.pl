<?php

declare(strict_types=1);

namespace App\Domain\Wspomnienia;

use App\Domain\Collections\ZapisyWpisu;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Support\Carbon;

/**
 * „Rok temu gotowałaś…" — własne archiwum jako powód powrotu (issue #34).
 *
 * DLA KOGO TO JEST
 * Dla osoby, która gotuje codziennie od czterdziestu lat, największą
 * wartością nie jest nowy przepis — jest zobaczenie, co gotowała we wrześniu
 * dwa lata temu (docs/product/SOUL.md). Archiwum profilu jest już
 * pogrupowane po miesiącach właśnie po to.
 *
 * TA MECHANIKA MA JEDNĄ POWAŻNĄ WADĘ I NIE WOLNO JEJ PRZEMILCZEĆ
 * Wspomnienia potrafią zaboleć. Wpis z przepisem po mamie, która zmarła w tym
 * roku, wyświetlony bez ostrzeżenia na stronie głównej, jest okrutny. Stąd
 * trzy rzeczy wbudowane w tę klasę, a nie dołożone później:
 *
 *   1. Jeden przełącznik wyłącza CAŁOŚĆ (`users.memories_enabled`) — człowiek
 *      w żałobie nie ma odklikiwać wspomnień po kolei.
 *   2. Pojedynczy wpis da się schować (`posts.hide_as_memory`), bo zwykle
 *      boli jedna rzecz, a nie wszystkie.
 *   3. Blok pojawia się WYŁĄCZNIE wtedy, gdy jest co pokazać. Nigdy „nie masz
 *      jeszcze wspomnień" — pusty stan zamieniłby tę mechanikę w wyrzut
 *      wobec osoby, która dopiero zaczyna.
 *
 * Ton jest cichy i tego pilnuje `podpis()`: „Rok temu, 6 września", nigdy
 * „Pamiętasz ten wspaniały dzień?!". Żadnych podsumowań roku z animacją.
 *
 * WŁASNE WYKONANIA („Ugotowałem”) — F6, research z 30 września 2026
 * Źródłem wspomnienia jest też własne wykonanie z tego samego dnia
 * w poprzednich latach (`wykonanieDlaOsoby()`). Reguły są te same: jeden
 * element na stronie głównej (`doPokazania()` wybiera starszy z dwóch), ten
 * sam wyłącznik, ukrywanie pojedyncze (`cooked_events.hide_as_memory`)
 * i ten sam cichy podpis. Wykonanie przepisu, którego ta osoba już nie może
 * zobaczyć (usunięty, prywatny, ukryty przez moderację, blokada z autorem),
 * nie wraca — bramką jest `Recipe::widoczneDla`, nie osobna reguła.
 */
final class Wspomnienia
{
    /** Dalej niż tyle lat wstecz nie szukamy — serwis jest młodszy. */
    private const MAKS_LAT_WSTECZ = 10;

    /**
     * `new ZapisyWpisu` jako wartość domyślna — dokładnie tak samo jak
     * `FollowingFeed` i `DiscoverFeed` biorą tę samą klasę. Kontener i tak
     * ją wstrzyknie (nie ma zależności), a domyślna wartość sprawia, że test
     * wołający `new Wspomnienia` wprost nie musi o niej wiedzieć.
     */
    public function __construct(private readonly ZapisyWpisu $zapisy = new ZapisyWpisu) {}

    /**
     * Jeden wpis sprzed roku (albo więcej lat) z TEGO SAMEGO DNIA.
     *
     * Jeden, nie lista: strona główna ma być feedem, a nie muzeum. Bierzemy
     * najstarszy pasujący — im dawniejszy, tym większa wartość wspomnienia,
     * a wpis sprzed roku i tak wróci za rok.
     */
    public function dlaOsoby(User $user): ?Post
    {
        if (! $user->memories_enabled) {
            return null;
        }

        // Dzień liczony w strefie CZYTELNIKA, nie w UTC. Baza trzyma czas
        // w UTC celowo (patrz App\Support\Czas), ale „rok temu, 6 września"
        // ma znaczyć szósty września u człowieka — inaczej przez dwie godziny
        // na dobę wspomnienie wypadałoby o dzień obok.
        $dzis = Czas::lokalnie(Carbon::now());

        return Post::query()
            ->where('author_id', $user->getKey())
            ->published()
            ->enabledKinds()
            // WŁASNE ARCHIWUM, WIĘC TAKŻE WPISY PRYWATNE. Widzi je wyłącznie
            // ta jedna osoba — to samo, co widzi na swoim profilu.
            ->where('hide_as_memory', false)
            ->whereNotNull('published_at')
            // `at time zone` NIE JEST TU OZDOBĄ — bez niego ta funkcja gubi
            // wspomnienia. `published_at` to `timestamptz`, więc `extract`
            // czyta z niego dzień W UTC, a porównujemy go z dniem CZYTELNIKA
            // wyliczonym linijkę wyżej. Dla wpisu opublikowanego po północy
            // czasu lokalnego te dwa dni są różne i rocznica po cichu
            // przesuwa się o dobę wstecz.
            //
            // Zmierzone na zamrożonym zegarze (15 marca 2026, 10:00 lokalnie),
            // wpis z 15 marca 2025 o 00:30 lokalnie, czyli 14 marca 23:30 UTC:
            //
            //     dzień UTC wpisu:     14
            //     dzień lokalny wpisu: 15
            //     wspomnienie:         NIE ZNALEZIONE
            //
            // Dotyczy każdego wpisu z przedziału 00:00–02:00 czasu polskiego
            // (00:00–01:00 zimą) — czyli osoby, która ugotowała późno i wrzuciła
            // zdjęcie po północy. Akurat w funkcji, której cała treść brzmi
            // „ten sam dzień, rok temu".
            //
            // Indeksu to nie psuje: `extract(...)` i tak nie korzystał z żadnego.
            ->whereRaw(
                'extract(month from published_at at time zone ?) = ? '
                .'and extract(day from published_at at time zone ?) = ?',
                [Czas::strefa(), $dzis->month, Czas::strefa(), $dzis->day],
            )
            // Ostro odcinamy dzisiejszy dzień: wpis z dzisiaj nie jest
            // wspomnieniem, tylko wpisem, i wisi kilka centymetrów niżej.
            ->where('published_at', '<', $dzis->copy()->startOfDay()->utc())
            ->where('published_at', '>=', $dzis->copy()->subYears(self::MAKS_LAT_WSTECZ)->utc())
            ->with(['media', 'author.profile.avatar'])
            // STAN „MASZ TO W ZESZYCIE” — TYM SAMYM ZAPYTANIEM (issue #275, D-081).
            //
            // Bez tego karta wspomnienia pokazywała przycisk „Zapisuję” także
            // wtedy, gdy wpis leżał już w zeszycie oglądającego — bo
            // `post-card.blade.php` czyta `czy_zapisany` i bez tej kolumny
            // dostaje `null`, czyli „nie zapisane”. Feed obserwowanych,
            // „Odkryj” i strona tagu dokładały tę kolumnę od początku; ten
            // jeden ekran — nie, i nikt tego nie pilnował testem zachowania.
            //
            // Wspomnienie jest ZAWSZE własnym wpisem oglądającego, więc liczby
            // `zapisow_count` to zwykle nie ruszy (własny zapis autora się nie
            // liczy — patrz `ZapisyWpisu`), ale pytanie „czy JA to mam
            // u siebie” ma tu pełny sens: do własnego zeszytu odkłada się
            // także własne dania.
            //
            // KOSZT: ZERO DODATKOWYCH ZAPYTAŃ. `dolicz()` dokłada kolumny do
            // SELECT-a, który i tak się wykonuje — to jest jedno `first()`
            // wyżej, nie drugie zapytanie. Zmierzone na renderze `/home`:
            // 29 zapytań przy 2 wierszach feedu i 32 przy 12 — tyle samo przed
            // tą zmianą i po niej.
            ->tap(fn ($q) => $this->zapisy->dolicz($q, $user))
            ->orderBy('published_at')
            ->first();
    }

    /**
     * Jedno własne wykonanie („Ugotowałem”) z TEGO SAMEGO DNIA sprzed roku
     * albo więcej lat. Te same reguły co `dlaOsoby()`: wyłącznik, ukrycie
     * pojedyncze, dzień w strefie czytelnika, najstarsze pierwsze.
     *
     * TYLKO WŁASNE. Zapytanie jest przywiązane do `user_id` oglądającego —
     * cudze wykonania mojego przepisu to nie moje wspomnienie.
     */
    public function wykonanieDlaOsoby(User $user): ?CookedEvent
    {
        if (! $user->memories_enabled) {
            return null;
        }

        $dzis = Czas::lokalnie(Carbon::now());

        return CookedEvent::query()
            ->where('user_id', $user->getKey())
            ->where('hide_as_memory', false)
            // Przepis musi być dla tej osoby WIDOCZNY. Wspomnienie prowadzi
            // do „Ugotuj znowu”, a przepis usunięty, zamieniony na prywatny,
            // ukryty przez moderację albo za blokadą z autorem nie ma wracać
            // na stronę główną tylnymi drzwiami (karta F6, „Ryzyka”).
            // Autor zbanowany / w trakcie usuwania / wymazany: `widoczneDla`
            // pyta o blokady i widoczność, ale nie o konto autora, a
            // `RecipePolicy::view` odmawia (#2347).
            ->whereHas('recipe', fn ($przepis) => $przepis->widoczneDla($user)
                ->whereHas('author', fn ($a) => $a->dostepnyJakoAutor()))
            // `at time zone` z tego samego powodu co w `dlaOsoby()`:
            // `cooked_at` to `timestamptz`, a dzień liczymy u czytelnika.
            ->whereRaw(
                'extract(month from cooked_at at time zone ?) = ? '
                .'and extract(day from cooked_at at time zone ?) = ?',
                [Czas::strefa(), $dzis->month, Czas::strefa(), $dzis->day],
            )
            ->where('cooked_at', '<', $dzis->copy()->startOfDay()->utc())
            ->where('cooked_at', '>=', $dzis->copy()->subYears(self::MAKS_LAT_WSTECZ)->utc())
            ->with(['media', 'user.profile.avatar', 'recipe'])
            ->orderBy('cooked_at')
            ->first();
    }

    /**
     * Jedno wspomnienie na stronę główną: wpis ALBO wykonanie, nigdy oba.
     *
     * „Strona główna ma być feedem, a nie muzeum” — dlatego z dwóch źródeł
     * wybieramy starsze (większa wartość wspomnienia), a przy tej samej
     * chwili wpis, bo to on niesie zdjęcie i słowa.
     */
    public function doPokazania(User $user): Post|CookedEvent|null
    {
        $wpis = $this->dlaOsoby($user);
        $wykonanie = $this->wykonanieDlaOsoby($user);

        if ($wpis === null || $wykonanie === null) {
            return $wpis ?? $wykonanie;
        }

        return $wykonanie->cooked_at->lt($wpis->published_at) ? $wykonanie : $wpis;
    }

    /**
     * Podpis nad wspomnieniem. Stwierdzenie faktu, nie zachwyt.
     *
     * „Rok temu, 6 września". Nie „Pamiętasz ten wspaniały dzień?!" — bo nie
     * wiemy, czy był wspaniały, i nie mamy prawa tego zakładać.
     */
    public function podpis(Post|CookedEvent $wspomnienie): string
    {
        $chwila = $wspomnienie instanceof CookedEvent ? $wspomnienie->cooked_at : $wspomnienie->published_at;
        $kiedy = Czas::lokalnie($chwila);

        // Różnica LICZONA NA LATACH KALENDARZOWYCH, nie przez `diffInYears`.
        // Wspomnienie jest z tego samego dnia i miesiąca, więc odejmowanie
        // roczników daje dokładnie to, co człowiek ma na myśli, i nie zależy
        // od godziny ani od tego, jak biblioteka zaokrągla ułamek roku.
        $lat = Czas::lokalnie(Carbon::now())->year - $kiedy->year;

        $ile = match (true) {
            $lat <= 1 => 'Rok temu',
            $lat === 2 => 'Dwa lata temu',
            $lat === 3 => 'Trzy lata temu',
            $lat === 4 => 'Cztery lata temu',
            default => $lat.' lat temu',
        };

        // Bez roku w dacie: rok mówi już „rok temu" / „dwa lata temu",
        // a „Rok temu, 6 września 2025" to ta sama informacja dwa razy.
        return $ile.', '.Czas::data($chwila, 'j F');
    }
}
