<?php

declare(strict_types=1);

namespace App\Domain\Contact\Actions;

use App\Domain\Security\DziennyBudzetListow;
use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\AuditLogEntry;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\User;
use App\Poczta\OdmowaEmailLabs;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Jedno wysłanie formularza ma jeden wiersz i najwyżej jedną próbę wysyłki.
 * Rezerwacja jest zatwierdzona przed pocztą. Wynik i audyt nie powodują
 * powtórzenia efektu zewnętrznego, także gdy proces nie poznał wyniku.
 * Znacznik audytu i wpis są atomowe; zaległość dokańcza POST lub wejście
 * na kartę. Nie obiecujemy dokładnie jednego doręczenia przez dostawcę.
 */
final class WyslijOdpowiedz
{
    /** Ile znaków powodu porażki trzymamy przy odpowiedzi (kolumna ma 500). */
    private const MAKSYMALNA_DLUGOSC_POWODU = 500;

    /**
     * @param  string  $tresc  treść listu, dokładnie taka, jak ją wpisał moderator
     * @param  string|null  $ip  adres żądania — do dziennika, wyłącznie jako skrót
     */
    public function handle(
        ContactMessage $wiadomosc,
        User $moderator,
        string $tresc,
        ?string $ip = null,
        ?string $replyKey = null,
    ): ContactMessageReply {
        // Prawo do listu sprawdza akcja, nie tylko kontroler: bezpośrednie
        // wywołanie (komenda, job, drugi endpoint) z osobą bez roli albo po
        // jej odebraniu odmawia przed wierszem odpowiedzi, pocztą i audytem.
        // Ta sama polityka co HTTP — bez drugiej kopii warunku roli (#1352).
        Gate::forUser($moderator)->authorize('reply', $wiadomosc);

        $adres = $wiadomosc->adresDoOdpowiedzi();

        if ($adres === null) {
            // Nie powinno się zdarzyć: formularz odpowiedzi nie jest w ogóle
            // pokazywany bez adresu, a kontroler sprawdza to drugi raz.
            // Zostaje jako obrona w głąb — akcja domenowa ma być prawdziwa
            // niezależnie od tego, kto ją zawoła (AGENTS.md §4).
            throw new BrakAdresuDoOdpowiedzi(
                'Ta wiadomość nie ma adresu do odpowiedzi — nie ma jak jej odpisać pocztą.',
            );
        }

        if ($replyKey === null || ! Str::isUuid($replyKey)) {
            throw ValidationException::withMessages(['reply_key' => 'Otwórz ponownie kartę wiadomości przed wysłaniem. Zachowaj tekst odpowiedzi.']);
        }

        $odpowiedz = DB::transaction(function () use ($wiadomosc, $moderator, $tresc, $replyKey): ContactMessageReply {
            // Blokada rodzica serializuje tworzenie, UNIQUE chroni także inne drogi zapisu.
            ContactMessage::query()->whereKey($wiadomosc->getKey())->lockForUpdate()->firstOrFail();
            $existing = $wiadomosc->odpowiedzi()->where('reply_key', $replyKey)->first();
            if ($existing !== null) {
                if ($existing->body !== $tresc || $existing->author_id !== $moderator->getKey()) {
                    throw ValidationException::withMessages(['reply_key' => 'Ten formularz był już użyty do innej odpowiedzi. Zachowaj tekst i otwórz nową odpowiedź.']);
                }

                return $existing;
            }
            $reply = new ContactMessageReply;
            $reply->forceFill([
                'contact_message_id' => $wiadomosc->getKey(),
                'author_id' => $moderator->getKey(),
                'body' => $tresc,
                'reply_key' => $replyKey,
            ])->save();

            return $reply->refresh();
        });

        // Zamek jest w bazie, przed efektem zewnętrznym. Po rozpoczęciu wysyłki
        // ponowiony POST nigdy nie wysyła ponownie, także po utracie odpowiedzi.
        $claimed = DB::transaction(fn () => ContactMessageReply::query()
            ->whereKey($odpowiedz->getKey())->whereNull('sending_started_at')
            ->where('status', ContactMessageReply::STATUS_W_TOKU)
            ->update(['sending_started_at' => now()]));
        if ($claimed === 0) {
            $this->finishAudit($odpowiedz->refresh(), $wiadomosc, $ip);

            return $odpowiedz;
        }

        /*
         |------------------------------------------------------------------
         | WSPÓLNA PULA POCZTY — rezerwacja przed wysyłką (D-239)
         |------------------------------------------------------------------
         |
         | Wpis przy `limits.kontakt_odpowiedz` w `config/kuking.php` mówił
         | wprost: sufitu dobowego tu świadomie nie ma, ale „gdyby kiedyś
         | powstał prawdziwy, WSPÓLNY licznik poczty, TO ON ma być jednym
         | miejscem tej decyzji — nie osobny sufit dopisany tutaj". Licznik
         | powstał 20 września 2026, więc odpowiedź przechodzi przez niego,
         | a osobnego progu przy tej trasie nadal nie ma.
         |
         | KLASA `zwykla`: odpowiedź pisze człowiek własnymi słowami, więc
         | fan-outu nie ma z czego zrobić — ale limit `kontakt_odpowiedz`
         | (20 na 10 minut) przy przejętej sesji moderatora to nadal listy
         | wychodzące na zewnątrz z naszej puli. Rezerwa transakcyjna (100
         | listów) zostaje wtedy nietknięta dla potwierdzeń rejestracji.
         |
         | REZERWACJA DOPIERO PO ZAMKU `sending_started_at`: ponowiony POST
         | tego samego formularza wraca wyżej i nie zajmuje drugiego miejsca.
         |
         | ODMOWA IDZIE TĄ SAMĄ DROGĄ CO NIEUDANA WYSYŁKA. Odpowiedź jest już
         | zapisana i ZOSTAJE — treść napisana przez człowieka nie przepada,
         | a panel pokazuje ją jako niewysłaną, z powodem mówiącym, co zrobić.
         */
        $budzet = DziennyBudzetListow::dlaListuObslugi();

        if (! $budzet->sprobujZarezerwowac()) {
            DB::transaction(fn () => $odpowiedz->oznaczNieudana(
                'Dobowa pula listów jest na dziś wyczerpana, więc ta odpowiedź nie wyszła. '
                .'Treść jest zapisana — wyślij ją jutro przyciskiem „Wyślij jako nową odpowiedź”.',
            ));
            $this->finishAudit($odpowiedz->refresh(), $wiadomosc, $ip);

            return $odpowiedz;
        }

        try {
            Mail::to($adres)->send(new OdpowiedzNaWiadomosc($wiadomosc, $tresc));
        } catch (Throwable $e) {
            DB::transaction(function () use ($odpowiedz, $e, $budzet): void {
                if ($e instanceof OdmowaEmailLabs && $e->isConfirmedRejection()) {
                    // List na pewno nie wyszedł, więc miejsce wraca do wspólnej
                    // puli. Przy nieustalonym wyniku miejsca NIE oddajemy: list
                    // mógł wyjść, a pula ma liczyć ostrożnie.
                    $budzet->zwolnij();
                    $odpowiedz->oznaczNieudana($this->bezpiecznyPowod($e));
                } else {
                    // Nieznany wyjątek nie jest dowodem odmowy. Zachowujemy
                    // także zredagowany powód, bez automatycznego ponowienia.
                    $odpowiedz->forceFill(['error' => $this->bezpiecznyPowod($e)])->save();
                }
            });
            $this->finishAudit($odpowiedz->refresh(), $wiadomosc, $ip);

            return $odpowiedz;
        }

        DB::transaction(fn () => $odpowiedz->oznaczWyslana());
        $this->finishAudit($odpowiedz, $wiadomosc, $ip);

        return $odpowiedz;
    }

