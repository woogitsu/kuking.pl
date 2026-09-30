<?php

declare(strict_types=1);

namespace App\Domain\Contact;

use App\Logging\EmailBleduHandler;
use App\Logging\KanalyAlarmowe;
use App\Models\ContactMessage;
use Throwable;

/**
 * „Przyszła nowa wiadomość" na webhook operatora — DZWONEK, NIE LISTONOSZ.
 *
 * CO STĄD WYCHODZI, A CO NIE — I DLACZEGO TO JEST NIENEGOCJOWALNE
 * Kanał `blad_webhook` (`config/logging.php`) wychodzi do Discorda albo
 * Slacka, czyli do usługi, nad którą nie mamy żadnej kontroli. We wrześniu
 * 2026 przeszedł naprawę prywatnościową (audyt A6-01): `WebhookBleduHandler`
 * przestał wysyłać komunikat wyjątku, bo `QueryException` potrafił wnieść
 * w nim adres e-mail i hash hasła prosto ze sterownika bazy. Od tamtej pory
 * treść wiadomości buduje się z LISTY DOZWOLONYCH PÓL.
 *
 * Ta klasa dokłada się do tej samej listy i musi się do niej stosować:
 *
 *   WYCHODZI: stałe napisane w tym pliku, identyfikator wiersza (UUID),
 *             rodzaj z zamkniętej listy `ContactMessage::RODZAJE`
 *             i adres ekranu w panelu.
 *   NIE WYCHODZI: treść wiadomości, adres e-mail, imię, nazwa użytkownika,
 *                 adres strony, z której pisano, ANI NIC INNEGO, czego
 *                 nie ma w linijce wyżej.
 *
 * Rodzaj jest tu bezpieczny dokładnie dlatego, że jest KLUCZEM ZE STAŁEJ,
 * a nie tekstem od człowieka — i dlatego przechodzi przez `array_key_exists`
 * niżej zamiast być wklejonym wprost. Gdyby ktoś kiedyś dopisał do tabeli
 * czwarty rodzaj z wolnego tekstu, ten warunek go zatrzyma.
 *
 * DRUGI KANAŁ: POCZTA (decyzja właściciela 30.09.2026)
 * Dzwonek idzie przez `KanalyAlarmowe`, więc przy ustawionym
 * `KUKING_ALARM_EMAIL` ta sama treść wychodzi też listem — i obowiązuje ją
 * ta sama lista dozwolonych pól (list nie dokłada ani jednego pola). Formularz
 * wypełnia jednak KAŻDY, więc list idzie spod WŁASNEGO, niższego sufitu
 * dobowego (`poczta.kontakt_operatora_na_dobe`, klucz kontekstu
 * `EmailBleduHandler::KONTEKST_PULA`), a nie spod sufitu alarmów o awariach.
 * Dwadzieścia wiadomości od ludzi nie może uciszyć listu o leżącej bazie.
 * Po wyczerpaniu sufitu skrzynka milknie do północy; Discord i panel
 * dostają dalej każdą wiadomość.
 *
 * DLACZEGO SAM IDENTYFIKATOR WYSTARCZA
 * Bo wiadomość JEST JUŻ ZAPISANA W KUKING, zanim ten dzwonek zadzwoni.
 * Webhook nie jest kopią zapasową i nie ma nią być — jest sygnałem „zajrzyj
 * do panelu". Gdyby Discord padł, nie ginie nic poza powiadomieniem.
 *
 * DLACZEGO `error()`, SKORO TO NIE JEST BŁĄD
 * Bo kanał ma w `config/logging.php` poziom ustawiony na sztywno na `error`
 * (świadomie — „nikt nie chce powiadomienia o każdym info") i wpis niższej
 * wagi zostałby po cichu odrzucony przez Monologa. Poziom jest tu cechą
 * KANAŁU, nie oceną zdarzenia.
 *
 * DLACZEGO WYSYŁKA JEST SYNCHRONICZNA
 * Bo `WebhookBleduHandler` ma własny limit czasu (2 s na połączenie, 3 s
 * łącznie) i własny `try/catch`, więc najgorszy możliwy skutek to trzy
 * sekundy dłuższe czekanie na stronę potwierdzenia. Zadanie w kolejce
 * kupiłoby te trzy sekundy za cenę nowego ruchomego elementu, który przy
 * niedziałającym workerze zamienia natychmiastowy dzwonek w brak dzwonka —
 * a AGENTS.md §3 zabrania dokładania machinerii bez zmierzonej potrzeby.
 */
final class DzwonekOperatora
{
    public function zadzwon(ContactMessage $wiadomosc): void
    {
        // Oba kanały wyłączone (brak `LOG_BLAD_WEBHOOK_URL` i
        // `KUKING_ALARM_EMAIL`) — tak jest lokalnie i w CI; na produkcji
        // Discord działa od 29.09.2026 (D-333), a treść jest bez danych
        // osobowych (`tresc()` niżej). Ten sam warunek stoi w
        // `bootstrap/app.php` przed raportowaniem wyjątków; handlery
        // sprawdzają swój adres jeszcze raz u siebie, ale sprawdzenie tutaj
        // oszczędza budowanie treści i czyni umowę „brak zmiennych = zero
        // efektu" widoczną tu.
        if (! KanalyAlarmowe::wlaczony()) {
            return;
        }

        try {
            // Bez okna serii (`SeriaAlarmow`): każda wiadomość to osobny
            // człowiek, nie powtórzenie jednej awarii. Zalew skrzynki
            // zatrzymuje sufit dobowy puli `kontakt`, a zalew formularza —
            // jego własny limit żądań.
            KanalyAlarmowe::zadzwon($this->tresc($wiadomosc), [
                EmailBleduHandler::KONTEKST_PULA => EmailBleduHandler::PULA_KONTAKT,
            ]);
        } catch (Throwable) {
            // Dzwonek nie ma prawa przewrócić zapisu, który już się udał.
            // Handler łyka własne błędy sam, ale między nim a tym miejscem
            // stoi jeszcze budowanie kanału z konfiguracji — a zła wartość
            // w configu nie może kosztować człowieka strony błędu po
            // poprawnie wysłanym formularzu.
        }
    }

    /**
     * Treść wiadomości na webhook — zbudowana WYŁĄCZNIE z listy dozwolonych.
     *
     * Metoda jest publiczna, bo to ona jest przedmiotem testu
     * `WiadomoscDoOperatoraNaWebhookuBezDanychTest`: sprawdzenie „czego tu
     * nie ma" musi dać się zrobić bez stawiania całego kanału logowania.
     */
    public function tresc(ContactMessage $wiadomosc): string
    {
        $rodzaj = array_key_exists((string) $wiadomosc->kind, ContactMessage::RODZAJE)
            ? (string) $wiadomosc->kind
            : 'nieznany';

        return implode(' ', [
            'Nowa wiadomość z formularza „Napisz do nas".',
            'Rodzaj: '.$rodzaj.'.',
            'Identyfikator: '.$wiadomosc->getKey().'.',
            'Treść zostaje w Kuking — otwórz panel: '.route('admin.contact.show', $wiadomosc).'.',
        ]);
    }
}
