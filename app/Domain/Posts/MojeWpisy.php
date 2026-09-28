<?php

declare(strict_types=1);

namespace App\Domain\Posts;

use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * „Moje wpisy” w „Moje” — lista WŁASNYCH wpisów autora (D-328).
 *
 * Decyzja właściciela z 26.09.2026: autor ma widzieć w jednym miejscu
 * wszystko, co sam napisał — także wpisy „tylko dla mnie”, „tylko dla
 * obserwujących”, szkice i wpisy ukryte przez moderację. Do tej pory
 * własne wpisy były tylko w profilu, a profil pokazuje wyłącznie
 * OPUBLIKOWANE (`ProfileController::postsFor()` → `published()`), więc
 * szkic albo wpis ukryty przez moderację nie był widoczny nigdzie.
 *
 * AUTORYZACJA TO SAMO ZAPYTANIE. Trasa nie ma identyfikatora w adresie,
 * a zakres to zawsze `author_id` zalogowanej osoby — nie ma czego
 * podmienić, żeby zobaczyć cudzą listę.
 *
 * CZEGO TU NIE MA, I DLACZEGO:
 *  - wpisów miękko usuniętych (`deleted_at`, w tym `removed` z moderacji) —
 *    wiązanie trasy wpisu ich nie znajduje, więc karta prowadziłaby do 404.
 *    Tę samą granicę ma profil i `PostPolicy::view()`;
 *  - pytań, gdy dział pytań jest wyłączony (`enabledKinds()`) — ta sama
 *    flaga odmawia wejścia na stronę pytania, a lista nie ma prowadzić
 *    do odmowy.
 *
 * KOLEJNOŚĆ OD NAJNOWSZEGO. Szkic nie ma `published_at`, więc datą wpisu
 * jest wtedy chwila założenia — inaczej szkice lądowałyby zawsze na końcu
 * albo zawsze na początku, niezależnie od tego, kiedy powstały. `id` na
 * końcu rozstrzyga remis sekundowej dokładności `timestamptz`, żeby
 * paginacja nie pokazała tego samego wpisu na dwóch stronach.
 */
final class MojeWpisy
{
    public const NA_STRONE = 20;

    /**
     * Relacje, które czyta karta listy — ładowane z góry, żeby liczba
     * zapytań nie rosła z liczbą wpisów (pilnuje `MojeWpisyTest`).
     * `media` w całości, nie tylko gotowe: `czyJestZapowiedziaPrzepisu()`
     * pyta o KAŻDE zdjęcie wpisu, a zdjęcie w obróbce też jest treścią.
     */
    private const RELACJE = [
        'media',
        'recipe:id,title,slug,visibility',
    ];

    /** @return LengthAwarePaginator<int, Post> */
    public function strona(User $autor): LengthAwarePaginator
    {
        return $autor->posts()
            ->enabledKinds()
            ->with(self::RELACJE)
            ->orderByRaw('coalesce(posts.published_at, posts.created_at) desc')
            ->orderByDesc('posts.id')
            ->paginate(self::NA_STRONE)
            ->withQueryString();
    }

    /**
     * Kto widzi ten wpis — słowami z karty wpisu (`x-post-card`).
     *
     * Zapowiedź przepisu ma `visibility = 'public'` nie z wyboru autora,
     * tylko jako brak własnego zawężenia; o tym, kto ją widzi, decyduje
     * przepis (`Post::scopeZWidocznymPrzepisem()`, #368). Wpis z własną
     * treścią ma widoczność własną (#1377).
     */
    public static function widocznosc(Post $wpis): string
    {
        $widocznosc = $wpis->czyJestZapowiedziaPrzepisu() && $wpis->recipe !== null
            ? $wpis->recipe->visibility
            : $wpis->visibility;

        return match ($widocznosc) {
            Post::VISIBILITY_FOLLOWERS => 'Dla obserwujących',
            Post::VISIBILITY_PRIVATE => 'Tylko dla mnie',
            default => 'Publiczny',
        };
    }

    /** Stan wpisu słowami autora, nie nazwą kolumny. */
    public static function stan(Post $wpis): string
    {
        return match (true) {
            $wpis->status === Post::STATUS_HIDDEN => 'Ukryty przez moderację',
            $wpis->status === Post::STATUS_REMOVED => 'Zdjęty przez moderację',
            $wpis->isPublished() => 'Opublikowany',
            default => 'Szkic — jeszcze nieopublikowany',
        };
    }
}
