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
        return $collection->posts()
            ->widoczneDla($viewer)
            // BRAMKA PRZEPISU, OSOBNA OD `widoczneDla()` (#368). Tamten
            // zakres pyta o WPIS, a wpis zapowiadający przepis ma
            // `visibility = 'public'` na stałe (`WpisWskazujacyPrzepis::dopisz()`)
            // — to nie jest jego widoczność, tylko brak własnego zawężenia,
            // bo bramką ma być PRZEPIS. Bez tego warunku zeszyt rysował
            // `x-post-card` z tytułem, zdjęciem głównym i odnośnikiem, w
            // którym slug niesie ten sam tytuł.
            //
            // W ZESZYCIE TEN WYCIEK DOJRZEWA W CZASIE i to jest jego różnica
            // wobec reszty rodziny. Zapowiedź zostaje tu wskazana na stałe,
            // więc gdy autor zawęzi przepis albo zdejmie go moderacja,
            // treść nie znika sama — a osoba, która ją zapisała, nie ma
            // powodu jej wyjmować, bo w chwili zapisu widziała przepis
            // całkowicie legalnie.
            ->zWidocznymPrzepisem($viewer)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            // TRZECIA GRANICA: AUTOR PRZEPISU, A NIE AUTOR WPISU (W5-08).
            //
            // Warunek linijkę wyżej pyta o autora WPISU. Wpis zapowiadający
            // przepis może jednak należeć do kogo innego niż przepis: A odkłada
            // sobie do zeszytu zapowiedź przepisu B. Gdy B zostanie zbanowany
            // albo oznaczony do usunięcia, jego przepis daje 403 pod własnym
            // adresem i znika z listy przepisów tego zeszytu (warunek wyżej przy
            // `przepisy()`) — ale wpis A dalej stał tu z tytułem, zdjęciem głównym
            // i odnośnikiem, bo `zWidocznymPrzepisem()` liczy widoczność
            // i publikację przepisu, a statusu konta jego autora celowo nie zna
            // (patrz `User::scopeDostepnyJakoAutor()`).
            //
            // Gałąź `recipe_id IS NULL` przepuszcza zwykłe wpisy bez przepisu —
            // bez niej zeszyt straciłby całą zawartość. Ten sam idiom liczy
            // `App\Domain\Tags\PodpowiedziTagow`.
            ->where(fn ($w) => $w->whereNull('posts.recipe_id')
                ->orWhereHas('recipe.author', fn ($autor) => $autor->dostepnyJakoAutor()));
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
