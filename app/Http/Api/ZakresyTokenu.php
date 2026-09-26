<?php

declare(strict_types=1);

namespace App\Http\Api;

use InvalidArgumentException;

/**
 * Zamknięty słownik zakresów (abilities) tokenu API `/api/v1` (D-271, #1928).
 *
 * Przed tą klasą `User::createToken()` wydawał tokeny z domyślnym zakresem
 * `['*']` — pakietowym wildcardem Sanctum. Każdy token wydany bez jawnych
 * abilities dostawał więc dostęp do KAŻDEJ trasy, także tej, która dopiero
 * powstanie. Dodanie nowego, wrażliwego endpointu rozszerzałoby wtedy
 * uprawnienia tokenów wydanych miesiące wcześniej — bez migracji, bez
 * ponownej zgody właściciela urządzenia.
 *
 * Zakres tokenu jest teraz zawsze jawną listą z TEGO słownika. Dopisanie
 * nowej stałej tutaj **nie** zmienia abilities już wydanych tokenów (leżą
 * w bazie jako zapisana lista, nie jako odwołanie do tej klasy) i **nie**
 * zmienia domyślnego zakresu nowo wydawanych tokenów — to osobna, świadoma
 * zmiana `User::DOMYSLNE_UPRAWNIENIA_API`.
 */
final class ZakresyTokenu
{
    /** Odczyt własnego profilu (dane konta, ustawienia widoczne w aplikacji). */
    public const PROFIL_CZYTAJ = 'profil:czytaj';

    /** Odczyt treści serwisu: feed, przepisy, wpisy, komentarze. */
    public const TRESC_CZYTAJ = 'tresc:czytaj';

    /** Zapis treści: nowy wpis, komentarz, „Ugotowałem”, reakcja. */
    public const TRESC_PISZ = 'tresc:pisz';

    private function __construct() {}

    /**
     * Cały zamknięty słownik. Jedyne miejsce, które decyduje, czy string
     * jest w ogóle prawidłowym zakresem tokenu.
     *
     * @return list<string>
     */
    public static function wszystkie(): array
    {
        return [
            self::PROFIL_CZYTAJ,
            self::TRESC_CZYTAJ,
            self::TRESC_PISZ,
        ];
    }

    /**
     * Odrzuca każdy zakres spoza słownika — w tym `*`, literówki i zakresy
     * usunięte w przeszłości. Wywoływane przez `User::createToken()` przy
     * KAŻDYM wydaniu tokenu, także gdy wołający poda abilities jawnie.
     *
     * @param  array<int, string>  $abilities
     */
    public static function waliduj(array $abilities): void
    {
        if ($abilities === []) {
            throw new InvalidArgumentException(
                'Token bez żadnego zakresu jest bezużyteczny — podaj co najmniej jeden zakres z ZakresyTokenu::wszystkie().',
            );
        }

        $nieznane = array_values(array_diff($abilities, self::wszystkie()));

        if ($nieznane !== []) {
            throw new InvalidArgumentException(sprintf(
                'Nieznany zakres tokenu: %s. Dozwolone zakresy: %s.',
                implode(', ', $nieznane),
                implode(', ', self::wszystkie()),
            ));
        }
    }
}
