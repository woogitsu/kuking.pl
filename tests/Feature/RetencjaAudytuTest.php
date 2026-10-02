<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWpisyAudytu;
use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Retencja `audit_log` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.1).
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU: wpisy `account.*` (dowód wykonania RODO
 * art. 17 i cofnięcia usunięcia konta) NIE ZNIKAJĄ, dopóki zamknięcie sprawy nie
 * ma potwierdzenia w rejestrze (#2708), ani dla konta z zabezpieczonym dowodem.
 */
class RetencjaAudytuTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(string $action, \DateTimeInterface|string $createdAt): AuditLogEntry
    {
        $wpis = AuditLogEntry::record($action, metadata: ['test' => true]);

        DB::table('audit_log')->where('id', $wpis->getKey())->update(['created_at' => $createdAt]);

        return $wpis->refresh();
    }

    public function test_wpis_starszy_niz_prog_znika_a_mlodszy_zostaje(): void
    {
        config(['kuking.audit_log.retention_months' => 24]);

        // Wyraźnie POZA i WEWNĄTRZ granicy — nie tylko "bardzo stary", żeby
        // test naprawdę pilnował progu, a nie dowolnej dużej różnicy.
        $stary = $this->wpis('post.hidden', now()->subMonths(24)->subDay());
        $mlody = $this->wpis('post.hidden', now()->subMonths(24)->addDay());

        $wynik = (new PrzedawnioneWpisyAudytu)->posprzataj(24);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame(0, $wynik['niekasowalne']);
        $this->assertDatabaseMissing('audit_log', ['id' => $stary->getKey()]);
        // Asercja kontrolna: młodszy wpis naprawdę został, to nie jest test,
        // który przeszedłby też wtedy, gdyby komenda skasowała WSZYSTKO.
        $this->assertDatabaseHas('audit_log', ['id' => $mlody->getKey()]);
    }

    /**
     * Od 2.10.2026 (#2708) wpisy `account.*` nie są niekasowalne, ale zostają,
     * dopóki ich zamknięcie nie ma potwierdzenia w `potwierdzenia_zadan_rodo`.
     */
    #[DataProvider('zamknieciaZzadan')]
    public function test_zamkniecie_zadania_bez_potwierdzenia_zostaje_niezaleznie_od_wieku(string $action, string $wynik): void
    {
        config(['kuking.audit_log.retention_months' => 24]);
        $konto = User::factory()->create();

        $dowod = $this->wpisDlaKonta($action, $konto, now()->subYears(20));
        // Kontrola dodatnia: zwykła kategoria w tym samym wieku znika.
        $zwykly = $this->wpis('post.hidden', now()->subYears(20));

        $przebieg = (new PrzedawnioneWpisyAudytu)->posprzataj(24);

        $this->assertSame(1, $przebieg['skasowano']);
        $this->assertSame(1, $przebieg['niekasowalne']);
        $this->assertTrue($przebieg['wstrzymane_do_przeniesienia']);
        $this->assertDatabaseHas('audit_log', ['id' => $dowod->getKey()]);
        $this->assertDatabaseMissing('audit_log', ['id' => $zwykly->getKey()]);

        // Po przeniesieniu (jest potwierdzenie) ten sam wpis podlega zwykłej retencji.
        $this->potwierdzenie($konto, $wynik);

        $po = (new PrzedawnioneWpisyAudytu)->posprzataj(24);

        $this->assertFalse($po['wstrzymane_do_przeniesienia']);
        $this->assertSame(1, $po['skasowano']);
        $this->assertDatabaseMissing('audit_log', ['id' => $dowod->getKey()]);
    }

    public function test_wpis_konta_z_zabezpieczonym_dowodem_zostaje_mimo_potwierdzenia(): void
    {
        $zatrzymane = User::factory()->create();
        $inne = User::factory()->create();
        foreach ([$zatrzymane, $inne] as $k) {
            $this->potwierdzenie($k, 'wykonane');
        }
        $zostaje = $this->wpisDlaKonta('account.data_erased', $zatrzymane, now()->subYears(5));
        $znika = $this->wpisDlaKonta('account.data_erased', $inne, now()->subYears(5));
        DB::table('zabezpieczenia_dowodow')->insert([
            'id' => (string) Str::uuid(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'subject_user_id' => $zatrzymane->getKey(),
        ]);

        $przebieg = (new PrzedawnioneWpisyAudytu)->posprzataj(12);

        $this->assertSame(1, $przebieg['skasowano']);
        $this->assertDatabaseHas('audit_log', ['id' => $zostaje->getKey()]);
        $this->assertDatabaseMissing('audit_log', ['id' => $znika->getKey()]);
    }

    public static function zamknieciaZzadan(): array
    {
        return [
            'wykonanie usunięcia konta (RODO art. 17)' => ['account.data_erased', 'wykonane'],
            'cofnięcie usunięcia konta' => ['account.delete_cancelled', 'cofniete'],
        ];
    }

    private function wpisDlaKonta(string $action, User $konto, \DateTimeInterface|string $createdAt): AuditLogEntry
    {
        $wpis = AuditLogEntry::record($action, null, $konto, metadata: ['zakres' => 'minimum']);
        DB::table('audit_log')->where('id', $wpis->getKey())->update(['created_at' => $createdAt]);

        return $wpis->refresh();
    }

    private function potwierdzenie(User $konto, string $wynik): void
    {
        $r = new RejestrPotwierdzenRodo;
        $r->dopiszZAudytu([
            'rodzaj' => 'usuniecie_konta',
            'wynik' => $wynik,
            'zakres' => $wynik === 'wykonane' ? 'minimum' : null,
            'otrzymano' => now()->subYears(5),
            'zakonczono' => now()->subYears(5),
            'wersja_procedury' => '2026-09-06',
            'wyjatki' => null,
            'konto_id' => $konto->getKey(),
        ]);
    }

    /**
     * Zdjęte z listy niekasowalnych (#2708); trzy wpisy `account.*` mają
     * własną, warunkową listę.
     */
    public function test_lista_niekasowalnych_jest_pusta_a_dowody_usuniecia_konta_maja_wlasna_liste(): void
    {
        $this->assertSame([], AuditLogEntry::NIGDY_NIE_KASUJ);
        $this->assertSame(
            ['account.data_erased', 'account.delete_requested', 'account.delete_cancelled'],
            AuditLogEntry::DOWODY_USUNIECIA_KONTA,
        );
    }

    public function test_komenda_retencji_dziala_i_respektuje_opcje_na_sucho(): void
    {
        config(['kuking.audit_log.retention_months' => 24]);
        $konto = User::factory()->create();

        $this->wpis('post.hidden', now()->subYears(3));
        $this->wpisDlaKonta('account.data_erased', $konto, now()->subYears(3));

        $this->artisan('kuking:sprzataj-audyt', ['--na-sucho' => true])->assertSuccessful();
        // Kontrola: OBA wiersze wciąż tu są po na-sucho.
        $this->assertDatabaseCount('audit_log', 2);

        $this->artisan('kuking:sprzataj-audyt')
            ->expectsOutputToContain('kuking:przenies-potwierdzenia-rodo')
            ->assertSuccessful();
        // Zwykły wpis zniknął, a dowód bez potwierdzenia został.
        $this->assertDatabaseCount('audit_log', 1);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.data_erased']);
    }
}
