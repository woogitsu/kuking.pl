<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kopie\StanKopiiBazy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Czujka kopii w stanie WYŁĄCZONA zostawia ślad w logu produkcji (#2297, IN-05).
 *
 * Audyt odtworzył: `schedule:test --name=kuking:sprawdz-kopie` bez bucketu
 * kopii wypisywał wyłącznie „DONE". Ostrzeżenie komendy szło przez `warn()`
 * do bufora `Artisan::call`, który adapter harmonogramu czyta tylko przy
 * kodzie różnym od zera. Produkcja bez ani jednej kopii meldowała więc
 * codziennie udane sprawdzenie.
 *
 * Test uruchamia TO SAMO zadanie z harmonogramu (nie samą komendę), bo
 * właśnie ta droga gubiła komunikat. Pilnuje też „bez zalewu": jeden wpis
 * na przebieg, ostrzeżenie (nie `error`, który poszedłby na Discorda)
 * i żadnego żądania na webhook.
 */
final class SprawdzKopieBazyZostawiaSladTest extends TestCase
{
    public function test_wylaczona_czujka_z_harmonogramu_zostawia_ostrzezenie_w_logu(): void
    {
        config()->set('kuking.kopie.dysk', 'r2_kopie');
        config()->set('filesystems.disks.r2_kopie.bucket', null);
        config()->set('logging.channels.blad_webhook.url', 'https://przyklad.invalid/webhook');
        Http::fake();
        Log::spy();

        $zadania = array_values(array_filter(
            app(Schedule::class)->events(),
            fn ($zadanie): bool => $zadanie->description === 'kuking:sprawdz-kopie',
        ));
        $this->assertCount(1, $zadania, 'Harmonogram nie ma dokładnie jednego zadania kuking:sprawdz-kopie.');

        $zadania[0]->run(app());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $tresc, array $kontekst = []): bool => ($kontekst['stage'] ?? null) === 'kopie_wylaczone'
                && ($kontekst['stan'] ?? null) === StanKopiiBazy::WYLACZONA
                && str_contains($tresc, 'NIE są sprawdzane'))
            ->once();
        Log::shouldNotHaveReceived('error');
        Http::assertNothingSent();
    }

    public function test_aktywna_czujka_nie_pisze_ostrzezenia_o_wylaczeniu(): void
    {
        // Kontrola dodatnia: ślad jest cechą stanu WYŁĄCZONA, nie każdego
        // przebiegu — inaczej ostrzeżenie codziennie byłoby szumem.
        config()->set('kuking.kopie.dysk', 'r2_kopie');
        config()->set('kuking.kopie.prefiks', 'baza/');
        config()->set('filesystems.disks.r2_kopie.bucket', 'kuking-kopie-test');
        Storage::fake('r2_kopie');
        Storage::disk('r2_kopie')->put('baza/kuking-'.now('UTC')->format('Ymd-His').'Z.dump.cms', 'to-udaje-szyfrogram');
        Http::fake();
        Log::spy();

        $this->artisan('kuking:sprawdz-kopie --bez-alarmu')->assertSuccessful();

        Log::shouldNotHaveReceived('warning');
    }
}
