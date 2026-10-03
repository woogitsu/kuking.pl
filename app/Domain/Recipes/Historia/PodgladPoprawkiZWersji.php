<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Support\Facades\DB;

/**
 * Odczytowy podgląd „Zastosuj jako nową poprawkę” (#2525, V2, D-333 — paczka E).
 *
 * NICZEGO NIE ZAPISUJE. Porównuje dzisiejszą treść przepisu z niezmienną
 * migawką wybranej wersji i mówi, co by wróciło, czego NIE przywracamy i czego
 * nie da się bezpiecznie przywrócić. Czego migawka nie ma (starsze migawki bez
 * klucza), to „brak danych” — nie wartość do odziedziczenia po cichu z dzisiejszego
 * przepisu.
 *
 * ZAKRES PILOTA (najbezpieczniejszy dla danych):
 *  - `dane`: nazwa, opis, porcje, gotowe sztuki, czasy, trudność;
 *  - `skladniki`: cała lista składników (wiersze zastępowane, bez mapowania
 *    na dawne identyfikatory);
 *  - `kroki`: cała lista kroków — ale TYLKO gdy żaden dzisiejszy krok nie ma
 *    zdjęcia. Migawka nie niesie identyfikatorów kroków ani zdjęć, więc nie
 *    zgadujemy powiązań po pozycji i nie dopinamy dzisiejszego zdjęcia do
 *    dawnej instrukcji; przy zdjęciach przy krokach ta część jest zablokowana
 *    (podgląd mówi to przed zgodą).
 *  - NIE przywracamy: pochodzenia i podpisu „Mojej wersji”, widoczności,
 *    autorstwa, kosztu, alergenów, zdjęć (głównego, kroków, skanu), adresu
 *    źródła ani opisu pochodzenia.
 */
final class PodgladPoprawkiZWersji
{
    public const SEKCJA_DANE = 'dane';

    public const SEKCJA_SKLADNIKI = 'skladniki';

    public const SEKCJA_KROKI = 'kroki';

    public const SEKCJE = [self::SEKCJA_DANE, self::SEKCJA_SKLADNIKI, self::SEKCJA_KROKI];

    /** Klucze migawki przywracane w sekcji „dane”. */
    public const KLUCZE_DANYCH = ['title', 'summary', 'servings', 'yield_count', 'yield_unit', 'prep_minutes', 'cook_minutes', 'difficulty'];

    /** Pola pokazywane w podglądzie (gotowe sztuki z opisem jednostki idą jednym wierszem). */
    private const POLA = ['title', 'summary', 'servings', 'yield_count', 'prep_minutes', 'cook_minutes', 'difficulty'];

    /** @var list<string> */
    public const NIE_PRZYWRACAMY = [
        'pochodzenia przepisu, podpisu „Mojej wersji” i autorstwa',
        'widoczności przepisu',
        'zdjęć: głównego, przy krokach i skanu kartki',
        'kosztu wg autora oraz oznaczenia alergenów (po zmianie składników trzeba je sprawdzić ponownie)',
        'adresu i opisu źródła przepisu',
    ];

