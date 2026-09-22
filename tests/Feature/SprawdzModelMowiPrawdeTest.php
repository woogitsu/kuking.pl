<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Komenda diagnostyczna, która myli się co do stanu rzeczy, jest gorsza niż
 * jej brak — bo po niej NIKT już nie sprawdza.
 *
 * PO CO ONA W OGÓLE POWSTAŁA
 * `KlientOpenAI` zwraca `null` przy każdej porażce: brak klucza, awaria sieci,
 * HTTP 401, nieznany kształt odpowiedzi. Dla aplikacji to jedyne poprawne
 * zachowanie — „nie wiemy" nie może znaczyć „treść jest w porządku", a cudza
 * awaria nie może zatrzymać czyjegoś wpisu. Ale to samo `null` sprawia, że
 * z zewnątrz nie da się odróżnić działającej moderacji od wyłączonej:
 * `/health` o niej milczy, a właściciel po wgraniu klucza nie ma jak sprawdzić,
 * czy trafił.
 *
 * Ten test pilnuje jednej rzeczy: że komenda mówi PRAWDĘ o tym, co zastała —
 * osobno dla braku klucza, osobno dla złego klucza, osobno dla działającego.
 */
class SprawdzModelMowiPrawdeTest extends TestCase
{
    private function zKluczem(): void
    {
        config()->set('kuking.moderation.model.klucz', 'sk-test-nieprawdziwy-klucz');
        config()->set('kuking.moderation.model.endpoint', 'https://api.openai.com/v1/moderations');
        config()->set('kuking.moderation.model.nazwa', 'omni-moderation-latest');
        config()->set('kuking.moderation.model.limit_czasu', 8);
    }

    #[Test]
    public function test_bez_klucza_mowi_ze_wylaczone_i_nie_puka_do_openai(): void
    {
        config()->set('kuking.moderation.model.klucz', null);
        Http::fake();

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('WYŁĄCZONA')
            ->assertExitCode(1);

        // Sedno: brak klucza NIE MOŻE wyglądać jak działająca moderacja,
        // a już na pewno nie może wysyłać zapytań bez uwierzytelnienia.
        Http::assertNothingSent();
    }

    #[Test]
    public function test_dziala_gdy_model_odpowie_i_nie_oznaczy_nieszkodliwej_probki(): void
    {
        $this->zKluczem();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'id' => 'modr-test',
                'model' => 'omni-moderation-latest',
                'results' => [[
                    'flagged' => false,
                    'categories' => ['violence' => false],
                    'category_scores' => ['violence' => 0.0001],
                ]],
            ]),
        ]);

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('Działa')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_zly_klucz_daje_rade_co_zrobic_a_nie_sam_kod_bledu(): void
    {
        $this->zKluczem();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('401')
            ->expectsOutputToContain('platform.openai.com')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_nieznana_nazwa_modelu_wskazuje_zmienna_do_poprawienia(): void
    {
        $this->zKluczem();
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'model not found']], 404)]);

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('KUKING_MODEL_NAZWA')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_awaria_po_stronie_openai_jest_nazwana_awaria_cudza(): void
    {
        $this->zKluczem();
        Http::fake(['api.openai.com/*' => Http::response('bad gateway', 502)]);

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('Awaria po stronie OpenAI')
            ->assertExitCode(1);
    }

    /**
     * Odpowiedź „200, ale to nie jest API moderacji" to najgorszy przypadek:
     * wygląda jak sukces. Musi być nazwany wprost.
     */
    #[Test]
    public function test_odpowiedz_bez_results_nie_jest_uznawana_za_sukces(): void
    {
        $this->zKluczem();
        Http::fake(['api.openai.com/*' => Http::response(['cokolwiek' => true])]);

        $this->artisan('kuking:sprawdz-model')
            ->expectsOutputToContain('nie ma pola')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_klucz_nie_pojawia_sie_w_wyniku_nawet_fragmentem(): void
    {
        config()->set('kuking.moderation.model.klucz', 'sk-tajny-1234567890');
        config()->set('kuking.moderation.model.endpoint', 'https://api.openai.com/v1/moderations');
        config()->set('kuking.moderation.model.nazwa', 'omni-moderation-latest');
        config()->set('kuking.moderation.model.limit_czasu', 8);

        Http::fake(['api.openai.com/*' => Http::response(['results' => [['flagged' => false]]])]);

        $this->artisan('kuking:sprawdz-model')->assertExitCode(0);

        $wynik = Artisan::output();

        $this->assertStringNotContainsString('sk-tajny', $wynik, 'Komenda wypisała klucz. Nigdy.');
        $this->assertStringNotContainsString('1234567890', $wynik, 'Komenda wypisała fragment klucza.');
    }
}
