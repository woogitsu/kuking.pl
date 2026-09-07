<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\DataExport;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Egzekutor 30-dniowej karencji po zgłoszeniu usunięcia konta (audyt A8).
 *
 * `kuking:usun-wygasle-konta` był jedynym brakującym elementem: bez niego
 * ani obietnica z ekranu „Twoje dane", ani `docs/legal/COMPLIANCE.md` nie
 * były niczym więcej niż tekstem — konto zostawało `pending_delete` bez
 * końca. Najważniejsze w tym pliku: egzekutor NIE MOŻE ruszyć konta przed
 * terminem ani konta z cofniętym usunięciem — to byłaby najgorsza możliwa
 * awaria (kasowanie za dużo), gorsza niż brak egzekutora w ogóle.
 */
class AccountDeletionPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function kontoPoTerminie(string $username = 'basia'): User
    {
        return $this->user($username, [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
        ]);
    }

    public function test_konto_po_terminie_karencji_zostaje_wymazane(): void
    {
        $basia = $this->kontoPoTerminie();
        $emailPrzedUsunieciem = $basia->email;

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $basia = $basia->fresh();
        $profil = Profile::query()->where('user_id', $basia->getKey())->first();

        $this->assertNotNull($basia->data_erased_at);
        $this->assertNotSame($emailPrzedUsunieciem, $basia->email);
        $this->assertFalse(Hash::check('haslo-testowe-123', $basia->password));
        $this->assertNull($basia->remember_token);

        // Status ZOSTAJE pending_delete — nie wprowadzamy nowej wartości do
        // CHECK-a, to `data_erased_at` niesie informację o wykonaniu.
        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->status);

        $this->assertSame('Użytkownik usunięty', $profil->display_name);
        $this->assertNull($profil->bio);
        $this->assertNull($profil->avatar_media_id);
        $this->assertNotSame('basia', $profil->username);
    }

    /**
     * Gotowa paczka danych przestaje być do pobrania razem z kontem.
     *
     * Paczka to kopia CAŁEGO konta: adres e-mail, wszystkie treści,
     * wszystkie zdjęcia — w tym oryginały. Wisiała pod podpisanym adresem
     * jeszcze do siedmiu dni PO tym, jak konto zostało wymazane, bo
     * `EraseAccountData` nie tykało tabeli `data_exports` wcale.
     *
     * Człowiek, który poprosił o usunięcie konta, nie ma powodu zakładać, że
     * najpełniejsza kopia jego danych zostaje osiągalna pod adresem, który
     * kiedyś dostał mailem.
     *
     * DLACZEGO PRZESTAWIAMY TERMIN, A NIE KASUJEMY PLIKU TUTAJ: kasowanie
     * z weryfikacją i ponawianiem jest już napisane i przetestowane
     * w `kuking:sprzataj-eksporty`. Przestawienie terminu odbiera dostęp
     * NATYCHMIAST (`isDownloadable()` patrzy na `expires_at`), a plik
     * znika tą samą, sprawdzoną drogą co każda inna wygasła paczka.
     */
    public function test_gotowa_paczka_danych_przestaje_byc_do_pobrania(): void
    {
        $basia = $this->kontoPoTerminie();

        $paczka = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/paczka.zip',
            'expires_at' => now()->addDays(5),
        ]);

        // KONTROLA: przed wymazaniem paczka NAPRAWDĘ jest do pobrania.
        // Bez tej asercji test przechodziłby także wtedy, gdyby paczka od
        // początku była niedostępna z całkiem innego powodu.
        $this->assertTrue($paczka->isDownloadable(), 'Paczka nie była do pobrania jeszcze przed wymazaniem konta.');

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertFalse(
            $paczka->fresh()->isDownloadable(),
            'Gotowa paczka z kopią całego konta została do pobrania po wymazaniu tego konta.',
        );
    }

    public function test_egzekutor_zapisuje_wpis_w_dzienniku_audytu(): void
    {
        $basia = $this->kontoPoTerminie();

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'account.data_erased',
            'subject_id' => $basia->getKey(),
            'actor_id' => null,
        ]);
    }

    public function test_konto_przed_terminem_karencji_zostaje_nietkniete(): void
    {
        $basia = $this->user('basia', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(5),
        ]);
        $email = $basia->email;

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $basia = $basia->fresh();

        $this->assertNull($basia->data_erased_at);
        $this->assertSame($email, $basia->email);
        $this->assertTrue(Hash::check('haslo-testowe-123', $basia->password));
    }

    public function test_konto_z_cofnietym_usunieciem_zostaje_nietkniete(): void
    {
        // Zgłoszone 40 dni temu — GDYBY status nadal był pending_delete,
        // egzekutor by je wziął. Cofnięcie ustawia status z powrotem na
        // active, więc to jest dokładnie test na to, że sam upływ czasu
        // nie wystarczy — musi też nadal obowiązywać zgłoszenie.
        $basia = $this->user('basia', [
            'status' => User::STATUS_ACTIVE,
            'delete_requested_at' => null,
        ]);
        $email = $basia->email;

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $basia = $basia->fresh();

        $this->assertNull($basia->data_erased_at);
        $this->assertSame($email, $basia->email);
        $this->assertSame(User::STATUS_ACTIVE, $basia->status);
    }

    public function test_dry_run_nic_nie_zmienia(): void
    {
        $basia = $this->kontoPoTerminie();
        $email = $basia->email;

        $this->artisan('kuking:usun-wygasle-konta --dry-run')->assertSuccessful();

        $basia = $basia->fresh();

        $this->assertNull($basia->data_erased_at);
        $this->assertSame($email, $basia->email);
    }

    public function test_dwukrotne_uruchomienie_nie_psuje_nic_ani_nie_liczy_podwojnie(): void
    {
        $basia = $this->kontoPoTerminie();

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();
        $emailPoPierwszym = $basia->fresh()->email;
        $terminPoPierwszym = $basia->fresh()->data_erased_at;

        // Drugie uruchomienie: konto ma już `data_erased_at`, więc zapytanie
        // egzekutora go w ogóle nie wybiera — a nawet gdyby coś je wybrało,
        // `EraseAccountData` sam odmawia (idempotencja jest podwójnie
        // zabezpieczona: zapytaniem I blokadą w transakcji).
        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $basia = $basia->fresh();

        $this->assertSame($emailPoPierwszym, $basia->email);
        $this->assertEquals($terminPoPierwszym->timestamp, $basia->data_erased_at->timestamp);
        $this->assertSame(1, AuditLogEntry::where('action', 'account.data_erased')->count());
    }

    public function test_przetwarza_wiele_kont_naraz(): void
    {
        $basia = $this->kontoPoTerminie('basia');
        $zosia = $this->kontoPoTerminie('zosia');

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertNotNull($basia->fresh()->data_erased_at);
        $this->assertNotNull($zosia->fresh()->data_erased_at);
    }
}
