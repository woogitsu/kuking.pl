<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Jobs\GenerateUserExport;
use App\Mail\DataExportReady;
use App\Mail\DataExportReadyInGracePeriod;
use App\Models\DataExport;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\DyskEksportuZHakiem;
use Tests\TestCase;

/**
 * ISSUE #1307: paczka z danymi nie może odżyć po wymazaniu konta.
 *
 * `EraseAccountData` unieważnia eksporty konta, przestawiając `expires_at`
 * w przeszłość. `GenerateUserExport` budował paczkę z danych odczytanych na
 * starcie i kończył BEZWARUNKOWYM `update(ready, expires_at = +7 dni)` na
 * modelu sprzed wymazania — więc wymazanie, które zdążyło wejść w trakcie
 * budowania, było po cichu cofane: w magazynie zostawała pełna kopia konta
 * z terminem, przed którym nocne sprzątanie jej nie ruszy.
 *
 * Wyścig jest tu DETERMINISTYCZNY: wymazanie wchodzi przez hak dysku
 * (`DyskEksportuZHakiem::$poZapisie`) dokładnie po `writeStream()`, a przed
 * przejściem w `ready` — w jedynym oknie, w którym usterka była możliwa.
 * Ten sam przeplot na dwóch prawdziwych połączeniach, razem z drugim
 * (wymazanie czekające na blokadę finalizacji), mierzy
 * `tests/Dwa/EksportPoWymazaniuKontaTest.php`.
 *
 * KONTROLA UJEMNA (wykonana): usunięcie warunku `revoked()` z `finalize()`
 * w `GenerateUserExport` oblewa `test_wymazanie_miedzy_zapisem_a_gotowoscia_…`
 * i `test_nieudane_usuniecie_…` — paczka wraca jako `ready` z terminem
 * w przyszłości. Usunięcie sprawdzenia na starcie oblewa
 * `test_eksport_zamowiony_przed_wymazaniem_…`. List wysyła od issue #820
 * `NotifyUserExportReady`; zdjęcie z niego świeżego sprawdzenia wymazania
 * i pobieralności (odczyt w `handle()` i warunki w `zajmij()`) oblewa
 * `test_wymazanie_tuz_po_gotowosci_…` — list idzie na zanonimizowany adres.
 * Wysyłanie zwykłego `DataExportReady` także w karencji oblewa oba testy
 * karencji; podawanie zawsze końca karencji (zamiast WCZEŚNIEJSZEJ z dat)
 * oblewa `test_list_w_karencji_podaje_termin_paczki_…`.
 */
class EksportNieOdtwarzaPaczkiPoWymazaniuKontaTest extends TestCase
{
    use RefreshDatabase;

    private DyskEksportuZHakiem $dysk;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');

        $this->dysk = DyskEksportuZHakiem::zarejestruj('eksporty-hak');

