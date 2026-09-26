<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Rocznice\OdnosnikWypisaniaZUrodzin;
use App\Domain\Rocznice\Urodziny;
use App\Logging\BezpiecznyBlad;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * List z życzeniami urodzinowymi od gospodarza (issue #1755, etap c; #1956).
 *
 * SPRAWDZENIE W CHWILI WYSYŁKI, nie tylko w chwili kolejkowania — ten sam
 * powód co w `PodsumowanieTygodnia::send()`: między kolejką a wysyłką ktoś
 * mógł wycofać zgodę odnośnikiem, wyłączyć życzenia albo zamknąć konto.
 * List, który wtedy wychodzi mimo wszystko, jest listem bez podstawy prawnej.
 *
 * `birthday_email_sent_on` STAWIA TEN LIST, PO WYSŁANIU (issue #1956, D-293)
 * Do 26 września 2026 stawiała go komenda zaraz po `Mail::queue()` — czyli po
 * ZAKOLEJKOWANIU, nie po wysyłce. Worker mógł potem wyczerpać próby, list
 * lądował w `failed_jobs`, a kolumna dalej twierdziła „list wyszedł". Dziś:
 *  - `send()` stawia znacznik dopiero, gdy `parent::send()` wróci BEZ
 *    wyjątku — transport pocztowy PRZYJĄŁ wiadomość. To nie znaczy „doszło
 *    do skrzynki", ale nie znaczy też „powstało zadanie w kolejce";
 *  - `failed()` zostawia w dzienniku identyfikator konta, którego list nie
 *    doszedł — bez adresu (AGENTS.md §7) — więc sprawę da się policzyć
 *    i ręcznie ponowić (dzień, który komenda i tak zajęła w
 *    `birthday_email_queued_on`, i tak wraca do zera dopiero za rok —
 *    świadomy wybór, patrz `WyslijZyczeniaUrodzinowe`).
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

        $wynik = parent::send($mailer);

        $this->potwierdzWyslanie($swiezy);

        return $wynik;
    }

    /**
     * Transport przyjął list — dopiero teraz dzień jest „wysłany".
     *
     * Znacznik zapisuje dzień ZAJĘTY PRZEZ KOMENDĘ (`birthday_email_queued_on`
     * na świeżo odczytanym wierszu), nie „dziś" liczone tu jeszcze raz: worker
     * mógł ruszyć zadanie już po północy, a to wciąż jest list za WCZORAJSZĄ
     * rezerwację.
     *
     * Wyjątek przy samym zapisie łapiemy i zapisujemy do dziennika, zamiast
     * rzucić: rzucony wyjątek kazałby workerowi powtórzyć zadanie, a każde
     * powtórzenie wysłałoby tej samej osobie DRUGI list z życzeniami (D-293).
     * Wolimy stan fałszywie ostrożny — znacznik pusty, choć list wyszedł —
     * od dwóch identycznych listów.
     */
    private function potwierdzWyslanie(User $swiezy): void
    {
        if ($swiezy->birthday_email_queued_on === null) {
            return;
        }

        $dzien = $swiezy->birthday_email_queued_on->toDateString();

        try {
            DB::transaction(fn () => User::query()
                ->whereKey($swiezy->getKey())
                ->where(function ($q) use ($dzien): void {
                    $q->whereNull('birthday_email_sent_on')->orWhere('birthday_email_sent_on', '<>', $dzien);
                })
                ->update(['birthday_email_sent_on' => $dzien]));
        } catch (Throwable $e) {
            Log::error('List z życzeniami urodzinowymi wyszedł, ale nie udało się zapisać znacznika birthday_email_sent_on.', [
                'user_id' => (string) $swiezy->getKey(),
                'error' => BezpiecznyBlad::kontekst($e),
            ]);
        }
    }

    /**
     * Worker wyczerpał próby: list NIE doszedł i ma to być widać.
     *
     * Bez adresu odbiorcy (AGENTS.md §7) — identyfikator konta wystarcza,
     * żeby sprawę znaleźć i ręcznie ponowić `Mail::to(...)->send(...)`.
     */
    public function failed(Throwable $e): void
    {
        Log::error('List z życzeniami urodzinowymi nie doszedł — zadanie wyczerpało próby.', [
            'user_id' => (string) $this->odbiorca->getKey(),
            'error' => BezpiecznyBlad::kontekst($e),
        ]);
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
