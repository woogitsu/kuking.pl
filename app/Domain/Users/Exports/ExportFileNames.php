<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Models\DataExport;
use App\Models\Recipe;
use Illuminate\Support\Str;

/**
 * Nazwy plików w paczce. Osobna klasa, bo tę samą nazwę trzeba wyliczyć
 * w trzech miejscach: w `dane.json`, w `index.html` i przy dodawaniu pliku
 * do archiwum. Rozjazd nazw = martwy link w spisie treści.
 */
final class ExportFileNames
{
    /** Plik HTML jednego przepisu, np. `rosol-z-kury-<uuid>.html`. */
    public static function recipeFile(Recipe $recipe): string
    {
        $slug = Str::limit(Str::slug((string) ($recipe->slug ?: $recipe->title)), 70, '');

        if ($slug === '') {
            $slug = 'przepis';
        }

        // Pełny identyfikator rozróżnia też przepisy utworzone blisko siebie:
        // UUID v7 ma wtedy wspólny początek, więc krótki fragment koliduje.
        return $slug.'-'.(string) $recipe->getKey().'.html';
    }

    /** Nazwa pliku ZIP, jaką zobaczy człowiek w katalogu Pobrane. */
    public static function archiveFile(DataExport $export): string
    {
        $date = ($export->created_at ?? now())->format('Y-m-d');

        return "kuking-moje-dane-{$date}.zip";
    }

    /** Klucz obiektu w storage. Zawiera id konta, więc paczki się nie mieszają. */
    public static function objectKey(DataExport $export): string
    {
        return sprintf('eksporty/%s/%s-%s',
            $export->user_id,
            (string) $export->getKey(),
            self::archiveFile($export),
        );
    }
}
