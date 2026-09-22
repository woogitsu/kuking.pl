<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * WYŚCIG PRZY „ZAMÓW SWOJE DANE" (audyt 10.09.2026 ustalenia QUEUE-04 /
 * RACE-05, `docs/DECISIONS.md` D-078).
 *
 * `DataSettingsController::requestExport()` robił `exists()` na stanach
 * `queued`/`processing`, a potem OSOBNY `INSERT`. Między tymi dwoma
 * zapytaniami jest okno: przy izolacji `read committed` dwa równoległe
 * żądania widzą „nie ma aktywnego eksportu" jednocześnie i oba wstawiają
 * swój wiersz, żadne nie czeka. Skutkiem są DWA `GenerateUserExport` na jedno
 * konto — czyli dwa razy spakowane te same zdjęcia (job ma 15 minut limitu
 * czasu, kolejka `low`, jeden worker) i dwa listy z tego samego dobowego
 * wiadra poczty.
 *
 * `lockForUpdate()` tego NIE naprawia i nie został dodany: `SELECT ... FOR
 * UPDATE`, który nie zwrócił żadnego wiersza, nie blokuje niczego — to jest
 * wstawienie fantomu, nie konflikt na wierszu (zmierzone przy
 * `reports_one_open_per_pair`, `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md`
 * §1.4.2). Naprawą jest częściowy indeks UNIQUE
 * `data_exports_one_active_per_user` plus sprowadzenie konfliktu do tego
 * samego, spokojnego komunikatu.
 *
 * METODA: jak w `IdempotencjaZgloszeniaWyscigTest`, `IngredientRaceTest`
 * i `ResolveTagsForPostRaceTest` — konkurencyjny wiersz wchodzi DOKŁADNIE
 * między `SELECT`-em a `INSERT`-em, przez `DB::listen()`. Prawdziwe dwa
 * połączenia nie przejdą tu przez `RefreshDatabase` (dane testu żyją
 * w transakcji nieznanej drugiemu połączeniu, a `data_exports.user_id` ma
 * klucz obcy do `users`), a dowód „baza sama odrzuca wiersz" stoi osobno,
 * w `JedenAktywnyEksportNaKontoTest::
 * test_baza_odrzuca_drugi_aktywny_eksport_takze_z_pominieciem_kontrolera`.
 */
class EksportDanychRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwa_niemal_jednoczesne_zamowienia_daja_jeden_eksport_i_spokojny_komunikat(): void
    {
        Queue::fake();

        $basia = $this->user('basiaeksportwyscig');

        $wstawione = false;

        DB::listen(function ($query) use (&$wstawione, $basia): void {
            if ($wstawione) {
                return;
            }

            $sql = mb_strtolower($query->sql);

            // Czekamy dokładnie na pytanie „czy ta osoba ma aktywny eksport".
            if (! str_starts_with(trim($sql), 'select')
                || ! str_contains($sql, '"data_exports"')
                || ! str_contains($sql, 'status')) {
                return;
            }

            $wstawione = true;

            // „Drugie żądanie" wygrywa wyścig: wstawia swój eksport w chwili,
            // gdy pierwsze już sprawdziło, że żadnego nie ma. Surowy `INSERT`
            // omija model, więc nie odpala zdarzeń i nie zapętla nasłuchu.
            DB::table('data_exports')->insert([
                'user_id' => $basia->getKey(),
                'status' => DataExport::STATUS_QUEUED,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $odpowiedz = $this->actingAs($basia)->post(route('settings.data.export'));

        $this->assertTrue($wstawione, 'Konkurencyjny wiersz nie wszedł — test nie zmierzył wyścigu.');

        // NAJWAŻNIEJSZE ZDANIE TEGO TESTU: człowiek, który przegrał wyścig,
        // widzi to samo co ten, który kliknął raz. Nie 500, nie „coś poszło
        // nie tak" — spokojne „już przygotowujemy".
        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHasNoErrors();
        $odpowiedz->assertSessionHas(
            'status',
            'Przygotowanie paczki z Twoimi danymi już trwa. Gotowość sprawdzisz w sekcji „Twoje paczki”.',
        );

        // JEDEN wiersz — ten, który wyścig wygrał. Wstawka przegranego
        // żądania odbiła się o indeks i nie została po niej sierota.
        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());

        // I ANI JEDNEGO zadania z przegranego żądania: to jest ta połowa
        // usterki, która realnie kosztuje — drugi ciężki eksport tego samego
        // konta.
        Queue::assertNotPushed(GenerateUserExport::class);
    }
}
