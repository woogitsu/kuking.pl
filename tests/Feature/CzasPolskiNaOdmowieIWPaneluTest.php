<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\PotwierdzenieAdresu;
use App\Models\CookedEvent;
use App\Models\MailFailure;
use App\Models\Recipe;
use App\Poczta\PowodOdmowy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dwa widoki pokazywały godzinę UTC jako godzinę człowieka (#746).
 *
 * Ekran „Potwierdź adres e-mail" (odmowa wysyłki) i panel „bez odpowiedzi"
 * dla przepisów i wykonań formatowały datę zwykłym `format()`, z pominięciem
 * `App\Support\Czas`. Strażnik `StrefaCzasowaTest` patrzy tylko na
 * `translatedFormat` i `diffForHumans`, więc tego nie widział.
 *
 * Momenty są wybrane tak, żeby UTC i Polska różniły się też DNIEM:
 * 22:30 UTC latem to już 00:30 następnego dnia, zimą to samo daje 23:30 UTC.
 * Asercje sprawdzają tekst w HTML, nie obecność pomocnika w źródle.
 */
class CzasPolskiNaOdmowieIWPaneluTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, string, string}> */
    public static function momenty(): array
    {
        return [
            'lato (UTC+2)' => ['2026-07-01 22:30:00', '2.07.2026', '00:30', '1.07.2026'],
            'zima (UTC+1)' => ['2026-01-01 23:30:00', '2.01.2026', '00:30', '1.01.2026'],
        ];
    }

    #[DataProvider('momenty')]
    public function test_odmowa_wysylki_pokazuje_czas_polski(string $utc, string $data, string $godzina, string $dataUtc): void
    {
        Mail::fake();
        $moment = Carbon::parse($utc, 'UTC');
        // Zamrożone „teraz" tuż po odmowie — mieści się w oknie informacji.
        $this->travelTo($moment->copy()->addMinutes(10));

        $osoba = $this->user(null, ['email_verified_at' => null]);

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

        $html = $this->actingAs($osoba)->get(route('verification.notice'))->assertOk()->getContent();

        // Kontrola dodatnia: zdanie o odmowie w ogóle jest na ekranie.
        $this->assertStringContainsString('Ostatnia wiadomość nie dotarła.', $html);
        $tekst = preg_replace('/\s+/u', ' ', strip_tags($html));
        $this->assertStringContainsString("Wysłaliśmy ją {$data} o {$godzina},", $tekst);
        $this->assertStringNotContainsString("Wysłaliśmy ją {$dataUtc}", $tekst);
    }

    /** @return array<string, array{string, string, string, string}> */
    public static function momentyPanelu(): array
    {
        return [
            'lato (UTC+2)' => ['2026-07-01 22:30:00', '02.07.2026, 00:30', '01.07.2026, 22:30', '2026-07-01T22:30:00+00:00'],
            'zima (UTC+1)' => ['2026-01-01 23:30:00', '02.01.2026, 00:30', '01.01.2026, 23:30', '2026-01-01T23:30:00+00:00'],
        ];
    }

    #[DataProvider('momentyPanelu')]
    public function test_panel_bez_odpowiedzi_pokazuje_czas_polski_dla_przepisow_i_wykonan(string $utc, string $polski, string $surowyUtc, string $iso): void
    {
        $moment = Carbon::parse($utc, 'UTC');
        $host = $this->moderator();

        Recipe::factory()->create(['author_id' => $this->user()->id, 'published_at' => $moment]);
        CookedEvent::factory()->create([
            'recipe_id' => Recipe::factory()->create(['author_id' => $this->user()->id])->id,
            'user_id' => $this->user()->id,
            'created_at' => $moment,
        ]);

        foreach (['przepisy', 'ugotowane'] as $typ) {
            $odpowiedz = $this->actingAs($host)->get(route('admin.unanswered', ['typ' => $typ]))->assertOk();
            // Kontrola dodatnia: pozycja z tym momentem jest na liście.
            $odpowiedz->assertViewHas('items', fn ($items) => $items->contains(
                fn ($item) => ($typ === 'przepisy' ? $item->published_at : $item->created_at)->equalTo($moment),
            ));

            $html = $odpowiedz->getContent();
            $this->assertStringContainsString('<time datetime="'.$iso.'">'.$polski.'</time>', $html, "Widok „{$typ}\" nie pokazał czasu polskiego.");
            $this->assertStringNotContainsString($surowyUtc, $html, "Widok „{$typ}\" pokazał czas UTC.");
        }

    }
}
