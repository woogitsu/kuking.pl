<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kasowanie konta bierze wiersze `follows` i `blocks` w kolejności ustalonej
 * PRZEZ DANE, nie przez rolę konta w wierszu (znalezisko Z-2 z audytu
 * kolejności blokad, `docs/research/2026-09-10-kolejnosc-blokad.md`;
 * decyzja D-093).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZŁAMANE
 * ══════════════════════════════════════════════════════════════════════
 *
 * `EraseAccountData` kasowało relacje dwoma hurtowymi `detach()` w stałej
 * kolejności ról: najpierw wiersze `(X, *)`, potem `(*, X)`. Dla pary, która
 * obserwuje się wzajemnie, egzekucja konta X brała `(X,Y)` przed `(Y,X)`,
 * a egzekucja konta Y — odwrotnie. Zmierzone na dwóch połączeniach:
 *
 *   ERROR: deadlock detected … while deleting tuple (0,5) in relation "follows"
 *
 * Skutek u człowieka: nocna komenda `kuking:usun-wygasle-konta` przerywa się
 * w połowie, a konto, które PROSIŁO o usunięcie, nie zostaje tej nocy
 * wymazane. To obowiązek prawny, nie wygoda, więc „przy kolejnym przebiegu
 * zwykle przejdzie" nie jest odpowiedzią.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CZEGO TE TESTY **NIE** DOWODZĄ — czytaj to przed dopisaniem asercji
 * ══════════════════════════════════════════════════════════════════════
 *
 * `docs/PULAPKI_TESTOW.md` §6: test na JEDNYM połączeniu nie dowodzi
 * zachowania przy dwóch. `RefreshDatabase` trzyma cały test w jednej
 * niezatwierdzonej transakcji, więc drugiego uczestnika wyścigu tu po prostu
 * nie ma i nie da się go zrobić.
 *
 * Te testy pilnują więc WYŁĄCZNIE tego, co da się sprawdzić bez drugiego
 * połączenia, i to jest cały ich zakres:
 *
 *  1. każde zapytanie kasujące wskazuje DOKŁADNIE JEDEN wiersz (obie kolumny
 *     klucza głównego związane) — czyli kolejności wierszy nie ustala plan
 *     zapytania, bo nie ma czego planować;
 *  2. dwie egzekucje na TEJ SAMEJ parze wzajemnej biorą te wiersze w TEJ
 *     SAMEJ kolejności — czyli kolejność jest funkcją danych, nie roli.
 *
 * **NIEPILNOWANE ZOSTAJE:** brak `40P01` przy prawdziwej równoległości.
 * Z punktów 1 i 2 wynika, że cyklu nie da się zbudować (dwie transakcje
 * biorące te same wiersze w tej samej kolejności czekają na siebie w kolejce,
 * zamiast zakleszczać się nawzajem) — ale to jest ROZUMOWANIE, a nie pomiar,
 * i żaden test w tym repozytorium go dziś nie robi. Sam skutek zmierzono poza
 * zestawem testów, na dwóch połączeniach do PostgreSQL, w obie strony:
 * kolejność po rolach dała `deadlock detected`, kolejność po danych — kolejkę
 * i zero wierszy po obu egzekucjach. Propozycja wprowadzenia takich testów do
 * repozytorium (grupa `dwa-polaczenia`) jest w rozdziale 7 raportu i jest
 * osobną decyzją, świadomie tu nie podjętą.
 *
 * Wzorzec pomiaru zapytań jest wzięty z `ZamekParyTest::kolejnoscBlokad()`
 * i dostosowany: tamten szuka `for update` i zbiera wiązania, a kasowanie
 * idzie przez `DELETE`, gdzie trzeba jeszcze odczytać, KTÓRA kolumna jest
 * którą — inaczej `(X,Y)` i `(Y,X)` byłyby nierozróżnialne, czyli zniknęłaby
 * dokładnie ta rzecz, którą mierzymy.
 */
class KasowanieKontaBierzeRelacjeWKolejnosciDanychTest extends TestCase
{
    use RefreshDatabase;

