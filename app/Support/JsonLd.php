<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * Bezpieczne kodowanie JSON-LD osadzanego w elemencie `<script>`.
 *
 * PROBLEM, KTÓRY TO ZAMYKA (audyt A01, stored XSS)
 * W zwykłym tekście JSON ciąg `</script>` jest całkowicie poprawną wartością.
 * W dokumencie HTML kończy jednak element skryptu — również wtedy, gdy jego
 * typem jest `application/ld+json`. Przeglądarka nie zagląda do środka
 * po JSON-a; szuka pierwszego `</script>`.
 *
 * Skutek: tytuł przepisu albo bio o treści
 *
 *     Rosół</script><img src=x onerror=alert(1)>
 *
 * wychodziło z kontekstu danych i wstrzykiwało HTML na stronę przepisu
 * i profilu — także tę oglądaną przez moderatora sprawdzającego zgłoszenie.
 * Walidacja tych pól sprawdza długość i typ, a nie zawartość, więc niczego
 * tu nie łapała. CSP jest dziś w trybie Report-Only i dopuszcza
 * `unsafe-inline`, więc też nie stanowi zabezpieczenia (#12).
 *
 * DLACZEGO TAK, A NIE PRZEZ ESCAPOWANIE W BLADE
 * `{{ }}` zamieniłoby `"` na `&quot;` i zepsuło JSON — przeglądarka nie
 * odczytałaby danych strukturalnych. Kodowanie musi odpowiadać kontekstowi:
 * tu kontekstem jest treść elementu `script`, nie tekst HTML.
 * (OWASP XSS Prevention Cheat Sheet.)
 *
 * Flagi `JSON_HEX_*` zamieniają `<`, `>`, `&`, `'` i `"` na sekwencje
 * `\uXXXX`. Wynik pozostaje **poprawnym JSON-em o niezmienionej wartości** —
 * `json_decode()` zwraca dokładnie ten sam tekst, który wpisał użytkownik.
 * Zmienia się wyłącznie zapis, więc wyszukiwarki czytają dane tak samo.
 *
 * `JSON_UNESCAPED_UNICODE` zostaje, żeby polskie znaki nie zamieniały się
 * w `ż` — to czytelniejsze w źródle strony i nie ma wpływu na
 * bezpieczeństwo.
 */
final class JsonLd
{
    /**
     * @param  array<mixed>  $dane
     *
     * @throws JsonException gdy danych nie da się zakodować — lepiej pusta
     *                       sekcja JSON-LD niż uszkodzony dokument HTML
     */
    public static function encode(array $dane): string
    {
        return json_encode(
            $dane,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
            | JSON_THROW_ON_ERROR,
        );
    }
}
