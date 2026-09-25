<?php

declare(strict_types=1);

namespace App\Domain\Kolejka;

use Illuminate\Contracts\Encryption\Encrypter;
use RuntimeException;

/**
 * `data.command` z ładunku zadania jako zserializowany tekst — także wtedy,
 * gdy zadanie jest szyfrowane (audyt A5-10).
 *
 * Powiadomienia niosące żywy żeton (`LinkDoLogowania`, `UstawienieNowegoHasla`,
 * `UstawienieHaslaZamiastLinku`, `ZaproszenieDoZalozeniaKonta`) mają
 * `ShouldBeEncrypted`, więc w `jobs` i `failed_jobs` leży szyfrogram, a nie
 * `O:…`. Trzy miejsca czytają z tego ODBIORCÓW (`kuking:martwe-zadania`,
 * `kuking:kto-nie-dostal-listu`, `ZapiszNieudanyList`) — bez odszyfrowania
 * przestałyby wiedzieć, komu list nie doszedł.
 *
 * Ta sama reguła co w `Illuminate\Queue\CallQueuedHandler::getCommand()`:
 * tekst zaczynający się od `O:` jest jawny, każdy inny odszyfrowuje klucz
 * aplikacji. Wynik zawiera żeton — wywołujący dalej nie drukuje ładunku
 * i odtwarza z niego tylko dozwolone klasy.
 */
final class PolecenieZadania
{
    /**
     * @throws RuntimeException gdy szyfrogramu nie da się odczytać
     */
    public static function zserializowane(string $polecenie): string
    {
        if (str_starts_with($polecenie, 'O:')) {
            return $polecenie;
        }

        $odszyfrowane = app(Encrypter::class)->decrypt($polecenie);

        if (! is_string($odszyfrowane)) {
            throw new RuntimeException('Polecenie zadania nie jest tekstem.');
        }

        return $odszyfrowane;
    }
}
