<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Appeal;
use App\Models\ContactMessage;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Support\Facades\Cache;

/**
 * ILE CZEKA NA CZŁOWIEKA W KAŻDEJ KOLEJCE PANELU — licznik przy pozycjach
 * menu (zgłoszenie właściciela z 10 września: „w »Odwołania« nie ma takiego
 * kwadracika jak przy Powiadomieniach").
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO TO NIE MOŻE BYĆ PIĘĆ `COUNT(*)` W WIDOKU
 * ────────────────────────────────────────────────────────────────────────
 *
 * Menu boczne stoi na KAŻDEJ stronie panelu, a od 10 września ma pięć
 * kolejek. Pięć `COUNT(*)` w bloku `@php` layoutu znaczyłoby pięć zapytań
 * na każdą odsłonę każdego ekranu `/admin/**` — i to zapytań, których koszt
 * rośnie z zawartością kolejek, więc najdroższe byłyby dokładnie wtedy, gdy
 * moderator ma najwięcej pracy. `Bez odpowiedzi` jest tu najgorsze: to
 * `WHERE NOT EXISTS` po komentarzach, czyli nie samo przeliczenie indeksu.
 *
 * Ten sam problem rozstrzygnął już licznik społeczności w stopce
 * (`App\Domain\Analytics\LiczbaKukingow`) i bierzemy stamtąd całe
 * rozwiązanie: PRZELICZANIE schodzi poza ścieżkę żądania, a widok tylko
 * CZYTA gotową wartość. Tam wnioski zapisano po pomiarze
 * (`MiniaturyBezWachlarzaZapytanTest` wybuchał na `Cache::remember()`, bo
 * cache „na żądanie" dokłada zapytanie do KTÓREJŚ odsłony — losowej), więc
 * nie powtarzamy tamtego błędu drugi raz.
 *
 * JEDEN WPIS W CACHE NA WSZYSTKIE PIĘĆ LICZB, nie pięć wpisów. Produkcja
 * chodzi na `CACHE_STORE=database` (AGENTS.md §3 — bez Redisa), więc każdy
 * `Cache::get()` to jedno zapytanie do tabeli `cache`. Pięć osobnych kluczy
 * zamieniłoby pięć `COUNT(*)` na pięć `SELECT`-ów i nie rozwiązałoby
 * niczego. Jeden klucz to JEDNO zapytanie o stałym koszcie, niezależne od
 * liczby pozycji w kolejkach — i to jest ta niezależność, którą mierzy
 * `LicznikiKolejekBezZapytanTest`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ŚWIEŻOŚĆ: ZDARZENIA MODELU DLA TRZECH KOLEJEK, HARMONOGRAM DLA RESZTY
 * ────────────────────────────────────────────────────────────────────────
 *
 * Licznik ma znaczyć „to czeka na Ciebie", więc nie wolno mu pokazywać „1"
 * dziesięć minut po tym, jak moderator zamknął ostatnią sprawę — kliknie
 * i zobaczy pustą kolejkę, czyli licznik raz skłamie i już nikt mu nie
 * uwierzy. Dlatego `odswiez()` woła się PRZY ZAPISIE, ze zdarzeń modeli
 * `Appeal`, `Report` i `ContactMessage` (rejestracja: `AppServiceProvider`).
 * Te trzy tabele zmieniają się kilka razy na dobę, więc pięć `COUNT(*)`
 * przy takim zapisie jest ceną, której w ogóle nie widać.
 *
 * `Post` i `Comment` ŚWIADOMIE NIE MAJĄ tego haka, choć od nich zależy
 * kolejka „Bez odpowiedzi". Publikacja wpisu i komentarz to GŁÓWNA akcja
 * produktu (AGENTS.md §1) i nie dokładamy do niej pięciu zapytań po to, żeby
 * licznik miękkiej kolejki był świeży co do sekundy. Tamta kolejka ma progi
 * 6 i 24 godzin (`BezOdpowiedziController`), więc pięciominutowe opóźnienie
 * harmonogramu nie zmienia w niej niczego.
 *
 * HARMONOGRAM (`kuking:policz-kolejki`, co pięć minut) jest jednocześnie
 * siatką bezpieczeństwa: po wdrożeniu cache jest pusty i bez niego liczniki
 * nie pojawiłyby się aż do pierwszego zapisu w którejś z kolejek.
 *
 * PUSTY CACHE ZNACZY „NIE WIADOMO" I POKAZUJE ZERO, czyli BRAK plakietki —
 * tak samo jak w liczniku społeczności. Licznik ukryty jest lepszy niż
 * licznik kłamiący, a „0" na ekranie jest samym hałasem: mówi tyle samo, co
 * jego brak, tylko zajmuje uwagę pięć razy na każdym ekranie.
 */