        config([
            'kuking.exports.disk' => 'eksporty-hak',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    public function test_wymazanie_miedzy_zapisem_a_gotowoscia_nie_zostawia_paczki_ani_terminu(): void
    {
        [$basia, $export] = $this->kontoWKarencjiZZamowionymEksportem();

        $zapisanyKlucz = null;

        $this->dysk->poZapisie = function (string $klucz) use ($basia, &$zapisanyKlucz): void {
            $zapisanyKlucz = $klucz;

            $this->assertTrue(
                app(EraseAccountData::class)->handle($basia->fresh()),
                'Wymazanie w haku nic nie zrobiło — test nie zmierzyłby wyścigu.',
            );
        };

        (new GenerateUserExport((string) $export->getKey()))->handle();

        // KONTROLA DODATNIA: hak naprawdę zadziałał i paczka naprawdę
        // wylądowała w magazynie, zanim wymazanie weszło.
        $this->assertNotNull($zapisanyKlucz, 'Hak po zapisie paczki nie został wywołany.');
        $this->assertNotNull(User::query()->whereKey($basia->getKey())->value('data_erased_at'));

        $export->refresh();

        $this->assertNotSame(DataExport::STATUS_READY, $export->status);
        $this->assertFalse($export->isDownloadable());
        $this->assertNotNull($export->expires_at);
        $this->assertTrue($export->expires_at->isPast(), 'Job nadpisał unieważnienie terminem w przyszłości.');

        $this->dysk->assertMissing($zapisanyKlucz);
        $this->assertNull($export->object_key, 'Plik usunięty, więc adres nie ma już na co wskazywać.');

        Mail::assertNotSent(DataExportReady::class);
    }

    /**
     * Wymazanie czekało na blokadę `finalize()` i zatwierdziło się zaraz po
     * jej commicie — paczka jest już `ready`, ale listu wysłać nie wolno:
     * adres jest zanonimizowany, a link prowadzi do paczki, której nikt już
     * nie pobierze. Wymazanie wchodzi przez `DB::afterCommit()` zarejestrowane
     * w chwili zapisu `ready`, czyli dokładnie po commicie finalizacji,
     * a przed listem.
     */
    public function test_wymazanie_tuz_po_gotowosci_nie_wysyla_listu(): void
    {
        [$basia, $export] = $this->kontoWKarencjiZZamowionymEksportem();
        $prawdziwyAdres = $basia->email;

        $wymazano = false;

        DataExport::updated(function (DataExport $zmieniony) use ($basia, &$wymazano): void {
            if ($wymazano || $zmieniony->status !== DataExport::STATUS_READY || ! $zmieniony->wasChanged('status')) {
                return;
            }

            $wymazano = true;

            DB::afterCommit(function () use ($basia): void {
                $this->assertTrue(app(EraseAccountData::class)->handle($basia->fresh()));
            });
        });

        (new GenerateUserExport((string) $export->getKey()))->handle();

        // KONTROLA DODATNIA: finalizacja naprawdę przeszła w `ready`,
        // a wymazanie naprawdę weszło po niej.
        $this->assertTrue($wymazano, 'Eksport nie doszedł do `ready` — test nie zmierzyłby okna.');
        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);
        $this->assertNotNull(User::query()->whereKey($basia->getKey())->value('data_erased_at'));
        $this->assertNotSame($prawdziwyAdres, User::query()->whereKey($basia->getKey())->value('email'));

        $this->assertFalse($export->isDownloadable());
        Mail::assertNotSent(DataExportReady::class);
    }

    public function test_nieudane_usuniecie_paczki_z_przegranego_wyscigu_zachowuje_adres_do_ponowienia(): void
    {
        [$basia, $export] = $this->kontoWKarencjiZZamowionymEksportem();

        $this->dysk->odmowUsuniecia = true;
        $zapisanyKlucz = null;

        $this->dysk->poZapisie = function (string $klucz) use ($basia, &$zapisanyKlucz): void {
            $zapisanyKlucz = $klucz;
            app(EraseAccountData::class)->handle($basia->fresh());
        };

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $export->refresh();

        $this->assertNotNull($zapisanyKlucz);
        $this->dysk->assertExists($zapisanyKlucz);

        // Trwały ślad: stan, który `kuking:sprzataj-eksporty` wybiera
        // do kasowania, z adresem, który trzeba skasować.
        $this->assertSame(DataExport::STATUS_EXPIRED, $export->status);
        $this->assertSame($zapisanyKlucz, $export->object_key);
        $this->assertSame('eksporty-hak', $export->disk);
        $this->assertTrue($export->expires_at->isPast());

        // I ta droga naprawdę działa, gdy magazyn wróci.
        $this->dysk->odmowUsuniecia = false;
        Artisan::call('kuking:sprzataj-eksporty');

        $this->dysk->assertMissing($zapisanyKlucz);
        $this->assertNull($export->refresh()->object_key);
    }

    public function test_eksport_zamowiony_przed_wymazaniem_nie_startuje_po_nim(): void
    {
        [$basia, $export] = $this->kontoWKarencjiZZamowionymEksportem();

        $this->assertTrue(app(EraseAccountData::class)->handle($basia->fresh()));

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $export->refresh();

        $this->assertSame(DataExport::STATUS_FAILED, $export->status);
        $this->assertSame(DataExport::REASON_ACCOUNT_MISSING, $export->failure_reason);
        $this->assertNull($export->object_key);
        $this->assertSame([], $this->dysk->allFiles());
        Mail::assertNotSent(DataExportReady::class);
    }

    /**
     * KONTROLA DODATNIA całego pliku: karencja (`pending_delete`) NIE
     * blokuje eksportu — do dnia egzekucji paczkę wolno zamówić i pobrać.
     * Bez tego testu „nigdy nie ready" przeszłoby wszystkie trzy wyżej.
     *
     * LIST W KARENCJI (decyzja właściciela z 23 września 2026): konto
     * w karencji nie zaloguje się, a pobranie wymaga logowania — więc NIE
     * zwykły „Twoje dane są gotowe” z przyciskiem „Pobierz”, tylko osobny
     * list z linkiem do cofnięcia usunięcia i terminem. Tu karencja kończy
     * się PRZED wygaśnięciem paczki (zgłoszenie 28 dni temu, paczka na
     * 7 dni), więc terminem jest koniec karencji.
     */
    public function test_eksport_w_karencji_i_na_zwyklym_koncie_dalej_jest_gotowy(): void
    {
        $this->travel(-28)->days();
        [$basia, $wKarencji] = $this->kontoWKarencjiZZamowionymEksportem();
        $this->travelBack();

        $marek = $this->user('marekeksport');
        $zwykly = DataExport::create(['user_id' => $marek->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        foreach ([$wKarencji, $zwykly] as $export) {
            (new GenerateUserExport((string) $export->getKey()))->handle();

            $export->refresh();

            $this->assertSame(DataExport::STATUS_READY, $export->status);
            $this->assertTrue($export->isDownloadable());
            $this->dysk->assertExists((string) $export->object_key);
        }

        $koniecKarencji = $basia->fresh()->deletionGraceEndsAt();
        $this->assertNotNull($koniecKarencji);
        $this->assertTrue($koniecKarencji->lt($wKarencji->expires_at), 'Założenie testu: karencja kończy się pierwsza.');

        Mail::assertSent(DataExportReadyInGracePeriod::class, 1);
        Mail::assertSent(DataExportReadyInGracePeriod::class, function (DataExportReadyInGracePeriod $list) use ($basia, $koniecKarencji): bool {
            $html = $list->render();

            return $list->hasTo($basia->email)
                && $list->deadline()?->equalTo($koniecKarencji)
                && str_contains($html, route('account.delete.cancel'))
                && str_contains($html, Czas::data($koniecKarencji, 'j F Y, H:i'))
                && str_contains($html, 'Żeby ją pobrać, cofnij usunięcie konta')
                // Żadnego linku do pobrania — konto w karencji go nie otworzy.
                && ! str_contains($html, '/ustawienia/twoje-dane/pobierz/');
        });

        // Zwykłe konto — zwykły list, i TYLKO ono.
        Mail::assertSent(DataExportReady::class, 1);
        Mail::assertSent(DataExportReady::class, fn (DataExportReady $list): bool => $list->hasTo($marek->email));
        Mail::assertNotSent(DataExportReady::class, fn (DataExportReady $list): bool => $list->hasTo($basia->email));
    }

    /**
     * Paczka wygasa PRZED końcem karencji (świeże zgłoszenie: 30 dni
     * karencji, paczka na 7) — list podaje WCZEŚNIEJSZĄ datę, czyli termin
     * paczki. Po cofnięciu usunięcia paczka czeka w ustawieniach jak zwykle.
     */
    public function test_list_w_karencji_podaje_termin_paczki_gdy_ten_jest_wczesniejszy(): void
    {
        [$basia, $export] = $this->kontoWKarencjiZZamowionymEksportem();

        (new GenerateUserExport((string) $export->getKey()))->handle();
        $export->refresh();

        $this->assertTrue($export->expires_at->lt($basia->fresh()->deletionGraceEndsAt()));

        Mail::assertNotSent(DataExportReady::class);
        Mail::assertSent(DataExportReadyInGracePeriod::class, function (DataExportReadyInGracePeriod $list) use ($export): bool {
            $html = $list->render();

            return $list->deadline()?->equalTo($export->expires_at)
                && str_contains($html, Czas::data($export->expires_at, 'j F Y, H:i'))
                && str_contains($html, 'Tego dnia paczka zostanie usunięta');
        });

        // Po cofnięciu usunięcia: paczka do pobrania w ustawieniach.
        $basia->fresh()->cancelDeletion();

        $link = URL::temporarySignedRoute('settings.data.download', $export->expires_at, ['export' => $export->getKey()]);

        $this->actingAs($basia->fresh())->get($link)->assertOk();
    }

    /** @return array{0: User, 1: DataExport} */
    private function kontoWKarencjiZZamowionymEksportem(): array
    {
        $basia = $this->user('basiawymazana');
        $basia->markForDeletion();

        $export = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        return [$basia, $export];
    }
}
