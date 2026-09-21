<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWpisyAudytu;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Termin dla kategorii dowodowych `AuditLogEntry::NIGDY_NIE_KASUJ`
 * (RODO art. 5 ust. 1 lit. e, `docs/decyzje/OCENA_RETENCJI_ZEWNETRZNA.md` §C/§D).
 *
 * CO TEN PLIK PILNUJE — DWIE RZECZY NARAZ, I OBIE SĄ WAŻNE:
 *
 * 1. DOMYŚLNIE NIC SIĘ NIE ZMIENIA. Bez jawnej decyzji właściciela mechanizm
 *    jest martwy i nie kasuje ani jednego wiersza, choćby miał dwadzieścia
 *    lat. To jest zabezpieczenie przed nieodwracalnym skasowaniem dowodu
 *    wykonania RODO przez samo scalenie tej gałęzi.
 * 2. PO WŁĄCZENIU NAPRAWDĘ KASUJE. Gdyby mechanizm nie działał, punkt 1
 *    przechodziłby sam z siebie — to jest ta sama pułapka co „skan, który nie
 *    znajduje żadnego pliku, przechodzi" (ZASADY_FLOTY.md).
 */
class RetencjaKategoriiDowodowychAudytuTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(string $action, ?User $podmiot, \DateTimeInterface|string $createdAt): AuditLogEntry
    {
        $wpis = AuditLogEntry::record($action, subject: $podmiot, metadata: ['test' => true]);

        DB::table('audit_log')->where('id', $wpis->getKey())->update(['created_at' => $createdAt]);

        return $wpis->refresh();
    }

    /**
     * STAN SPRZED ZMIANY, ZABETONOWANY: brak konfiguracji = brak kasowania.
     */
    public function test_bez_decyzji_wlasciciela_kategorie_dowodowe_nie_znikaja_nigdy(): void
    {
        $konto = User::factory()->create();
        $stary = $this->wpis('account.data_erased', $konto, now()->subYears(20));

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe(null);

        $this->assertFalse($wynik['wlaczone']);
        $this->assertSame(0, $wynik['skasowano']);
        $this->assertDatabaseHas('audit_log', ['id' => $stary->getKey()]);
    }

    /**
     * Zero i liczba ujemna to NIE jest „kasuj wszystko" — to jest „wyłączone".
     * Bez tej asercji literówka w zmiennej środowiskowej (`0`) kasowałaby
     * cały dowód RODO przy najbliższym nocnym przebiegu.
     */
    public function test_zero_i_liczba_ujemna_znacza_wylaczone_a_nie_kasuj_wszystko(): void
    {
        $konto = User::factory()->create();
        $stary = $this->wpis('account.data_erased', $konto, now()->subYears(20));

        foreach ([0, -1, -36] as $wartosc) {
            $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe($wartosc);

            $this->assertFalse($wynik['wlaczone'], "wartość {$wartosc} nie powinna niczego włączać");
            $this->assertSame(0, $wynik['skasowano']);
        }

        $this->assertDatabaseHas('audit_log', ['id' => $stary->getKey()]);
    }

    /**
     * KONTROLA DODATNIA dla poprzednich testów: po włączeniu mechanizm
     * naprawdę kasuje, a próg jest progiem — wpis tuż po granicy zostaje.
     */
    public function test_po_wlaczeniu_stara_sprawa_znika_a_swiezsza_zostaje(): void
    {
        $stareKonto = User::factory()->create();
        $swiezeKonto = User::factory()->create();

        $stary = $this->wpis('account.data_erased', $stareKonto, now()->subMonths(36)->subDay());
        $swiezy = $this->wpis('account.data_erased', $swiezeKonto, now()->subMonths(36)->addDay());

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe(36);

        $this->assertTrue($wynik['wlaczone']);
        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame(1, $wynik['grup']);
        $this->assertDatabaseMissing('audit_log', ['id' => $stary->getKey()]);
        $this->assertDatabaseHas('audit_log', ['id' => $swiezy->getKey()]);
    }

    /**
     * SEDNO PROJEKTU: para „zgłosił" + „cofnął" wygasa RAZEM.
     *
     * `account.delete_requested` jest zawsze starsze od
     * `account.delete_cancelled`. Kasowanie wiersz po wierszu zostawiłoby
     * samo „cofnął usunięcie" — dowód okrojony do połowy, mylący bardziej
     * niż jego brak (OCENA_RETENCJI_ZEWNETRZNA.md §B.2).
     */
    public function test_zgloszenie_i_cofniecie_wygasaja_razem_a_nie_osobno(): void
    {
        $konto = User::factory()->create();

        // Zgłoszenie 40 miesięcy temu, cofnięcie 10 miesięcy temu.
        // Zgłoszenie samo w sobie jest starsze niż próg 36 miesięcy.
        $zgloszenie = $this->wpis('account.delete_requested', $konto, now()->subMonths(40));
        $cofniecie = $this->wpis('account.delete_cancelled', $konto, now()->subMonths(10));

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe(36);

        $this->assertSame(0, $wynik['skasowano'], 'sprawa zamknięta 10 miesięcy temu nie jest jeszcze przedawniona');
        $this->assertDatabaseHas('audit_log', ['id' => $zgloszenie->getKey()]);
        $this->assertDatabaseHas('audit_log', ['id' => $cofniecie->getKey()]);

        // KONTROLA DODATNIA: gdy CAŁA sprawa jest starsza niż próg, znika
        // w całości — obie połowy naraz, nigdy jedna bez drugiej.
        DB::table('audit_log')->where('id', $cofniecie->getKey())
            ->update(['created_at' => now()->subMonths(38)]);

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe(36);

        $this->assertSame(2, $wynik['skasowano']);
        $this->assertDatabaseMissing('audit_log', ['id' => $zgloszenie->getKey()]);
        $this->assertDatabaseMissing('audit_log', ['id' => $cofniecie->getKey()]);
    }

    /**
     * Wiersz bez podmiotu nie ma jak zostać przypisany do sprawy, więc przy
     * wątpliwości zostaje. Inaczej `subject_id IS NULL` byłoby cichą dziurą,
     * przez którą dowód znikałby bez żadnej reguły.
     */
    public function test_wpis_bez_podmiotu_nie_jest_kasowany(): void
    {
        $bezPodmiotu = $this->wpis('account.data_erased', null, now()->subYears(20));

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe(36);

        $this->assertSame(0, $wynik['skasowano']);
        $this->assertDatabaseHas('audit_log', ['id' => $bezPodmiotu->getKey()]);
    }

    /**
     * Tryb `--na-sucho` liczy, ale nie kasuje — tak jak w pierwszym przebiegu.
     */
    public function test_na_sucho_liczy_ale_nie_kasuje(): void
    {
        $konto = User::factory()->create();
        $stary = $this->wpis('account.data_erased', $konto, now()->subYears(20));

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe(36, naSucho: true);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertDatabaseHas('audit_log', ['id' => $stary->getKey()]);
    }

    /**
     * Zwykłe kategorie nie są tą ścieżką ruszane — od nich jest `posprzataj()`.
     */
    public function test_drugi_przebieg_nie_rusza_zwyklych_kategorii(): void
    {
        $konto = User::factory()->create();
        $zwykly = $this->wpis('post.hidden', $konto, now()->subYears(20));

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzatajKategorieDowodowe(36);

        $this->assertSame(0, $wynik['skasowano']);
        $this->assertDatabaseHas('audit_log', ['id' => $zwykly->getKey()]);
    }

    /**
     * DOMYŚLNA KONFIGURACJA REPOZYTORIUM jest wyłączona — i komenda mówi
     * o tym wprost, zamiast milczeć.
     */
    public function test_domyslna_konfiguracja_jest_wylaczona_a_komenda_to_oglasza(): void
    {
        $this->assertNull(
            config('kuking.audit_log.retencja_kategorii_dowodowych_miesiace'),
            'domyślnie mechanizm ma być martwy — włączenie go jest decyzją właściciela',
        );

        $konto = User::factory()->create();
        $stary = $this->wpis('account.data_erased', $konto, now()->subYears(20));

        $this->artisan('kuking:sprzataj-audyt')
            ->expectsOutputToContain('Retencja kategorii dowodowych: WYŁĄCZONA')
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_log', ['id' => $stary->getKey()]);
    }

    /**
     * KONTROLA DODATNIA dla powyższego: gdy właściciel wpisze liczbę,
     * komenda przechodzi w tryb kasowania i mówi to innym zdaniem.
     */
    public function test_po_ustawieniu_konfiguracji_komenda_kasuje_kategorie_dowodowe(): void
    {
        config(['kuking.audit_log.retencja_kategorii_dowodowych_miesiace' => 36]);

        $konto = User::factory()->create();
        $stary = $this->wpis('account.data_erased', $konto, now()->subYears(20));

        $this->artisan('kuking:sprzataj-audyt')
            ->expectsOutputToContain('Skasowano w kategoriach dowodowych')
            ->assertSuccessful();

        $this->assertDatabaseMissing('audit_log', ['id' => $stary->getKey()]);
    }
}
