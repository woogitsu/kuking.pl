<?php

declare(strict_types=1);

namespace App\Domain\Tags;

use App\Models\Post;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Publiczne liczby tagu na `/tagi` i `/tag/{slug}` — wpisy, zdjęcia i ich
 * autorzy — w cache per tag (audyt B4 W2).
 *
 * `/tagi` liczył te agregaty dla stu tagów przy KAŻDEJ odsłonie (`withCount`
 * po wpisach i join `posts × post_tags × post_media × media`
 * w `TagPublicStats`), a strony są publiczne, więc chodzą po nich także
 * roboty — zmierzone lokalnie w sekundach przy 200 tys. wpisów. Liczby są
 * te same dla każdego widza (D-087), więc trzymamy je przez `CACHE_SEKUND`:
 * jedna odsłona czyta wszystkie tagi strony jednym `Cache::many()`, a liczy
 * tylko te, których w cache nie ma — zbiorczo, tak jak dotąd. Ceną jest
 * świeżość: nowy wpis podnosi liczbę do kilku minut później.
 *
 * Samo liczenie zostaje w `TagPublicStats` (zdjęcia, autorzy) i tutaj
 * (wpisy) — bez cache, żeby jego reguły dało się testować wprost.
 */
final class LiczbyTagowWCache
{
    public const CACHE_SEKUND = 600;

    private const KLUCZ = 'tagi:liczby-publiczne:';

    public function __construct(private readonly TagPublicStats $statystyki = new TagPublicStats) {}

    /**
     * Unieważnia zapisane liczby podanych tagów — dla przyrządów, które
     * piszą do bazy z pominięciem aplikacji (jak `TagCollage::zapomnijGoscia()`).
     *
     * @param  iterable<string>  $tagIds
     */
    public static function zapomnij(iterable $tagIds): void
    {
        foreach ($tagIds as $id) {
            Cache::forget(self::KLUCZ.$id);
        }
    }

    /**
     * @param  iterable<string>  $tagIds
     * @return array<string, array{postsCount: int, photosCount: int, contributorsCount: int}>
     */
    public function forTags(iterable $tagIds): array
    {
        $ids = [];
        foreach ($tagIds as $id) {
            $ids[$id] = $id;
        }

        if ($ids === []) {
            return [];
        }

        $zCache = Cache::many(array_map(fn (string $id): string => self::KLUCZ.$id, array_values($ids)));

        $wynik = [];
        $brakujace = [];
        foreach ($ids as $id) {
            $zapisane = $zCache[self::KLUCZ.$id] ?? null;
            if (is_array($zapisane)) {
                $wynik[$id] = $zapisane;
            } else {
                $brakujace[] = $id;
            }
        }

        if ($brakujace !== []) {
            $wpisy = $this->liczbyWpisow($brakujace);
            $doZapisu = [];
            foreach ($this->statystyki->forTags($brakujace) as $id => $zdjecia) {
                $wynik[$id] = ['postsCount' => $wpisy[$id] ?? 0, ...$zdjecia];
                $doZapisu[self::KLUCZ.$id] = $wynik[$id];
            }
            Cache::putMany($doZapisu, self::CACHE_SEKUND);
        }

        return array_map(fn (string $id): array => $wynik[$id], $ids);
    }

    /**
     * Wpisy widoczne dla KAŻDEGO — ten sam zakres co
     * `TagController::tylkoPubliczne()` i dawne `withCount('posts')` w spisie
     * tagów (D-087, #941): bez warunku na zdjęcia i na status tagu.
     *
     * @param  list<string>  $tagIds
     * @return array<string, int>
     */
    public function liczbyWpisow(array $tagIds): array
    {
        // Wpis z własną treścią według własnej widoczności (issue #1377) —
        // jak w `TagController::tylkoPubliczne()`.
        $wpisy = Post::query()->publiclyVisible()
            ->tylkoOdAktywnychAutorow()->zWidocznymPrzepisemAlboWlasnaTrescia(null)
            ->select('posts.id');

        return DB::query()->fromSub($wpisy, 'visible_posts')
            ->join('post_tags', 'post_tags.post_id', '=', 'visible_posts.id')
            ->whereIn('post_tags.tag_id', $tagIds)
            ->groupBy('post_tags.tag_id')
            ->selectRaw('post_tags.tag_id, COUNT(*) AS posts_count')
            ->pluck('posts_count', 'tag_id')
            ->map(fn ($ile): int => (int) $ile)
            ->all();
    }
}