final class KolejkiPanelu
{
    private const KLUCZ_CACHE = 'panel:kolejki';

    /**
     * Nazwy kolejek — klucz w cache i klucz w widoku. Kolejność ta sama, co
     * w menu bocznym, żeby czytanie jednego obok drugiego miało sens.
     *
     * @var list<string>
     */
    public const KOLEJKI = [
        'bez_odpowiedzi',
        'zgloszenia',
        'sygnaly',
        'odwolania',
        'wiadomosci',
    ];

    /**
     * Przelicza wszystkie pięć liczb i zapisuje je JEDNYM wpisem w cache.
     *
     * `Cache::forever`, nie TTL — z tego samego powodu co w
     * `LiczbaKukingow`: gdyby harmonogram na chwilę przestał chodzić, menu
     * ma pokazać ostatnią znaną liczbę, a nie po cichu zgubić plakietki.
     *
     * @return array<string, int>
     */
    public function przelicz(): array
    {
        $liczby = [
            // Wpisy bez ani jednej odpowiedzi — dokładnie ten sam warunek co
            // lista na `/admin/bez-odpowiedzi`. Gdyby liczby i lista liczyły
            // co innego, licznik „3" nad pustym ekranem byłby usterką, której
            // nikt nie umiałby wyjaśnić.
            'bez_odpowiedzi' => Post::query()
                ->published()
                ->whereIn('visibility', [Post::VISIBILITY_PUBLIC, Post::VISIBILITY_FOLLOWERS])
                ->whereDoesntHave('allComments', fn ($q) => $q->where('status', 'published'))
                ->whereHas('author', fn ($q) => $q->widocznyJakoOsoba())
                ->count(),

            // Zgłoszenia OD LUDZI, których jeszcze nikt nie tknął. Nie
            // wliczamy `reviewing`: sprawa wzięta do przeglądu już jest
            // u człowieka, a licznik ma mówić „to czeka", nie „tyle jest
            // wszystkiego" (ten sam podział, który `ModerationController`
            // pokazuje jako osobne zakładki „Nowe" i „W toku").
            'zgloszenia' => Report::query()
                ->where('source', '!=', Report::SOURCE_AUTOMAT)
                ->where('status', Report::STATUS_OPEN)
                ->count(),

            // Sygnały automatu — ten sam warunek co `SygnalyController::otwarte()`.
            'sygnaly' => Report::query()
                ->where('source', Report::SOURCE_AUTOMAT)
                ->whereIn('status', Report::STANY_OTWARTE)
                ->count(),

            // Odwołania do rozpatrzenia. Ta jedna liczba ma TERMIN (DSA
            // art. 20, `Appeal::responseDeadline()`) i jest powodem, dla
            // którego cała ta klasa istnieje.
            'odwolania' => Appeal::query()
                ->where('status', Appeal::STATUS_OPEN)
                ->count(),

            // Wiadomości z „Napisz do nas" — same nowe. `in_progress` jest
            // już u kogoś w rękach, dokładnie jak `reviewing` wyżej.
            'wiadomosci' => ContactMessage::query()
                ->where('status', ContactMessage::STATUS_NOWA)
                ->count(),
        ];

        Cache::forever(self::KLUCZ_CACHE, $liczby);

        return $liczby;
    }

    /**
     * Ostatnie przeliczone liczby. CZYSTY ODCZYT — jeden `Cache::get()`,
     * bez ani jednego `COUNT(*)`, niezależnie od tego, ile leży w kolejkach.
     *
     * Zawsze zwraca pełny zestaw kluczy z `KOLEJKI`, także gdy cache jest
     * pusty albo pochodzi ze starszego wdrożenia z mniejszą liczbą kolejek —
     * widok nie ma prawa wybuchnąć na brakującym kluczu.
     *
     * @return array<string, int>
     */
    public function liczby(): array
    {
        $zapisane = Cache::get(self::KLUCZ_CACHE);
        $zapisane = is_array($zapisane) ? $zapisane : [];

        $liczby = [];

        foreach (self::KOLEJKI as $nazwa) {
            $liczby[$nazwa] = max(0, (int) ($zapisane[$nazwa] ?? 0));
        }

        return $liczby;
    }

    /**
     * Przelicz od nowa, bo coś w kolejkach się zmieniło. Osobna nazwa od
     * `przelicz()` wyłącznie po to, żeby w miejscu wywołania było widać
     * INTENCJĘ („kolejka się zmieniła"), a nie mechanizm.
     */
    public function odswiez(): void
    {
        $this->przelicz();
    }
}