    /** Znacznik i audyt są jedną transakcją — idiom z e89f28a5. */
    public function finishAudit(ContactMessageReply $reply, ContactMessage $message, ?string $ip = null): void
    {
        if ($reply->sending_started_at === null) {
            $reply->refresh();
        }
        // Aktywnej/nieustalonej wysyłki bez wyniku nie nazywamy porażką.
        if ($reply->status === ContactMessageReply::STATUS_W_TOKU && $reply->error === null) {
            return;
        }
        try {
            DB::transaction(function () use ($reply, $message, $ip): void {
                $claimed = ContactMessageReply::query()->whereKey($reply->getKey())
                    ->whereNull('audit_recorded_at')->update(['audit_recorded_at' => now()]);
                if ($claimed === 0) {
                    return;
                }
                AuditLogEntry::record(
                    action: match ($reply->status) {
                        ContactMessageReply::STATUS_WYSLANA => 'admin.contact_reply_sent',
                        ContactMessageReply::STATUS_NIEUDANA => 'admin.contact_reply_failed',
                        default => 'admin.contact_reply_unknown',
                    },
                    actor: $reply->author,
                    subject: $message,
                    metadata: ['reply_id' => $reply->getKey()],
                    ip: $ip,
                );
            });
        } catch (Throwable) {
            // Wyjątek SQL może zawierać dane listu: zapisujemy tylko identyfikator.
            Log::warning('Dokończ zapis audytu odpowiedzi przy kolejnym wejściu na wiadomość.', ['reply_id' => $reply->getKey()]);
        }
    }

    /**
     * Powód porażki w kształcie, który wolno zapisać w bazie i pokazać
     * moderatorowi.
     *
     * `OdmowaEmailLabs` jest już zredagowana u źródła (kod błędu, tytuł,
     * `uniqId`, bez treści listu i bez adresu). Ale to nie jest jedyny
     * wyjątek, który tu doleci: Symfony rzuca własnymi przy niepoprawnym
     * adresie, a te potrafią wypisać go wprost („Email … does not comply
     * with addr-spec of RFC 2822"). Adres odbiorcy jest daną osobową osoby,
     * która nie ma nawet konta — więc redagujemy TU, na wyjściu, a nie
     * liczymy na to, że każdy przyszły wyjątek będzie grzeczny. Ta sama
     * lekcja co audyt A6-01 i `TransportEmailLabs::tytulBledu()`.
     */
    private function bezpiecznyPowod(Throwable $e): string
    {
        $powod = (string) preg_replace('/\s+/', ' ', trim($e->getMessage()));
        $powod = (string) preg_replace('/[^\s<>()@,;]+@[^\s<>()@,;]+/', '[adres]', $powod);

        if ($powod === '') {
            // Wyjątek bez komunikatu (bywa przy błędach niskopoziomowych).
            // Sama klasa mówi wtedy więcej niż pustka.
            $powod = 'Wysyłka przerwana: '.class_basename($e).'.';
        }

        return mb_substr($powod, 0, self::MAKSYMALNA_DLUGOSC_POWODU);
    }
}
