<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Domain\Recipes\KosztPrzepisu;
use App\Models\Recipe;

/**
 * Czytelny, odporny na braki odczyt zapisanej migawki wersji przepisu
 * (issue #2024).
 *
 * ZASADA: BRAK KLUCZA TO „BRAK DANYCH", NIE PUSTA WARTOŚĆ. Starsze migawki nie
 * mają `source_url` ani `ingredients[].no_amount` (issue #896), a
 * `SnapshotRecipeVersion` zabrania uzupełniania ich dzisiejszą treścią. Ta
 * klasa więc nigdy nie sięga do przepisu — czyta wyłącznie to, co leży
 * w migawce, a nieobecny klucz odróżnia od pustego przez `maKlucz()`.
 *
 * CZEGO TU CELOWO NIE MA: `editor_id`, wykonań (publiczna historia nic nie
 * sugeruje o tym, z której wersji ktoś gotował; wskaźnik z #2378 jest
 * prywatny dla kucharza i czyta go `WersjaWykonania`) i zdjęć (migawka ich
 * nie obejmuje).
 */
final class MigawkaWersji
{
    /**
     * Pola skalarne w kolejności, w jakiej czyta je człowiek. Każde z nich
     * widać dziś na stronie przepisu — historia nie pokazuje niczego więcej.
     *
     * @var array<string, string>
     */
    public const ETYKIETY = [
        'title' => 'Nazwa',
        'summary' => 'Opis',
        'servings' => 'Ilość porcji',
        'prep_minutes' => 'Przygotowanie (minuty)',
        'cook_minutes' => 'Gotowanie (minuty)',
        'difficulty' => 'Poziom trudności',
        'estimated_cost_pln' => 'Koszt wg autora',
        'source_type' => 'Rodzaj przepisu',
        'source_person' => 'Skąd ten przepis — od kogo',
        'source_note' => 'Skąd ten przepis — opis',
        'source_url' => 'Adres strony, z której pochodzi przepis',
        'family_since_year' => 'W rodzinie od roku',
    ];

    /** @param array<string, mixed> $dane */
    public function __construct(private readonly array $dane) {}

    public function maKlucz(string $klucz): bool
    {
        return array_key_exists($klucz, $this->dane);
    }

    /**
     * Wartość pola gotowa do pokazania: tekst albo `null` (autor nie podał).
     * Nie odróżnia „brak klucza" od „puste" — do tego służy `maKlucz()`.
     */
    public function pole(string $klucz): ?string
    {
        $wartosc = $this->dane[$klucz] ?? null;

        if ($wartosc === null || $wartosc === '' || is_array($wartosc) || is_object($wartosc)) {
            return null;
        }

        $tekst = match ($klucz) {
            'servings' => self::liczba($wartosc),
            'difficulty' => Recipe::DIFFICULTY_LABELS[(string) $wartosc] ?? null,
            'source_type' => Recipe::SOURCE_LABELS[(string) $wartosc] ?? null,
            'estimated_cost_pln' => is_numeric($wartosc) ? KosztPrzepisu::zdanie((float) $wartosc) : null,
            // Adres źródła widać na stronie tylko przy przepisie „z książki,
            // bloga lub telewizji" — tu ta sama bramka.
            'source_url' => ($this->dane['source_type'] ?? null) === Recipe::SOURCE_EXTERNAL ? (string) $wartosc : null,
            default => (string) $wartosc,
        };

        $tekst = $tekst === null ? null : trim($tekst);

        return $tekst === '' ? null : $tekst;
    }

    /**
     * Pola do wypisania: etykieta, wartość (`null` = autor nie podał)
     * i `brakDanych` = starsza migawka tego pola nie zapisała.
     *
     * @return list<array{klucz: string, etykieta: string, wartosc: ?string, brakDanych: bool}>
     */
    public function pola(): array
    {
        $wynik = [];

        foreach (self::ETYKIETY as $klucz => $etykieta) {
            $wynik[] = [
                'klucz' => $klucz,
                'etykieta' => $etykieta,
                'wartosc' => $this->pole($klucz),
                'brakDanych' => ! $this->maKlucz($klucz),
            ];
        }

        return $wynik;
    }

    /**
     * Składniki w kolejności zapisu. Widać dziś: tekst, dopisek, zamiennik
     * i nazwę grupy; ilość i jednostka siedzą w tekście autora.
     *
     * @return list<array{group_name: ?string, text: string, note: ?string, substitutes: ?string}>
     */
    public function skladniki(): array
    {
        $wiersze = $this->wiersze('ingredients');
        usort($wiersze, fn (array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

        $wynik = [];

        foreach ($wiersze as $wiersz) {
            $tekst = self::tekst($wiersz['text'] ?? null);
            if ($tekst === null) {
                continue;
            }

            $wynik[] = [
                'group_name' => self::tekst($wiersz['group_name'] ?? null),
                'text' => $tekst,
                'note' => self::tekst($wiersz['note'] ?? null),
                'substitutes' => self::tekst($wiersz['substitutes'] ?? null),
            ];
        }

        return $wynik;
    }

    /**
     * @return list<array{instruction: string, timer_seconds: ?int}>
     */
    public function kroki(): array
    {
        $wiersze = $this->wiersze('steps');
        usort($wiersze, fn (array $a, array $b): int => (int) ($a['position'] ?? 0) <=> (int) ($b['position'] ?? 0));

        $wynik = [];
        foreach ($wiersze as $wiersz) {
            $tresc = self::tekst($wiersz['instruction'] ?? null);
            if ($tresc === null) {
                continue;
            }

            $wynik[] = [
                'instruction' => $tresc,
                'timer_seconds' => isset($wiersz['timer_seconds']) && is_numeric($wiersz['timer_seconds'])
                    ? (int) $wiersz['timer_seconds']
                    : null,
            ];
        }

        return $wynik;
    }

    /** Minutnik kroku słowami, np. „10 min". */
    public static function minutnik(?int $sekundy): ?string
    {
        if ($sekundy === null || $sekundy <= 0) {
            return null;
        }

        return $sekundy % 60 === 0
            ? ($sekundy / 60).' min'
            : $sekundy.' s';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wiersze(string $klucz): array
    {
        $surowe = $this->dane[$klucz] ?? null;
        if (! is_array($surowe)) {
            return [];
        }

        return array_values(array_filter($surowe, 'is_array'));
    }

    private static function tekst(mixed $wartosc): ?string
    {
        if ($wartosc === null || is_array($wartosc) || is_object($wartosc)) {
            return null;
        }

        $tekst = trim((string) $wartosc);

        return $tekst === '' ? null : $tekst;
    }

    private static function liczba(mixed $wartosc): ?string
    {
        if (! is_numeric($wartosc)) {
            return null;
        }

        $tekst = rtrim(rtrim(number_format((float) $wartosc, 2, ',', ''), '0'), ',');

        return $tekst === '' ? '0' : $tekst;
    }
}
