<?php

declare(strict_types=1);

namespace App\Domain\Widocznosc;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Widoczność wpisu, przepisu i „Ugotowałem" jako SPECYFIKACJA ZAPYTANIA
 * (issue #1687, etap 1).
 *
 * PO CO TA KLASA ISTNIEJE
 * Do tej zmiany te same warunki stały jako prywatne metody modelu
 * `Notification` (`wierszTresciWidoczny()`, `wierszWykonaniaWidoczny()`).
 * Model powiadomienia był przez to równoległym silnikiem dostępu do treści:
 * zmiana reguły w `PostPolicy`/`RecipePolicy`/`CookedEventPolicy` wymagała
 * drugiej, niezależnej zmiany w SQL encji innego modułu — a przypominał
 * o tym wyłącznie komentarz. Tutaj reguła ma nazwę, jedno miejsce i test
 * kontraktowy (`Tests\Feature\Visibility\PowiadomieniaZgodneZPolicyTest`),
 * który porównuje wynik tego SQL z Policy na macierzy przypadków.
 *
 * KSZTAŁT: FRAGMENT `EXISTS`, NIE SCOPE ELOQUENTA
 * Obie metody dopisują warunki do podzapytania `whereExists(...)`
 * skorelowanego z kolumną zewnętrznego zapytania (`$kolumnaId`, np.
 * `pc.post_id`). Dzięki temu filtr całej strony powiadomień zostaje JEDNYM
 * zapytaniem niezależnie od liczby wierszy (D-196, #833) — Policy wołana po
 * jednym rekordzie przywróciłaby N+1.
 *
 * CZYM SIĘ RÓŻNI OD `Post::scopeWidoczneDla()` / `Recipe::scopeWidoczneDla()`
 * Tamte zakresy odpowiadają listom treści i nie liczą statusu konta autora
 * (tę granicę listy dokładają osobno albo wcale). Ta specyfikacja odtwarza
 * `view()` z Policy dla zalogowanego widza, BEZ furtki moderatora (pominięcie
 * furtki jest ostrzejsze, nie luźniejsze). Ujednolicenie list z tą
 * specyfikacją to kolejny etap #1687 — każda z tych różnic zmienia dziś
 * zachowanie jakiejś listy i wymaga własnej decyzji, nie cichego przepięcia.
 */
final class WidocznoscTresciSql
{
    /**
     * Ten sam literał co `Post::STATUS_PUBLISHED` i `Recipe::STATUS_PUBLISHED`
     * — nazwana stała zamiast magicznego stringa powtórzonego w SQL niżej.
     */
    private const STATUS_TRESCI_OPUBLIKOWANA = 'published';

    /**
     * EXISTS potwierdzający, że wiersz `posts`/`recipes` wskazywany przez
     * $kolumnaId (np. `pc.post_id`) jest w tej chwili widoczny dla $widz —
     * tymi samymi regułami co `PostPolicy::view()` / `RecipePolicy::view()`
     * (obie tabele mają identyczny kształt: `author_id`, `status`,
     * `published_at`, `visibility`, `deleted_at`).
     *
     * @param  'posts'|'recipes'  $tabela
     */
    public static function wpisLubPrzepis(QueryBuilder $sub, string $tabela, string $kolumnaId, User $widz): void
    {
        $widzId = $widz->getKey();

        if ($tabela === 'posts' && ! config('kuking.questions.enabled')) {
            $sub->where('tw.kind', Post::KIND_DISH);
        }

        $sub->selectRaw('1')
            ->from("{$tabela} as tw")
            ->whereColumn('tw.id', $kolumnaId)
            // Skasowana (soft delete) treść nie wraca do nikogo, nawet do autora.
            ->whereNull('tw.deleted_at')
            ->where(function (QueryBuilder $w) use ($widzId): void {
                // Właściciel widzi zawsze własną treść — szkic, ukrytą przez
                // moderację, prywatną. „Poprawne dane nigdy nie znikają."
                $w->where('tw.author_id', $widzId)
                    ->orWhere(function (QueryBuilder $obce) use ($widzId): void {
                        $obce->where('tw.status', self::STATUS_TRESCI_OPUBLIKOWANA)
                            ->whereNotNull('tw.published_at')
                            // Autor treści zbanowany/do usunięcia odcina WSZYSTKICH
                            // poza sobą — już obsłużonym w gałęzi wyżej.
                            ->whereNotExists(function (QueryBuilder $autor): void {
                                $autor->selectRaw('1')
                                    ->from('users as autorzy_tresci')
                                    ->whereColumn('autorzy_tresci.id', 'tw.author_id')
                                    ->whereIn('autorzy_tresci.status', User::STATUSY_UKRYWAJACE_TRESC);
                            })
                            // Blokada między WIDZEM a AUTOREM TREŚCI — może
                            // być inna osoba niż sprawca zdarzenia (odpowiedź
                            // w cudzym wątku).
                            ->whereNotExists(function (QueryBuilder $blok) use ($widzId): void {
                                $blok->selectRaw('1')
                                    ->from('blocks')
                                    ->where(function (QueryBuilder $w2) use ($widzId): void {
                                        $w2->where('blocks.blocker_id', $widzId)
                                            ->whereColumn('blocks.blocked_id', 'tw.author_id');
                                    })
                                    ->orWhere(function (QueryBuilder $w2) use ($widzId): void {
                                        $w2->whereColumn('blocks.blocker_id', 'tw.author_id')
                                            ->where('blocks.blocked_id', $widzId);
                                    });
                            })
                            ->where(function (QueryBuilder $widocznosc) use ($widzId): void {
                                $widocznosc->where('tw.visibility', 'public')
                                    ->orWhere(function (QueryBuilder $obserwujacy) use ($widzId): void {
                                        $obserwujacy->where('tw.visibility', 'followers')
                                            ->whereExists(function (QueryBuilder $f) use ($widzId): void {
                                                $f->selectRaw('1')
                                                    ->from('follows')
                                                    ->where('follows.follower_id', $widzId)
                                                    ->whereColumn('follows.followed_id', 'tw.author_id');
                                            });
                                    });
                            });
                    });
            });
    }

    /**
     * To samo dla „Ugotowałem" (`CookedEventPolicy::view()`) wskazywanego
     * przez $kolumnaId (np. `pc.cooked_event_id`): wykonanie nie ma własnej
     * widoczności, idzie za przepisem, a osobno liczy się blokada
     * widz↔kucharz. Brak przepisu (`recipe_id IS NULL`) zostawia dostęp
     * wyłącznie właścicielowi wykonania.
     *
     * ZNANY ROZJAZD Z POLICY (#1385): `CookedEventPolicy::view()` wpuszcza
     * kucharza do WŁASNEGO wykonania niezależnie od stanu przepisu (ukryty,
     * prywatny, miękko usunięty). Ten SQL przepuszcza go tylko przez widoczny
     * przepis. Test kontraktowy toleruje wyłącznie ten kierunek rozjazdu —
     * naprawa #1385 ma go stąd usunąć razem z tolerancją w teście.
     */
    public static function wykonanie(QueryBuilder $sub, string $kolumnaId, User $widz): void
    {
        $widzId = $widz->getKey();

        $sub->selectRaw('1')
            ->from('cooked_events as ce')
            ->whereColumn('ce.id', $kolumnaId)
            ->whereNotExists(function (QueryBuilder $blok) use ($widzId): void {
                $blok->selectRaw('1')
                    ->from('blocks')
                    ->where(function (QueryBuilder $w) use ($widzId): void {
                        $w->where('blocks.blocker_id', $widzId)->whereColumn('blocks.blocked_id', 'ce.user_id');
                    })
                    ->orWhere(function (QueryBuilder $w) use ($widzId): void {
                        $w->whereColumn('blocks.blocker_id', 'ce.user_id')->where('blocks.blocked_id', $widzId);
                    });
            })
            ->where(function (QueryBuilder $w) use ($widzId, $widz): void {
                $w->where(function (QueryBuilder $bezPrzepisu) use ($widzId): void {
                    $bezPrzepisu->whereNull('ce.recipe_id')->where('ce.user_id', $widzId);
                })->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                    fn (QueryBuilder $s) => self::wpisLubPrzepis($s, 'recipes', 'ce.recipe_id', $widz),
                ));
            });
    }
}
