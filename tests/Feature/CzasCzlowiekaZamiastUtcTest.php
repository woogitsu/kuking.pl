<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\MailFailure;
use App\Models\Recipe;
use App\Notifications\PotwierdzenieAdresu;
use App\Poczta\PowodOdmowy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ISSUE #746 — DWA WIDOKI OMIJAŁY `App\Support\Czas` I POKAZYWAŁY GODZINĘ
 * UTC JAKO GODZINĘ CZŁOWIEKA.
 *
 * `resources/views/auth/verify-email.blade.php` i
 * `resources/views/pages/admin/bez-odpowiedzi-inne.blade.php` wołały
 * `->format(...)` wprost na atrybucie `datetime` (UTC — `config('app.timezone')`),
 * zamiast przez `Czas::data()`. Latem różnica to dwie godziny, zimą jedna,
 * a przy północy — także inny dzień.
 *
 * Test zamraża moment TUŻ PRZED północą czasu polskiego, żeby błąd
 * ("15 lipca" zamiast "16 lipca") było widać, nie tylko błąd godziny —
 * dokładnie tak, jak `StrefaCzasowaTest::test_termin_na_granicy_doby...`
 * mierzy to samo dla innych ekranów.
 */
class CzasCzlowiekaZamiastUtcTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** 22:30 UTC = 16 lipca 2026, 00:30 czasu polskiego (lato, UTC+2). */
    private const MOMENT_LATO_UTC = '2026-07-15 22:30:00';

    private const OCZEKIWANA_DATA_LATO = '16.07.2026';

    private const OCZEKIWANA_GODZINA_LATO = '00:30';

    /** 22:30 UTC = 23:30 czasu polskiego (zima, UTC+1) — bez zmiany dnia. */
    private const MOMENT_ZIMA_UTC = '2026-01-15 22:30:00';

    private const OCZEKIWANA_DATA_ZIMA = '15.01.2026';

    private const OCZEKIWANA_GODZINA_ZIMA = '23:30';

    public function test_odmowa_wysylki_pokazuje_polska_date_i_godzine_na_granicy_doby(): void
    {
        $osoba = $this->user('czasodmowy', ['email_verified_at' => null]);

        $slad = new MailFailure;
        $slad->failed_job_uuid = (string) Str::uuid();
        $slad->powod = PowodOdmowy::TRWALA;
        $slad->status_http = 400;
        $slad->rodzaj = PotwierdzenieAdresu::class;
        $slad->kolejka = 'high';
        $slad->prob = 3;
        $slad->user_id = $osoba->getKey();
        $slad->komunikat = 'Dostawca odmówił.';
        $slad->failed_at = CarbonImmutable::parse(self::MOMENT_LATO_UTC, 'UTC');
        $slad->save();

        // Okno „prawdy" (`kuking.poczta.okno_prawdy_godzin`) pokazuje zdanie
        // tylko dla ŚWIEŻEJ odmowy — zamrażamy „teraz" tuż po awarii, tak jak
        // w `NieudanyListZostawiaSladTest`, zamiast polegać na realnym zegarze.
        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(self::MOMENT_LATO_UTC, 'UTC')->addMinutes(5),
        );

        $html = (string) $this->actingAs($osoba)
            ->get('/potwierdz-email')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            self::OCZEKIWANA_DATA_LATO,
            $html,
            'Ekran pokazuje datę UTC (15.07.2026) zamiast polskiej (16.07.2026) — '
            .'22:30 UTC to już następny dzień w Europe/Warsaw.',
        );
        $this->assertStringContainsString(
            self::OCZEKIWANA_GODZINA_LATO,
            $html,
            'Ekran pokazuje godzinę UTC (22:30) zamiast polskiej (00:30).',
        );
    }

    public function test_odmowa_wysylki_pokazuje_polska_date_i_godzine_zima(): void
    {
        $osoba = $this->user('czasodmowyzima', ['email_verified_at' => null]);

        $slad = new MailFailure;
        $slad->failed_job_uuid = (string) Str::uuid();
        $slad->powod = PowodOdmowy::TRWALA;
        $slad->status_http = 400;
        $slad->rodzaj = PotwierdzenieAdresu::class;
        $slad->kolejka = 'high';
        $slad->prob = 3;
        $slad->user_id = $osoba->getKey();
        $slad->komunikat = 'Dostawca odmówił.';
        $slad->failed_at = CarbonImmutable::parse(self::MOMENT_ZIMA_UTC, 'UTC');
        $slad->save();

        CarbonImmutable::setTestNow(
            CarbonImmutable::parse(self::MOMENT_ZIMA_UTC, 'UTC')->addMinutes(5),
        );

        $html = (string) $this->actingAs($osoba)
            ->get('/potwierdz-email')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(self::OCZEKIWANA_DATA_ZIMA, $html);
        $this->assertStringContainsString(self::OCZEKIWANA_GODZINA_ZIMA, $html);
    }

    public function test_panel_przepisow_bez_odpowiedzi_pokazuje_polska_date_lato(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autorprzepisulato');

        Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'title' => 'Rosół na czas',
            'published_at' => CarbonImmutable::parse(self::MOMENT_LATO_UTC, 'UTC'),
        ]);

        $html = (string) $this->actingAs($moderator)
            ->get(route('admin.unanswered', ['typ' => 'przepisy']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            self::OCZEKIWANA_DATA_LATO.', '.self::OCZEKIWANA_GODZINA_LATO,
            $html,
            'Panel przepisów bez odpowiedzi pokazuje datę/godzinę UTC zamiast polskiej.',
        );
    }

    public function test_panel_ugotowanych_bez_odpowiedzi_pokazuje_polska_date_zima(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autorugotowanezima');
        $kucharz = $this->user('kucharzugotowanezima');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subWeek(),
        ]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::MOMENT_ZIMA_UTC, 'UTC'));

        try {
            app(RecordCookedEvent::class)->handle($kucharz, $przepis, 'Wyszło.');
        } finally {
            CarbonImmutable::setTestNow();
        }

        $html = (string) $this->actingAs($moderator)
            ->get(route('admin.unanswered', ['typ' => 'ugotowane']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            self::OCZEKIWANA_DATA_ZIMA.', '.self::OCZEKIWANA_GODZINA_ZIMA,
            $html,
            'Panel wykonań bez odpowiedzi pokazuje datę/godzinę UTC zamiast polskiej.',
        );
    }
}
