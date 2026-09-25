<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Jeden kontrakt transportu dla czujek na webhook właściciela (#972).
 *
 * `blad_webhook` (D-041) to jedno miejsce, w które właściciel patrzy. Do
 * #972 `AlarmKopii`, `AlarmKolejki` i `AlarmPolaczen` miały trzy kopie tej
 * samej metody `kanalPrzyjal()` — a #690 pokazał, że poprawka kontraktu
 * trafiała najpierw do dwóch z nich, a trzecia czekała na osobne zgłoszenie.
 */
final class KanalAlarmowy
{
    public function wlaczony(): bool
    {
        // Brak adresu = kanał wyłączony — tak jest DZIŚ na produkcji (odczyt
        // listy zmiennych usługi, 17.09.2026: brak `LOG_BLAD_WEBHOOK_URL`).
        // Ten sam warunek stoi w `bootstrap/app.php`.
        return ! blank(config('logging.channels.blad_webhook.url'));
    }

    /**
     * Czy kanał PRZYJĄŁ wiadomość — czyli czy odpowiedział 2xx.
     *
     * NAZWA JEST DOSŁOWNA I TAKA MA ZOSTAĆ. „Przyjął" znaczy: usługa po
     * drugiej stronie potwierdziła odbiór żądania. NIE znaczy: „człowiek to
     * zobaczył". Kto patrzy na kanał Discorda albo Slacka, na który wskazuje
     * webhook, jest poza zasięgiem tego kodu — dlatego ta metoda nie nazywa
     * się `dostarczono()` ani `powiadomiono()`. Ta sama granica stoi
     * w `kuking:sprawdz-alarm` i w §7.4 `docs/infra/MONITORING_BLEDOW.md`.
     *
     * CO BYŁO NIE TAK (#687, #690)
     * Wcześniej wysyłkę uznawano za udaną na SAM BRAK WYJĄTKU.
     * `WebhookBleduHandler::write()` z zasady nigdy nie rzuca, a klient HTTP
     * Laravela bez `throw()` oddaje 404 z odwołanego webhooka i 500
     * z zepsutego jako ZWYKŁĄ ODPOWIEDŹ — metoda meldowała sukces, nie
     * dodzwoniwszy się (`docs/PULAPKI_TESTOW.md` §5).
     */
    public function przyjal(string $tresc): bool
    {
        // CZYSTA KARTKA PRZED PRÓBĄ. Pamięć wyniku w handlerze jest
        // STATYCZNA, czyli wspólna dla całego procesu — a w jednym przebiegu
        // harmonogramu idą po sobie czujki kopii, połączeń i kolejki. Bez
        // wyzerowania cudzy sukces sprzed chwili zostałby odczytany jako nasz
        // (handler nie dotyka tej pamięci, gdy kanał ma pusty adres).
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        try {
            // `error()`, bo kanał ma poziom ustawiony na sztywno na `error`
            // i wpis niższej wagi zostałby po cichu odrzucony przez Monologa.
            Log::channel('blad_webhook')->error($tresc);
        } catch (Throwable) {
            // Nieudane powiadomienie nie ma prawa przewrócić zadania
            // harmonogramu — w roli `all` błąd harmonogramu kładł kiedyś
            // cały kontener (`docker/entrypoint.sh`).
            return false;
        }

        // BRAK WYJĄTKU NIE JEST DOWODEM PRZYJĘCIA. Kod odpowiedzi zna handler
        // i trzeba go o niego zapytać. `null` (nie próbowaliśmy — kanał
        // zbudowany z pustym adresem) też nie jest przyjęciem.
        return WebhookBleduHandler::ostatniaWysylkaSieUdala() === true;
    }
}
