<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeServingPreference;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Jawnie zapamiętana liczba porcji jednej osoby dla jednego przepisu (#2602).
 *
 * Nic tu nie dzieje się samo. Wiersz powstaje po świadomym przycisku
 * „Zapamiętaj dla mnie” zalogowanej osoby, a znika po „Zapomnij moje
 * ustawienie”. Gość nie zapisuje niczego.
 *
 * PIERWSZEŃSTWO (od najsilniejszego), liczone w `wybor()`:
 *  1. `?porcje=autor` — świadomy powrót do ilości autora, także przy
 *     zapamiętanym wyborze (link „Pokaż ilości z przepisu”). Preferencji nie
 *     kasuje: osoba chce tylko zobaczyć oryginał. Dzięki jawnemu słowu
 *     „zwykłe usunięcie parametru” (adres bez `?porcje=`) nie jest już
 *     powrotem do oryginału — to adres „po mojemu”, więc nie ma pętli.
 *  2. `?porcje=N` — jawny wybór z adresu (też z Planera i wysłany rodzinie)
 *     przechodzi przez `WyborPorcji` jak dotąd; błędna wartość zachowuje
 *     dotychczasowe wyjaśnienie i NIE udaje własnego wyboru.
 *  3. zapamiętana liczba — tylko gdy adres nie mówi nic o porcjach.
 *  4. liczba autora.
 *
 * Wartość z adresu nie wraca na stronę: `?porcje=autor` jest rozpoznawane
 * dokładnie, każda inna wartość idzie do `WyborPorcji::dla()`, który ją
 * waliduje i nigdy nie odsyła surowego tekstu.
 *
 * Receptura się nie zmienia (D-284): zapisujemy samą liczbę, nigdy
 * przeliczonych składników. Zmiana podstawy przez autora działa na bieżącej
 * recepturze; gdy autor usunie liczbę porcji, przepis nie jest skalowany,
 * a zapamiętane ustawienie można zapomnieć.
 */
final class ZapamietanePorcje
{
    /** Wartość `?porcje=` oznaczająca „ilości z przepisu autora, jednorazowo”. */
    public const PARAMETR_AUTORA = 'autor';

    public function zapamietana(?User $widz, Recipe $recipe): ?float
    {
        if ($widz === null) {
            return null;
        }

        $wiersz = RecipeServingPreference::query()
            ->where('user_id', $widz->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->first();

        return $wiersz === null ? null : (float) $wiersz->servings;
    }

    /**
     * Co pokazać osobie, która patrzy na przepis. Wołać dopiero po
     * `authorize('view')`: preferencja nie otwiera żadnej treści.
     */
    public function wybor(Recipe $recipe, ?User $widz, mixed $zAdresu): WyborZapamietanychPorcji
    {
        $zapamietana = $this->zapamietana($widz, $recipe);

        if (is_string($zAdresu) && strtolower(trim($zAdresu)) === self::PARAMETR_AUTORA) {
            return new WyborZapamietanychPorcji(WyborPorcji::dla($recipe, null), $zapamietana, WyborZapamietanychPorcji::AUTOR);
        }

        if ($zAdresu !== null && $zAdresu !== '') {
            return new WyborZapamietanychPorcji(WyborPorcji::dla($recipe, $zAdresu), $zapamietana, WyborZapamietanychPorcji::ADRES);
        }

        if ($zapamietana !== null) {
            return new WyborZapamietanychPorcji(WyborPorcji::dla($recipe, $zapamietana), $zapamietana, WyborZapamietanychPorcji::ZAPAMIETANE);
        }

        return new WyborZapamietanychPorcji(WyborPorcji::dla($recipe, null), null, WyborZapamietanychPorcji::AUTOR);
    }

    /**
     * Zapisuje albo zmienia liczbę. Przyjmuje tylko to, co `WyborPorcji`
     * uzna za poprawne i RÓŻNE od liczby autora (nie ma czego zapamiętywać
     * przy ilościach z przepisu).
     *
     * @throws BladDlaCzlowieka
     */
    public function zapamietaj(User $osoba, Recipe $recipe, mixed $liczba): float
    {
        $wybor = WyborPorcji::dla($recipe, $liczba);

        if (! $wybor->dostepny()) {
            throw new BladDlaCzlowieka('Ten przepis nie ma podanej liczby porcji, więc nie da się go przeliczyć. Zostaje tak, jak napisał autor.');
        }

        if ($wybor->odrzucone || $liczba === null || $liczba === '') {
            throw new BladDlaCzlowieka('Tej liczby porcji nie da się zapamiętać. Wybierz od '.WyborPorcji::NAJMNIEJ.' do '.WyborPorcji::NAJWIECEJ.' przyciskami „Mniej” i „Więcej”, a potem naciśnij „Zapamiętaj dla mnie”.');
        }

        if (! $wybor->przeliczone()) {
            throw new BladDlaCzlowieka('To jest liczba porcji z przepisu autora, więc nie ma czego zapamiętywać. Najpierw wybierz inną liczbę przyciskami „Mniej” i „Więcej”.');
        }

        $ma = RecipeServingPreference::query()
            ->where('user_id', $osoba->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->exists();

        $limit = max(1, (int) config('kuking.porcje_zapamietane.limit_na_osobe', 500));

        if (! $ma && RecipeServingPreference::query()->where('user_id', $osoba->getKey())->count() >= $limit) {
            throw new BladDlaCzlowieka('Masz już zapamiętaną liczbę porcji przy '.$limit.' przepisach. Otwórz któryś z nich i naciśnij „Zapomnij moje ustawienie”, a potem zapamiętaj tę liczbę jeszcze raz.');
        }

        $teraz = now();
        $wybrane = (float) $wybor->wybrane;

        RecipeServingPreference::query()->upsert(
            [[
                'id' => (string) Str::uuid(),
                'user_id' => $osoba->getKey(),
                'recipe_id' => $recipe->getKey(),
                'servings' => $wybrane,
                'created_at' => $teraz,
                'updated_at' => $teraz,
            ]],
            ['user_id', 'recipe_id'],
            ['servings', 'updated_at'],
        );

        return $wybrane;
    }

    /** Usuwa zapamiętany wybór tej osoby dla tego przepisu; `true`, gdy coś było. */
    public function zapomnij(User $osoba, Recipe $recipe): bool
    {
        return RecipeServingPreference::query()
            ->where('user_id', $osoba->getKey())
            ->where('recipe_id', $recipe->getKey())
            ->delete() > 0;
    }
}