    /** Kolumny klucza głównego obu symetrycznych tabel relacji. */
    private const KOLUMNY = [
        'follows' => ['follower_id', 'followed_id'],
        'blocks' => ['blocker_id', 'blocked_id'],
    ];

    /**
     * Wiersze skasowane z `$tabela` przez `$co`, W KOLEJNOŚCI KASOWANIA.
     *
     * Każdy element to kanoniczny opis wiersza — `kolumna=id,kolumna=id`,
     * kolumny alfabetycznie, żeby opis nie zależał od tego, w jakiej
     * kolejności Laravel złożył klauzule. Zapytanie, z którego nie da się
     * odczytać JEDNEGO wiersza (mniej związanych wartości niż kolumn klucza),
     * trafia tu jako `HURTEM: <sql>`: takie zapytanie kasuje nieznaną liczbę
     * wierszy w kolejności ustalonej przez plan, więc jego kolejności nie da
     * się ani zmierzyć, ani obiecać.
     *
     * @param  list<string>  $kolumny
     * @return list<string>
     */
    private function kasowaneWiersze(string $tabela, array $kolumny, callable $co): array
    {
        $zebrane = [];
        $poczatek = 'delete from "'.$tabela.'"';

        DB::listen(function (QueryExecuted $zapytanie) use (&$zebrane, $poczatek, $kolumny): void {
            if (! str_starts_with($zapytanie->sql, $poczatek)) {
                return;
            }

            // Kolumny w kolejności WYSTĄPIENIA W SQL-u — tylko tak wiadomo,
            // której z nich odpowiada które wiązanie.
            $pozycje = [];

            foreach ($kolumny as $kolumna) {
                $pozycja = strpos($zapytanie->sql, '"'.$kolumna.'"', strlen($poczatek));

                if ($pozycja !== false) {
                    $pozycje[$kolumna] = $pozycja;
                }
            }

            asort($pozycje);
            $wiazania = array_map('strval', array_values($zapytanie->bindings));

            if (count($pozycje) !== count($kolumny) || count($wiazania) !== count($kolumny)) {
                $zebrane[] = 'HURTEM: '.$zapytanie->sql;

                return;
            }

            $wiersz = array_combine(array_keys($pozycje), $wiazania);
            ksort($wiersz);

            $opis = [];

            foreach ($wiersz as $kolumna => $wartosc) {
                $opis[] = $kolumna.'='.$wartosc;
            }

            $zebrane[] = implode(',', $opis);
        });

        $co();

        return $zebrane;
    }

    /**
     * Dwie osoby, które obserwują się WZAJEMNIE i zablokowały się WZAJEMNIE,
     * zwrócone w kolejności rosnącej po identyfikatorze.
     *
     * Obserwowanie wpisujemy PRZED blokadami: wyzwalacz
     * `follows_blokada_ma_pierwszenstwo_trg` odrzuca `INSERT` do `follows`,
     * gdy blokada między parą już istnieje (D-080). Odwrotna kolejność
     * fabryki wywaliłaby test na barierze, a nie na przedmiocie pomiaru.
     *
     * Para WZAJEMNA jest tu istotą, nie ozdobą: tylko przy niej dwie
     * egzekucje mają DWA wspólne wiersze, czyli tylko przy niej da się
     * w ogóle zbudować cykl. Przy relacji jednostronnej wspólny wiersz jest
     * jeden i kolejność nie ma znaczenia.
     *
     * @return array{0: User, 1: User}
     */
    private function paraWzajemna(): array
    {
        $jedna = $this->user('pierwsza_osoba');
        $druga = $this->user('druga_osoba');

        $jedna->following()->attach($druga->getKey());
        $druga->following()->attach($jedna->getKey());

        $jedna->blocking()->attach($druga->getKey());
        $druga->blocking()->attach($jedna->getKey());

        return ((string) $jedna->getKey() < (string) $druga->getKey())
            ? [$jedna, $druga]
            : [$druga, $jedna];
    }