    /**
     * @return array{
     *   dane: array{dostepna: bool, wiersze: list<array{etykieta: string, teraz: ?string, z_wersji: ?string, stan: string}>, zmian: int},
     *   skladniki: array{dostepna: bool, powod: ?string, teraz: list<string>, z_wersji: list<string>, zmieni: bool},
     *   kroki: array{dostepna: bool, powod: ?string, teraz: list<string>, z_wersji: list<string>, zmieni: bool, zdjec: int},
     *   nie_przywracamy: list<string>
     * }
     */
    public static function dla(Recipe $przepis, RecipeVersion $zrodlo): array
    {
        $teraz = new MigawkaWersji(app(SnapshotRecipeVersion::class)->tresc($przepis));
        $stara = new MigawkaWersji($zrodlo->snapshot ?? []);

        $wiersze = [];
        $zmian = 0;
        foreach (self::POLA as $klucz) {
            $etykieta = MigawkaWersji::ETYKIETY[$klucz];
            $jestKlucz = $stara->maKlucz($klucz);
            $a = $teraz->pole($klucz);
            $b = $stara->pole($klucz);
            $stan = ! $jestKlucz ? 'brak_danych' : ($a === $b ? 'bez_zmian' : 'zmieni');
            // Nazwa i porcje nie mogą być puste — pusta wartość z migawki zostaje bez zmian.
            if ($stan === 'zmieni' && in_array($klucz, ['title', 'servings'], true) && $b === null) {
                $stan = 'brak_danych';
            }
            $zmian += $stan === 'zmieni' ? 1 : 0;
            $wiersze[] = ['etykieta' => $etykieta, 'teraz' => $a, 'z_wersji' => $b, 'stan' => $stan];
        }

        $skladnikiTeraz = self::linieSkladnikow($teraz);
        $skladnikiStare = self::linieSkladnikow($stara);
        $maSkladniki = $stara->maKlucz('ingredients') && $skladnikiStare !== [];

        $krokiTeraz = self::linieKrokow($teraz);
        $krokiStare = self::linieKrokow($stara);
        $zdjecKrokow = (int) DB::table('recipe_steps')->where('recipe_id', $przepis->getKey())->whereNotNull('media_id')->count();
        $maKroki = $stara->maKlucz('steps') && $krokiStare !== [];

        $powodKrokow = match (true) {
            ! $maKroki => 'Ta wersja nie ma zapisanych kroków, więc nie ma czego przywrócić.',
            $zdjecKrokow > 0 => 'Dzisiejsze kroki mają zdjęcia ('.$zdjecKrokow.'). Zapis wersji nie zawiera zdjęć ani powiązań z konkretnym krokiem, więc nie przypisujemy ich do dawnych instrukcji. Kroków nie przywracamy; popraw je ręcznie w kreatorze.',
            default => null,
        };

        return [
            'dane' => ['dostepna' => $zmian > 0, 'wiersze' => $wiersze, 'zmian' => $zmian],
            'skladniki' => [
                'dostepna' => $maSkladniki && $skladnikiTeraz !== $skladnikiStare,
                'powod' => $maSkladniki ? null : 'Ta wersja nie ma zapisanych składników, więc nie ma czego przywrócić.',
                'teraz' => $skladnikiTeraz,
                'z_wersji' => $skladnikiStare,
                'zmieni' => $maSkladniki && $skladnikiTeraz !== $skladnikiStare,
            ],
            'kroki' => [
                'dostepna' => $powodKrokow === null && $krokiTeraz !== $krokiStare,
                'powod' => $powodKrokow,
                'teraz' => $krokiTeraz,
                'z_wersji' => $krokiStare,
                'zmieni' => $powodKrokow === null && $krokiTeraz !== $krokiStare,
                'zdjec' => $zdjecKrokow,
            ],
            'nie_przywracamy' => self::NIE_PRZYWRACAMY,
        ];
    }

    /** @return list<string> */
    private static function linieSkladnikow(MigawkaWersji $migawka): array
    {
        return array_map(
            static fn (array $s): string => ($s['group_name'] !== null ? $s['group_name'].': ' : '')
                .$s['text']
                .($s['note'] !== null ? ' — '.$s['note'] : '')
                .($s['substitutes'] !== null ? ' (zamiast tego: '.$s['substitutes'].')' : ''),
            $migawka->skladniki(),
        );
    }

    /** @return list<string> */
    private static function linieKrokow(MigawkaWersji $migawka): array
    {
        return array_map(
            static fn (array $k): string => ($k['section_name'] !== null ? '['.$k['section_name'].'] ' : '')
                .$k['instruction']
                .(($m = MigawkaWersji::minutnik($k['timer_seconds'])) !== null ? ' (minutnik: '.$m.')' : ''),
            $migawka->kroki(),
        );
    }
}
