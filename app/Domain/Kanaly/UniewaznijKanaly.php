<?php

declare(strict_types=1);

namespace App\Domain\Kanaly;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Wyrzuca z cache kopie kanałów Atom, które mogły nieść zmienioną treść
 * (D-333, łagodzenie ryzyka prawnego): zdjęcie z urzędu (DSA), ukrycie
 * przez moderację i usunięcie przez autora mają znikać z kanału OD RAZU,
 * nie po `kuking.kanal_cache_sekund`.
 *
 * JEDNO MIEJSCE: woła je model `Post` (obok `UniewaznijCacheTagow`) i model
 * `Recipe` przy zapisie, usunięciu i przywróceniu, więc akcje domeny
 * i moderacja przechodzą tędy bez osobnych wywołań w kontrolerach.
 *
 * CO CZYŚCIMY (klucze z `KluczeKanalu`)
 *  - kanał profilu autora wpisu (i przepisu),
 *  - kanały tagów wpisu,
 *  - kanały zeszytów, w których pozycja leży (`collection_items`),
 *  - przy zmianie PRZEPISU także profile i tagi wpisów, które go
 *    pokazują (`posts.recipe_id`) — ukryty przepis zmienia ich widoczność.
 *
 * TAK JAK `UniewaznijCacheTagow`: zbiór kluczy liczymy dwa razy (w chwili
 * zdarzenia — przed kaskadą przy trwałym usunięciu i przed zmianą tagów —
 * oraz po commicie) i czyścimy sumę; `Cache::forget` po commicie, żeby
 * równoległe żądanie nie zapisało starego stanu po czyszczeniu.
 *
 * CZEGO NIE POKRYWA (zostaje TTL do 5 minut): masowe `UPDATE` z pominięciem
 * modeli (np. zmiana statusu konta autora), zmiana zdjęcia wpisu/przepisu
 * (`Media`), zmiana tagu (ukrycie, scalenie) i zmiana nazwy zeszytu.
 */
final class UniewaznijKanaly
{
    public static function poZmianieWpisu(Post $post): void
    {
        $postIds = [(string) $post->getKey()];
        $przepisIds = $post->recipe_id !== null ? [(string) $post->recipe_id] : [];

        self::zapomnijPoCommicie($postIds, $przepisIds, [(string) $post->author_id]);
    }

    public static function poZmianiePrzepisu(Recipe $przepis): void
    {
        $postIds = DB::table('posts')->where('recipe_id', $przepis->getKey())
            ->pluck('id')->map(fn ($id): string => (string) $id)->all();

        self::zapomnijPoCommicie($postIds, [(string) $przepis->getKey()], [(string) $przepis->author_id]);
    }

    /**
     * @param  list<string>  $postIds
     * @param  list<string>  $przepisIds
     * @param  list<string>  $kontoIds
     */
    private static function zapomnijPoCommicie(array $postIds, array $przepisIds, array $kontoIds): void
    {
        $przed = self::klucze($postIds, $przepisIds, $kontoIds);

        DB::afterCommit(static function () use ($postIds, $przepisIds, $kontoIds, $przed): void {
            foreach (array_unique([...$przed, ...self::klucze($postIds, $przepisIds, $kontoIds)]) as $klucz) {
                Cache::forget($klucz);
            }
        });
    }

    /**
     * @param  list<string>  $postIds
     * @param  list<string>  $przepisIds
     * @param  list<string>  $kontoIds
     * @return list<string>
     */
    private static function klucze(array $postIds, array $przepisIds, array $kontoIds): array
    {
        $klucze = [];

        // Autorzy wpisów: `DB::table`, więc także wpisy usunięte miękko.
        $autorzy = array_merge($kontoIds, DB::table('posts')->whereIn('id', $postIds)->pluck('author_id')->all());
        foreach (DB::table('profiles')->whereIn('user_id', array_unique($autorzy))->get(['user_id', 'username']) as $profil) {
            $klucze[] = KluczeKanalu::profil((string) $profil->user_id, (string) $profil->username);
        }

        foreach (DB::table('post_tags')->whereIn('post_id', $postIds)->distinct()->pluck('tag_id') as $tagId) {
            $klucze[] = KluczeKanalu::tag((string) $tagId);
        }

        $zeszyty = DB::table('collection_items')
            ->where(fn ($q) => $q->whereIn('post_id', $postIds)->orWhereIn('recipe_id', $przepisIds))
            ->distinct()->pluck('collection_id');
        foreach ($zeszyty as $zeszytId) {
            $klucze[] = KluczeKanalu::zeszyt((string) $zeszytId);
        }

        return $klucze;
    }
}
