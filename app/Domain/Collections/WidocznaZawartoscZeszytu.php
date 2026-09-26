<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Co z zeszytu wolno pokazać oglądającemu — JEDNO miejsce dla listy, licznika
 * niedostępnych zapisów i porządkowania ich (issue #773).
 *
 * Filtry stały wcześniej wprost w `CollectionController::show()`. Porządkowanie
 * niedostępnych zapisów musi skasować DOKŁADNIE te pozycje, które ekran liczy
 * jako niedostępne; dwie kopie tych samych warunków rozjechałyby się przy
 * pierwszej zmianie jednej z nich i akcja wyjęłaby z zeszytu coś, co człowiek
 * nadal na ekranie widzi.
 */
final class WidocznaZawartoscZeszytu
{
    public function przepisy(Collection $collection, ?User $viewer): BelongsToMany
    {
        return $collection->recipes()
            ->widoczneDla($viewer)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor());
    }

    public function wpisy(Collection $collection, ?User $viewer): BelongsToMany
    {
        // Cztery granice w jednym zakresie (`Post::scopeWidoczneWZeszycieDla()`):
        // widoczność wpisu, bramka przepisu dla czystej zapowiedzi (#368, #1377),
        // autor wpisu i autor PRZEPISU (W5-08). Ta sama reguła liczy kartę
        // zeszytu i „Ostatnio zapisane" (#1319), więc „niedostępne" (#773)
        // to dokładnie to, czego nie ma na liście.
        return $collection->posts()->widoczneWZeszycieDla($viewer);
    }

    /**
     * Zapisy tego zeszytu, których oglądający nie zobaczy na liście: treść
     * usunięta, zawężona przez autora, zablokowana albo od zamkniętego konta.
     *
     * Zwraca same identyfikatory — do liczenia i do kasowania powiązań, nigdy
     * do pokazania. Czytamy wiersze `collection_items`, nie `recipes`/`posts`,
     * więc miękko usunięta treść liczy się bez `withTrashed()` na modelu.
     *
     * @return array{przepisy: list<string>, wpisy: list<string>}
     */
    public function niedostepne(Collection $collection, ?User $viewer): array
    {
        $pozycje = DB::table('collection_items')->where('collection_id', $collection->getKey());

        $przepisy = (clone $pozycje)->whereNotNull('recipe_id')
            ->whereNotIn('recipe_id', $this->przepisy($collection, $viewer)->select('recipes.id')->reorder())
            ->orderBy('recipe_id')
            ->pluck('recipe_id');

        $wpisy = (clone $pozycje)->whereNotNull('post_id')
            ->whereNotIn('post_id', $this->wpisy($collection, $viewer)->select('posts.id')->reorder())
            ->orderBy('post_id')
            ->pluck('post_id');

        return [
            'przepisy' => $przepisy->map(fn ($id) => (string) $id)->all(),
            'wpisy' => $wpisy->map(fn ($id) => (string) $id)->all(),
        ];
    }

    /**
     * Odcisk zbioru niedostępnych zapisów, wysyłany z formularzem potwierdzenia.
     *
     * Sama liczba nie wystarcza: jeden zapis może w międzyczasie wrócić, a inny
     * zniknąć — liczba się zgadza, a skasowalibyśmy pozycję, której człowiek
     * nie potwierdzał. HMAC na kluczu aplikacji, żeby odcisk nie był
     * sprawdzianem „czy ten identyfikator jest w cudzym zeszycie".
     *
     * @param  array{przepisy: list<string>, wpisy: list<string>}  $niedostepne
     */
    public function odcisk(Collection $collection, array $niedostepne): string
    {
        return hash_hmac('sha256', implode("\n", [
            'zeszyt:'.$collection->getKey(),
            'przepisy:'.implode(',', $niedostepne['przepisy']),
            'wpisy:'.implode(',', $niedostepne['wpisy']),
        ]), (string) config('app.key'));
    }
}
