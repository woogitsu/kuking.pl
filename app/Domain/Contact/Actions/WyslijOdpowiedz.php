<?php

declare(strict_types=1);

namespace App\Domain\Contact\Actions;

use App\Domain\Security\DziennyBudzetListow;
use App\Mail\OdpowiedzNaWiadomosc;
use App\Models\AuditLogEntry;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Wysłanie odpowiedzi na wiadomość z „Napisz do nas" (D-058).
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  KOLEJNOŚĆ JEST TU CAŁĄ TREŚCIĄ TEJ KLASY — I JEST INNA NIŻ W
 *  `PrzyjmijWiadomosc`
 * ══════════════════════════════════════════════════════════════════════════
 *
 *   1. ZAPIS wiersza odpowiedzi ze stanem „wysyłka w toku",
 *   2. wysyłka SYNCHRONICZNA, w tym samym żądaniu,
 *   3. zapis PRAWDZIWEGO wyniku: „wysłana" albo „nie udało się" z powodem,
 *   4. wpis do `audit_log` — tak samo przy sukcesie, jak przy porażce.
 *
 * KROK 1 PRZED KROKIEM 2, I TO NIE JEST DROBIAZG. Gdyby wiersz powstawał
 * dopiero po udanej wysyłce, przerwanie procesu w środku (koniec limitu czasu
 * PHP, restart kontenera na Railway, zamknięta karta) zostawiłoby stan
 * najgorszy z możliwych: list w drodze albo już dostarczony i ZERO śladu
 * w serwisie. Moderator zobaczyłby pustą historię i napisałby to samo drugi
 * raz. Przy zapisie „w toku" na pierwszym miejscu ten sam wypadek zostawia
 * na ekranie zdanie „Wysyłka w toku — nie wiadomo, czy list wyszedł",
 * czyli prawdę.
 *
 * DLACZEGO WYSYŁKA JEST SYNCHRONICZNA — pełne uzasadnienie w nagłówku
 * `App\Mail\OdpowiedzNaWiadomosc`. W skrócie: kolejka przy issue #234 znaczy
 * „moderator widzi »wysłano«, list po ~6 minutach ląduje w `failed_jobs`
 * i nikt się o tym nie dowiaduje". Nie piszemy „wysłano", jeśli tego nie
 * wiemy.
 *
 * TA KLASA NIE RZUCA WYJĄTKIEM PRZY NIEUDANEJ WYSYŁCE. Oddaje wiersz
 * odpowiedzi z jego prawdziwym stanem, a decyzję o tym, co pokazać
 * człowiekowi, podejmuje kontroler. Wyjątek wyleciałby na stronę błędu 500
 * i zabrał ze sobą wpisaną treść — czyli złamał regułę „poprawnie wpisane
 * dane nigdy nie znikają" (docs/UX_50_PLUS.md) w najgorszym momencie, bo
 * przy tekście, który ktoś właśnie napisał własnymi słowami.
 *
 * WPIS W `audit_log` PRZY OBU WYNIKACH (poz. 3.2 z INSPIRATION_DECISIONS).
 * Wysłanie listu na czyjś adres jest DZIAŁANIEM na cudzych danych, nie tylko
 * wglądem — więc tym bardziej ma zostawić ślad niż otwarcie karty konta
 * (`admin.user_viewed`). Nieudana próba też: „ktoś próbował odpisać tej
 * osobie i nie wyszło" jest odpowiedzią na pytanie, które kiedyś padnie
 * („dlaczego nikt mi nie odpisał").
 *
 * CZEGO W DZIENNIKU NIE MA: TREŚCI ODPOWIEDZI ANI ADRESU. `AuditLogEntry`
 * zapisuje FAKT i AKTORA, nigdy treści — a i bez tej zasady byłoby to
 * przepisywanie tych samych danych osobowych do drugiej tabeli, która ma
 * własną, dłuższą retencję niż wiadomość. `subject_id` wskazuje wiadomość,
 * `metadata.reply_id` — konkretny list; obie te wartości znikają razem
 * z wiadomością, a wpis zostaje jako sam fakt.
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
    ): ContactMessageReply {
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

        $odpowiedz = ContactMessageReply::create([
            'contact_message_id' => $wiadomosc->getKey(),
            'author_id' => $moderator->getKey(),
            'body' => $tresc,
        ]);

        /*
         |------------------------------------------------------------------
         | WSPÓLNA PULA POCZTY — rezerwacja przed wysyłką (D-225)
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
         | ODMOWA IDZIE TĄ SAMĄ DROGĄ CO NIEUDANA WYSYŁKA. Odpowiedź jest już
         | zapisana wierszem wyżej i ZOSTAJE — treść napisana przez człowieka
         | nie przepada, a panel pokazuje ją jako niewysłaną, z powodem
         | mówiącym, co zrobić.
         */
        $budzet = DziennyBudzetListow::dlaListuObslugi();

        if (! $budzet->sprobujZarezerwowac()) {
            $odpowiedz->oznaczNieudana(
                'Dobowa pula listów jest na dziś wyczerpana, więc ta odpowiedź nie wyszła. '
                .'Treść jest zapisana — wyślij ją jutro tym samym przyciskiem.',
            );

            AuditLogEntry::record(
                action: 'admin.contact_reply_failed',
                actor: $moderator,
                subject: $wiadomosc,
                metadata: ['reply_id' => $odpowiedz->getKey()],
                ip: $ip,
            );

            return $odpowiedz;
        }

        try {
            Mail::to($adres)->send(new OdpowiedzNaWiadomosc($wiadomosc, $tresc));
        } catch (Throwable $e) {
            // List nie wyszedł, więc miejsce wraca do wspólnej puli.
            $budzet->zwolnij();

            $odpowiedz->oznaczNieudana($this->bezpiecznyPowod($e));

            AuditLogEntry::record(
                action: 'admin.contact_reply_failed',
                actor: $moderator,
                subject: $wiadomosc,
                metadata: ['reply_id' => $odpowiedz->getKey()],
                ip: $ip,
            );

            return $odpowiedz;
        }

        $odpowiedz->oznaczWyslana();

        AuditLogEntry::record(
            action: 'admin.contact_reply_sent',
            actor: $moderator,
            subject: $wiadomosc,
            metadata: ['reply_id' => $odpowiedz->getKey()],
            ip: $ip,
        );

        return $odpowiedz;
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
