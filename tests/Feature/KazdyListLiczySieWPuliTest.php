<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use App\Models\Report;
use App\Notifications\PilnyAlarmModeracyjny;
use App\Poczta\ListZarezerwowany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as Powiadomienie;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Każdy list, który wychodzi do dostawcy, jest w rachunku wspólnej puli
 * poczty (audyt B8-02).
 *
 * Do 25.09.2026 licznik `DziennyBudzetListow` widział tylko drogi
 * z rezerwacją — decyzje moderacji, potwierdzenia zgłoszeń DSA, eksport
 * i alarm automatu szły obok. Dostawca liczy wszystko, więc aplikacja
 * uważała, że ma wolne miejsce, gdy EmailLabs już odrzucał.
 *
 * Tu `Mail::fake()` NIE WYSTARCZA: podróbka nie rozgłasza `MessageSending`.
 * Testy idą przez prawdziwy mailer `array` (phpunit.xml).
 */
class KazdyListLiczySieWPuliTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Klasy, które niosą znacznik „miejsce już zarezerwowane", i plik, który
     * rezerwuje miejsce przed ich wysyłką. Znacznik bez rezerwacji znaczyłby
     * list poza rachunkiem — dlatego rejestr jest zamknięty w obie strony.
     */
    private const REZERWUJACE = [
        'app/Notifications/LinkDoLogowania.php' => 'app/Http/Controllers/Auth/LoginLinkController.php',
        'app/Notifications/UstawienieHaslaZamiastLinku.php' => 'app/Http/Controllers/Auth/LoginLinkController.php',
        'app/Notifications/ZaproszenieDoZalozeniaKonta.php' => 'app/Http/Controllers/Auth/LoginLinkController.php',
        'app/Notifications/PotwierdzenieAdresu.php' => 'app/Domain/Security/WyslijPotwierdzenieAdresu.php',
        'app/Notifications/UstawienieNowegoHasla.php' => 'app/Http/Controllers/Auth/PasswordResetController.php',
        'app/Notifications/PilneZgloszenieOdCzlowieka.php' => 'app/Domain/Moderation/Actions/AlarmujOPilnymZgloszeniu.php',
        'app/Notifications/PilnyAlarmModeracyjny.php' => 'app/Domain/Moderation/Actions/AlarmujModeratora.php',
        'app/Mail/OdpowiedzNaWiadomosc.php' => 'app/Domain/Contact/Actions/WyslijOdpowiedz.php',
        'app/Mail/PodsumowanieTygodnia.php' => 'app/Console/Commands/WyslijPodsumowaniaTygodnia.php',
    ];

    #[Test]
    public function list_bez_rezerwacji_jest_doliczany_do_wspolnej_puli(): void
    {
        Notification::route('mail', 'ktos@example.com')->notify(new class extends Powiadomienie
        {
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                return (new MailMessage)->subject('Zwykły list')->line('Treść.');
            }
        });

        $this->assertSame(1, $this->pula()->zuzyte(), 'List bez rezerwacji wyszedł obok wspólnego licznika.');
        $this->assertCount(1, $this->wyslane());
    }

    #[Test]
    public function list_zarezerwowany_nie_jest_liczony_drugi_raz_a_znacznik_nie_wychodzi(): void
    {
        $zgloszenie = (new Report)->forceFill([
            'id' => '12345678-1234-4234-8234-123456789abc',
            'numer_sprawy' => 'KUK-2026-TEST',
            'reason' => 'minor',
        ]);

        Notification::route('mail', 'moderator@example.com')->notifyNow(new PilnyAlarmModeracyjny($zgloszenie));

        Mail::to('ktos@example.com')->send(new class extends Mailable
        {
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'Zarezerwowany');
            }

            public function headers(): Headers
            {
                return new Headers(text: ListZarezerwowany::naglowekTekstowy());
            }

            public function content(): Content
            {
                return new Content(htmlString: '<p>Treść.</p>');
            }
        });

        $this->assertSame(0, $this->pula()->zuzyte(), 'List z rezerwacją policzony drugi raz.');

        $wyslane = $this->wyslane();
        $this->assertCount(2, $wyslane);
        foreach ($wyslane as $list) {
            $this->assertFalse($list->getHeaders()->has(ListZarezerwowany::NAGLOWEK), 'Wewnętrzny znacznik wyszedł do dostawcy.');
        }
    }

    #[Test]
    public function znacznik_rezerwacji_niosa_tylko_klasy_z_rejestru_a_kazda_ma_rezerwacje(): void
    {
        $zeZnacznikiem = [];
        foreach (array_merge(glob(base_path('app/Notifications/*.php')), glob(base_path('app/Mail/*.php'))) as $plik) {
            if (str_contains((string) file_get_contents($plik), 'ListZarezerwowany::')) {
                $zeZnacznikiem[] = str_replace(base_path().'/', '', $plik);
            }
        }
        sort($zeZnacznikiem);
        $rejestr = array_keys(self::REZERWUJACE);
        sort($rejestr);

        $this->assertSame($rejestr, $zeZnacznikiem,
            'Znacznik ListZarezerwowany ma klasa spoza rejestru (list wypadnie z rachunku) albo klasa z rejestru go zgubiła.');

        foreach (self::REZERWUJACE as $klasa => $rezerwujacy) {
            $this->assertStringContainsString('sprobujZarezerwowac()', (string) file_get_contents(base_path($rezerwujacy)),
                "{$klasa} niesie znacznik rezerwacji, a {$rezerwujacy} niczego nie rezerwuje.");
        }
    }

    private function pula(): DziennyBudzetListow
    {
        return DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_ZWYKLA);
    }

    /** @return list<Email> */
    private function wyslane(): array
    {
        return array_map(
            static fn ($wyslany) => $wyslany->getOriginalMessage(),
            iterator_to_array(app('mailer')->getSymfonyTransport()->messages()),
        );
    }
}
