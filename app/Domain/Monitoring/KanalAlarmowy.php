<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use App\Logging\KanalyAlarmowe;

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
        // Discord (`LOG_BLAD_WEBHOOK_URL`) albo poczta (`KUKING_ALARM_EMAIL`,
        // #599). Oba puste = cisza. Ten sam warunek stoi w `bootstrap/app.php`.
        return KanalyAlarmowe::wlaczony();
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
        // Czysta kartka, osobny `try` na każdy kanał i odpowiedź „czy
        // KTÓRYKOLWIEK przyjął” (2xx od Discorda albo list przyjęty przez
        // transport): `KanalyAlarmowe::zadzwon()`. Brak wyjątku nadal nie
        // jest dowodem przyjęcia — handlery pytają o kod odpowiedzi.
        return KanalyAlarmowe::zadzwon($tresc);
    }
}
