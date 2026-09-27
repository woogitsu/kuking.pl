<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\User;
use App\Support\Odmiana;
use Illuminate\Support\Facades\Cache;

/**
 * Licznik społeczności do stopki: „{n} kuKINGów" (issue #38,
 * docs/brand/COPY_STYLE.md §8: „kuKING w 3-4 miejscach: rejestracja,
 * tablica «kuKINGi na dziś», LICZNIK SPOŁECZNOŚCI, digest").
 *
 * KOGO LICZYMY — TE SAME WYKLUCZENIA CO „DOWÓD ŻYWEJ SPOŁECZNOŚCI" GDZIE
 * INDZIEJ (`CookEligibility`, ten sam wzorzec co `AktywniWTygodniu`)
 * Ten licznik ma dokładnie tę samą naturę co WAC i „aktywni w tygodniu":
 * to LICZBA POKAZYWANA OBCEMU jako dowód, że serwis żyje. Dwanaście kont
 * zalążkowych (`users.is_seeded`, D-025) to persony z pliku, nie ludzie,
 * którzy się zarejestrowali — wliczenie ich zawyżałoby liczbę tak samo,
 * jak zawyżałoby WAC. Gospodarz i konta testowe (już wykluczone przez
 * `CookEligibility`) z tego samego powodu: te konta istnieją, żeby
 * generować treść i pomagać, nie żeby być dowodem, że DOŁĄCZAJĄ OBCY.
 * Zbanowane/`pending_delete`/`erased` konta już nie są częścią serwisu.
 *
 * Reguła „kto się nie liczy" żyje w JEDNYM miejscu
 * (`CookEligibility::tylkoLiczeni()`) — nie kopiujemy jej po raz trzeci
 * (komentarz w `AktywniWTygodniu` już ostrzega, że dokładnie to jest
 * najczęściej wracająca usterka w tym repozytorium).
 *
 * DLACZEGO TO NIE JEST `Cache::remember()` NA ŻĄDANIE — STOPKA JEST NA
 * KAŻDEJ STRONIE
 * Pierwszy szkic tej klasy liczył na żądanie i cache'ował wynik przez
 * godzinę (`Cache::remember`, `CACHE_STORE=database` z `config/cache.php`,
 * zgodnie z tym, czego ten projekt już używa zamiast Redisa — AGENTS.md §3).
 * To NIE ZADZIAŁAŁO w praktyce: `MiniaturyBezWachlarzaZapytanTest` mierzy
 * DWA odsłony tej samej strony w JEDNYM teście (mało treści / dużo treści)
 * i porównuje liczbę zapytań — pierwsza odsłona trafiała na zimny cache
 * (+1 zapytanie COUNT), druga na ciepły (+0), więc test widział fałszywy
 * spadek liczby zapytań i wybuchał, mimo że żadnego N+1 nie było. Innymi
 * słowy: cache „na żądanie" wciąż dokłada zapytanie do KTÓREJŚ odsłony —
 * tej, która akurat trafi na pusty cache — i to jest strona losowa.
 *
 * Rozwiązanie, dokładnie w duchu `ReportWeeklyActiveCooks`/`RaportPowrotow`
 * (komendy w `app/Console/Commands`, wołane z harmonogramu przez
 * `Schedule::call()` w `routes/console.php` — `command()` jest tu zakazane,
 * `HarmonogramBezProcOpenTest`, bo wymaga `proc_open` wyłączonego w
 * `docker/php.ini`): PRZELICZANIE schodzi CAŁKOWICIE poza ścieżkę żądania,
 * do komendy `kuking:policz-kukingow` uruchamianej co godzinę. Widok
 * (`liczba()`/`widoczna()`) tylko CZYTA gotową wartość z cache —
 * `Cache::get()`, zero zapytań do `users`, na KAŻDEJ odsłonie, zawsze.
 * Rozmiar społeczności zmienia się wolno, więc godzina nieaktualności jest
 * niewidoczna dla człowieka, a `Cache::forever()` (nie TTL) oznacza, że
 * nawet gdyby harmonogram na chwilę przestał działać, stopka pokazuje
 * ostatnią znaną, wciąż prawdziwą liczbę, zamiast po cichu zniknąć.
 *
 * Skutek uboczny: na świeżym środowisku (i w testach, które nie wołają
 * `przelicz()` same) cache jest pusty, więc `liczba()` zwraca 0
 * i `widoczna()` — `false`. To jest poprawne zachowanie, nie usterka:
 * zanim komenda przeliczy choć raz, uczciwa odpowiedź na „ile osób" to
 * „nie wiadomo jeszcze", a licznik ukryty jest lepszy niż licznik kłamiący.
 *
 * PRÓG WIDOCZNOŚCI: 20
 * „Zero na starcie" — serwis dziś nie ma jeszcze użytkowników, więc
 * „0 kuKINGów" (albo „3 kuKINGów") w stopce KAŻDEJ strony wygląda gorzej
 * niż brak licznika: to pierwsza rzecz, którą widzi ktoś sceptyczny, i mówi
 * „nikogo tu nie ma". 20 nie jest liczbą wziętą z sufitu — to Bramka A
 * z `docs/product/COLD_START.md` §9: punkt, w którym PROJEKT SAM uznaje
 * zamkniętą alfę za prawdziwą, żywą społeczność i odblokowuje kolejne
 * zaproszenia (D-012, `docs/DECISIONS.md`). Poniżej tego progu liczba nie
 * jest kłamstwem, po prostu nie jest jeszcze dowodem niczego — poczeka,
 * aż będzie.
 *
 * ODMIANA: NIE „kuKINGi" DLA 2-4
 * Polska liczba mnoga ma trzy formy, ale D-013 (`docs/DECISIONS.md`)
 * ostrzega, że `kuKINGi` jako rzeczownik OSOBOWY bywa odbierany jak
 * „profesory"/„chłopy" — forma deprecjonująca — i dlatego
 * `docs/brand/COPY_STYLE.md` §2 definiuje `kuKINGi` WYŁĄCZNIE w znaczeniu
 * rzeczy („kuKINGi na dziś"), nigdy ludzi. Ten licznik liczy LUDZI, więc dla
 * 2 i więcej używamy dopełniacza mnogiego („kuKINGów") — dokładnie tej
 * formy, którą `COPY_STYLE.md` już podaje jako przykład headcountu
 * („2 431 kuKINGów"), i tej samej bezpiecznej alternatywy, którą D-013
 * przygotował na wypadek, gdyby „kuKINGi" wypadło źle w testach z osobami
 * 50+ („Dziś u kuKINGów"). W praktyce forma „kilka" (2-4) jest tu i tak
 * martwa poniżej progu widoczności (20) — zostaje udokumentowana
 * i przetestowana, bo `Odmiana::rzeczownik()` jest funkcją ogólną
 * i kolejny ekran może jej użyć inaczej.
 */
