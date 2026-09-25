<?php

declare(strict_types=1);

namespace App\Poczta;

use RuntimeException;

/**
 * Sterownik `emaillabs` jest wybrany, ale brakuje mu czegoś, bez czego nie da
 * się wysłać ANI JEDNEGO listu (klucza, konta SMTP albo adresu API).
 *
 * DLACZEGO TO LECI PRZY BUDOWANIU TRANSPORTU, A NIE PRZY PIERWSZYM LIŚCIE
 * `App\Support\Poczta::dziala()` buduje transport, żeby odpowiedzieć na
 * pytanie „czy poczta wychodzi". Rzucenie tutaj sprawia, że pusty klucz jest
 * widoczny NATYCHMIAST: ekran „Nie pamiętam hasła" mówi wtedy prawdę, a
 * `kuking:sprawdz-poczte` wypisuje powód. Gdyby ten warunek sprawdzał się
 * dopiero w `doSend()`, brak klucza wyglądałby dokładnie jak awaria z
 * 9 września: strona mówi „wysłaliśmy", zadanie umiera w kolejce, nikt nic
 * nie dostaje.
 *
 * KOMUNIKAT NIGDY NIE NIESIE WARTOŚCI KLUCZA — tylko nazwę zmiennej, której
 * brakuje. Ten tekst trafia do konsoli operatora i do dziennika.
 */
final class BrakKonfiguracjiEmailLabs extends RuntimeException
{
    public static function brakujeZmiennej(string $zmienna, string $coToJest): self
    {
        return new self(
            "Sterownik poczty `emaillabs` jest wybrany, ale zmienna {$zmienna} jest pusta ({$coToJest}). "
            .'Bez niej API EmailLabs odrzuci każdą wysyłkę. '
            .'Krok po kroku: docs/infra/POCZTA_URUCHOMIENIE.md §2A.',
        );
    }

    public static function zlyAdresApi(string $zmienna): self
    {
        return new self(
            "Zmienna {$zmienna} nie jest adresem HTTPS. Wysyłka poczty przez zwykły HTTP oznaczałaby, "
            .'że klucz do API i treść listu idą przez sieć otwartym tekstem. '
            .'Poprawna wartość domyślna: https://api.emaillabs.io/v2.1/email.',
        );
    }

    /**
     * Adres jest HTTPS, ale nie jest adresem API dostawcy (#991, D-250):
     * obcy host, port, ścieżka, query albo fragment. W komunikacie NIE MA
     * samego adresu — bywa, że ktoś wkleja w tę zmienną adres z tokenem;
     * jest tylko nazwa zmiennej i nazwa złej części
     * (`DozwolonyHostApi::powod()`).
     */
    public static function obcyHostApi(string $zmienna, string $powod): self
    {
        return new self(
            "Zmienna {$zmienna} nie jest adresem API EmailLabs ({$powod}). Wysyłka tam oznaczałaby, "
            .'że klucz do API i treść listu mogą trafić do kogoś obcego, więc poczta nie wystartuje. '
            .'Jedyna dozwolona wartość: https://api.emaillabs.io/v2.1/email '
            .'(to też wartość domyślna — wystarczy usunąć zmienną).',
        );
    }
}
