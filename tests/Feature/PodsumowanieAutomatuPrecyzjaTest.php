<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
use App\Notifications\PodsumowanieKolejkiAutomatu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PodsumowanieAutomatuPrecyzjaTest extends TestCase
{
    use RefreshDatabase;

    private function sprawdzList(PodsumowanieKolejkiAutomatu $notification, string $okres, int $nowe = 2, int $czekaja = 3): void
    {
        $mail = $notification->toMail(new \stdClass);
        $this->assertSame('Kuking: nowe oznaczenia automatu ('.$nowe.')', $mail->subject);
        $html = (string) $mail->render();
        $plain = (string) app(Markdown::class)->renderText($mail->markdown, $mail->data());
        foreach ([html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $plain] as $text) {
            $text = preg_replace('/\s+/u', ' ', str_replace('**', '', $text));
            $this->assertStringContainsString($okres.' automat oznaczył '.$nowe, $text, 'OKRES_LISTU');
            $this->assertStringContainsString('W kolejce czeka łącznie '.$czekaja, $text);
            $this->assertStringContainsString('Automat sam nie ukrywa treści ani nie blokuje kont.', $text, 'MECHANIZM_AUTOMATU');
            $this->assertStringNotContainsString('o niczym nie wiedzą', $text, 'OBIETNICA_WIEDZY');
            $this->assertStringNotContainsString('widoczne normalnie', $text, 'OBIETNICA_WIDOCZNOSCI');
        }
        $this->assertSame(route('admin.sygnaly'), $mail->actionUrl);
        $this->assertStringContainsString(route('admin.sygnaly'), $plain);
    }

    public function test_stary_payload_bez_okresu_renderuje_neutralny_opis(): void
    {
        // Fixture pochodzi z rzeczywistej klasy ce82638, przed dodaniem pola okresu.
        $payload = base64_decode(file_get_contents(base_path('tests/Fixtures/podsumowanie-automatu-ce82638.b64')), true);
        $this->assertIsString($payload);
        $notification = unserialize($payload, ['allowed_classes' => [PodsumowanieKolejkiAutomatu::class]]);
        $this->assertInstanceOf(PodsumowanieKolejkiAutomatu::class, $notification);
        $this->sprawdzList($notification, 'W okresie objętym podsumowaniem');
    }

    public function test_nowe_okresy_przezywaja_serializacje_i_stary_konstruktor_dziala(): void
    {
        $this->sprawdzList(new PodsumowanieKolejkiAutomatu(2, 3, []), 'W ciągu ostatnich 24 godzin');
        foreach ([1, 24, 48] as $hours) {
            $notification = unserialize(serialize(new PodsumowanieKolejkiAutomatu(2, 3, [], $hours)));
            $this->sprawdzList($notification, $hours === 1 ? 'W ciągu ostatniej godziny' : 'W ciągu ostatnich '.$hours.' godzin');
        }
    }

    public function test_komenda_przekazuje_okres_i_liczy_takze_rozpatrzone_zgloszenia(): void
    {
        $this->freezeTime();
        config(['kuking.moderation.model.alarm_email' => 'moderacja@example.test']);
        foreach ([0, 2, 25, 49] as $hours) {
            $post = Post::factory()->create(['status' => Post::STATUS_PUBLISHED, 'visibility' => 'private']);
            $report = Report::create([
                'target_type' => 'post', 'target_id' => $post->getKey(),
                'source' => Report::SOURCE_AUTOMAT, 'reason' => 'harassment',
                'status' => $hours === 0 ? Report::STATUS_RESOLVED : Report::STATUS_OPEN,
            ]);
            $report->forceFill(['created_at' => now()->subHours($hours)])->save();
        }
        foreach ([1 => 1, 24 => 2, 48 => 3] as $hours => $count) {
            Notification::fake();
            $this->artisan('kuking:podsumowanie-automatu', ['--godzin' => $hours])->assertSuccessful();
            Notification::assertSentOnDemand(PodsumowanieKolejkiAutomatu::class, function ($notification) use ($hours, $count) {
                $this->sprawdzList($notification, $hours === 1 ? 'W ciągu ostatniej godziny' : 'W ciągu ostatnich '.$hours.' godzin', $count);

                return true;
            });
            Notification::assertSentOnDemandTimes(PodsumowanieKolejkiAutomatu::class, 1);
        }
    }
}
