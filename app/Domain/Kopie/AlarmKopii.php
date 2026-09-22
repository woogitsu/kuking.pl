<?php

declare(strict_types=1);

namespace App\Domain\Kopie;

use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * „Kopia bazy przestała powstawać" na webhook właściciela.
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500 (`blad_webhook`, D-041), i to jest celowe:
 * właściciel ma JEDNO miejsce, w które patrzy. Drugi kanał znaczyłby drugie
 * miejsce do niepatrzenia.
 *
 * CO STĄD WYCHODZI — LISTA ZAMKNIĘTA
 *   stan z listy stałych `StanKopiiBazy`, wiek w godzinach, próg w godzinach
 *   i zdanie mówiące, CO ZROBIĆ.
 *
 * CZEGO NIE WYCHODZI NIGDY
 *   nazwy bucketu, klucza obiektu, poświadczenia, komunikatu wyjątku.
 *   Powód jest ten sam, co w `App\Logging\WebhookBleduHandler` po audycie
 *   A6-01: ten kanał wychodzi do Discorda albo Slacka, czyli do usługi, nad
 *   którą nie mamy żadnej kontroli, a komunikat biblioteki potrafi wnieść
 *   w siebie wartości, o których nikt nie pomyślał.
 *
 * Nazwa bucketu nie jest daną osobową, ale jest podpowiedzią dla kogoś, kto
 * przejął ten kanał — a nie kupuje nam ani jednej minuty przy diagnozie,
 * bo i tak stoi w zmiennych środowiskowych.
 *
 * DLACZEGO `error()`, SKORO TO NIE JEST WYJĄTEK
 * Bo kanał ma w `config/logging.php` poziom ustawiony na sztywno na `error`
 * i wpis niższej wagi zostałby po cichu odrzucony przez Monologa. Poziom
 * jest cechą KANAŁU, nie oceną zdarzenia (ta sama uwaga, co w
 * `App\Domain\Contact\DzwonekOperatora`).
 */
final class AlarmKopii
{
    /**
     * Stany, które są ALARMEM. `wylaczona` i `aktualna` nie dzwonią:
     * pierwsza to brak konfiguracji (bucket jeszcze nie istnieje), druga
     * to stan pożądany.
     */
    private const ALARMUJACE = [
        StanKopiiBazy::BRAK_KOPII,
        StanKopiiBazy::PRZESTARZALA,
        StanKopiiBazy::NIEDOSTEPNY,
    ];

    /**
     * @param  array{stan: string, wiek_godzin: int|null, liczba: int, prog_godzin: int}  $wynik
     */
    public function zadzwonJesliTrzeba(array $wynik): bool
    {
        if (! in_array($wynik['stan'], self::ALARMUJACE, true)) {
            return false;
        }

        if (blank(config('logging.channels.blad_webhook.url'))) {
            // Kanał wyłączony — tak jest dziś na produkcji. Ten sam warunek
            // stoi w `bootstrap/app.php` i w `DzwonekOperatora`.
            return false;
        }

        return $this->kanalPrzyjal($this->tresc($wynik));
    }

