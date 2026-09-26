<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\MailFailure;
use App\Models\Recipe;
use App\Models\User;
use App\Notifications\PotwierdzenieAdresu;
use App\Poczta\PowodOdmowy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #746 — dwa widoki pokazywały godzinę UTC jako godzinę człowieka.
 *
 * `failed_at->format('H:i')` na ekranie potwierdzenia adresu i
 * `published->format('d.m.Y, H:i')` w panelu „bez odpowiedzi" omijały
 * `App\Support\Czas`. Latem to dwie godziny różnicy, zimą jedna, a tuż przed
 * północą UTC — także inny DZIEŃ. Strażnik statyczny patrzył wyłącznie na
 * `translatedFormat` i `diffForHumans`, więc gołe `format` przechodziło.
 *
 * Testy mierzą TEKST W ODPOWIEDZI, nie obecność nazwy pomocnika w źródle.
 */
class DatyBezStrefyNaEkranieTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function momenty(): array
    {
        // UTC w bazie → data i godzina, które człowiek ma przeczytać w Polsce.
        return [
            'lato, zmiana doby' => ['2026-07-01 22:30:00', '2.07.2026', '00:30'],
            'zima, zmiana doby' => ['2026-01-01 23:30:00', '2.01.2026', '00:30'],
        ];
    }

    #[DataProvider('momenty')]
    public function test_ekran_potwierdzenia_adresu_podaje_czas_polski(string $utc, string $data, string $godzina): void
    {
        $moment = CarbonImmutable::parse($utc, 'UTC');
        // „Teraz" chwilę po odmowie, żeby ślad mieścił się w oknie informacji.
        CarbonImmutable::setTestNow($moment->addMinutes(10));

        // Zdanie o nieudanej wysyłce pojawia się tylko przy działającej
        // poczcie (`Poczta::dziala()`); sterownik `array` z suity się nie
        // liczy. Ta sama konfiguracja co w `NieudanyListZostawiaSladTest`.
        config([
            'mail.default' => 'emaillabs',
            'services.emaillabs.key' => 'klucz-aplikacji-do-testu',
            'services.emaillabs.secret' => 'klucz-autoryzacyjny-do-testu',
            'services.emaillabs.smtp_account' => '1.kuking.smtp',
            'services.emaillabs.tracking' => false,
        ]);
        Mail::purge('emaillabs');

        $osoba = User::factory()->unverified()->create();

        $slad = new MailFailure;
        $slad->failed_job_uuid = (string) Str::uuid();
        $slad->powod = PowodOdmowy::TRWALA;
        $slad->status_http = 400;
        $slad->rodzaj = PotwierdzenieAdresu::class;
        $slad->kolejka = 'high';
        $slad->prob = 3;
        $slad->user_id = (string) $osoba->getKey();
        $slad->komunikat = 'Dostawca odmówił.';
        $slad->failed_at = $moment;
        $slad->save();

        $odpowiedz = $this->actingAs($osoba->fresh())->get('/potwierdz-email');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('Ostatnia wiadomość nie dotarła', false);
        $odpowiedz->assertSee('Wysłaliśmy ją '.$data, false);
        $odpowiedz->assertSee('o '.$godzina.',', false);
        $odpowiedz->assertDontSee($moment->format('H:i').',', false);
    }

    #[DataProvider('momenty')]
    public function test_panel_przepisow_bez_odpowiedzi_podaje_czas_polski(string $utc, string $data, string $godzina): void
    {
        $moment = CarbonImmutable::parse($utc, 'UTC');
        CarbonImmutable::setTestNow($moment->addDay());

        Recipe::factory()->create([
            'author_id' => $this->user()->getKey(),
            'title' => 'Rosół babci Zosi',
            'published_at' => $moment,
        ]);

        $this->sprawdzPanel('przepisy', $moment, $data, $godzina);
    }

    #[DataProvider('momenty')]
    public function test_panel_ugotowanych_bez_odpowiedzi_podaje_czas_polski(string $utc, string $data, string $godzina): void
    {
        $moment = CarbonImmutable::parse($utc, 'UTC');
        CarbonImmutable::setTestNow($moment->addDay());

        $przepis = Recipe::factory()->create([
            'author_id' => $this->user()->getKey(),
            'published_at' => $moment->subDay(),
        ]);

        CookedEvent::factory()->create([
            'user_id' => $this->user()->getKey(),
            'recipe_id' => $przepis->getKey(),
            'created_at' => $moment,
            'cooked_at' => $moment,
        ]);

        $this->sprawdzPanel('ugotowane', $moment, $data, $godzina);
    }

    private function sprawdzPanel(string $typ, CarbonImmutable $moment, string $data, string $godzina): void
    {
        [$dzien, $miesiac, $rok] = explode('.', $data);
        $oczekiwana = sprintf('%02d.%s.%s, %s', (int) $dzien, $miesiac, $rok, $godzina);

        $odpowiedz = $this->actingAs($this->moderator())->get('/admin/bez-odpowiedzi?typ='.$typ);

        $odpowiedz->assertOk();
        // Atrybut maszynowy zostaje momentem UTC — zmienia się tylko tekst dla człowieka.
        $odpowiedz->assertSee('datetime="'.$moment->toIso8601String().'">'.$oczekiwana.'</time>', false);
        $odpowiedz->assertDontSee('>'.$moment->format('d.m.Y, H:i').'</time>', false);
    }
}
