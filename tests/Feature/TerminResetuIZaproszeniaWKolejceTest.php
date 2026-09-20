<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\WyslijZaproszenieDoRejestracji;
use App\Models\RegistrationInvite;
use App\Notifications\UstawienieNowegoHasla;
use App\Notifications\ZaproszenieDoZalozeniaKonta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TerminResetuIZaproszeniaWKolejceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['mail.default' => 'array', 'auth.passwords.users.expire' => 60]);
        Queue::fake();
    }

    public static function delays(): array
    {
        return ['od razu' => [0, 1], 'sekunda przed' => [3599, 1], 'dokładnie termin' => [3600, 0], 'po terminie' => [3601, 0]];
    }

    #[DataProvider('delays')]
    public function test_reset_zachowuje_termin_zadania(int $seconds, int $count): void
    {
        $user = $this->user();
        $this->assertSame(Password::RESET_LINK_SENT, Password::sendResetLink(['email' => $user->email]));
        $payload = serialize(Queue::pushed(SendQueuedNotifications::class)->sole());
        $before = (array) DB::table('password_reset_tokens')->sole();
        $this->travel($seconds)->seconds();
        // Worker startuje z nową konfiguracją; stare żądanie nie dostaje nowego terminu.
        config(['auth.passwords.users.expire' => 120]);
        app()->forgetInstance('auth.password');
        Password::clearResolvedInstance('auth.password');
        $this->deliver($payload, $count);
        $this->assertSame($before, (array) DB::table('password_reset_tokens')->sole());
        if ($count) {
            $this->assertStringContainsString('przez godzinę od chwili zamówienia', $this->html());
        }
    }

    #[DataProvider('delays')]
    public function test_zaproszenie_uzywa_przekazanej_daty(int $seconds, int $count): void
    {
        config(['kuking.login_link.zaproszenia.waznosc_godzin' => 1]);
        $this->assertTrue(app(WyslijZaproszenieDoRejestracji::class)->handle('zaproszenie@example.com'));
        $payload = serialize(Queue::pushed(SendQueuedNotifications::class)->sole());
        $before = RegistrationInvite::sole()->getAttributes();
        $this->travel($seconds)->seconds();
        config(['kuking.login_link.zaproszenia.waznosc_godzin' => 24]);
        $this->deliver($payload, $count);
        $this->assertSame($before, RegistrationInvite::sole()->getAttributes());
        if ($count) {
            $this->assertStringContainsString('przez godzinę od chwili zamówienia', $this->html());
        }
    }

    public static function channels(): array
    {
        return ['reset' => [false], 'zaproszenie' => [true]];
    }

    #[DataProvider('channels')]
    public function test_wczesniejszy_argument_ogranicza_tresc_i_wysylke(bool $invite): void
    {
        [$recipient, $token] = $this->credentials($invite);
        $notification = $invite
            ? new ZaproszenieDoZalozeniaKonta($token, now()->addMinutes(10))
            : new UstawienieNowegoHasla($token, now()->addMinutes(10));
        $payload = serialize(new SendQueuedNotifications($recipient, $notification, ['mail']));
        $this->deliver($payload, 1);
        $this->assertStringContainsString('przez 10 min. od chwili zamówienia', $this->html());
        $this->travel(10)->minutes();
        $this->deliver($payload, 1);
    }

    #[DataProvider('channels')]
    public function test_stare_zadanie_bez_daty_czyta_baze(bool $invite): void
    {
        [$recipient, $token] = $this->credentials($invite);
        $notification = $invite ? new ZaproszenieDoZalozeniaKonta($token) : new UstawienieNowegoHasla($token);
        // Dawny reset w ogóle nie miał właściwości wygasa, zaproszenie miało null.
        if (! $invite) {
            $notification = (new \ReflectionClass(UstawienieNowegoHasla::class))->newInstanceWithoutConstructor();
            $notification->token = $token;
        }
        $payload = serialize(new SendQueuedNotifications($recipient, $notification, ['mail']));
        $this->deliver($payload, 1);
        $this->assertStringContainsString('przez godzinę od chwili zamówienia', $this->html());
        $this->travel(3601)->seconds();
        $this->deliver($payload, 1);
    }

    #[DataProvider('channels')]
    public function test_usuniety_token_nie_wychodzi(bool $invite): void
    {
        [$recipient, $token] = $this->credentials($invite);
        $notification = $invite ? new ZaproszenieDoZalozeniaKonta($token, now()->addHour()) : new UstawienieNowegoHasla($token, now()->addHour());
        $payload = serialize(new SendQueuedNotifications($recipient, $notification, ['mail']));
        $invite ? RegistrationInvite::query()->delete() : Password::deleteToken($recipient);
        $this->deliver($payload, 0);
    }

    #[DataProvider('channels')]
    public function test_zastapiony_token_nie_wychodzi(bool $invite): void
    {
        [$recipient, $token] = $this->credentials($invite);
        $notification = $invite ? new ZaproszenieDoZalozeniaKonta($token, now()->addHour()) : new UstawienieNowegoHasla($token, now()->addHour());
        $payload = serialize(new SendQueuedNotifications($recipient, $notification, ['mail']));
        if ($invite) {
            RegistrationInvite::sole()->forceFill(['token_hash' => RegistrationInvite::skrot(RegistrationInvite::nowyToken())])->save();
        } else {
            Password::createToken($recipient);
        }
        $this->deliver($payload, 0);
    }

    private function credentials(bool $invite): array
    {
        if (! $invite) {
            $user = $this->user();

            return [$user, Password::createToken($user)];
        }
        $token = RegistrationInvite::nowyToken();
        (new RegistrationInvite)->forceFill([
            'email' => 'zaproszenie@example.com', 'token_hash' => RegistrationInvite::skrot($token),
            'created_at' => now(), 'expires_at' => now()->addHour(),
        ])->save();

        return [(new AnonymousNotifiable)->route('mail', 'zaproszenie@example.com'), $token];
    }

    private function deliver(string $payload, int $count): void
    {
        unserialize($payload)->handle(app(ChannelManager::class));
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertCount($count, $transport->messages(), 'Nieaktualny token nie może trafić do transportu.');
    }

    private function html(): string
    {
        return app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getHtmlBody();
    }
}
