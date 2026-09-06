<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Notifications\PotwierdzenieAdresu;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Env;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Issue #79 — listy z systemu przychodziły po angielsku.
 *
 * Osoba po sześćdziesiątce, która zapomniała hasła, dostawała wiadomość
 * po angielsku od nieznanego nadawcy z przyciskiem „Reset Password" —
 * czyli coś, co wygląda dokładnie jak phishing, przed którym ostrzegają
 * ją w telewizji i w banku. To jest jedyna droga powrotu na konto,
 * więc zamknięcie jej znaczy utratę konta na stałe.
 *
 * Test celowo sprawdza CAŁĄ wysłaną wiadomość (temat + treść HTML + treść
 * tekstowa), a nie sam szablon: część napisów dokłada Laravel po drodze
 * (powitanie, podpis, stopka, tekst „jeśli przycisk nie działa"), więc
 * sprawdzenie samego widoku przepuściłoby angielski.
 */
final class ListyZSystemuPoPolskuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Angielskie frazy kotwiczące z domyślnych wiadomości Laravela.
     *
     * Każda z nich jest w oryginale widoczna dla człowieka — nie w kodzie
     * ani w nagłówkach technicznych.
     *
     * @var list<string>
     */
    private const ANGIELSKIE_KOTWICE = [
        'Regards',
        "If you're having trouble",
        'Reset Password',
        'Reset your password',
        'Verify Email Address',
        'Verify your email address',
        'This link will expire',
        'This password reset link will expire',
        'Hello!',
        'Whoops!',
        'All rights reserved',
        'You are receiving this email',
        'no further action is required',
        'Please click the button below',
    ];

    public function test_list_z_nowym_haslem_jest_po_polsku(): void
    {
        $user = $this->user(null, ['email' => 'basia@example.com']);

        $user->sendPasswordResetNotification('token-testowy');

        $wiadomosc = $this->ostatniaWiadomosc();

        // NAJPIERW dowód, że cokolwiek się wyrenderowało. Bez tego asercja
        // „nie ma angielskiego" przechodzi także na pustej wiadomości.
        $this->assertNotSame('', trim((string) $wiadomosc->getSubject()));
        $this->assertGreaterThan(200, mb_strlen($this->tresc($wiadomosc)));

        $this->assertStringContainsString('hasło', (string) $wiadomosc->getSubject());
        $this->assertStringContainsString('Ustaw nowe hasło', $this->tresc($wiadomosc));
        $this->assertStringContainsString('Jeśli przycisk nie działa', $this->tresc($wiadomosc));
        $this->assertStringContainsString('token-testowy', $this->tresc($wiadomosc));

        $this->assertBezAngielskiego($wiadomosc);
    }

    public function test_list_z_potwierdzeniem_adresu_jest_po_polsku(): void
    {
        $user = $this->user(null, ['email' => 'basia@example.com', 'email_verified_at' => null]);

        $user->sendEmailVerificationNotification();

        $wiadomosc = $this->ostatniaWiadomosc();

        $this->assertNotSame('', trim((string) $wiadomosc->getSubject()));
        $this->assertGreaterThan(200, mb_strlen($this->tresc($wiadomosc)));

        $this->assertStringContainsString('adres', $wiadomosc->getSubject());
        $this->assertStringContainsString('Potwierdź', $this->tresc($wiadomosc));
        $this->assertStringContainsString('Jeśli przycisk nie działa', $this->tresc($wiadomosc));
        $this->assertStringContainsString('/potwierdz-email/', $this->tresc($wiadomosc));

        $this->assertBezAngielskiego($wiadomosc);
    }

    /**
     * Klasy powiadomień są nasze, ale ktoś kiedyś napisze zwykły
     * `MailMessage` — wtedy treść leci szablonem `notifications::email`
     * Laravela. Ten szablon puszcza KAŻDY swój napis przez `__()`,
     * więc `lang/pl.json` musi go tłumaczyć w całości.
     */
    public function test_domyslny_szablon_powiadomien_tez_jest_po_polsku(): void
    {
        $user = $this->user();

        $user->notify(new class extends Notification
        {
            /** @return list<string> */
            public function via(mixed $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(mixed $notifiable): MailMessage
            {
                return (new MailMessage)
                    ->subject('Wiadomość testowa')
                    ->line('Pierwsze zdanie wiadomości.')
                    ->action('Zobacz przepis', 'https://kuking.pl/przepisy/rosol');
            }
        });

        $wiadomosc = $this->ostatniaWiadomosc();

        $this->assertGreaterThan(200, mb_strlen($this->tresc($wiadomosc)));
        $this->assertStringContainsString('Pierwsze zdanie wiadomości.', $this->tresc($wiadomosc));

        $this->assertBezAngielskiego($wiadomosc);
    }

    public function test_nadawca_podpisuje_sie_kuking(): void
    {
        $user = $this->user();

        $user->sendPasswordResetNotification('token-testowy');

        $nadawca = $this->ostatniaWiadomosc()->getFrom()[0];

        $this->assertSame('Kuking', $nadawca->getName());
        $this->assertStringEndsWith('@kuking.pl', $nadawca->getAddress());
    }

    /**
     * Na Railway zmienne środowiskowe ustawia człowiek i człowiek potrafi
     * ich nie ustawić. Wtedy zostaje wartość domyślna z pliku konfiguracji
     * — a domyślne wartości Laravela to „Laravel" i „hello@example.com".
     * List od „Laravel <hello@example.com>" z linkiem do zmiany hasła jest
     * phishingiem w czystej postaci.
     */
    public function test_konfiguracja_poczty_nie_ma_domyslnych_wartosci_laravela(): void
    {
        $repozytorium = Env::getRepository();

        $kopia = [];

        foreach (['MAIL_FROM_NAME', 'MAIL_FROM_ADDRESS', 'APP_NAME'] as $zmienna) {
            $kopia[$zmienna] = $repozytorium->get($zmienna);
            $repozytorium->clear($zmienna);
        }

        try {
            /** @var array{from: array{address: string, name: string}} $poczta */
            $poczta = require base_path('config/mail.php');

            $this->assertSame('Kuking', $poczta['from']['name']);
            $this->assertStringEndsWith('@kuking.pl', $poczta['from']['address']);
        } finally {
            foreach ($kopia as $zmienna => $wartosc) {
                if ($wartosc !== null) {
                    $repozytorium->set($zmienna, $wartosc);
                }
            }
        }
    }

    public function test_wlasne_klasy_powiadomien_sa_uzywane(): void
    {
        $user = $this->user();

        \Illuminate\Support\Facades\Notification::fake();

        $user->sendPasswordResetNotification('token-testowy');
        $user->sendEmailVerificationNotification();

        \Illuminate\Support\Facades\Notification::assertSentTo($user, UstawienieNowegoHasla::class);
        \Illuminate\Support\Facades\Notification::assertSentTo($user, PotwierdzenieAdresu::class);
    }

    private function ostatniaWiadomosc(): Email
    {
        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();

        $wiadomosci = $transport->messages();

        $this->assertNotEmpty($wiadomosci, 'Aplikacja nie wysłała żadnej wiadomości.');

        /** @var Email $email */
        $email = $wiadomosci->last()->getOriginalMessage();

        return $email;
    }

    private function tresc(Email $wiadomosc): string
    {
        return (string) $wiadomosc->getHtmlBody().' '.(string) $wiadomosc->getTextBody();
    }

    private function assertBezAngielskiego(Email $wiadomosc): void
    {
        $cala = (string) $wiadomosc->getSubject()."\n".$this->tresc($wiadomosc);

        foreach (self::ANGIELSKIE_KOTWICE as $fraza) {
            $this->assertStringNotContainsString(
                $fraza,
                $cala,
                "W wiadomości został angielski tekst: „{$fraza}”.",
            );
        }
    }
}
