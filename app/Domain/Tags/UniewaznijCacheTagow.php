<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

/**
 * Unieważnia cache stron tagów dla gościa (kolaż i liczby, audyt B4 W2)
 * po zmianie wpisu, który ten tag nosi — decyzja właściciela: nowe danie
 * ma być na stronie tagu OD RAZU, nie po `CACHE_SEKUND`.
 *
 * JEDNO MIEJSCE. Woła je model `Post` (zapis, usunięcie, przywrócenie,
 * trwałe usunięcie) i `Media` (zmiana statusu zdjęcia, np. gotowe po
 * przetworzeniu). Publikacja, edycja, ukrycie i usunięcie przez akcje
 * domeny zapisują model, więc przechodzą tędy bez osobnych wywołań.
 *
 * STARE I NOWE TAGI. Identyfikatory tagów czytamy dwa razy: w chwili
 * zdarzenia (wewnątrz transakcji — przy `EditPost` to jeszcze tagi sprzed
 * zmiany, bo `save()` stoi przed `detach()`/`attach()`) i po commicie
 * (tagi po zmianie; przy publikacji dopiero wtedy są przypięte). Czyścimy
 * sumę — wpis znika z tagu, który stracił, i pojawia się w nowym.
 *
 * PO COMMICIE (`DB::afterCommit`). Czyszczenie w trakcie transakcji
 * pozwoliłoby równoległej odsłonie przeliczyć i zapisać do cache STARY
 * stan, zanim commit go zmieni. Bez transakcji callback biegnie od razu.
 *
 * Masowe `UPDATE` z pominięciem modeli (np. zmiana statusu konta autora)
 * tu nie trafiają — tam świeżość dalej wynosi `CACHE_SEKUND`.
 */
final class UniewaznijCacheTagow
{
    public static function poZmianieWpisu(Post $post): void
    {
        $postId = (string) $post->getKey();
        $przed = self::tagiWpisow([$postId]);

        DB::afterCommit(static function () use ($postId, $przed): void {
            self::zapomnij([...$przed, ...self::tagiWpisow([$postId])]);
        });
    }

    public static function poZmianieZdjecia(Media $media): void
    {
        $postIds = $media->posts()->pluck('posts.id')->map(fn ($id): string => (string) $id)->all();
        if ($postIds === []) {
            return;
        }

        DB::afterCommit(static function () use ($postIds): void {
            self::zapomnij(self::tagiWpisow($postIds));
        });
    }

    /**
     * @param  list<string>  $postIds
     * @return list<string>
     */
    private static function tagiWpisow(array $postIds): array
    {
        return DB::table('post_tags')->whereIn('post_id', $postIds)
            ->distinct()->pluck('tag_id')
            ->map(fn ($id): string => (string) $id)->all();
    }

    /** @param  list<string>  $tagIds */
    private static function zapomnij(array $tagIds): void
    {
        $tagIds = array_values(array_unique($tagIds));
        if ($tagIds === []) {
            return;
        }

        TagCollage::zapomnijGoscia($tagIds);
        LiczbyTagowWCache::zapomnij($tagIds);
    }
}
