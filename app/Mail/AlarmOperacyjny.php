<?php

declare(strict_types=1);

namespace App\Mail;

use App\Poczta\ListZarezerwowany;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * List z alarmem operacyjnym na `KUKING_ALARM_EMAIL` (#599).
 *
 * BEZ `ShouldQueue` — alarm idzie synchronicznie, bo kolejka jest jedną
 * z rzeczy, o których alarmuje (martwy worker, nieudane zadania). Alarm
 * w stojącej kolejce nie wyszedłby dokładnie wtedy, gdy jest potrzebny.
 *
 * CZYSTY TEKST, BEZ WIDOKU BLADE. Widok wymaga działającego renderowania
 * i katalogu `storage`, czyli rzeczy, które w czasie awarii bywają zepsute.
 * Treść i temat przychodzą gotowe z `EmailBleduHandler`, zbudowane z listy
 * dozwolonych pól (`WebhookBleduHandler::tresc()`) — ten list nie dokłada
 * od siebie ani jednego pola z rekordu logu.
 *
 * Miejsce w puli poczty rezerwuje `EmailBleduHandler` przed wysyłką
 * (`DziennyBudzetListow::dlaAlarmuOperacyjnego()`), stąd znacznik.
 */
final class AlarmOperacyjny extends Mailable
{
    public function __construct(
        public readonly string $temat,
        public readonly string $tresc,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->temat);
    }

    public function headers(): Headers
    {
        return new Headers(text: ListZarezerwowany::naglowekTekstowy());
    }

    /**
     * `raw` = `text/plain` bez szablonu (`Illuminate\Mail\Mailer::parseView()`).
     *
     * @return array<string, string>
     */
    protected function buildView(): array
    {
        return ['raw' => $this->tresc];
    }
}
