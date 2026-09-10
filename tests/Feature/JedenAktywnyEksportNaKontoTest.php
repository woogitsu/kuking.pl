<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Jeden AKTYWNY eksport danych na konto — indeks częściowy w bazie (audyt
 * 10.09.2026 ustalenia QUEUE-04 / RACE-05, `docs/DECISIONS.md` D-078,
 * migracja `2026_09_10_400100_one_active_data_export_per_user`).
 *
 * CZEGO TEN PLIK PILNUJE — trzy rzeczy naraz i żadna nie zastępuje pozostałych:
 *
 *  1. że drugie żądanie NIE tworzy drugiego wiersza i kończy się spokojnym
 *     zdaniem, nie ekranem błędu — dwuklik w grupie 60+ jest scenariuszem
 *     typowym (`docs/UX_50_PLUS.md`), a eksport pakuje zdjęcia, czyli jest
 *     jedną z najdroższych rzeczy w tym serwisie;
 *  2. że gwarancję daje BAZA, nie `exists()` w PHP — dlatego jeden z testów
 *     omija kontroler zupełnie. `exists()` łapie tylko dwa żądania jedno po
 *     drugim; przy dwóch RÓWNOLEGŁYCH oba widzą „nie ma aktywnego eksportu"
 *     i oba idą do `INSERT`;
 *  3. że indeks jest CZĘŚCIOWY, nie na zawsze: po domknięciu pierwszej paczki
 *     kolejne żądanie znów przechodzi. RODO art. 15 nie jest jednorazowe.
 */
class JedenAktywnyEksportNaKontoTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_10_400100_one_active_data_export_per_user.php';

    private const SPOKOJNY_KOMUNIKAT = 'Przygotowanie paczki z Twoimi danymi już trwa. Napiszemy, gdy będzie gotowa.';

    #[Test]
    public function test_dwa_zadania_pod_rzad_daja_jeden_wiersz_i_spokojny_komunikat(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $drugie = $this->actingAs($basia)->post(route('settings.data.export'));

        $drugie->assertRedirect();
        $drugie->assertSessionHas('status', self::SPOKOJNY_KOMUNIKAT);

        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());

        // I ANI JEDNEGO drugiego zadania w kolejce — o to w tej sprawie
        // naprawdę chodzi: dwa `GenerateUserExport` na jedno konto to dwa
        // razy spakowane te same zdjęcia i dwa listy z tego samego dobowego
        // wiadra 300 wiadomości.
        Queue::assertPushed(GenerateUserExport::class, 1);
    }

    #[Test]
    public function test_baza_odrzuca_drugi_aktywny_eksport_takze_z_pominieciem_kontrolera(): void
    {
        $basia = $this->user('basia');

        DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        // NAJWAŻNIEJSZA ASERCJA W TYM PLIKU. Nie idzie przez kontroler
        // świadomie: gdyby jedyną obroną był `exists()` w PHP, ten zapis
        // przeszedłby, a seeder, komenda konsolowa i przyszły endpoint nie
        // byłyby chronione niczym. Gwarancji ma dawać schemat.
        $this->expectException(UniqueConstraintViolationException::class);

        DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_PROCESSING]);
    }

    #[Test]
    public function test_po_domknieciu_pierwszej_paczki_nowe_zadanie_znow_przechodzi(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $pierwszy = DataExport::query()->where('user_id', $basia->getKey())->sole();
        $pierwszy->update([
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/basia.zip',
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        // DWA wiersze i DWA zadania: indeks obejmuje tylko stany aktywne,
        // więc domknięta paczka nie blokuje kolejnego zamówienia. Gdyby to
        // był zwykły UNIQUE na `user_id`, ten test oblewałby się i człowiek
        // nie mógłby już nigdy zamówić swoich danych po raz drugi.
        $this->assertSame(2, DataExport::query()->where('user_id', $basia->getKey())->count());
        Queue::assertPushed(GenerateUserExport::class, 2);
    }

    #[Test]
    public function test_dwa_konta_moga_miec_swoje_eksporty_naraz(): void
    {
        $basia = $this->user('basia');
        $marek = $this->user('marek');

        DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        DataExport::create(['user_id' => $marek->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        // Asercja kontrolna do indeksu: ograniczenie jest NA KONTO. Gdyby
        // ktoś napisał ten indeks bez `user_id` (np. na samym `status`),
        // testy wyżej nadal by przechodziły, a serwis obsługiwałby jeden
        // eksport w całym serwisie naraz.
        $this->assertSame(2, DataExport::query()->count());
    }

    #[Test]
    public function test_migracja_odmawia_gdy_w_bazie_leza_juz_dwa_aktywne_eksporty(): void
    {
        $basia = $this->user('basia');

        // Cofamy indeks PO ŚCIEŻCE, nie `--step=1`: gdyby ktoś dopisał
        // później nowszą migrację, „ostatnia" przestałaby być tą sprawdzaną
        // (ta sama pułapka co w `CofniecieMigracjiCeluZdjeciaTest`).
        $this->cofnijMigracje();

        $this->dwaAktywneWiersze($basia);

        try {
            Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Migracja przeszła, mimo że w tabeli leżą dwa aktywne eksporty jednego konta.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('więcej niż jednym aktywnym eksportem', $e->getMessage());
            // Komunikat musi mówić, CO ZROBIĆ — inaczej wdrożenie staje
            // i nikt nie wie, gdzie szukać.
            $this->assertStringContainsString('NAJSTARSZY', $e->getMessage());
        }

        // Migracja NIE kasuje niczego sama. Który wiersz obowiązuje,
        // rozstrzyga człowiek — a dane osoby są tu w środku.
        $this->assertSame(2, DataExport::query()->where('user_id', $basia->getKey())->count());
    }

    #[Test]
    public function test_migracja_cofa_sie_i_wraca_bez_utraty_ochrony(): void
    {
        $basia = $this->user('basia');

        // Po cofnięciu indeksu baza znów przepuszcza dwa aktywne wiersze —
        // to jest dowód, że test wyżej naprawdę mierzy INDEKS, a nie coś
        // innego, co przypadkiem stoi na drodze.
        $this->cofnijMigracje();
        $this->dwaAktywneWiersze($basia);
        $this->assertSame(2, DataExport::query()->where('user_id', $basia->getKey())->count());

        // Sprzątamy nadmiarowy wiersz tak, jak każe komunikat migracji, i
        // wracamy — czyli przechodzimy dokładnie tę drogę, którą chodzi
        // `migrate:refresh` w CI (`down()`, potem `up()`).
        DataExport::query()
            ->where('user_id', $basia->getKey())
            ->where('status', DataExport::STATUS_PROCESSING)
            ->delete();

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->expectException(UniqueConstraintViolationException::class);

        DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);
    }

    private function cofnijMigracje(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
    }

    private function dwaAktywneWiersze(User $user): void
    {
        DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_PROCESSING]);
    }
}
