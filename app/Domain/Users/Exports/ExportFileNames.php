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
    /** Plik HTML jednego przepisu, np. `rosol-z-kury-0199….html` (czytelny początek + pełne id). */
    public static function recipeFile(Recipe $recipe): string
    {
        $slug = Str::limit(Str::slug((string) ($recipe->slug ?: $recipe->title)), 70, '');

        if ($slug === '') {
            $slug = 'przepis';
        }

        // PEŁNY identyfikator na końcu (issue #825). Do 23 września 2026 stał
        // tu fragment 6 znaków — a UUID v7 zaczyna się od ZNACZNIKA CZASU
        // w milisekundach, więc 6 znaków szesnastkowych zmienia się raz na
        // ~4,6 godziny. Dwa przepisy o tym samym tytule zapisane tego samego
        // popołudnia miały ten sam fragment, jedną ścieżkę w ZIP-ie i jedną stronę
        // zamiast dwóch. Nazwę czyta człowiek po czytelnym początku; końcówka
        // jest dla rozróżnienia, więc nie wolno jej skracać.
        return $slug.'-'.(string) $recipe->getKey().'.html';
    }

    /** Nazwa pliku ZIP, jaką zobaczy człowiek w katalogu Pobrane. */
    public static function archiveFile(DataExport $export): string
    {
        $date = ($export->created_at ?? now())->format('Y-m-d');

        return "kuking-moje-dane-{$date}.zip";
    }

    /**
     * Klucz obiektu w storage. Zawiera id konta i PEŁNE id paczki (issue #825):
     * 8 znaków UUID v7 to sam znacznik czasu (zmienia się raz na ~65 sekund),
     * więc dwie paczki jednego konta zlecone blisko siebie dostawały ten sam
     * klucz — nowsza nadpisywała starszą, a sprzątanie starszej kasowało nowszą.
     */
    public static function objectKey(DataExport $export): string
    {
        return sprintf('eksporty/%s/%s-%s',
            $export->user_id,
            (string) $export->getKey(),
            self::archiveFile($export),
        );
    }
}
