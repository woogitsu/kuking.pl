<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Opublikowany przepis ma w strumieniach JEDEN wpis, który go WSKAZUJE
 * (issue #368).
 *
 * SKĄD TO SIĘ WZIĘŁO. Wszystkie trzy strumienie („Świeżo z Kuking", feed
 * obserwowanych, tablica dnia) pytają wyłącznie o `posts` — słowo `Recipe::`
 * nie padało w `app/Domain/Feed/` ani razu. Opublikowany przepis był więc
 * widoczny tylko na profilu autora i w wyszukiwarce, czyli tam, gdzie trzeba
 * było go już szukać. Cała instalacja po stronie wpisu stała przy tym GOTOWA
 * od pierwszego dnia: kolumna `posts.recipe_id`, relacja `Post::recipe()`,
 * doładowanie w strumieniach i karta z odnośnikiem do przepisu oraz
 * przyciskiem „Ugotowałem". Brakowało jednego: nikt nigdy nie tworzył wiersza.
 *
 * WPIS WSKAZUJE, NIE KOPIUJE — I TO JEST TU NAJWAŻNIEJSZE.
 * `body` zostaje `null`, wpis nie dostaje ani jednego własnego zdjęcia i nie
 * przepisuje z przepisu ani tytułu, ani widoczności. Karta bierze wszystko
 * z relacji `$post->recipe`, a widoczność liczy
 * `Post::scopeZWidocznymPrzepisem()` — też z przepisu. Naiwna wersja
 * („skopiuj tytuł, zdjęcie i widoczność") dałaby cztery miejsca do
 * rozjechania się: usunięcie przepisu, zmianę widoczności, ukrycie przez
 * moderację i zwykłą zmianę tytułu.
 *
 * DLACZEGO OSOBNA KLASA, A NIE METODA W `PublishRecipe`. Bo woła to także
 * `kuking:dopisz-wpisy-przepisow` — komenda uzupełniająca przepisy
 * opublikowane, zanim ten mechanizm powstał. Gdyby reguła („co zapisujemy",
 * „kiedy wolno zapisać drugi raz") żyła w dwóch miejscach, komenda
 * uzupełniająca zaczęłaby produkować wpisy odrobinę inne niż publikacja
 * — a rozjazd tego rodzaju widać dopiero po miesiącach.
 */
final class WpisWskazujacyPrzepis
{
    /**
     * Dopisuje wpis dla JEDNEGO przepisu, jeśli jeszcze go nie ma.
     *
     * MUSI BYĆ WOŁANE W TRANSAKCJI. Publikacja przepisu NIE MA dziś klucza
     * idempotencji, jaki ma wpis (`posts.klucz_wyslania`, D-027) — ani
     * kolumny, ani indeksu. Sprawdzenie „czy wpis już jest" stoi więc w tej
     * samej transakcji co tworzenie, a nie przed nią, i idzie po blokadzie
     * wiersza przepisu. Samo `exists()` przed transakcją byłoby klasycznym
     * check-then-act: dwa równoległe żądania czytają „nie ma", oba wstawiają.
     *
     * DLACZEGO BLOKADA JEST OSOBNA, SKORO `Recipe::update()` I TAK BIERZE
     * WIERSZ. Bo nie zawsze bierze: Eloquent pomija `UPDATE`, gdy żaden
     * atrybut nie jest brudny — czyli dokładnie przy drugim wysłaniu tego
     * samego formularza, w jedynym przypadku, który ta bramka ma obsłużyć.
     * Kolejność blokad zostaje niezmieniona (`media` → `users` → `recipes`
     * → `posts`, D-079): `PublishRecipe` trzyma ten sam wiersz `recipes`
     * już od `Recipe::create()`/`update()`.
     *
     * WPIS USUNIĘTY MIĘKKO LICZY SIĘ JAKO ISTNIEJĄCY (`withTrashed()`).
     * Skasowanie wpisu z feedu jest decyzją człowieka; kolejna edycja
     * przepisu nie ma prawa jej cofnąć i wystawić treści z powrotem.
     *
     * @return Post|null wpis, który WŁAŚNIE powstał; `null`, gdy nie było
     *                   czego dopisywać (przepis nieopublikowany albo wpis
     *                   już istnieje)
     */
    public static function dopisz(Recipe $recipe): ?Post
    {
        if (! $recipe->isPublished()) {
            return null;
        }

        // Blokada wiersza przepisu — patrz akapit w opisie metody. `first()`,
        // nie `exists()`: `select exists (...)` nie niesie `FOR UPDATE`.
        Recipe::query()->whereKey($recipe->getKey())->lockForUpdate()->first(['id']);

        $juzJest = Post::query()
            ->withTrashed()
            ->where('recipe_id', $recipe->getKey())
            ->exists();

        if ($juzJest) {
            return null;
        }

        return Post::create([
            'author_id' => $recipe->author_id,
            'body' => null,
            // `visibility = 'public'` NIE JEST KOPIĄ widoczności przepisu,
            // tylko brakiem własnego zawężenia: wpis nie niesie żadnej treści,
            // której miałby strzec, a jedyną bramką jest przepis
            // (`Post::scopeZWidocznymPrzepisem()`). Zapisanie tu widoczności
            // przepisu byłoby dokładnie tą kopią, której unikamy — i
            // rozjechałoby się przy pierwszej zmianie w formularzu przepisu.
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'recipe_id' => $recipe->getKey(),
            // Data publikacji PRZEPISU, nie „teraz": wpis ma stać
            // w chronologii tam, gdzie przepis naprawdę powstał. Ma to
            // znaczenie zwłaszcza dla komendy uzupełniającej, która inaczej
            // wrzuciłaby cały archiwalny zbiór na górę wszystkich strumieni
            // naraz.
            'published_at' => $recipe->published_at ?? now(),
        ]);
    }

    /**
     * Przepisy opublikowane, którym brakuje wpisu.
     *
     * `whereNotExists` na surowym `posts`, a nie relacja z `whereDoesntHave`:
     * wpis usunięty miękko MA się tu liczyć (patrz `dopisz()`), a relacja
     * Eloquenta dołożyłaby `posts.deleted_at is null` i komenda odtwarzałaby
     * wpisy skasowane świadomie przez ludzi.
     *
     * @return Builder<Recipe>
     */
    public static function zalegle(): Builder
    {
        return Recipe::query()
            ->published()
            ->whereNotExists(function (QueryBuilder $sub): void {
                $sub->selectRaw('1')
                    ->from('posts')
                    ->whereColumn('posts.recipe_id', 'recipes.id');
            });
    }

    /**
     * Uzupełnia brakujące wpisy dla przepisów opublikowanych wcześniej.
     *
     * IDEMPOTENTNA: drugie uruchomienie nie ma już czego znaleźć, bo pytanie
     * brzmi „które przepisy nie mają wpisu", a nie „które przepisy
     * przetworzyliśmy".
     *
     * KAŻDY PRZEPIS W OSOBNEJ TRANSAKCJI, nie wszystkie w jednej: jedna
     * transakcja na tysiąc wierszy trzymałaby blokady przez cały przebieg
     * i po jednym błędzie cofałaby całą pracę. Tutaj przerwany przebieg
     * zostawia to, co zdążył, a kolejne uruchomienie dokańcza resztę —
     * bo jest idempotentne.
     *
     * @return int ile wpisów dopisano (albo ile BY dopisano przy `$naSucho`)
     */
    public static function uzupelnijZaleglosci(bool $naSucho = false): int
    {
        if ($naSucho) {
            return self::zalegle()->count();
        }

        $dopisane = 0;

        self::zalegle()->orderBy('id')->chunkById(200, function ($przepisy) use (&$dopisane): void {
            foreach ($przepisy as $przepis) {
                $wpis = DB::transaction(static fn (): ?Post => self::dopisz($przepis));

                if ($wpis !== null) {
                    $dopisane++;
                }
            }
        });

        return $dopisane;
    }
}
