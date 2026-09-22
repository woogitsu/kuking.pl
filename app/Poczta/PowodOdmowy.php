<?php

declare(strict_types=1);

namespace App\Poczta;

/**
 * DLACZEGO list nie wyszedł — w czterech możliwościach, nie w jednym worku
 * „awaria poczty" (issue #234, D-062).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ROZRÓŻNIENIE ISTNIEJE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Bo „nie wyszedł TERAZ" i „nie wyjdzie NIGDY" wymagają dwóch różnych
 * czynności człowieka, a do 10 września 2026 wyglądały identycznie: jeden
 * wpis w `failed_jobs` z komunikatem, z którego trzeba było wyczytać, czy
 * warto powtarzać.
 *
 *  - wyczerpany limit dobowy (HTTP 429 albo błąd o limicie) — powtarzanie
 *    dziś nie ma sensu, bo każda próba odbije się identycznie. Sensowna
 *    czynność to `queue:retry` po północy albo zmiana planu u dostawcy;
 *  - awaria przejściowa (HTTP 5xx, zerwane połączenie) — powtórzenie ma
 *    sens i najprawdopodobniej wystarczy;
 *  - odmowa trwała (HTTP 4xx: zły adres, zły klucz, odrzucony nadawca) —
 *    powtarzanie nie da nic, dopóki ktoś nie zmieni danych. To jedyna
 *    kategoria, w której winna jest NASZA strona;
 *  - nieznana — dostawca odpowiedział czymś, czego nie umiemy odczytać.
 *    Zgadywanie kategorii byłoby tu gorsze niż przyznanie się do niewiedzy:
 *    „na pewno przejściowa" kazałoby czekać na samoistną naprawę, której
 *    może nie być.
 *
 * KATEGORIA NIE STERUJE PONAWIANIEM I TO JEST ŚWIADOME. Worker chodzi
 * z `--tries=3 --backoff=10,60,300` i tak zostaje (issue #234 wprost mówi:
 * nie podnosić `--tries`). Ta kategoria trafia do wiersza `mail_failures`
 * i do `/health`, czyli mówi CZŁOWIEKOWI, co zrobić — nie próbuje być
 * drugim systemem kolejek.
 */
enum PowodOdmowy: string
{
    /** Dobowy limit dostawcy wyczerpany — dziś nic więcej nie wyjdzie. */
    case LIMIT_DOBOWY = 'limit_dobowy';

    /** Awaria po drugiej stronie albo w drodze — powtórzenie ma sens. */
    case PRZEJSCIOWA = 'przejsciowa';

    /** Dostawca odrzucił wiadomość i odrzuci ją znowu — trzeba coś zmienić. */
    case TRWALA = 'trwala';

    /** Odpowiedzi nie dało się zaklasyfikować. Nie zgadujemy. */
    case NIEZNANA = 'nieznana';

    /**
     * Wartości dopuszczone w kolumnie `mail_failures.powod`.
     *
     * Ta metoda istnieje po to, żeby CHECK w bazie i ten typ nie rozjechały
     * się przy dopisaniu piątej kategorii — `NieudanyListZostawiaSladTest`
     * porównuje jedno z drugim.
     *
     * @return list<string>
     */
    public static function wartosci(): array
    {
        return array_map(static fn (self $powod): string => $powod->value, self::cases());
    }

    /** Jedno zdanie dla właściciela — do konsoli i do dziennika. */
    public function opis(): string
    {
        return match ($this) {
            self::LIMIT_DOBOWY => 'wyczerpany dobowy limit listów u dostawcy',
            self::PRZEJSCIOWA => 'awaria przejściowa u dostawcy albo w drodze do niego',
            self::TRWALA => 'dostawca odrzucił wiadomość i odrzuci ją ponownie',
            self::NIEZNANA => 'powód nierozpoznany — odpowiedzi dostawcy nie dało się zaklasyfikować',
        };
    }

    /** Co z tym zrobić. Pełnym zdaniem, po polsku, bez kodów błędów. */
    public function coZrobic(): string
    {
        return match ($this) {
            self::LIMIT_DOBOWY => 'Nie powtarzaj dziś — każda próba odbije się tak samo. '
                .'Po północy uruchom `php artisan queue:retry <uuid>`, a jeśli to się powtarza, '
                .'przejdź na płatny plan u dostawcy (docs/decyzje/POCZTA.md §4).',
            self::PRZEJSCIOWA => 'Powtórzenie ma sens: `php artisan queue:retry <uuid>`. '
                .'Jeśli odbije się znowu, sprawdź stan dostawcy i `php artisan kuking:sprawdz-poczte <adres>`.',
            self::TRWALA => 'Powtarzanie nic nie da. Sprawdź konfigurację i adres: '
                .'`php artisan kuking:sprawdz-poczte <adres>` wypisze, co stoi na drodze. '
                .'Jeśli winny jest adres odbiorcy, człowiek musi go poprawić — napisz do niego innym kanałem.',
            self::NIEZNANA => 'Zajrzyj w `php artisan queue:failed` i w panel dostawcy po identyfikator żądania. '
                .'Dopóki nie wiadomo, co to było, traktuj jak awarię — nie jak coś, co samo przejdzie.',
        };
    }

    /**
     * Czy ponowienie TEGO SAMEGO zadania ma dziś jakikolwiek sens.
     *
     * Używa tego wyłącznie tekst dla człowieka. Kolejka nie pyta o to nikogo
     * — patrz uwaga o `--tries` w komentarzu klasy.
     */
    public function czyPowtorzenieMaSens(): bool
    {
        return $this === self::PRZEJSCIOWA;
    }
}
