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

/**
 * Ostrzeżenie o zmianie adresu trafia na adres z chwili prośby (#888).
 *
 * CO BYŁO ZEPSUTE
 * `RequestEmailChange` wysyłał ostrzeżenie przez `$user->notify()`. Klasa
 * idzie kolejką, a `SendQueuedNotifications` zapisuje odbiorcę jako
 * identyfikator modelu i odtwarza go z bazy dopiero przy wykonaniu. Gdy
 * worker ruszył po potwierdzeniu zmiany, `users.email` był już NOWY —
 * i jedyne ostrzeżenie dla właściciela trafiało do skrzynki napastnika.
 *
 * DLACZEGO PRAWDZIWA SERIALIZACJA, A NIE `Notification::fake()`
 * `assertSentTo($user)` przechodzi w obu wersjach kodu — sprawdza, KOMU
 * zlecono list, nie DOKĄD pójdzie. Tu job jest serializowany jak w kolejce,
 * wykonywany po zmianie adresu, a adres czytamy z transportu pamięciowego.
 * Żaden list nie wychodzi poza proces (`ArrayTransport`).
 */
class OstrzezenieZmianyAdresuWKolejceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{bool, bool}> */
    public static function przypadki(): array
    {
        return [
            // Kontrola dodatnia: zwykła dostawa przed potwierdzeniem.
            'przed potwierdzeniem' => [false, false],
            'po potwierdzeniu' => [true, false],
            'po potwierdzeniu i bez profilu' => [true, true],
        ];
    }

    #[DataProvider('przypadki')]
    public function test_ostrzezenie_trafia_na_adres_z_chwili_prosby(bool $potwierdz, bool $bezProfilu): void
    {
        config(['mail.default' => 'array']);
        $kolejka = Queue::fake();

        $user = $this->user('ostrzezenie', ['email' => 'stary@example.test', 'display_name' => 'Basia']);
        $zmiana = app(RequestEmailChange::class)->handle($user, 'nowy@example.test');

        $zadania = $kolejka->pushed(SendQueuedNotifications::class);
        $this->assertCount(2, $zadania);
        $ostrzezenie = serialize($zadania->first(fn ($z) => $z->notification instanceof ZgloszonaZmianaAdresu));
        $this->assertNotNull($zadania->first(fn ($z) => $z->notification instanceof PotwierdzenieNowegoAdresu));

        if ($potwierdz) {
            app(ConfirmEmailChange::class)->handle($user, $zmiana);
            $this->assertSame('nowy@example.test', $user->fresh()->email);
        }

        if ($bezProfilu) {
            $user->profile()->delete();
        }

        unserialize($ostrzezenie)->handle(app(ChannelManager::class));

        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $list = $transport->messages()->sole()->getOriginalMessage();

        $this->assertSame(
            'stary@example.test',
            $list->getTo()[0]->getAddress(),
            'Ostrzeżenie musi trafić do starej skrzynki także wtedy, gdy kolejka wykona je po potwierdzeniu zmiany.',
        );
        $this->assertStringContainsString('n***@example.test', $list->getHtmlBody());
        $this->assertStringNotContainsString('nowy@example.test', $list->getHtmlBody());
        // Imię jest kopią z chwili prośby — worker nie sięga do profilu.
        $this->assertStringContainsString('Basia,', $list->getHtmlBody());
    }

    /** Spóźniony list nie może obiecywać, że „nic się nie zmieniło". */
    public function test_tresc_nie_obiecuje_stanu_konta_w_chwili_czytania(): void
    {
        $html = (string) (new ZgloszonaZmianaAdresu('n***@example.test', now()->addDay()))
            ->toMail($this->user())
            ->render();

        $this->assertStringContainsString('Sama prośba nie zmienia adresu konta', $html);
        $this->assertStringContainsString('Zmień hasło', $html);
        $this->assertStringNotContainsString('Na razie nic się nie zmieniło', $html);
    }
}
