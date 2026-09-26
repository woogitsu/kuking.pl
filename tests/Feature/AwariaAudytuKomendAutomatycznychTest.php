<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Awaria dziennika audytu w komendach automatycznych nie ma zjadać dowodu
 * zatwierdzonej zmiany konta (D-249, #1894).
 *
 * CO BYŁO ŹLE
 * `kuking:zdejmij-wygasle-kary` (`RestoreExpiredSuspensions`) wołał
 * `$user->reinstate()`, a POTEM rzucający `AuditLogEntry::record()` — poza
 * jakąkolwiek transakcją. Awaria dawała dwie szkody naraz: konto zostawało
 * `active` bez wpisu `account.suspension_expired` (jedyny zapis TEGO
 * zdarzenia — `reinstate()` nie zostawia po sobie żadnego innego śladu),
 * a wyjątek z `record()` przerywał całą pętlę, więc kolejne zawieszone konta
 * w tym samym uruchomieniu w ogóle nie były przetwarzane.
 *
 * `kuking:usun-wygasle-konta` (`PurgeExpiredAccountDeletions`) miał gorszy
 * wariant tej samej choroby: `EraseAccountData::handle()` (anonimizacja,
 * NIEODWRACALNA) kończyła się i zatwierdzała, a dopiero PO POWROCIE stąd
 * komenda wołała rzucający `AuditLogEntry::record('account.data_erased', …)`.
 * `account.data_erased` jest JEDYNYM dowodem wykonania prawa z art. 17 RODO
 * (`AuditLogEntry::NIGDY_NIE_KASUJ`) — po awarii konto zostawało bez niego
 * NA ZAWSZE, bo `data_erased_at` (już ustawione) wyklucza je z warunku
 * kolejki (`whereNull('data_erased_at')`) na każdym następnym przebiegu.
 *
 * REGUŁA (D-249, klasa 1): oba wpisy stoją teraz W TEJ SAMEJ transakcji co
 * zmiana, którą opisują — awaria cofa całość, a nie tylko dziennik. Konto
 * zostaje w stanie SPRZED zmiany i trafia w kolejny przebieg tej samej
 * komendy.
 */
class AwariaAudytuKomendAutomatycznychTest extends TestCase
{
    use RefreshDatabase;

    private bool $awaria = true;

    private function zepsujWpis(string $akcja): void
    {
        DB::listen(function ($zapytanie) use ($akcja): void {
            if ($this->awaria
                && str_contains($zapytanie->sql, 'insert into "audit_log"')
                && in_array($akcja, $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria dziennika: '.$akcja);
            }
        });
    }

    private function wpisy(string $akcja): int
    {
        return AuditLogEntry::query()->where('action', $akcja)->count();
    }

    // ------------------------------------------------------------------
    // kuking:zdejmij-wygasle-kary — account.suspension_expired
    // ------------------------------------------------------------------

    private function zawieszonyPoTerminie(string $username): User
    {
        return $this->user($username, [
            'status' => User::STATUS_SUSPENDED,
            'status_expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Kontrola ujemna (wykonana ręcznie): przywrócenie starego kształtu
     * (`reinstate()` poza transakcją, `record()` po nim, bez `try/catch`
     * wokół pętli) sprawia, że ten test pada na PIERWSZEJ asercji — `zenek`
     * zostaje `active` bez wpisu, bez drogi ponowienia, a `basia` (druga
     * w kolejce) nigdy nie jest przetwarzana, bo wyjątek przerywa pętlę.
     */
    public function test_awaria_audytu_cofa_przywrocenie_i_nie_przerywa_reszty_kolejki(): void
    {
        $zenek = $this->zawieszonyPoTerminie('zenek');
        $basia = $this->zawieszonyPoTerminie('basia');
        $this->zepsujWpis('account.suspension_expired');

        $this->artisan('kuking:zdejmij-wygasle-kary')
            ->assertSuccessful()
            ->expectsOutputToContain("Nie udało się przywrócić konta {$zenek->getKey()}")
            ->expectsOutputToContain("Nie udało się przywrócić konta {$basia->getKey()}");

        // OBA konta zostają nietknięte — awaria dziennika cofnęła
        // przywrócenie, nie tylko brakujący wpis. Wyjątek jednego konta
        // nie przerwał obsługi drugiego.
        $this->assertSame(User::STATUS_SUSPENDED, $zenek->fresh()->status);
        $this->assertSame(User::STATUS_SUSPENDED, $basia->fresh()->status);
        $this->assertSame(0, $this->wpisy('account.suspension_expired'));

        // Awaria minęła — następny przebieg (harmonogram za godzinę) łapie
        // OBA konta, bo żadne nie wypadło z warunku `WHERE status = suspended`.
        $this->awaria = false;

        $this->artisan('kuking:zdejmij-wygasle-kary')->assertSuccessful();

        $this->assertSame(User::STATUS_ACTIVE, $zenek->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
        $this->assertSame(2, $this->wpisy('account.suspension_expired'));
    }

    public function test_kontrola_dodatnia_przywrocenie_bez_awarii_zapisuje_wpis_dla_kazdego_konta(): void
    {
        $zenek = $this->zawieszonyPoTerminie('zenek');
        $basia = $this->zawieszonyPoTerminie('basia');

        $this->artisan('kuking:zdejmij-wygasle-kary')->assertSuccessful();

        $this->assertSame(User::STATUS_ACTIVE, $zenek->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()->status);
        $this->assertSame(2, $this->wpisy('account.suspension_expired'));
    }

    // ------------------------------------------------------------------
    // kuking:usun-wygasle-konta — account.data_erased
    // ------------------------------------------------------------------

    private function kontoPoTerminieKarencji(string $username): User
    {
        return $this->user($username, [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
        ]);
    }

    public function test_awaria_audytu_cofa_takze_anonimizacje_a_ponowienie_dokancza(): void
    {
        $basia = $this->kontoPoTerminieKarencji('basia');
        $emailPrzed = $basia->email;
        $this->zepsujWpis('account.data_erased');

        $this->artisan('kuking:usun-wygasle-konta')
            ->assertSuccessful()
            ->expectsOutputToContain("Nie udało się obsłużyć konta {$basia->getKey()}");

        $swiezy = $basia->fresh();
        $this->assertNull($swiezy->data_erased_at, 'Anonimizacja miała się cofnąć razem z awarią dziennika.');
        $this->assertSame($emailPrzed, $swiezy->email, 'Adres zanonimizowany bez trwałego dowodu wykonania (art. 17 RODO).');
        $this->assertSame(User::STATUS_PENDING_DELETE, $swiezy->status);
        $this->assertSame(0, $this->wpisy('account.data_erased'));

        // Awaria minęła — konto NADAL spełnia `whereNull('data_erased_at')`,
        // więc następny przebieg je podejmie (to jest cały sens klasy 1 tutaj).
        $this->awaria = false;

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $swiezy = $basia->fresh();
        $this->assertNotNull($swiezy->data_erased_at);
        $this->assertNotSame($emailPrzed, $swiezy->email);
        $this->assertSame(1, $this->wpisy('account.data_erased'));
    }

    public function test_kontrola_dodatnia_wymazanie_bez_awarii_zapisuje_wpis(): void
    {
        $basia = $this->kontoPoTerminieKarencji('basia');

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertNotNull($basia->fresh()->data_erased_at);
        $this->assertSame(1, $this->wpisy('account.data_erased'));
    }
}
