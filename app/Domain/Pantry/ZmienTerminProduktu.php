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

    /** @return list<int> */
    public static function lataDoWyboru(?string $dzis = null): array
    {
        $rok = (int) substr($dzis ?? PriorytetZuzycia::dzis(), 0, 4);

        return range($rok - self::LAT_WSTECZ, $rok + self::LAT_NAPRZOD);
    }

    /**
     * @param  array<string, mixed>  $dane  surowe pola formularza
     * @return bool `false`, gdy produktu już nie ma (usunięty równolegle)
     *
     * @throws ValidationException
     */
    public function handle(PantryItem $produkt, array $dane, ?string $dzis = null): bool
    {
        $dzis ??= PriorytetZuzycia::dzis();
        $zmiany = $this->zmiany($produkt, $dane, $dzis);

        return DB::transaction(function () use ($produkt, $zmiany): bool {
            $wiersz = DB::table('pantry_items')
                ->where('id', $produkt->getKey())
                ->where('user_id', $produkt->user_id)
                ->lockForUpdate()
                ->first(['id']);

            if ($wiersz === null) {
                return false;
            }

            DB::table('pantry_items')
                ->where('id', $produkt->getKey())
                ->where('user_id', $produkt->user_id)
                ->update($zmiany);

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $dane
     * @return array<string, mixed> kolumny do zapisu
     *
     * @throws ValidationException
     */
    private function zmiany(PantryItem $produkt, array $dane, string $dzis): array
    {
        $bledy = [];

        $ilosc = $this->ilosc($dane['ilosc'] ?? null, $bledy);
        $zmiany = [
            'quantity_note' => $ilosc,
            'frozen' => filter_var($dane['mrozone'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];

        $termin = $this->termin($produkt, $dane, $dzis, $bledy);

        if ($bledy !== []) {
            throw ValidationException::withMessages($bledy);
        }

        return $zmiany + $termin;
    }

    /**
     * @param  array<string, mixed>  $dane
     * @param  array<string, string>  $bledy
     * @return array<string, mixed>
     */
    private function termin(PantryItem $produkt, array $dane, string $dzis, array &$bledy): array
    {
        $rodzaj = is_string($dane['rodzaj'] ?? null) ? $dane['rodzaj'] : '';
        $dzien = $this->liczba($dane['termin_dzien'] ?? null);
        $miesiac = $this->liczba($dane['termin_miesiac'] ?? null);
        $rok = $this->liczba($dane['termin_rok'] ?? null);
        $za = is_string($dane['za'] ?? null) ? $dane['za'] : '';
        $czysc = ['expires_on' => null, 'expiry_kind' => null];

        if (! empty($dane['wyczysc']) || $rodzaj === self::NIEZNANY) {
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
                $bledy['rodzaj'] = $this->bladRodzaju();

                return [];
            }

            $data = CarbonImmutable::parse($dzis)->startOfDay();
            $data = $za === 'miesiac' ? $data->addMonthNoOverflow() : $data->addDays((int) $za);

            return ['expires_on' => $data->toDateString(), 'expiry_kind' => $rodzaj];
        }

        // Nic nie wybrano i nic nie było: zostaje bez terminu (zmiana samej ilości).
        if (! $rodzajPoprawny && ! $cokolwiekZDaty) {
            return $this->zostawTermin($produkt);
        }

        if (! $rodzajPoprawny) {
            $bledy['rodzaj'] = $this->bladRodzaju();

            return [];
        }

        if ($dzien === null || $miesiac === null || $rok === null) {
            $bledy['termin_dzien'] = 'Wybierz dzień, miesiąc i rok terminu z list albo użyj jednego z przycisków „Za 3 dni”.';

            return [];
        }

        if (! in_array($rok, self::lataDoWyboru($dzis), true)) {
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

    /** @return array<string, mixed> */
    private function zostawTermin(PantryItem $produkt): array
    {
        return [
            'expires_on' => $produkt->expires_on?->toDateString(),
            'expiry_kind' => $produkt->expiry_kind,
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
