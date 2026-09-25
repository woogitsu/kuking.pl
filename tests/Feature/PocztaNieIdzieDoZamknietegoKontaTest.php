<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Poczta z `notify()` sprawdza stan konta w chwili wysyłki (audyt B8-05).
 *
 * Zadanie z kolejki czyta konto z bazy na nowo, a `User::routeNotificationForMail()`
 * oddaje `null` dla konta zamkniętego. Bez tego list zlecony przed banem albo
 * wymazaniem wychodził na stary adres albo na `usuniete+{id}@konto.kuking.pl`
 * — w naszej własnej domenie.
 *
 * Przez prawdziwy mailer `array`: `Notification::fake()` nie woła `MailChannel`,
 * więc nie widziałby ani trasy, ani `null`.
 */
class PocztaNieIdzieDoZamknietegoKontaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
    }

    /** @return array<string, array{string, int}> */
    public static function stany(): array
    {
        return [
            // Kontrola dodatnia: konta otwarte list dostają.
            'aktywne' => [User::STATUS_ACTIVE, 1],
            'zawieszone' => [User::STATUS_SUSPENDED, 1],
            'zablokowane' => [User::STATUS_BANNED, 0],
            'w karencji usunięcia' => [User::STATUS_PENDING_DELETE, 0],
            'wymazane' => [User::STATUS_ERASED, 0],
        ];
    }

    #[Test]
    #[DataProvider('stany')]
    public function list_z_kolejki_patrzy_na_stan_konta_w_chwili_wysylki(string $stan, int $ile): void
    {
        Queue::fake();
        $user = $this->user();

        // Zlecone, gdy konto było otwarte…
        $zadanie = serialize(new SendQueuedNotifications($user, $this->zwyklyList(), ['mail']));

        // …a wysyłane po zmianie stanu.
        $user->forceFill(['status' => $stan, 'data_erased_at' => $stan === User::STATUS_ERASED ? now() : null])->save();

        unserialize($zadanie)->handle(app(ChannelManager::class));

        $this->assertCount($ile, $this->transport()->messages());
    }

    #[Test]
    public function konto_w_karencji_dostaje_list_ustaw_nowe_haslo(): void
    {
        // Strona „Cofnij usunięcie konta" wymaga hasła i odsyła do odzyskiwania
        // hasła — ten jeden list musi dojść także do konta `pending_delete`.
        $user = $this->user();
        $user->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();
        $token = Password::createToken($user);

        $user->notifyNow(new UstawienieNowegoHasla($token, now()->addHour()));

        $this->assertCount(1, $this->transport()->messages());
    }

    #[Test]
    public function konto_zablokowane_nie_dostaje_nawet_listu_ustaw_nowe_haslo(): void
    {
        $user = $this->user();
        $user->forceFill(['status' => User::STATUS_BANNED])->save();
        $token = Password::createToken($user);

        $user->notifyNow(new UstawienieNowegoHasla($token, now()->addHour()));

        $this->assertCount(0, $this->transport()->messages());
    }

    private function zwyklyList(): Notification
    {
        return new ListProbnyDoKonta;
    }

    private function transport(): ArrayTransport
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport;
    }
}

/** Nazwana klasa, bo zadanie z kolejki musi dać się zserializować. */
final class ListProbnyDoKonta extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Zwykły list')->line('Treść.');
    }
}
