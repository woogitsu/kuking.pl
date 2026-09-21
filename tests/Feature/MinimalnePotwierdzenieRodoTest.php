<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\SlownikPotwierdzenRodo;
use App\Models\AuditLogEntry;
use App\Models\User;
use App\Support\NumerSprawy;
use App\Support\NumerZadaniaRodo;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `potwierdzenia_zadan_rodo` — czy tabela naprawdę pilnuje tego, co obiecuje
 * `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C.
 *
 * CZEGO TEN TEST NIE MIERZY, ŻEBY NIE UDAWAŁ, ŻE MIERZY
 * Nie ma dziś kodu, który do tej tabeli pisze — ta tura zakłada schemat,
 * a nie ścieżkę zapisu (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`).
 * Test sprawdza więc BAZĘ: czy ograniczenia, na których opiera się cała
 * minimalizacja, są w PostgreSQL-u, a nie w czyichś dobrych chęciach.
 * Dlatego wiersze wstawia tu `DB::table()`, a nie model — gdyby szedł przez
 * model, mierzyłby regułę w PHP i przechodziłby także wtedy, gdyby CHECK-ów
 * w bazie nie było wcale.
 *
 * `PULAPKI_TESTOW.md` §2 — KAŻDA ODMOWA MA KONTROLĘ DODATNIĄ. Test, który
 * pokazuje wyłącznie, że baza czegoś nie przyjmuje, przechodziłby także na
 * tabeli, która nie przyjmuje NICZEGO.
 */
class MinimalnePotwierdzenieRodoTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_21_100000_utworz_potwierdzenia_zadan_rodo.php';

    /**
     * Kolumny, których w tym rejestrze być NIE MOŻE — ocena §C wymienia je
     * wprost („Nie kopiuj starego profilu, hasha hasła, fotografii ani całej
     * korespondencji"), a `metadata` dokłada ta tura, bo jest tą jedną
     * kolumną, przez którą wszystkie pozostałe wracają bez migracji.
     *
     * @var list<string>
     */
    private const ZAKAZANE_FRAGMENTY_NAZW = [
        'email', 'mail', 'haslo', 'password', 'ip', 'nazwa', 'name',
        'avatar', 'zdjecie', 'photo', 'bio', 'tresc', 'body', 'metadata',
        'profil', 'profile',
    ];

    #[Test]
    public function test_wykonane_usuniecie_nie_moze_wskazywac_na_konto(): void
    {
        // TO JEST NAJWAŻNIEJSZA ASERCJA TEGO PLIKU. Po wykonaniu usunięcia
        // konto jest zanonimizowane, ale NIE skasowane — wskaźnik na nie
        // wiązałby dowód usunięcia danych osobowych ze wszystkim, co po tym
        // koncie w serwisie zostało.
        $basia = $this->user('basia');

        $this->expectException(QueryException::class);

        $this->wstaw([
            'wynik' => 'wykonane',
            'zakres' => User::DELETE_SCOPE_MINIMUM,
            'zakonczono' => '2026-09-20',
            'konto_id' => $basia->getKey(),
        ]);
    }

    #[Test]
    public function test_wykonane_usuniecie_bez_konta_przechodzi(): void
    {
        // KONTROLA DODATNIA numer jeden: bez `konto_id` ten sam wiersz musi
        // wejść. Inaczej test wyżej dowodziłby tylko, że coś jest zepsute.
        $numer = $this->wstaw([
            'wynik' => 'wykonane',
            'zakres' => User::DELETE_SCOPE_MINIMUM,
            'zakonczono' => '2026-09-20',
            'konto_id' => null,
        ]);

        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $numer, 'konto_id' => null]);
    }

    #[Test]
    public function test_zadanie_w_toku_moze_wskazywac_na_konto(): void
    {
        // KONTROLA DODATNIA numer dwa, i zarazem druga połowa rozstrzygnięcia
        // z §C: dopóki żądanie biegnie, konto istnieje w pełni, a bez tego
        // wskaźnika nie dałoby się domknąć sprawy, gdy człowiek ją cofnie.
        $basia = $this->user('basia');

        $numer = $this->wstaw([
            'wynik' => 'w_toku',
            'zakres' => null,
            'zakonczono' => null,
            'konto_id' => $basia->getKey(),
        ]);

        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', [
            'numer' => $numer,
            'konto_id' => $basia->getKey(),
        ]);
    }

    #[Test]
    public function test_cofniete_zadanie_moze_wskazywac_na_konto(): void
    {
        // Spór „nigdy nie prosiłem o usunięcie konta" (ADR §3.1) toczy się
        // z człowiekiem, którego konto ŻYJE — powiązanie jest tu potrzebne
        // i nie ujawnia niczego, czego baza już nie wie.
        $basia = $this->user('basia');

        $numer = $this->wstaw([
            'wynik' => 'cofniete',
            'zakres' => null,
            'zakonczono' => '2026-09-20',
            'konto_id' => $basia->getKey(),
        ]);

        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $numer]);
    }

    #[Test]
    public function test_wykonane_bez_zakresu_nie_wchodzi(): void
    {
        // „Wykonane" bez zakresu nie mówi, CO wykonano — a to jest właśnie
        // ta różnica, o którą toczy się spór (D-022).
        $this->expectException(QueryException::class);

        $this->wstaw([
            'wynik' => 'wykonane',
            'zakres' => null,
            'zakonczono' => '2026-09-20',
        ]);
    }

    #[Test]
    public function test_cofniete_zadanie_nie_moze_podawac_zakresu_wykonania(): void
    {
        // Druga strona tego samego CHECK-a: zakres przy żądaniu, którego nie
        // wykonano, jest zapisem o czynności, której nie było.
        $this->expectException(QueryException::class);

        $this->wstaw([
            'wynik' => 'cofniete',
            'zakres' => User::DELETE_SCOPE_EVERYTHING,
            'zakonczono' => '2026-09-20',
        ]);
    }

    #[Test]
    public function test_sprawa_w_toku_nie_ma_daty_konca_a_zamknieta_ma(): void
    {
        // Wiersz „wykonane" bez `zakonczono` nigdy nie trafiłby pod retencję
        // — bezterminowość wróciłaby przez pustą kolumnę.
        $this->zaOdmowa(fn () => $this->wstaw([
            'wynik' => 'wykonane',
            'zakres' => User::DELETE_SCOPE_MINIMUM,
            'zakonczono' => null,
        ]), 'wykonane bez daty zakończenia');

        // I w drugą stronę: sprawa w toku z datą końca to sprzeczność.
        $this->zaOdmowa(fn () => $this->wstaw([
            'wynik' => 'w_toku',
            'zakres' => null,
            'zakonczono' => '2026-09-20',
        ]), 'w toku z datą zakończenia');
    }

    #[Test]
    public function test_zakonczenie_nie_moze_byc_przed_otrzymaniem(): void
    {
        $this->expectException(QueryException::class);

        $this->wstaw([
            'otrzymano' => '2026-09-20',
            'zakonczono' => '2026-09-19',
            'wynik' => 'cofniete',
        ]);
    }

    #[Test]
    public function test_wstrzymanie_kasowania_wymaga_wskazanej_sprawy(): void
    {
        // Wstrzymanie bez wskazanej sprawy to retencja bezterminowa pisana
        // inną kolumną — dokładnie to, co ta tabela usuwa.
        $this->zaOdmowa(fn () => $this->wstaw([
            'wynik' => 'cofniete',
            'zakonczono' => '2026-09-20',
            'wstrzymanie_do' => '2030-01-01',
            'wstrzymanie_sprawa' => null,
        ]), 'wstrzymanie bez sprawy');

        // KONTROLA DODATNIA: para przechodzi.
        $numer = $this->wstaw([
            'wynik' => 'cofniete',
            'zakonczono' => '2026-09-20',
            'wstrzymanie_do' => '2030-01-01',
            'wstrzymanie_sprawa' => 'Sygn. akt I C 123/29',
        ]);

        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $numer]);
    }

    #[Test]
    public function test_numer_jest_unikalny(): void
    {
        $numer = NumerZadaniaRodo::wygeneruj();

        $this->wstaw(['numer' => $numer, 'wynik' => 'w_toku', 'zakonczono' => null]);

        $this->expectException(QueryException::class);

        $this->wstaw(['numer' => $numer, 'wynik' => 'w_toku', 'zakonczono' => null]);
    }

    #[Test]
    public function test_check_w_bazie_zna_ten_sam_wzor_co_stala_w_php(): void
    {
        // Wzór jest w bazie ZAMROŻONY w dniu migracji, a `NumerSprawy::ALFABET`
        // liczy się od nowa przy każdym uruchomieniu PHP. Rozjazd znaczy, że
        // `wygeneruj()` prędzej czy później wyprodukuje numer, którego baza
        // nie przyjmie — czyli 500 przy KAŻDYM nowym żądaniu RODO.
        $definicja = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
            ['potwierdzenia_zadan_rodo_numer_check'],
        );

        $this->assertNotNull($definicja, 'CHECK na numerze w ogóle nie istnieje — migracja go nie założyła.');

        foreach (mb_str_split(NumerSprawy::ALFABET) as $znak) {
            $this->assertStringContainsString(
                $znak,
                (string) $definicja->def,
                'Alfabet w PHP ma znak `'.$znak.'`, którego zamrożony CHECK w bazie nie zna.',
            );
        }

        // KONTROLA DODATNIA: świeżo wylosowany numer musi przejść przez ten
        // CHECK, a numer z mylącym znakiem — nie.
        $numer = $this->wstaw(['numer' => NumerZadaniaRodo::wygeneruj(), 'wynik' => 'w_toku', 'zakonczono' => null]);
        $this->assertDatabaseHas('potwierdzenia_zadan_rodo', ['numer' => $numer]);

        $this->zaOdmowa(
            fn () => $this->wstaw(['numer' => 'RODO-0000-1111-IIII', 'wynik' => 'w_toku', 'zakonczono' => null]),
            'numer ze znakami mylącymi',
        );
    }

    #[Test]
    public function test_numer_rodo_nie_jest_numerem_zgloszenia_moderacyjnego(): void
    {
        // Dwa rejestry, dwa przedrostki. Człowiek z kartką musi wiedzieć,
        // o który pyta.
        $numer = NumerZadaniaRodo::wygeneruj();

        $this->assertTrue(NumerZadaniaRodo::poprawny($numer));
        $this->assertFalse(NumerSprawy::poprawny($numer), 'Numer RODO przechodzi jako numer zgłoszenia moderacyjnego.');
        $this->assertFalse(NumerZadaniaRodo::poprawny(NumerSprawy::wygeneruj()));
    }

    #[Test]
    public function test_tabela_nie_trzyma_zadnych_danych_z_profilu(): void
    {
        $kolumny = $this->kolumny();

        // KONTROLA DODATNIA PRZED SKANEM (`PULAPKI_TESTOW.md` §2): skan,
        // który nie widzi żadnej kolumny, przeszedłby na nieistniejącej
        // tabeli i na literówce w nazwie.
        $this->assertContains('numer', $kolumny, 'Skan nie widzi kolumn tej tabeli — zła nazwa tabeli?');
        $this->assertContains('wersja_procedury', $kolumny);
        $this->assertGreaterThanOrEqual(13, count($kolumny));

        $znalezione = [];

        foreach ($kolumny as $kolumna) {
            foreach (self::ZAKAZANE_FRAGMENTY_NAZW as $fragment) {
                if (Str::contains($kolumna, $fragment)) {
                    $znalezione[] = $kolumna.' (zawiera „'.$fragment.'")';
                }
            }
        }

        $this->assertSame(
            [],
            $znalezione,
            "W rejestrze potwierdzeń pojawiła się kolumna wyglądająca na dane osobowe:\n  "
            .implode("\n  ", $znalezione)
            ."\n\nOcena zewnętrzna §C mówi wprost: nie kopiuj starego profilu, hasha hasła, "
            .'fotografii ani całej korespondencji. Jeśli nowa kolumna naprawdę jest potrzebna, '
            .'najpierw dopisz jej uzasadnienie w `docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`.',
        );
    }

    #[Test]
    public function test_lista_nigdy_nie_kasuj_jest_nienaruszona(): void
    {
        // Ta gałąź NIE skraca retencji istniejących wpisów i NIE dodaje
        // klucza configu sterującego tą listą — potwierdzenie ma ją
        // ZASTĄPIĆ dopiero po krokach właściciela, a nie przy okazji.
        $this->assertSame(
            ['account.data_erased', 'account.delete_requested', 'account.delete_cancelled'],
            AuditLogEntry::NIGDY_NIE_KASUJ,
        );

        $this->assertNull(
            config('kuking.audit_log.nigdy_nie_kasuj'),
            'W configu pojawił się klucz sterujący listą NIGDY_NIE_KASUJ — `config/kuking.php` '
            .'tłumaczy, dlaczego tej listy tam nie ma.',
        );
    }

    #[Test]
    public function test_cofniecie_migracji_odmawia_gdy_stoi_zamknieta_sprawa(): void
    {
        $this->wstaw(['wynik' => 'cofniete', 'zakonczono' => '2026-09-20']);

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba zamkniętych potwierdzeń obsługi żądań RODO: 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
            $this->assertSame(1, DB::table('potwierdzenia_zadan_rodo')->count(), 'Odmowa zdążyła skasować wiersz.');

            return;
        }

        $this->fail('Cofnięcie przeszło, mimo że w tabeli stoi dowód obsługi żądania RODO.');
    }

    #[Test]
    public function test_cofniecie_migracji_przechodzi_na_pustej_tabeli(): void
    {
        // KONTROLA DODATNIA do odmowy wyżej: zablokowanie rollbacku na
        // zawsze jest błędem tej samej wagi w drugą stronę (AGENTS.md §6).
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame([], $this->kolumny(), 'Tabela została po wycofaniu migracji.');

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertContains('numer', $this->kolumny());
    }

    #[Test]
    public function test_cofniecie_migracji_przechodzi_gdy_sprawa_jest_w_toku(): void
    {
        // Druga kontrola dodatnia: odmowa ma być WĄSKA. Sprawa w toku żyje
        // nadal w `users.delete_requested_at`, więc nie jest dowodem, którego
        // nie ma gdzie indziej.
        $this->wstaw(['wynik' => 'w_toku', 'zakonczono' => null]);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame([], $this->kolumny());

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
    }

    /**
     * Wstawia wiersz i zwraca jego numer. Domyślne wartości są POPRAWNE —
     * każdy test psuje dokładnie jedną rzecz, więc widać, która z nich
     * odbija się od bazy.
     *
     * @param  array<string, mixed>  $zmiany
     */
    private function wstaw(array $zmiany = []): string
    {
        $wiersz = array_merge([
            'id' => (string) Str::uuid(),
            'numer' => NumerZadaniaRodo::wygeneruj(),
            'rodzaj' => SlownikPotwierdzenRodo::RODZAJE[0],
            'wynik' => 'wykonane',
            'zakres' => User::DELETE_SCOPE_MINIMUM,
            'otrzymano' => '2026-08-20',
            'zakonczono' => '2026-09-20',
            'wersja_procedury' => SlownikPotwierdzenRodo::WERSJA_PROCEDURY_DZIS,
            'wyjatki' => 'Treści zachowane pod zanonimizowanym autorem (COMPLIANCE.md §2).',
            'konto_id' => null,
            'wstrzymanie_do' => null,
            'wstrzymanie_sprawa' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $zmiany);

        // Zakres istnieje dokładnie wtedy, gdy coś wykonano — więc domyślnie
        // znika razem ze zmianą wyniku. Test, który CHCE złamać tę regułę,
        // podaje `zakres` wprost i wtedy ta linia go nie rusza.
        if ($wiersz['wynik'] !== 'wykonane' && ! array_key_exists('zakres', $zmiany)) {
            $wiersz['zakres'] = null;
        }

        DB::table('potwierdzenia_zadan_rodo')->insert($wiersz);

        return (string) $wiersz['numer'];
    }

    /**
     * Nazwy kolumn tabeli — pusta tablica, gdy tabeli nie ma.
     *
     * @return list<string>
     */
    private function kolumny(): array
    {
        return array_map(
            static fn (object $wiersz): string => (string) $wiersz->column_name,
            DB::select(
                'SELECT column_name FROM information_schema.columns WHERE table_name = ? ORDER BY column_name',
                ['potwierdzenia_zadan_rodo'],
            ),
        );
    }

    /**
     * Wywołuje domknięcie i oczekuje odmowy BAZY, nie PHP.
     *
     * Osobny pomocnik zamiast `expectException`, bo kilka testów sprawdza
     * dwie strony tego samego warunku w jednym przebiegu — a `expectException`
     * kończy test na pierwszym wyjątku i druga strona nigdy by się nie
     * wykonała (`PULAPKI_TESTOW.md` §2: asercja, której nie widać, nie
     * istnieje).
     */
    private function zaOdmowa(callable $co, string $opis): void
    {
        $przed = DB::table('potwierdzenia_zadan_rodo')->count();

        try {
            // `DB::transaction()`, a nie gołe wywołanie — i to nie jest
            // ozdoba. `RefreshDatabase` trzyma cały test w JEDNEJ transakcji,
            // więc nieudany `INSERT` zatruwa ją całą: każde następne
            // zapytanie tym samym połączeniem odbija się o „current
            // transaction is aborted", łącznie z kontrolą dodatnią niżej.
            // Laravel w trakcie transakcji otwiera SAVEPOINT, więc konflikt
            // cofa TYLKO tę jedną wstawkę (ta sama pułapka i to samo
            // lekarstwo co w `DataSettingsController`).
            DB::transaction($co);
        } catch (QueryException) {
            // Odmowa to za mało — trzeba jeszcze sprawdzić, że SAVEPOINT
            // naprawdę cofnął wstawkę. Bez tej asercji metoda nie robiłaby
            // żadnego pomiaru i PHPUnit słusznie nazywałby test „risky".
            $this->assertSame(
                $przed,
                DB::table('potwierdzenia_zadan_rodo')->count(),
                'Baza odmówiła, ale wiersz mimo to został: '.$opis.'.',
            );

            return;
        }

        $this->fail('Baza przyjęła wiersz, którego przyjąć nie może: '.$opis.'.');
    }
}