    /** Konto po karencji, gotowe do egzekucji. */
    private function poKarencji(User $user): User
    {
        $user->markForDeletion();
        $user->forceFill(['delete_requested_at' => now()->subDays(31)])->save();

        return $user->fresh();
    }

    /**
     * Uruchamia `$co` i WYCOFUJE wszystko, co zapisało.
     *
     * Dzięki temu obie egzekucje w teście kolejności widzą DOKŁADNIE ten sam
     * świat — te same dwa identyfikatory i te same dwa wiersze. Bez tego
     * druga egzekucja musiałaby dostać inną parę kont, a wtedy nie byłoby
     * czego porównywać: kolejność jest funkcją identyfikatorów.
     *
     * `RefreshDatabase` trzyma test w transakcji, więc to jest punkt powrotu
     * wewnątrz niej, nie druga transakcja.
     *
     * @template T
     *
     * @param  \Closure(): T  $co
     * @return T
     */
    private function iWycofaj(\Closure $co): mixed
    {
        DB::beginTransaction();

        try {
            return $co();
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function test_kasowanie_wierszy_follows_wskazuje_kazdy_wiersz_osobno(): void
    {
        [$nizszy] = $this->paraWzajemna();
        $doEgzekucji = $this->poKarencji($nizszy);

        $wiersze = $this->kasowaneWiersze(
            'follows',
            self::KOLUMNY['follows'],
            fn () => app(EraseAccountData::class)->handle($doEgzekucji),
        );

        // Kontrola dodatnia pomiaru: gdyby nasłuch nie łapał niczego (zła
        // nazwa tabeli, zmieniony kształt SQL-a), lista byłaby pusta i każda
        // asercja niżej przechodziłaby na pustym miejscu.
        $this->assertCount(2, $wiersze, 'Egzekucja nie skasowała obu wierszy pary wzajemnej — pomiar nic nie widzi.');

        foreach ($wiersze as $opis) {
            $this->assertStringNotContainsString(
                'HURTEM',
                $opis,
                'Kasowanie `follows` idzie hurtem — kolejność wierszy ustala wtedy plan zapytania, '
                .'a dwie egzekucje na parze wzajemnej mogą wziąć je w przeciwnych kolejnościach (Z-2).',
            );
        }
    }

    /**
     * `blocks` ma DOKŁADNIE ten sam kształt co `follows`: klucz główny
     * `(blocker_id, blocked_id)` i żadnego ograniczenia zabraniającego pary
     * wzajemnej, więc `(X,Y)` i `(Y,X)` to dwa osobne wiersze. Ma więc i tę
     * samą usterkę — i osobny test, bo osobne wywołanie łatwo poprawić tylko
     * w jednym z dwóch miejsc.
     */
    #[Test]
    public function test_kasowanie_wierszy_blocks_wskazuje_kazdy_wiersz_osobno(): void
    {
        [$nizszy] = $this->paraWzajemna();
        $doEgzekucji = $this->poKarencji($nizszy);

        $wiersze = $this->kasowaneWiersze(
            'blocks',
            self::KOLUMNY['blocks'],
            fn () => app(EraseAccountData::class)->handle($doEgzekucji),
        );

        $this->assertCount(2, $wiersze, 'Egzekucja nie skasowała obu wierszy `blocks` — pomiar nic nie widzi.');

        foreach ($wiersze as $opis) {
            $this->assertStringNotContainsString(
                'HURTEM',
                $opis,
                'Kasowanie `blocks` idzie hurtem — ta tabela ma ten sam kształt co `follows` i tę samą usterkę.',
            );
        }
    }

    #[Test]
    public function test_dwie_egzekucje_tej_samej_pary_biora_wiersze_follows_w_tej_samej_kolejnosci(): void
    {
        [$nizszy, $wyzszy] = $this->paraWzajemna();

        $kolejnoscNizszego = $this->iWycofaj(fn () => $this->kasowaneWiersze(
            'follows',
            self::KOLUMNY['follows'],
            fn () => app(EraseAccountData::class)->handle($this->poKarencji($nizszy)),
        ));

        $kolejnoscWyzszego = $this->iWycofaj(fn () => $this->kasowaneWiersze(
            'follows',
            self::KOLUMNY['follows'],
            fn () => app(EraseAccountData::class)->handle($this->poKarencji($wyzszy)),
        ));

        $this->assertCount(2, $kolejnoscNizszego, 'Egzekucja konta o niższym identyfikatorze nic nie skasowała.');
        $this->assertCount(2, $kolejnoscWyzszego, 'Egzekucja konta o wyższym identyfikatorze nic nie skasowała — punkt powrotu nie przywrócił wierszy?');

        // TO JEST CAŁY PRZEDMIOT TEGO PLIKU. Gdyby kolejność szła za rolą
        // konta w wierszu, te dwie listy byłyby swoimi odwrotnościami —
        // i dwie równoległe egzekucje trzymałyby to, na co czeka druga.
        $this->assertSame(
            $kolejnoscNizszego,
            $kolejnoscWyzszego,
            'Kolejność kasowania wierszy `follows` zależy od tego, KTÓRE konto jest wymazywane, '
            .'a nie od danych w wierszu — dwie egzekucje na parze wzajemnej zakleszczą się (Z-2).',
        );
    }

    #[Test]
    public function test_dwie_egzekucje_tej_samej_pary_biora_wiersze_blocks_w_tej_samej_kolejnosci(): void
    {
        [$nizszy, $wyzszy] = $this->paraWzajemna();

        $kolejnoscNizszego = $this->iWycofaj(fn () => $this->kasowaneWiersze(
            'blocks',
            self::KOLUMNY['blocks'],
            fn () => app(EraseAccountData::class)->handle($this->poKarencji($nizszy)),
        ));

        $kolejnoscWyzszego = $this->iWycofaj(fn () => $this->kasowaneWiersze(
            'blocks',
            self::KOLUMNY['blocks'],
            fn () => app(EraseAccountData::class)->handle($this->poKarencji($wyzszy)),
        ));

        $this->assertCount(2, $kolejnoscNizszego, 'Egzekucja konta o niższym identyfikatorze nic nie skasowała.');
        $this->assertCount(2, $kolejnoscWyzszego, 'Egzekucja konta o wyższym identyfikatorze nic nie skasowała.');

        $this->assertSame(
            $kolejnoscNizszego,
            $kolejnoscWyzszego,
            'Kolejność kasowania wierszy `blocks` zależy od wymazywanego konta, a nie od danych w wierszu.',
        );
    }

    /**
     * KONTROLA DODATNIA CAŁEJ ZMIANY.
     *
     * Kasowanie wiersz po wierszu, w wyliczonej kolejności, jest łatwo
     * zepsuć tak, żeby kolejność była wzorowa, a wiersze zostały w bazie —
     * na przykład myląc stronę relacji przy `detach()`. Wtedy oba testy
     * kolejności wyżej dalej byłyby zielone, a polityka prywatności
     * kłamałaby (`resources/legal/polityka-prywatnosci.md` §2, wiersz
     * „Relacje w serwisie").
     */
    #[Test]
    public function test_kontrola_dodatnia_po_egzekucji_nie_zostaje_ani_jeden_wiersz_pary(): void
    {
        [$nizszy, $wyzszy] = $this->paraWzajemna();

        $this->assertSame(2, DB::table('follows')->count(), 'Fabryka nie zapisała pary wzajemnej.');
        $this->assertSame(2, DB::table('blocks')->count(), 'Fabryka nie zapisała wzajemnych blokad.');

        $this->assertTrue(app(EraseAccountData::class)->handle($this->poKarencji($nizszy)));

        $this->assertSame(0, DB::table('follows')->count(), 'Po egzekucji został wiersz `follows`.');
        $this->assertSame(0, DB::table('blocks')->count(), 'Po egzekucji został wiersz `blocks`.');

        // Konto po drugiej stronie pary zostaje nietknięte — kasujemy
        // relacje, nie ludzi.
        $this->assertNull($wyzszy->fresh()?->data_erased_at);
    }
}
