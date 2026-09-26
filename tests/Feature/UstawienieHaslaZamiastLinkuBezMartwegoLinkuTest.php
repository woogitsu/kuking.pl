<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\UstawienieHaslaZamiastLinku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * List „ustaw hasło" dla konta bez potwierdzonego adresu nie wychodzi
 * z martwym linkiem (audyt B8-04).
 *
 * Bliźniak `UstawienieNowegoHasla` ma od #599 strażnika w `shouldSend()`:
 * token nadal istnieje w brokerze i nie wygasł. Ta klasa — ten sam token,
 * ta sama trasa — go nie miała, więc po postoju workera i drugiej prośbie
 * (broker zastępuje token) wychodził list, którego link już nie działał.
 *
 * Każdy przypadek przechodzi przez PRAWDZIWE zadanie z kolejki
 * (serializacja → `handle()`) i mailer `array`, nie przez `Notification::fake()`,
 * które `shouldSend()` pomija.
 */
class UstawienieHaslaZamiastLinkuBezMartwegoLinkuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['mail.default' => 'array', 'auth.passwords.users.expire' => 60]);
        Queue::fake();
    }

    #[Test]
    public function swiezy_token_wychodzi(): void
    {
        // Kontrola dodatnia: bez niej trzy testy niżej przeszłyby także
        // wtedy, gdyby list nie wychodził nigdy.
        [$user, $zadanie] = $this->zakolejkowany();

        $this->dostarcz($zadanie, 1);
    }

    #[Test]
    public function usuniety_token_nie_wychodzi(): void
    {
        [$user, $zadanie] = $this->zakolejkowany();
        Password::deleteToken($user);

        $this->dostarcz($zadanie, 0);
    }

    #[Test]
    public function zastapiony_token_nie_wychodzi(): void
    {
        [$user, $zadanie] = $this->zakolejkowany();
        Password::createToken($user);

        $this->dostarcz($zadanie, 0);
    }

    #[Test]
    public function wygasly_token_nie_wychodzi(): void
    {
        [, $zadanie] = $this->zakolejkowany();
        $this->travel(3601)->seconds();

        $this->dostarcz($zadanie, 0);
    }

    /** @return array{User, string} */
    private function zakolejkowany(): array
    {
        $user = $this->user();
        $token = Password::createToken($user);

        return [$user, serialize(new SendQueuedNotifications($user, new UstawienieHaslaZamiastLinku($token), ['mail']))];
    }

    private function dostarcz(string $zadanie, int $ile): void
    {
        unserialize($zadanie)->handle(app(ChannelManager::class));
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount($ile, $transport->messages(), 'List z nieaktualnym tokenem nie może trafić do transportu.');
    }
}
