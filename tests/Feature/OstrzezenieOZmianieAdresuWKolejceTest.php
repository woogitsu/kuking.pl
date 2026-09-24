<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\ConfirmEmailChange;
use App\Domain\Users\Actions\RequestEmailChange;
use App\Models\User;
use App\Notifications\ZgloszonaZmianaAdresu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/**
 * Ostrzeżenie „ktoś prosi o zmianę adresu" trafia na STARY adres także wtedy,
 * gdy worker wykona je dopiero po potwierdzeniu nowego (issue #888).
 *
 * ZGŁOSZENIE
 * Ostrzeżenie szło zakolejkowanym `$user->notify()`. Zadanie w kolejce
 * trzyma wtedy tylko identyfikator konta, a adres worker czyta z bazy
 * w chwili wysyłki. Kilka workerów, opóźnienie albo ponowienie — i gdy ktoś
 * zdążył kliknąć link na nowej skrzynce, ostrzeżenie szło na NOWY adres,
 * czyli do tego, przed kim miało ostrzegać.
 *
 * Test przechodzi całą drogę: prawdziwe zadanie z kolejki, `serialize()`,
 * potwierdzenie zmiany prawdziwą akcją, `unserialize()`, wykonanie — i patrzy
 * na adresata w TRANSPORCIE, nie na `assertSentTo(User)`, które niczego
 * o adresie nie mówi.
 */
final class OstrzezenieOZmianieAdresuWKolejceTest extends TestCase
{
    use RefreshDatabase;

    private QueueFake $kolejka;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.default' => 'array']);
        $this->kolejka = Queue::fake();
    }

    public function test_ostrzezenie_wykonane_po_potwierdzeniu_idzie_na_stary_adres(): void
    {
        [$basia, $zadanie] = $this->zamowIZatrzymajOstrzezenie();

        $zmiana = $basia->fresh()->pendingEmailChange()->sole();
        app(ConfirmEmailChange::class)->handle($basia->fresh(), $zmiana);
        $this->assertSame('nowa.basia@example.test', $basia->fresh()->email, 'Zmiana powinna już być potwierdzona.');

        unserialize($zadanie)->handle(app(ChannelManager::class));

        $this->assertSame(['basia@example.test'], $this->adresaciOstrzezenia());
    }

    /** Kontrola: zwykła dostawa przed potwierdzeniem — też stary adres. */
    public function test_ostrzezenie_wykonane_od_razu_idzie_na_stary_adres(): void
    {
        [, $zadanie] = $this->zamowIZatrzymajOstrzezenie();

        unserialize($zadanie)->handle(app(ChannelManager::class));

        $this->assertSame(['basia@example.test'], $this->adresaciOstrzezenia());
    }

    /** @return array{User, string} */
    private function zamowIZatrzymajOstrzezenie(): array
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        app(RequestEmailChange::class)->handle($basia, 'nowa.basia@example.test');

        $ostrzezenie = $this->kolejka->pushed(
            SendQueuedNotifications::class,
            static fn (SendQueuedNotifications $job): bool => $job->notification instanceof ZgloszonaZmianaAdresu,
        )->sole();

        return [$basia, serialize($ostrzezenie)];
    }

    /** @return list<string> */
    private function adresaciOstrzezenia(): array
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        $list = $transport->messages()->sole()->getOriginalMessage();
        $this->assertStringContainsString('zmianę adresu', (string) $list->getSubject());
        // Imię też jest utrwalone w chwili prośby — list nie sięga do konta.
        $this->assertStringContainsString('Testowa osoba,', (string) $list->getHtmlBody());

        return array_map(static fn (Address $a): string => $a->getAddress(), $list->getTo());
    }
}
