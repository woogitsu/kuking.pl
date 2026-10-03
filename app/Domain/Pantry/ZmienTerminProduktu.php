<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\PantryItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ustawienie terminu, ilości i oznaczenia „mrożone” przy produkcie z listy
 * „Co mam w domu” (#1903, D-333).
 *
 * NAZWANA AKCJA DOMENOWA, NIE MASOWE PRZYPISANIE. `expires_on`, `expiry_kind`
 * i `frozen` nie są w `$fillable` modelu — ustawia je wyłącznie ta klasa,
 * po walidacji. W `$fillable` jest tylko `quantity_note`.
 *
 * TRZY DROGI DO TERMINU (wszystkie działają bez JavaScriptu)
 *  - listy wyboru Dzień / Miesiąc / Rok (`termin_dzien`, `termin_miesiac`,
 *    `termin_rok`) — wzorem `pages/settings/birthday.blade.php`;
 *  - szybkie przyciski „Za 3 dni” … (`za` = liczba dni albo `miesiac`), data
 *    liczona na serwerze od dziś (`Europe/Warsaw`);
 *  - „Nie znam terminu” (rodzaj `nieznany`) albo przycisk „Wyczyść termin”
 *    (`wyczysc`) — kasują termin i rodzaj razem (CHECK-i w bazie).
 *
 * Terminy z przeszłości wolno wpisać: ktoś dopisuje produkt już po terminie.
 * Serwer odrzuca tylko daty nieistniejące i spoza listy lat.
 *
 * ZAPIS TYLKO KOLUMN Z FORMULARZA, pod blokadą wiersza. Dwie edycje naraz —
 * wygrywa ostatnia, bez utraty innych pól; edycja równoległa z usunięciem
 * kończy się `false` (produktu już nie ma), a nie wyjątkiem z bazy.
 */
final class ZmienTerminProduktu
{
    public const NIEZNANY = 'nieznany';

    public const MAKS_ZNAKOW_ILOSCI = 40;

    /** Szybkie przyciski: wartość pola `za` => etykieta. */
    public const SZYBKIE = [
        '3' => 'Za 3 dni',
        '7' => 'Za tydzień',
        '14' => 'Za 2 tygodnie',
        'miesiac' => 'Za miesiąc',
    ];

    /** Ile lat wstecz i w przód oferuje lista „Rok”: poprzedni rok do dziś + 5. */
    public const LAT_WSTECZ = 1;

    public const LAT_NAPRZOD = 5;

    /**
     * Do listy dołączamy rok ZAPISANEGO terminu (`$zapisanyRok`), nawet gdy wypada
     * poza zakresem: produkt z takim terminem (import, stary wpis, wiele lat
     * wstecz) nie może kończyć się błędem „Wybierz rok z listy” przy zmianie
     * samej ilości albo „mrożone”. Ta sama lista służy widokowi i walidacji.
     *
     * @return list<int>
     */
    public static function lataDoWyboru(?string $dzis = null, ?int $zapisanyRok = null): array
    {
        $rok = (int) substr($dzis ?? PriorytetZuzycia::dzis(), 0, 4);
        $lata = range($rok - self::LAT_WSTECZ, $rok + self::LAT_NAPRZOD);

        if ($zapisanyRok !== null && ! in_array($zapisanyRok, $lata, true)) {
            $lata[] = $zapisanyRok;
            sort($lata);
        }

        return $lata;
    }

    /**
     * Data szybkiego przycisku („Za 3 dni”) liczona od `$dzis`; `null` dla
     * wartości spoza `SZYBKIE`. Ta sama liczba służy zapisowi i ponownemu
     * wyświetleniu formularza po błędzie (data nie ginie).
     */
    public static function dataZaPrzyciskiem(mixed $za, ?string $dzis = null): ?string
    {
        if (! is_string($za) || ! array_key_exists($za, self::SZYBKIE)) {
            return null;
        }

        $data = CarbonImmutable::parse($dzis ?? PriorytetZuzycia::dzis())->startOfDay();
        $data = $za === 'miesiac' ? $data->addMonthNoOverflow() : $data->addDays((int) $za);

        return $data->toDateString();
    }

    /**
     * @param  array<string, mixed>  $dane  surowe pola formularza
     * @param  string|null  $odcisk  odcisk pierwszego opakowania z chwili otwarcia formularza
     *                               (`Opakowanie::odcisk()`); bez niego pierwotne opakowanie zachowuje
     *                               dawne działanie, lecz po awansie drugiego zapis jest odrzucany
     * @return bool `false`, gdy produktu już nie ma (usunięty równolegle)
     *
     * @throws ValidationException
     */
    public function handle(PantryItem $produkt, array $dane, ?string $dzis = null, ?string $odcisk = null): bool
    {
        $dzis ??= PriorytetZuzycia::dzis();

        return DB::transaction(function () use ($produkt, $dane, $dzis, $odcisk): bool {
            // STAN CZYTAMY POD BLOKADĄ. Model z kontrolera został wczytany przed
            // blokadą, więc mógł być już nieaktualny: gdy druga edycja
            // zdążyła zapisać termin, zmiana samej ilości (która „zostawia
            // termin”) cofnęłaby ten termin po cichu. Termin do zachowania
            // i rok dopuszczony w liście lat pochodzą z wiersza zablokowanego.
            $wiersz = DB::table('pantry_items')
                ->where('id', $produkt->getKey())
                ->where('user_id', $produkt->user_id)
                ->lockForUpdate()
                ->first(['id', 'first_package_id', 'expires_on', 'expiry_kind', 'quantity_note', 'frozen']);

            if ($wiersz === null) {
                return false;
            }

            // Po usunięciu pierwszego opakowania drugie AWANSUJE na jego miejsce
            // (`DrugieOpakowanieProduktu::usun()`). Stary formularz pierwszego
            // opakowania nie może wtedy zapisać swojej treści na cudze miejsce.
            if (($wiersz->first_package_id !== null && ($odcisk === null || $odcisk === ''))
                || ($odcisk !== null && $odcisk !== '' && $odcisk !== Opakowanie::odciskTresci(
                    $wiersz->expires_on === null ? null : (string) $wiersz->expires_on,
                    $wiersz->expiry_kind,
                    $wiersz->quantity_note,
                    (bool) $wiersz->frozen,
                    (string) ($wiersz->first_package_id ?? $wiersz->id),
                ))) {
                throw ValidationException::withMessages(['opakowanie' => DrugieOpakowanieProduktu::BLAD_ZMIENILO_SIE]);
            }

            $zmiany = $this->kolumny($wiersz, $dane, $dzis);

            DB::table('pantry_items')
                ->where('id', $produkt->getKey())
                ->where('user_id', $produkt->user_id)
                ->update($zmiany);

            return true;
        });
    }

    /**
     * Zwalidowane kolumny do zapisu (termin, rodzaj, ilość, „mrożone”) —
     * wspólne dla pierwszego opakowania (`handle()`) i drugiego
     * (`DrugieOpakowanieProduktu`), żeby oba mówiły te same błędy po polsku.
     *
     * @param  array<string, mixed>  $dane
     * @param  object{expires_on: ?string, expiry_kind: ?string}  $zapisany  wiersz odczytany pod blokadą
     * @return array<string, mixed> kolumny do zapisu
     *
     * @throws ValidationException
     */
    public function kolumny(object $zapisany, array $dane, string $dzis): array
    {
        $bledy = [];

        $ilosc = $this->ilosc($dane['ilosc'] ?? null, $bledy);
        $zmiany = [
            'quantity_note' => $ilosc,
            'frozen' => filter_var($dane['mrozone'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        $termin = $this->termin($zapisany, $dane, $dzis, $bledy);

        if ($bledy !== []) {
            throw ValidationException::withMessages($bledy);
        }

        return $zmiany + $termin;
    }

    /**
     * @param  array<string, mixed>  $dane
     * @param  object{expires_on: ?string, expiry_kind: ?string}  $zapisany  wiersz odczytany pod blokadą
     * @param  array<string, string>  $bledy
     * @return array<string, mixed>
     */
    private function termin(object $zapisany, array $dane, string $dzis, array &$bledy): array
    {
        $rodzaj = is_string($dane['rodzaj'] ?? null) ? $dane['rodzaj'] : '';
        $dzien = $this->liczba($dane['termin_dzien'] ?? null);
        $miesiac = $this->liczba($dane['termin_miesiac'] ?? null);
        $rok = $this->liczba($dane['termin_rok'] ?? null);
        $za = is_string($dane['za'] ?? null) ? $dane['za'] : '';
        $czysc = ['expires_on' => null, 'expiry_kind' => null];

        if (! empty($dane['wyczysc'])) {
            return $czysc;
        }

        // „Za 3 dni” sprawdzamy PRZED „Nie znam terminu”: kliknięty przycisk to
        // wyraźny zamiar ustawienia daty. Gdy zaznaczone zostało „Nie znam
        // terminu”, nie kasujemy po cichu terminu — prosimy o wybór rodzaju.
        if ($za === '' && $rodzaj === self::NIEZNANY) {
            return $czysc;
        }

        $rodzajeZnane = array_keys(PriorytetZuzycia::RODZAJE);
        $rodzajPoprawny = in_array($rodzaj, $rodzajeZnane, true);
        $cokolwiekZDaty = $dzien !== null || $miesiac !== null || $rok !== null;

        // „Za 3 dni” i spółka: data od dziś, rodzaj musi być zaznaczony.
        if ($za !== '') {
            if (! array_key_exists($za, self::SZYBKIE)) {
                $bledy['termin_dzien'] = 'Wybierz jeden z przycisków albo ustaw datę z list.';

                return [];
            }

            if (! $rodzajPoprawny) {
                // Data z przycisku wraca na listy Dzień / Miesiąc / Rok
                // (`dataZaPrzyciskiem()` w widoku), więc wystarczy zaznaczyć rodzaj.
                $bledy['rodzaj'] = 'Przycisk „'.self::SZYBKIE[$za].'” liczy datę od dziś, ale trzeba jeszcze wiedzieć, jaki to termin. '
                    .'Zaznacz „Należy zużyć do” albo „Najlepiej spożyć przed” i naciśnij „Zapisz” — data jest już wpisana w listach. Nic nie zmieniliśmy.';

                return [];
            }

            return ['expires_on' => self::dataZaPrzyciskiem($za, $dzis), 'expiry_kind' => $rodzaj];
        }

        // Nic nie wybrano i nic nie było: zostaje bez terminu (zmiana samej ilości).
        if (! $rodzajPoprawny && ! $cokolwiekZDaty) {
            return $this->zostawTermin($zapisany);
        }

        if (! $rodzajPoprawny) {
            $bledy['rodzaj'] = $this->bladRodzaju();

            return [];
        }

        if ($dzien === null || $miesiac === null || $rok === null) {
            $bledy['termin_dzien'] = 'Wybierz dzień, miesiąc i rok terminu z list albo użyj jednego z przycisków „Za 3 dni”.';

            return [];
        }

        if (! in_array($rok, self::lataDoWyboru($dzis, $zapisany->expires_on === null ? null : (int) substr((string) $zapisany->expires_on, 0, 4)), true)) {
            $bledy['termin_rok'] = 'Wybierz rok z listy.';

            return [];
        }

        if (! checkdate($miesiac, $dzien, $rok)) {
            $bledy['termin_dzien'] = 'W tym miesiącu nie ma takiego dnia. Wybierz inny dzień albo miesiąc.';

            return [];
        }

        return [
            'expires_on' => sprintf('%04d-%02d-%02d', $rok, $miesiac, $dzien),
            'expiry_kind' => $rodzaj,
        ];
    }

    /**
     * @param  object{expires_on: ?string, expiry_kind: ?string}  $zapisany
     * @return array<string, mixed>
     */
    private function zostawTermin(object $zapisany): array
    {
        return [
            'expires_on' => $zapisany->expires_on,
            'expiry_kind' => $zapisany->expiry_kind,
        ];
    }

    private function bladRodzaju(): string
    {
        return 'Zaznacz, jaki to termin: „Należy zużyć do” albo „Najlepiej spożyć przed”, albo wybierz „Nie znam terminu”.';
    }

    /** @param  array<string, string>  $bledy */
    private function ilosc(mixed $surowa, array &$bledy): ?string
    {
        if (! is_string($surowa)) {
            return null;
        }

        $tekst = Str::squish($surowa);

        if ($tekst === '') {
            return null;
        }

        if (mb_strlen($tekst) > self::MAKS_ZNAKOW_ILOSCI) {
            $bledy['ilosc'] = 'Skróć opis ilości do '.self::MAKS_ZNAKOW_ILOSCI.' znaków, na przykład „pół kostki” albo „1 litr”.';

            return null;
        }

        return $tekst;
    }

    private function liczba(mixed $wartosc): ?int
    {
        if (! is_string($wartosc) && ! is_int($wartosc)) {
            return null;
        }

        $tekst = trim((string) $wartosc);

        return $tekst !== '' && ctype_digit($tekst) ? (int) $tekst : null;
    }
}
