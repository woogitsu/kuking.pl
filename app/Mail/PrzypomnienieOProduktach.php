<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Pantry\OdnosnikWypisaniaZPrzypomnienia;
use App\Domain\Pantry\Opakowanie;
use App\Domain\Pantry\PriorytetZuzycia;
use App\Logging\BezpiecznyBlad;
use App\Models\User;
use App\Poczta\ListZarezerwowany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sobotnie przypomnienie o produktach do zużycia (#1903, D-333).
 *
 * Wychodzi WYŁĄCZNIE za osobną, domyślnie wyłączoną zgodą
 * (`users.wants_pantry_reminder`), raz w tygodniu, w sobotę rano, i tylko
 * wtedy, gdy na liście „Co mam w domu” jest co wymienić (pusty nie wychodzi).
 *
 * SPRAWDZENIE W CHWILI WYSYŁKI, nie tylko w chwili kolejkowania — ten sam
 * powód co w `ZyczeniaUrodzinowe::send()` i `PodsumowanieTygodnia::send()`:
 * między kolejką a wysyłką ktoś mógł wycofać zgodę odnośnikiem, usunąć produkt
 * albo zamknąć konto. List, który wtedy wychodzi mimo wszystko, jest listem
 * bez podstawy prawnej albo pustym. Treść składamy świeżo z bazy.
 *
 * W liście są nazwy produktów z prywatnej listy. Skrzynka bywa wspólna dla
 * domowników, więc stopka mówi o tym wprost i podaje wypisanie bez logowania.
 * Bez słów „świeże”, „bezpieczne”, „zepsute”: termin jest notatką właściciela
 * z opakowania, a Kuking nie ocenia, czy produkt nadaje się do jedzenia.
 */
class PrzypomnienieOProduktach extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public User $odbiorca) {}

    public function send($mailer)
    {
        $swiezy = User::query()->whereKey($this->odbiorca->getKey())->first();

        if ($swiezy === null
            || ! $swiezy->wants_pantry_reminder
            || $swiezy->status !== User::STATUS_ACTIVE
            || $swiezy->email_verified_at === null
            || PriorytetZuzycia::pilneDla($swiezy)->isEmpty()) {
            Log::info('Sobotnie przypomnienie o produktach pominięte w chwili wysyłki.', [
                'powod' => 'brak_zgody_konto_nieczynne_albo_nic_do_wymienienia',
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

    /**
     * Worker wyczerpał próby: list NIE doszedł i ma to być widać. Bez adresu
     * odbiorcy (AGENTS.md §7) — identyfikator konta wystarcza.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Sobotnie przypomnienie o produktach nie doszło — zadanie wyczerpało próby.', [
            'user_id' => (string) $this->odbiorca->getKey(),
            'error' => BezpiecznyBlad::kontekst($e),
        ]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Produkty do zużycia w najbliższych dniach',
            replyTo: [config('kuking.community.contact_email')],
        );
    }

    public function headers(): Headers
    {
        // Sam `List-Unsubscribe` z adresem GET — bez `List-Unsubscribe-Post`,
        // bo trasa wypisania przyjmuje GET tylko jako pytanie (jak urodziny).
        return new Headers(text: [
            'List-Unsubscribe' => '<'.OdnosnikWypisaniaZPrzypomnienia::dla($this->odbiorca).'>',
            // Miejsce w puli zajmuje `kuking:wyslij-przypomnienia-spizarni` przed
            // zakolejkowaniem (`dlaPrzypomnienSpizarni()`), więc list nie może
            // być liczony drugi raz przez `PoliczListBezRezerwacji` (B8-02).
            ...ListZarezerwowany::naglowekTekstowy(),
        ]);
    }

    public function content(): Content
    {
        $maks = max(1, (int) config('kuking.pantry.przypomnienie.produktow_w_liscie', 10));
        $dzis = PriorytetZuzycia::dzis();
        /** @var Collection<int, Opakowanie> $pilne */
        $pilne = PriorytetZuzycia::pilneDla($this->odbiorca, $dzis);

        $pozycje = $pilne->take($maks)->map(fn ($produkt): array => [
            'nazwa' => (string) $produkt->name,
            'ilosc' => $produkt->quantity_note,
            // Produkt z dwoma opakowaniami: lista mówi, o które chodzi (#2568);
            // jedna pozycja na produkt, z jego najwcześniejszym pilnym opakowaniem.
            'termin' => ($produkt->maDwa ? $produkt->etykieta().': ' : '')
                .PriorytetZuzycia::etykietaTerminu($produkt).' '.PriorytetZuzycia::dataSlownie($produkt->expires_on, false),
            'stan' => PriorytetZuzycia::opisStanu($produkt, $dzis),
        ])->values()->all();

        return new Content(
            view: 'mail.przypomnienie-o-produktach',
            text: 'mail.przypomnienie-o-produktach-tekst',
            with: [
                'pozycje' => $pozycje,
                'reszta' => max(0, $pilne->count() - $maks),
                'dni' => PriorytetZuzycia::pilneDni(),
                'przepisy' => route('pantry.cook', ['najpierw' => 'termin']),
                'lista' => route('pantry.index'),
                'wypisz' => OdnosnikWypisaniaZPrzypomnienia::dla($this->odbiorca),
            ],
        );
    }
}
