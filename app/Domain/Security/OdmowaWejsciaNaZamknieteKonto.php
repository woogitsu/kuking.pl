<?php

declare(strict_types=1);

namespace App\Domain\Security;

use Illuminate\Validation\ValidationException;

/**
 * Odmowa logowania hasłem na konto zamknięte — zwykły błąd walidacji pola,
 * który dodatkowo niesie przycisk do pokazania pod komunikatem.
 *
 * PO CO OSOBNA KLASA (D-333, „link cofnięcia przy odmowie logowania”).
 * `SprawdzHasloPrzyLogowaniu` jest wspólna dla formularza w przeglądarce
 * i dla API (D-270), więc nie może sama pisać do sesji — żądanie API sesji
 * nie ma. Kontroler przeglądarkowy łapie TĘ klasę i przekłada `akcja` na
 * `status_akcja`. Rozpoznanie po klasie, a nie po koncie z loginu: przycisk
 * pojawia się tylko wtedy, gdy hasło się ZGADZAŁO (odmowa zapada po
 * `Auth::validate()`), więc samo wpisanie cudzego loginu nie zdradza, że to
 * konto czeka na usunięcie.
 *
 * Dla API zachowuje się jak każdy `ValidationException` (422 i ten sam
 * JSON) — pole `akcja` nigdzie tam nie trafia.
 */
final class OdmowaWejsciaNaZamknieteKonto extends ValidationException
{
    /** @var array{url: string, etykieta: string}|null */
    public ?array $akcja = null;
}
