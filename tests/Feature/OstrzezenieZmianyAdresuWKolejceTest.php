<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\ConfirmEmailChange;
use App\Domain\Users\Actions\RequestEmailChange;
use App\Notifications\PotwierdzenieNowegoAdresu;
use App\Notifications\ZgloszonaZmianaAdresu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OstrzezenieZmianyAdresuWKolejceTest extends TestCase
{
    use RefreshDatabase;

    public static function deliveryCases(): array
    {
        return [
            'przed potwierdzeniem' => [false, false],
            'po potwierdzeniu' => [true, false],
            'po potwierdzeniu bez profilu' => [true, true],
        ];
    }

    #[DataProvider('deliveryCases')]
    public function test_ostrzezenie_trafia_na_adres_z_chwili_zlecenia(bool $confirm, bool $deleteProfile): void
    {
        config(['mail.default' => 'array']);
        $kolejka = Queue::fake();
        $user = $this->user('ostrzezenie', ['email' => 'stary@example.test']);
        $change = app(RequestEmailChange::class)->handle($user, 'nowy@example.test');
        $jobs = $kolejka->pushed(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $warning = serialize($jobs->first(fn ($job) => $job->notification instanceof ZgloszonaZmianaAdresu));
        $confirmation = serialize($jobs->first(fn ($job) => $job->notification instanceof PotwierdzenieNowegoAdresu));

        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        unserialize($confirmation)->handle(app(ChannelManager::class));
        $this->assertSame('nowy@example.test', $transport->messages()->last()->getOriginalMessage()->getTo()[0]->getAddress());
        if ($confirm) {
            app(ConfirmEmailChange::class)->handle($user, $change);
            $this->assertSame('nowy@example.test', $user->fresh()->email);
        }
        if ($deleteProfile) {
            $user->profile()->delete();
        }

        unserialize($warning)->handle(app(ChannelManager::class));
        $this->assertCount(2, $transport->messages());
        $mail = $transport->messages()->last()->getOriginalMessage();
        $this->assertSame('stary@example.test', $mail->getTo()[0]->getAddress(), 'Ostrzeżenie musi trafić do starej skrzynki także po potwierdzeniu zmiany.');
        $this->assertStringContainsString('n***@example.test', $mail->getHtmlBody());
        $this->assertStringNotContainsString('nowy@example.test', $mail->getHtmlBody());
    }

    public function test_tresc_nie_obiecuje_stanu_konta_w_chwili_czytania(): void
    {
        $user = $this->user();
        $html = (string) (new ZgloszonaZmianaAdresu('n***@example.test', now()->addDay()))->toMail($user)->render();
        $this->assertStringContainsString('Zmień hasło', $html);
        $this->assertStringNotContainsString('Na razie nic się nie zmieniło', $html);
        $this->assertStringNotContainsString('Twoje konto nadal', $html);
    }
}
