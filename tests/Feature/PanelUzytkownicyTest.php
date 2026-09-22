<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Konta użytkowników w panelu moderacji — `/admin/uzytkownicy`.
 *
 * Zgłoszenie właściciela: „gdzie będę mógł zarządzać użytkownikami (lista
 * użytkowników, data rejestracji itp.)".
 *
 * CZEGO TE TESTY PILNUJĄ NAJMOCNIEJ — bo to są rzeczy, które regresja cofa
 * najciszej, a skutek widać dopiero wtedy, gdy jest za późno:
 *
 *  1. że ekran NIC NIE ZMIENIA — żadnego formularza zmieniającego rolę
 *     ani stan konta (AGENTS.md §7, D-039);
 *  2. że wgląd w dane jednej osoby ZOSTAWIA WPIS W BAZIE, a nie tylko
 *     wywołanie w kodzie (docs/INSPIRATION_DECISIONS.md poz. 3.2);
 *  3. że przy tysiącach kont liczba zapytań NIE ROŚNIE z liczbą wierszy —
 *     mierzone, nie deklarowane;
 *  4. że konto zanonimizowane nie pokazuje danych, których już nie ma (D-022).
 */
class PanelUzytkownicyTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------
    // Kto tu wchodzi
    // ---------------------------------------------------------------------

    public function test_moderator_z_2fa_widzi_liste_z_prawdziwymi_danymi(): void
    {
        $halina = $this->user('halinka', [
            'display_name' => 'Halina Kowalska',
            'created_at' => now()->subDays(40),
        ]);
        Post::factory()->count(3)->create(['author_id' => $halina->getKey()]);

        $zawieszony = $this->user('zenek', ['display_name' => 'Zenon Nowak']);
        $zawieszony->suspend(now()->addDays(7));

        $html = $this->actingAs($this->moderator())
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Halina Kowalska')
            ->assertSee('halinka')
            ->assertSee('Zenon Nowak')
            // DATA REJESTRACJI — o to pytał wprost właściciel. Przez
            // `App\Support\Czas`, bo ekran pokazuje datę w strefie CZŁOWIEKA,
            // a kolumna trzyma ją w UTC — surowe `translatedFormat()` w teście
            // wypadałoby o dzień obok przy każdym przebiegu po 22:00.
            ->assertSee(Czas::data($halina->created_at, 'j F Y'))
            // STAN KONTA — słowem, nie samym kolorem (WCAG 1.4.1).
            ->assertSee('Zawieszone')
            ->assertSee('Aktywne')
            ->getContent();

        // LICZBA WPISÓW JEST PRAWDZIWA, a nie zerem z pustej kolumny.
        $this->assertMatchesRegularExpression(
            '~halinka.*?<td>3</td>~s',
            (string) $html,
            'W wierszu Haliny nie widać jej trzech wpisów.',
        );
    }

    public function test_zwykly_uzytkownik_dostaje_404_nie_403(): void
    {
        // 404, nie 403 — ten sam powód co przy reszcie panelu: istnienie
        // panelu moderacji nie jest informacją wartą potwierdzania
        // (`EnsureUserIsModerator`).
        $this->actingAs($this->user('basia'))
            ->get(route('admin.users'))
            ->assertNotFound();

        $ktos = $this->user('ktos');

        $this->actingAs($this->user('basia2'))
            ->get(route('admin.users.show', $ktos))
            ->assertNotFound();
    }

    /**
     * GOŚĆ NIE DOSTAJE 404, TYLKO PRZEKIEROWANIE NA LOGOWANIE — i to jest
     * poprawne, nie luka.
     *
     * Pierwszym middleware w tej grupie jest `auth`, a on przekierowuje
     * niezalogowanego na `/login`, zanim `EnsureUserIsModerator` w ogóle
     * zostanie zawołany. Gość nie dowiaduje się z tego niczego o panelu:
     * dokładnie tę samą odpowiedź dostaje na każdej stronie za logowaniem.
     * Ta sama pułapka jest opisana w `TagiPromowaneAdminTest` — tam ktoś
     * napisał „gość dostaje 404", bo `actingAs()` z wcześniejszej linijki
     * dalej działało.
     */
    public function test_gosc_nie_widzi_listy(): void
    {
        $this->get(route('admin.users'))->assertRedirect(route('login'));

        $this->get(route('admin.users'))->assertDontSee('Halina Kowalska');
    }

    public function test_moderator_bez_2fa_dostaje_ekran_wymagane_2fa_zamiast_listy(): void
    {
        // Świadomie NIE przez `$this->moderator()` — ta metoda daje konto
        // z potwierdzonym 2FA, bo taki jest stan zgodny z produkcją.
        $moderator = $this->user('moderatorbez2fa', ['role' => User::ROLE_MODERATOR]);
        $this->user('szukanaosoba', ['display_name' => 'Halina Kowalska']);

        $this->actingAs($moderator)
            ->get(route('admin.users'))
            ->assertForbidden()
            ->assertSee('Ten panel wymaga weryfikacji dwuetapowej')
            ->assertDontSee('Halina Kowalska');
    }

    // ---------------------------------------------------------------------
    // Szukanie, filtry, sortowanie, stronicowanie — wszystko bez JavaScriptu
    // ---------------------------------------------------------------------

    public function test_wyszukiwanie_naprawde_zaweza_wynik(): void
    {
        // Szukamy także po e-mailu: losowy adres mógł zawierać „kowalska”.
        $this->user('halinka', ['display_name' => 'Halina Kowalska', 'email' => 'halina@example.test']);
        $this->user('zenek', ['display_name' => 'Zenon Nowak', 'email' => 'zenon@example.test']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['szukaj' => 'kowalska']))
            ->assertOk()
            ->assertSee('Halina Kowalska')
            ->assertDontSee('Zenon Nowak');
    }

    public function test_wyszukiwanie_znajduje_po_adresie_email_i_po_nazwie_konta(): void
    {
        $this->user('halinka', ['display_name' => 'Halina Kowalska', 'email' => 'halina@wp.pl']);
        $this->user('zenek', ['display_name' => 'Zenon Nowak', 'email' => 'zenon@onet.pl']);

        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('admin.users', ['szukaj' => 'halina@wp']))
            ->assertOk()
            ->assertSee('Halina Kowalska')
            ->assertDontSee('Zenon Nowak');

        $this->actingAs($moderator)
            ->get(route('admin.users', ['szukaj' => 'zenek']))
            ->assertOk()
            ->assertSee('Zenon Nowak')
            ->assertDontSee('Halina Kowalska');
    }

    /**
     * Polskie znaki w jedną i w drugą stronę — „Żaneta" ma się znaleźć po
     * wpisaniu „zaneta" i odwrotnie. Bez tego wyszukiwanie po nazwisku
     * z „ł" albo „ż" byłoby ozdobą, a nie narzędziem.
     */
    public function test_wyszukiwanie_nie_potyka_sie_o_polskie_znaki(): void
    {
        $this->user('zanetka', ['display_name' => 'Żaneta Wójcik']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['szukaj' => 'zaneta wojcik']))
            ->assertOk()
            ->assertSee('Żaneta Wójcik', false);
    }

    /**
     * `%` w `LIKE` znaczy „cokolwiek". Bez uciekania go jeden znak wpisany
     * w pole szukania oddawałby CAŁĄ tabelę kont — czyli dokładnie
     * odwrotność tego, po co to pole jest.
     */
    public function test_znak_procenta_nie_oddaje_wszystkich_kont(): void
    {
        $this->user('halinka', ['display_name' => 'Halina Kowalska']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['szukaj' => '%']))
            ->assertOk()
            ->assertDontSee('Halina Kowalska');
    }

    public function test_stronicowanie_dziala_zwyklym_odnosnikiem(): void
    {
        for ($i = 0; $i < 29; $i++) {
            $this->user('konto'.$i, ['created_at' => now()->subDays(30 - $i)]);
        }

        $moderator = $this->moderator();

        // Domyślnie najnowsze na górze, 25 na stronę — najstarsze konto
        // („konto0") musi wypaść na drugą stronę.
        $this->actingAs($moderator)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertDontSee('konto0<')
            ->assertSee('konto28');

        $this->actingAs($moderator)
            ->get(route('admin.users', ['page' => 2]))
            ->assertOk()
            ->assertSee('konto0')
            ->assertDontSee('konto28');
    }

    /** Szukana fraza przeżywa przejście na drugą stronę wyników. */
    public function test_stronicowanie_zachowuje_szukana_fraze(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->user('kowal'.$i, ['display_name' => 'Kowalski numer '.$i]);
        }
        $this->user('zenek', ['display_name' => 'Zenon Nowak']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['szukaj' => 'kowalski', 'page' => 2]))
            ->assertOk()
            ->assertSee('Kowalski numer')
            ->assertDontSee('Zenon Nowak');
    }

    public function test_filtr_po_dacie_rejestracji_zaweza_do_wskazanych_dni(): void
    {
        $this->user('dawna', ['display_name' => 'Dawna Osoba', 'created_at' => now()->subDays(30)]);
        $this->user('dzisiejsza', ['display_name' => 'Dzisiejsza Osoba']);

        $dzis = now()->setTimezone(Czas::strefa())->format('Y-m-d');

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['od' => $dzis]))
            ->assertOk()
            ->assertSee('Dzisiejsza Osoba')
            ->assertDontSee('Dawna Osoba');
    }

    public function test_filtr_bez_wpisow_pokazuje_osoby_do_powitania(): void
    {
        $piszaca = $this->user('piszaca', ['display_name' => 'Osoba Piszaca']);
        Post::factory()->create(['author_id' => $piszaca->getKey()]);
        $this->user('milczaca', ['display_name' => 'Osoba Milczaca']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['bez_wpisow' => '1']))
            ->assertOk()
            ->assertSee('Osoba Milczaca')
            ->assertDontSee('Osoba Piszaca');
    }

    public function test_filtr_stanu_konta_pokazuje_tylko_zawieszone(): void
    {
        $zawieszony = $this->user('zenek', ['display_name' => 'Zenon Nowak']);
        $zawieszony->suspend();
        $this->user('halinka', ['display_name' => 'Halina Kowalska']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['status' => User::STATUS_SUSPENDED]))
            ->assertOk()
            ->assertSee('Zenon Nowak')
            ->assertDontSee('Halina Kowalska');
    }

    /**
     * DOMYŚLNIE NIE JEST TO RANKING NAJAKTYWNIEJSZYCH (AGENTS.md §12).
     *
     * Lista otwarta bez parametrów ma być ułożona po dacie rejestracji, a nie
     * po liczbie wpisów malejąco — inaczej sam zrzut ekranu z tego panelu jest
     * publicznym rankingiem użytkowników, którego nie budujemy.
     */
    public function test_domyslna_kolejnosc_to_data_rejestracji_a_nie_liczba_wpisow(): void
    {
        $pisarz = $this->user('pisarz', ['display_name' => 'Osoba Plodna', 'created_at' => now()->subDays(30)]);
        Post::factory()->count(5)->create(['author_id' => $pisarz->getKey()]);

        $this->user('nowy', ['display_name' => 'Osoba Nowa']);

        $html = (string) $this->actingAs($this->moderator())
            ->get(route('admin.users'))
            ->assertOk()
            ->getContent();

        $this->assertLessThan(
            strpos($html, 'Osoba Plodna'),
            strpos($html, 'Osoba Nowa'),
            'Domyślnie na górze stoi osoba z największą liczbą wpisów — to jest ranking, '
            .'a rankingów użytkowników nie budujemy (AGENTS.md §12).',
        );

        $this->assertStringContainsString('aria-sort="descending"', $html);
        $this->assertStringNotContainsString('Wpisy ↓', $html);
    }

    /**
     * `?sortuj=` idzie wprost do `ORDER BY`, więc wartość spoza białej listy
     * musi wrócić do domyślnej, a nie do bazy.
     */
    public function test_podrobione_sortowanie_nie_dociera_do_bazy(): void
    {
        $this->user('halinka', ['display_name' => 'Halina Kowalska']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['sortuj' => 'users.email); drop table users --', 'kierunek' => 'losowo']))
            ->assertOk()
            ->assertSee('Halina Kowalska');

        $this->assertDatabaseCount('users', 2);
    }

    // ---------------------------------------------------------------------
    // Karta jednego konta
    // ---------------------------------------------------------------------

    public function test_karta_konta_pokazuje_szczegoly_i_historie_decyzji(): void
    {
        $moderator = $this->moderator();
        $halina = $this->user('halinka', [
            'display_name' => 'Halina Kowalska',
            'email' => 'halina@wp.pl',
            'created_at' => now()->subDays(90),
        ]);

        ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'user',
            'target_id' => $halina->getKey(),
            'subject_user_id' => $halina->getKey(),
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'spam',
            'user_message' => 'Prosimy nie wklejać linków do sklepu.',
        ]);

        $this->actingAs($moderator)
            ->get(route('admin.users.show', $halina))
            ->assertOk()
            ->assertSee('Halina Kowalska')
            // PEŁNY ADRES, w odróżnieniu od listy — tu moderator zajmuje się
            // jedną osobą i wejście zostawia ślad w dzienniku.
            ->assertSee('halina@wp.pl')
            ->assertSee(Czas::data($halina->created_at, 'j F Y'))
            ->assertSee('Ostrzeżenie dla autora')
            ->assertSee('Prosimy nie wklejać linków do sklepu.');
    }

    public function test_karta_konta_bez_decyzji_mowi_o_tym_wprost(): void
    {
        $this->actingAs($this->moderator())
            ->get(route('admin.users.show', $this->user('czysta')))
            ->assertOk()
            ->assertSee('Żadnej decyzji', false);
    }

    // ---------------------------------------------------------------------
    // Dane osobowe: maska na liście, dziennik przy karcie
    // ---------------------------------------------------------------------

    public function test_lista_pokazuje_adres_email_w_masce_a_nie_w_calosci(): void
    {
        $this->user('halinka', ['display_name' => 'Halina Kowalska', 'email' => 'halina@wp.pl']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('h***@wp.pl')
            ->assertDontSee('halina@wp.pl');
    }

    /**
     * TEST NA WPIS W BAZIE, nie na obecność wywołania w kodzie.
     */
    public function test_wglad_w_karte_konta_zapisuje_wpis_w_audit_log(): void
    {
        $moderator = $this->moderator();
        $halina = $this->user('halinka');

        $this->actingAs($moderator)->get(route('admin.users.show', $halina))->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'admin.user_viewed',
            'actor_id' => $moderator->getKey(),
            'subject_type' => 'User',
            'subject_id' => $halina->getKey(),
        ]);
    }

    /**
     * KAŻDE wejście, nie tylko pierwsze — dziennik ma odpowiadać także na
     * pytanie „ile razy ktoś do mojego konta wracał".
     */
    public function test_drugie_wejscie_na_te_sama_karte_tez_zostawia_wpis(): void
    {
        $moderator = $this->moderator();
        $halina = $this->user('halinka');

        $this->actingAs($moderator)->get(route('admin.users.show', $halina))->assertOk();
        $this->actingAs($moderator)->get(route('admin.users.show', $halina))->assertOk();

        $this->assertSame(2, DB::table('audit_log')
            ->where('action', 'admin.user_viewed')
            ->where('subject_id', $halina->getKey())
            ->count());
    }

    /**
     * LISTA NIE LOGUJE — i to jest decyzja, nie przeoczenie.
     *
     * Przy tysiącach kont moderator wchodzi na listę kilkanaście razy dziennie
     * po drodze do czegoś innego, a widzi na niej adresy w masce. Wpisy z niej
     * zalałyby dziennik tak, że prawdziwe wejścia na kartę utonęłyby w szumie —
     * czyli odebrałyby wartość dokładnie temu, po co decyzja 3.2 powstała.
     * Uzasadnienie pełne: nagłówek `UzytkownicyController`.
     */
    public function test_samo_otwarcie_listy_nie_zasmieca_dziennika(): void
    {
        $this->user('halinka');

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['szukaj' => 'halinka']))
            ->assertOk();

        $this->assertDatabaseMissing('audit_log', ['action' => 'admin.user_viewed']);
        $this->assertDatabaseMissing('audit_log', ['action' => 'admin.users_listed']);
    }

    // ---------------------------------------------------------------------
    // Konto zanonimizowane (D-022)
    // ---------------------------------------------------------------------

    public function test_konto_wymazane_nie_pokazuje_danych_ktorych_juz_nie_ma(): void
    {
        $wymazane = $this->user('bylaosoba', ['display_name' => 'Była Osoba', 'email' => 'byla@wp.pl']);
        // Stan po `EraseAccountData`: `markDataErased()` to ta sama, nazwana
        // metoda, której tamta akcja używa (CHECK w bazie pilnuje, że statusu
        // `erased` nie da się ustawić bez `data_erased_at`). Sam przebieg
        // anonimizacji ma własne testy — tutaj sprawdzamy, czy WIDOK
        // respektuje wynik.
        $wymazane->markDataErased();

        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('admin.users'))
            ->assertOk()
            ->assertSee('Usunięte')
            ->assertSee('dane wymazane')
            ->assertDontSee('byla@wp.pl')
            ->assertDontSee('h***@wp.pl');

        $html = (string) $this->actingAs($moderator)
            ->get(route('admin.users.show', $wymazane))
            ->assertOk()
            ->assertSee('wymazany razem z kontem')
            ->assertDontSee('byla@wp.pl')
            ->getContent();

        // Konto zamknięte nie jest widoczne jako osoba, więc odnośnik
        // do profilu publicznego prowadziłby prosto w 403.
        $this->assertStringNotContainsString('href="'.route('profile.show', 'bylaosoba').'"', $html);
    }

    // ---------------------------------------------------------------------
    // Ekran jest DO PATRZENIA
    // ---------------------------------------------------------------------

    /**
     * ANI JEDNEGO FORMULARZA ZMIENIAJĄCEGO KONTO.
     *
     * Rolę nadaje wyłącznie `kuking:nadaj-role` z powłoki (D-039) — ekran
     * w przeglądarce znaczyłby, że przejęcie jednego konta administratora
     * wystarcza, żeby zrobić administratorów z kolejnych. Zawieszenie
     * i blokada mają iść przez zgłoszenie, bo tam zapada POWÓD i od tamtej
     * decyzji przysługuje odwołanie (DSA art. 20).
     *
     * Test patrzy na HTML, a nie na dobre intencje: jedyny formularz na tej
     * stronie to `GET` z wyszukiwaniem.
     */
    public function test_ekran_nie_ma_zadnego_formularza_zmieniajacego_konto(): void
    {
        $halina = $this->user('halinka');
        $moderator = $this->moderator();

        foreach ([route('admin.users'), route('admin.users.show', $halina)] as $adres) {
            $html = (string) $this->actingAs($moderator)->get($adres)->assertOk()->getContent();

            // Wycinamy menu boczne i stopkę — tam stoi formularz wylogowania,
            // który z tym ekranem nie ma nic wspólnego.
            $tresc = $this->wytnijTresc($html);

            $this->assertStringNotContainsString(
                'method="POST"',
                $tresc,
                'Na '.$adres.' pojawił się formularz zapisujący. Ten ekran jest do patrzenia — '
                .'rolę nadaje `kuking:nadaj-role`, a karę zgłoszenie.',
            );
            $this->assertStringNotContainsString('name="role"', $tresc);
            $this->assertStringNotContainsString('name="status"><option', $tresc);
        }
    }

    // ---------------------------------------------------------------------
    // Skala: setki i tysiące kont
    // ---------------------------------------------------------------------

    /**
     * LICZBA ZAPYTAŃ NIE ROŚNIE Z LICZBĄ WIERSZY NA STRONIE.
     *
     * Mierzymy dwa przebiegi tego samego ekranu: raz przy pięciu kontach
     * (pięć wierszy), raz przy trzydziestu (pełna strona, 25 wierszy).
     * Gdyby profil, liczba wpisów albo cokolwiek innego szło w pętli po
     * wierszach, drugi przebieg byłby o dwadzieścia zapytań droższy — przy
     * tysiącach kont to jest różnica między ekranem, który się otwiera,
     * a takim, który się nie otwiera.
     *
     * Porównanie DWÓCH POMIARÓW, a nie stały próg: liczba zapytań layoutu
     * zmienia się przy każdej zmianie w menu i próg trzeba by wtedy poprawiać
     * w teście, który o menu nic nie wie.
     */
    public function test_liczba_zapytan_nie_rosnie_z_liczba_kont(): void
    {
        $moderator = $this->moderator();

        for ($i = 0; $i < 4; $i++) {
            $this->user('male'.$i);
        }

        $malo = $this->policzZapytania($moderator);

        for ($i = 0; $i < 25; $i++) {
            $this->user('duze'.$i);
        }

        $duzo = $this->policzZapytania($moderator);

        $this->assertSame(
            $malo,
            $duzo,
            "Strona z 25 wierszami kosztuje {$duzo} zapytań, a z pięcioma {$malo}. "
            .'Coś chodzi w pętli po wierszach (N+1) — przy tysiącach kont ten ekran przestanie się otwierać.',
        );
    }

    /**
     * INDEKSY Z MIGRACJI ISTNIEJĄ I DAJĄ SIĘ UŻYĆ DO TEGO, PO CO POWSTAŁY.
     *
     * Sama obecność indeksu nic nie znaczy — to jest pułapka opisana
     * w `docs/DATABASE.md`: indeks na `email` NIE zostałby użyty przez warunek
     * pytający o `kuking_normalize(email)`, bo to jest inne wyrażenie.
     * Zapytanie i indeks muszą pytać o dokładnie to samo, więc pytamy o to
     * planer, a nie siebie.
     *
     * `enable_seqscan = off` zamiast sypania tysiąca kont: przy pustej tabeli
     * skan sekwencyjny jest zawsze najtańszy, a nam chodzi o to, CZY indeks
     * w ogóle nadaje się do tego warunku.
     */
    public function test_indeksy_listy_kont_sa_uzywalne_dla_swoich_zapytan(): void
    {
        $istniejace = collect(DB::select("select indexname from pg_indexes where tablename = 'users'"))
            ->pluck('indexname')
            ->all();

        $this->assertContains('users_created_at_idx', $istniejace);
        $this->assertContains('users_email_trgm_idx', $istniejace);

        DB::statement('set enable_seqscan = off');

        try {
            $poDacie = $this->plan('select id from users order by created_at desc limit 25');
            $this->assertStringContainsString(
                'users_created_at_idx',
                $poDacie,
                'Sortowanie po dacie rejestracji nie umie skorzystać z indeksu.',
            );

            $poAdresie = $this->plan(
                "select id from users where public.kuking_normalize(email) like '%wp.pl%'",
            );
            $this->assertStringContainsString(
                'users_email_trgm_idx',
                $poAdresie,
                'Szukanie po adresie e-mail nie umie skorzystać z indeksu — najpewniej zapytanie '
                .'i indeks pytają o różne wyrażenia (docs/DATABASE.md, kuking_normalize).',
            );
        } finally {
            DB::statement('set enable_seqscan = on');
        }
    }

    /** Plan zapytania jako tekst — wystarczy nam nazwa użytego indeksu. */
    private function plan(string $sql): string
    {
        return collect(DB::select('EXPLAIN '.$sql))
            ->map(fn (object $wiersz): string => (string) $wiersz->{'QUERY PLAN'})
            ->implode("\n");
    }

    /** Ile zapytań kosztuje jedno otwarcie listy. */
    private function policzZapytania(User $moderator): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($moderator)->get(route('admin.users'))->assertOk();

        $ile = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $ile;
    }

    /**
     * Treść strony bez menu bocznego i stopki.
     *
     * `<main>` jest w `components/layout.blade.php` jedynym miejscem, w którym
     * stoi to, co dał ekran — reszta należy do szkieletu i ma własne testy.
     */
    private function wytnijTresc(string $html): string
    {
        $start = strpos($html, '<main');
        $this->assertNotFalse($start, 'Brak `<main>` na stronie.');

        $koniec = strpos($html, '</main>', $start);
        $this->assertNotFalse($koniec, '`<main>` nie jest domknięty.');

        return substr($html, $start, $koniec - $start);
    }
}
