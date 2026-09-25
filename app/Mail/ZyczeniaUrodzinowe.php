<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Rocznice\OdnosnikWypisaniaZUrodzin;
use App\Domain\Rocznice\Urodziny;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * List z życzeniami urodzinowymi od gospodarza (issue #1755, etap c).
 *
 * SPRAWDZENIE W CHWILI WYSYŁKI, nie tylko w chwili kolejkowania — ten sam
 * powód co w `PodsumowanieTygodnia::send()`: między kolejką a wysyłką ktoś
 * mógł wycofać zgodę odnośnikiem, wyłączyć życzenia albo zamknąć konto.
 * List, który wtedy wychodzi mimo wszystko, jest listem bez podstawy prawnej.
 */
class ZyczeniaUrodzinowe extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $odbiorca) {}

    public function send($mailer)
    {
        $swiezy = User::query()->whereKey($this->odbiorca->getKey())->first();

        if ($swiezy === null
            || ! $swiezy->wants_birthday_email
            || ! $swiezy->birthday_wishes_enabled
            || $swiezy->status !== User::STATUS_ACTIVE
            || $swiezy->email_verified_at === null
            || ! Urodziny::maDate($swiezy)) {
            Log::info('List z życzeniami urodzinowymi pominięty w chwili wysyłki.', [
                'powod' => 'brak_zgody_lub_konto_nieczynne',
            ]);

            return null;
        }

        $this->odbiorca = $swiezy;
        $this->to = [];
        $this->cc = [];
        $this->bcc = [];
        $this->to((string) $swiezy->email);

        return parent::send($mailer);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Wszystkiego dobrego z okazji urodzin',
            replyTo: [config('kuking.community.contact_email')],
        );
    }

    public function headers(): Headers
    {
        // Sam `List-Unsubscribe` z adresem GET — bez `List-Unsubscribe-Post`,
        // bo trasa wypisania przyjmuje wyłącznie GET (bez wyjątku z CSRF).
        return new Headers(text: [
            'List-Unsubscribe' => '<'.OdnosnikWypisaniaZUrodzin::dla($this->odbiorca).'>',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.zyczenia-urodzinowe',
            text: 'mail.zyczenia-urodzinowe-tekst',
            with: [
                'zyczenia' => Urodziny::tekstZyczen($this->odbiorca),
                'gospodarz' => Urodziny::podpis(),
                'wypisz' => OdnosnikWypisaniaZUrodzin::dla($this->odbiorca),
                'ustawienia' => route('settings.birthday'),
            ],
        );
    }
}