final class LiczbaKukingow
{
    private const KLUCZ_CACHE = 'community:liczba-kukingow';

    private const PROG_WIDOCZNOSCI = 20;

    public function __construct(private readonly CookEligibility $eligibility = new CookEligibility) {}

    /**
     * Przelicza od zera z bazy i zapisuje w cache na stałe (`forever`,
     * nie TTL — patrz „DLACZEGO TO NIE JEST Cache::remember()" w komentarzu
     * klasy). WOŁANA WYŁĄCZNIE z `kuking:policz-kukingow`
     * (harmonogram, `routes/console.php`) — nigdy ze ścieżki żądania.
     *
     * @return int przeliczona wartość, do wypisania przez komendę
     */
    public function przelicz(): int
    {
        // Zamknięte statusy odcina już `tylkoLiczeni()` — druga kopia tego
        // warunku tutaj byłaby dokładnie tym rozjazdem, przed którym ostrzega
        // komentarz klasy.
        $liczeni = User::query();
        $this->eligibility->tylkoLiczeni($liczeni, 'users.id');

        $liczba = $liczeni->count();

        Cache::forever(self::KLUCZ_CACHE, $liczba);

        return $liczba;
    }

    /**
     * Ostatnia przeliczona wartość. CZYSTY ODCZYT Z CACHE — zero zapytań do
     * bazy. Zanim `przelicz()` zadziała choć raz (świeże środowisko, testy),
     * zwraca 0, co poprawnie chowa licznik (patrz próg widoczności).
     */
    public function liczba(): int
    {
        return (int) Cache::get(self::KLUCZ_CACHE, 0);
    }

    /** Czy jest sens pokazać licznik — patrz „PRÓG WIDOCZNOŚCI" wyżej. */
    public function widoczna(): bool
    {
        return $this->liczba() >= self::PROG_WIDOCZNOSCI;
    }

    /** Liczba z polskim separatorem tysięcy: „2 431", nie „2,431". */
    public function liczbaSformatowana(): string
    {
        return number_format($this->liczba(), 0, ',', ' ');
    }

    /**
     * Sufiks do `<x-kuking-word>`: pusty dla 1 („kuKING"), „ów" dla reszty
     * („kuKINGów") — patrz uzasadnienie odmiany w komentarzu klasy.
     */
    public function sufiks(): string
    {
        return Odmiana::rzeczownik($this->liczba(), '', 'ów', 'ów');
    }
}