    /**
     * Czy kanał PRZYJĄŁ wiadomość — czyli czy odpowiedział 2xx.
     *
     * NAZWA JEST DOSŁOWNA I TAKA MA ZOSTAĆ, dokładnie jak w `AlarmKolejki`
     * i `AlarmPolaczen`: „przyjął" znaczy, że usługa po drugiej stronie
     * potwierdziła odbiór żądania. NIE znaczy „człowiek to zobaczył" — kto
     * patrzy na kanał Discorda albo Slacka, jest poza zasięgiem tego kodu.
     *
     * CO BYŁO NIE TAK (issue #690)
     * Do 19 września 2026 ta metoda nie istniała, a `zadzwonJesliTrzeba()`
     * oddawało `true` na SAM BRAK WYJĄTKU. `WebhookBleduHandler::write()`
     * z zasady nigdy nie rzuca (jego własny docblock), a klient HTTP Laravela
     * bez `throw()` oddaje 404 z odwołanego webhooka i 500 z zepsutego jako
     * ZWYKŁĄ ODPOWIEDŹ. `catch` niżej był więc kodem nieosiągalnym dla każdej
     * realnej awarii kanału: metoda meldowała sukces, nie dodzwoniwszy się.
     *
     * To ta sama klasa błędu, którą `docs/PULAPKI_TESTOW.md` §5 opisuje jako
     * „narzędzie melduje sukces, nie robiąc nic", i w tym repozytorium
     * wystąpiła już czwarty raz (`HealthController::powiadomWebhook()`,
     * `kuking:sprawdz-alarm` w #682, `AlarmPolaczen` i `AlarmKolejki` w #687).
     *
     * DLACZEGO WARTO BYŁO TO ZAMKNĄĆ, CHOĆ NIC NIE BOLAŁO
     * `AlarmKopii` nie ma pamięci wyciszania, a `SprawdzKopieBazy` odrzucało
     * wartość zwracaną — więc usterka nie kupowała niczyjej ciszy i nie gubiła
     * alarmu. Była LATENTNA: wystarczyłoby dołożyć wyciszanie duplikatów albo
     * zacząć czytać wynik, żeby stała się czynna BEZ ŻADNEJ zmiany w tej
     * metodzie. Po #687 kontrakt był też po prostu niespójny — dwie klasy
     * alarmowe pytały handler o wynik, trzecia nie.
     */
    private function kanalPrzyjal(string $tresc): bool
    {
        // CZYSTA KARTKA PRZED PRÓBĄ. Pamięć wyniku w handlerze jest STATYCZNA,
        // czyli wspólna dla całego procesu — a w jednym przebiegu harmonogramu
        // idą po sobie czujki kopii, połączeń i kolejki. Bez wyzerowania cudzy
        // sukces sprzed chwili zostałby odczytany jako nasz.
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        try {
            Log::channel('blad_webhook')->error($tresc);
        } catch (Throwable) {
            // Nieudane powiadomienie nie ma prawa przewrócić zadania
            // harmonogramu — w roli `all` błąd harmonogramu kładł kiedyś
            // cały kontener (`docker/entrypoint.sh`).
            return false;
        }

        // `null` znaczy „nie było próby" i też nie jest przyjęciem.
        return WebhookBleduHandler::ostatniaWysylkaSieUdala() === true;
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu: sprawdzenie
     * „czego tu nie ma" musi dać się zrobić bez stawiania kanału logowania.
     *
     * @param  array{stan: string, wiek_godzin: int|null, liczba: int, prog_godzin: int}  $wynik
     */
    public function tresc(array $wynik): string
    {
        $prog = (int) $wynik['prog_godzin'];

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych — nie składamy ich z niczego, co przyszło
        // z zewnątrz. To jest ta sama zasada „lista dozwolonych, nie
        // filtrowanie zakazanych", co w handlerze webhooka.
        $co = match ($wynik['stan']) {
            StanKopiiBazy::BRAK_KOPII => 'W buckecie kopii nie ma ANI JEDNEGO zrzutu bazy.',
            StanKopiiBazy::PRZESTARZALA => sprintf(
                'Najnowsza kopia bazy ma %d h, a próg to %d h.',
                (int) $wynik['wiek_godzin'],
                $prog,
            ),
            StanKopiiBazy::NIEDOSTEPNY => 'Nie udało się odpytać bucketu z kopiami bazy.',
            default => 'Nieznany stan kopii bazy.',
        };

        // BEZ NAGŁÓWKA `[nazwa/środowisko]`. Dokleja go sam kanał
        // (`WebhookBleduHandler::tresc()`), więc wpisany tutaj drugi raz
        // dochodził do odbiornika jako „[Kuking/production] [Kuking/production]
        // kopia bazy: …". Zmierzone na prawdziwym odbiorniku webhooka 17.09.2026.
        return implode(' ', [
            'kopia bazy:',
            $co,
            'To znaczy, że serwis `kopia-bazy` prawdopodobnie przestał chodzić —',
            'sprawdź jego ostatnie uruchomienie w Railway (Deployments → Cron).',
            'Procedura: docs/infra/KOPIE_I_ODTWORZENIE.md sekcja 7.',
        ]);
    }
}
