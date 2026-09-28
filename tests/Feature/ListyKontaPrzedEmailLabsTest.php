<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\PotwierdzenieAdresu;
use App\Notifications\UstawienieNowegoHasla;
use App\Poczta\SladySledzeniaOtwarc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Pomiar #204 na granicy aplikacja → API, bez wysyłania listów do ludzi.
 * Nie mierzy treści DORĘCZONEJ ani ustawienia Open Tracking u dostawcy.
 */
final class ListyKontaPrzedEmailLabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_hasla_i_potwierdzenie_adresu_wychodza_bez_obrazkow(): void
    {
        config([
            'mail.default' => 'emaillabs',
            'services.emaillabs.key' => 'atrapa-klucza-aplikacji',
            'services.emaillabs.secret' => 'atrapa-klucza-autoryzacji',
            'services.emaillabs.smtp_account' => '1.test.smtp',
            'services.emaillabs.endpoint' => 'https://api.emaillabs.io/v2.1/email',
            'services.emaillabs.tracking' => false,
        ]);
        Mail::purge('emaillabs');
        Http::preventStrayRequests();
        Http::fake(['https://api.emaillabs.io/v2.1/email' => Http::response([
            'meta' => ['numberOfErrors' => 0, 'numberOfData' => 1],
            'data' => [['to' => ['messageId' => 'pomiar-lokalny']]],
        ])]);

        $user = $this->user(null, ['email' => 'pomiar@example.com', 'email_verified_at' => null]);

        // Rzeczywisty kanał renderuje widok i przepuszcza go przez transport;
        // atrapa stoi dopiero przed siecią, nie zamiast wysyłki Laravela.
        foreach ([new UstawienieNowegoHasla('atrapa-tokena'), new PotwierdzenieAdresu] as $notification) {
            app(MailChannel::class)->send($user, $notification);
        }

        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $body = $request->data();
            $html = $body['content']['html'];
            $traces = SladySledzeniaOtwarc::wHtml($html);

            $this->assertGreaterThan(200, mb_strlen($html));
            $this->assertGreaterThan(0, $traces->adresow);
            $this->assertSame([], $traces->slady, 'Treść przed API zawiera ślad śledzenia.');
            $this->assertDoesNotMatchRegularExpression('/<img\b/i', $html);
            $this->assertSame('1', $body['headers']['X-TRACKING-OFF'] ?? null, 'Brak wyłączenia śledzenia kliknięć.');
            $this->assertSame('1.test.smtp', $body['smtpAccount']);
        }
        $requests = Http::recorded();
        $this->assertSame('Ustaw nowe hasło do Kuking', $requests[0][0]->data()['subject']);
        $this->assertStringContainsString('atrapa-tokena', $requests[0][0]->data()['content']['html']);
        $this->assertStringContainsString('/potwierdz-email/', $requests[1][0]->data()['content']['html']);
    }
}
