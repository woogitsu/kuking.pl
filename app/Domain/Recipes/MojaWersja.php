<?php

declare(strict_types=1);

namespace App\Domain\Recipes;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Reguły „Mojej wersji" — przepisu zrobionego na podstawie cudzego
 * (issue #23, D-301). Jedno miejsce, bo te same odpowiedzi czytają strona
 * przepisu, zapis przepisu (`PublishRecipe`) i powiadomienia.
 *
 * TRZY RZECZY, PRZED KTÓRYMI TA KLASA CHRONI (za treścią issue)
 *
 *  1. Kradzież autorstwa — podpis „Na podstawie przepisu…" jest nieusuwalny:
 *     kolumny nie ma w `$fillable`, a widok pokazuje go zawsze, także gdy
 *     oryginał zniknął (wtedy „oryginał jest niedostępny").
 *  2. Farma duplikatów w Google — wersja zbyt podobna do PUBLICZNEGO
 *     oryginału dostaje `noindex, follow` (`czyIndeksowac()`), próg 30%
 *     z `docs/seo/SEO_TECHNICAL.md` §1.4 pkt 4.
 *  3. Rozmycie oryginału — wersja bez żadnej zmiany nie zostanie
 *     opublikowana (`pilnujRoznicy()`), a autor oryginału dostaje
 *     powiadomienie i listę „Wersje innych osób" na swojej stronie.
 */
final class MojaWersja
{
    public const KOMUNIKAT_BEZ_ZMIAN = 'To jest ten sam przepis. Może wystarczy „Ugotowałem”? '
        .'Jeśli robisz go po swojemu, zmień składniki, kroki, czas albo liczbę porcji — wtedy opublikujesz swoją wersję.';

    /**
     * Najmniejszy udział tekstu, którego NIE MA w oryginale, przy którym
     * wersja może być indeksowana (`docs/seo/SEO_TECHNICAL.md` §1.4 pkt 4:
     * „< 30% unikalnego tekstu → domyślnie noindex").
     */
    public const PROG_UNIKALNOSCI = 0.30;

    /** Długość fragmentu (w słowach) przy porównaniu tekstu. */
    private const DLUGOSC_FRAGMENTU = 3;

    public function __construct(
        private readonly NotifyUser $notify,
    ) {}

    /**
     * Oryginał, jeśli TEN widz może go zobaczyć — inaczej `null`, a widok
     * pisze „oryginał jest niedostępny". Pyta Policy, nie kolumnę: przepis
     * usunięty, ukryty, zawężony do obserwujących, autora zbanowanego albo
     * zablokowanego znika z podpisu tak samo, jak znika z serwisu.
     */
    public static function oryginalDlaWidza(Recipe $wersja, ?User $widz): ?Recipe
    {
        if (! $wersja->jestWersja() || $wersja->forked_from_id === null) {
            return null;
        }

        $oryginal = $wersja->forkedFrom;

        if ($oryginal === null || ! Gate::forUser($widz)->allows('view', $oryginal)) {
            return null;
        }

        return $oryginal;
    }

    /**
     * Opublikowane wersje tego przepisu, które widz może zobaczyć.
     * Kolejność chronologiczna, bez żadnego rankingu i bez liczby
     * (AGENTS.md §12 — żadnych liczników popularności).
     *
     * @return Builder<Recipe>
     */
    public static function wersjeDlaWidza(Recipe $oryginal, ?User $widz): Builder
    {
        return Recipe::query()
            ->where('forked_from_id', $oryginal->getKey())
            ->published()
            ->widoczneDla($widz)
            ->whereHas('author', fn ($autor) => $autor->dostepnyJakoAutor())
            ->with(Recipe::RELACJE_KARTY)
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * Czy stronę tej wersji wolno oddać wyszukiwarce.
     *
     * Ryzyko to niemal identyczne strony W INDEKSIE. Porównujemy więc tylko
     * z oryginałem, który sam jest w indeksie, czyli widocznym dla gościa.
     * Oryginał usunięty, prywatny albo zdjęty nie ma z czym się dublować.
     */
    public static function czyIndeksowac(Recipe $recipe): bool
    {
        if (! $recipe->jestWersja() || $recipe->forked_from_id === null) {
            return true;
        }

        $oryginal = $recipe->forkedFrom;

        if ($oryginal === null || ! Gate::forUser(null)->allows('view', $oryginal)) {
            return true;
        }

        return self::udzialNowegoTekstu($recipe, $oryginal) >= self::PROG_UNIKALNOSCI;
    }

    /**
     * Jaka część tekstu wersji (składniki + kroki) nie występuje w oryginale.
     * Fragmenty po trzy słowa (shingling), bez wielkości liter i interpunkcji.
     * 0.0 — wszystko przepisane, 1.0 — nic wspólnego.
     */
    public static function udzialNowegoTekstu(Recipe $wersja, Recipe $oryginal): float
    {
        $fragmentyWersji = self::fragmenty($wersja);

        if ($fragmentyWersji === []) {
            return 0.0;
        }

        $fragmentyOryginalu = array_flip(self::fragmenty($oryginal));
        $nowe = 0;

        foreach ($fragmentyWersji as $fragment) {
            if (! isset($fragmentyOryginalu[$fragment])) {
                $nowe++;
            }
        }

        return $nowe / count($fragmentyWersji);
    }

    /**
     * Wersja, w której nic nie zmieniono, nie zostaje opublikowana.
     *
     * Liczy się to, z czego się GOTUJE: składniki (tekst, grupa, uwaga,
     * „bez ilości"), kroki (treść i minutnik), liczba porcji i czasy.
     * Sam tytuł, opis, zdjęcie czy pochodzenie NIE są zmianą przepisu —
     * inaczej wystarczyłoby przemianować cudzy rosół, żeby go „mieć".
     *
     * Porównanie z oryginałem także usuniętym (`withTrashed`): opublikowanie
     * niezmienionej kopii przepisu, który autor zdjął, byłoby wskrzeszeniem
     * cudzej treści pod innym nazwiskiem. Oryginał skasowany twardo
     * (`forked_from_id` = NULL) nie ma z czym się porównać.
     *
     * @throws BladDlaCzlowieka
     */
    public static function pilnujRoznicy(Recipe $wersja): void
    {
        if (! $wersja->jestWersja() || $wersja->forked_from_id === null) {
            return;
        }

        $oryginal = Recipe::withTrashed()->find($wersja->forked_from_id);

        if ($oryginal !== null && self::odcisk($wersja) === self::odcisk($oryginal)) {
            throw new BladDlaCzlowieka(self::KOMUNIKAT_BEZ_ZMIAN);
        }
    }

    /**
     * Powiadomienie autora oryginału o PIERWSZEJ publikacji wersji.
     *
     * Tylko wtedy, gdy autor oryginału może tę wersję zobaczyć — wersja
     * prywatna nie powiadamia nikogo, bo „Zobacz" prowadziłby w ścianę.
     * Raz na wersję: drugie powiadomienie o tej samej wersji nie niesie
     * nowej informacji. Blokadę, własną akcję i konto zamknięte odcina
     * `NotifyUser`, jak przy każdym innym powiadomieniu.
     */
    public function powiadomAutoraOryginalu(Recipe $wersja, User $autorWersji): void
    {
        if (! $wersja->jestWersja() || $wersja->forked_from_id === null || ! $wersja->isPublished()) {
            return;
        }

        $oryginal = Recipe::query()->with('author')->find($wersja->forked_from_id);
        $odbiorca = $oryginal?->author;

        if ($oryginal === null || $odbiorca === null) {
            return;
        }

        if (! Gate::forUser($odbiorca)->allows('view', $wersja)) {
            return;
        }

        $juzBylo = Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', Notification::TYPE_FORKED)
            ->where('data->fork_id', (string) $wersja->getKey())
            ->exists();

        if ($juzBylo) {
            return;
        }

        $this->notify->handle($odbiorca, Notification::TYPE_FORKED, $autorWersji, [
            'recipe_id' => (string) $oryginal->getKey(),
            'fork_id' => (string) $wersja->getKey(),
            'recipe_title' => $oryginal->title,
        ]);
    }

    /** @return array<string, mixed> */
    private static function odcisk(Recipe $recipe): array
    {
        return [
            'porcje' => $recipe->servings === null ? null : round((float) $recipe->servings, 2),
            'przygotowanie' => $recipe->prep_minutes,
            'gotowanie' => $recipe->cook_minutes,
            'skladniki' => $recipe->ingredients()->get()->map(fn ($s): array => [
                self::normalizuj($s->group_name),
                self::normalizuj($s->ingredient_text),
                self::normalizuj($s->note),
                (bool) $s->no_amount,
            ])->all(),
            'kroki' => $recipe->steps()->get()->map(fn ($k): array => [
                self::normalizuj($k->instruction),
                $k->timer_seconds === null ? null : (int) $k->timer_seconds,
            ])->all(),
        ];
    }

    /** @return list<string> */
    private static function fragmenty(Recipe $recipe): array
    {
        $tekst = $recipe->ingredients()->pluck('ingredient_text')
            ->merge($recipe->steps()->pluck('instruction'))
            ->implode(' ');

        $slowa = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($tekst), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($slowa) < self::DLUGOSC_FRAGMENTU) {
            return array_values($slowa);
        }

        $fragmenty = [];

        for ($i = 0; $i <= count($slowa) - self::DLUGOSC_FRAGMENTU; $i++) {
            $fragmenty[] = implode(' ', array_slice($slowa, $i, self::DLUGOSC_FRAGMENTU));
        }

        return $fragmenty;
    }

    private static function normalizuj(?string $tekst): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) $tekst)));
    }
}
