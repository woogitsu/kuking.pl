<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\RequestAccountDeletion;
use App\Domain\Users\Exports\WynikZamowieniaEksportu;
use App\Domain\Users\Exports\ZamowEksportDanych;
use App\Http\Requests\Settings\ProsbaOUsuniecieKontaRequest;
use App\Jobs\GenerateUserExport;
use App\Models\AuditLogEntry;
use App\Models\DataExport;
use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * „Twoje dane" po #970: wejście w FormRequeście, przypadki użycia poza
 * kontrolerem.
 *
 * Zachowanie ekranu pilnują dotychczasowe testy HTTP
 * (`EksportNieUtykaMiedzyCommitemAWyslaniemTest`, `EksportDanychRaceTest`,
 * testy usunięcia konta). Ten plik sprawdza nowe granice: że zamówienie
 * paczki i zgłoszenie usunięcia działają BEZ kontrolera (drugi punkt wejścia
 * nie może ominąć transakcji ani dziennika) i że reguły formularza dają się
 * sprawdzić bez żądania.
 */
final class TwojeDanePrzypadkiUzyciaTest extends TestCase
{
    use RefreshDatabase;

    public function test_zamowienie_paczki_bez_kontrolera_zapisuje_rekord_zadanie_i_dziennik(): void
    {
        Queue::fake();
        $basia = $this->user('basia');

        $wynik = app(ZamowEksportDanych::class)->handle($basia, '203.0.113.7');

        $this->assertSame(WynikZamowieniaEksportu::Przyjety, $wynik);
        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());
        Queue::assertPushed(GenerateUserExport::class, 1);
        $this->assertTrue(AuditLogEntry::query()->where('action', 'data.export_requested')->exists());
    }

    public function test_drugie_zamowienie_mowi_juz_trwa_i_nie_zleca_drugiego_zadania(): void
    {
        Queue::fake();
        $basia = $this->user('basia');

        app(ZamowEksportDanych::class)->handle($basia);
        $drugie = app(ZamowEksportDanych::class)->handle($basia);

        $this->assertSame(WynikZamowieniaEksportu::JuzTrwa, $drugie);
        Queue::assertPushed(GenerateUserExport::class, 1);
    }

    public function test_porzucony_eksport_jest_ponawiany_na_tym_samym_rekordzie(): void
    {
        Queue::fake();
        $basia = $this->user('basia');
        $porzucony = DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        DataExport::query()->whereKey($porzucony->getKey())->update([
            'updated_at' => now()->subMinutes(DataExport::MINUT_NA_PODJECIE + 5),
        ]);

        $this->assertSame(WynikZamowieniaEksportu::Ponowiony, app(ZamowEksportDanych::class)->handle($basia));
        $this->assertSame(1, DataExport::query()->count());
        Queue::assertPushed(GenerateUserExport::class, 1);
    }

    public function test_zgloszenie_usuniecia_bez_kontrolera_oznacza_konto_otwiera_sprawe_i_zapisuje_zakres(): void
    {
        $basia = $this->user('basia');

        app(RequestAccountDeletion::class)->handle($basia, User::DELETE_SCOPE_EVERYTHING, '203.0.113.7');

        $this->assertSame(User::STATUS_PENDING_DELETE, $basia->fresh()?->status);
        $this->assertSame(1, PotwierdzenieZadaniaRodo::query()->count());
        $wpis = AuditLogEntry::query()->where('action', 'account.delete_requested')->sole();
        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, $wpis->metadata['zakres'] ?? null);
    }

    public function test_reguly_formularza_usuniecia_i_polskie_komunikaty(): void
    {
        $request = new ProsbaOUsuniecieKontaRequest;

        $bledy = Validator::make(['usun_tresci' => 'może'], $request->rules(), $request->messages())->errors();

        $this->assertSame('Wpisz swoje hasło, żeby potwierdzić, że to Ty.', $bledy->first('password'));
        $this->assertSame('Zaznacz, że rozumiesz, co się stanie.', $bledy->first('confirm'));
        $this->assertSame('Zaznacz haczyk albo zostaw go pustym.', $bledy->first('usun_tresci'));

        // Kontrola dodatnia: brak haczyka to poprawna, najczęstsza odpowiedź (D-022).
        $poprawne = Validator::make(['password' => 'x', 'confirm' => '1'], $request->rules(), $request->messages());
        $this->assertFalse($poprawne->fails());
    }

    public function test_zakres_z_haczyka(): void
    {
        $this->assertSame(User::DELETE_SCOPE_MINIMUM, ProsbaOUsuniecieKontaRequest::create('/', 'POST', [])->zakres());
        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, ProsbaOUsuniecieKontaRequest::create('/', 'POST', ['usun_tresci' => '1'])->zakres());
    }
}
