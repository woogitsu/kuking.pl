<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * „Zapisz do zeszytu” przeżywa rejestrację i logowanie gościa (#2028).
 *
 * Gość czyta przepis, klika „Zapisz do zeszytu” i przechodzi rejestrację
 * (albo logowanie). Wraca na TEN SAM przepis, z rozwiniętym wyborem zeszytu
 * (`?wybierz_zeszyt=1`). To wszystko, co robimy za niego: NIC NIE ZAPISUJEMY.
 * Do zeszytu przepis trafia dopiero po jego własnym kliknięciu w wybrany
 * zeszyt — zeszyt bywa publiczny, a człowiek, który dopiero założył konto,
 * nie wie jeszcze, który jest który.
 *
 * Wzór i granice jak w `ZamiarUgotowania` (#2058) i `PowrotDoRozmowy` (#2027):
 *
 * - link niesie UUID przepisu (`save_recipe`), nigdy adres. Adres powrotu
 *   budujemy wyłącznie z przepisu z bazy (`route()`), więc `https://…`,
 *   `//host`, slug ani tablica w parametrze nie mają którędy dojść
 *   do przekierowania;
 * - zamiar zapisuje GOŚĆ, więc liczy się to, co widzi gość (Policy `view`
 *   bez konta) — a w chwili powrotu Policy `view` dla NOWEGO konta, bo to ona
 *   rządzi zapisem (`CollectionController::saveRecipe`). Przepis, który
 *   w międzyczasie stał się prywatny, ukryty albo zablokowany, nie otwiera
 *   niczego;
 * - przy rejestracji zamiar jest JEDNORAZOWY i wiąże się z kontem założonym
 *   w tej sesji; przy logowaniu cel idzie do `url.intended`;
 * - obowiązuje NAJNOWSZY jawny zamiar: link „Zapisz” kasuje wcześniejsze
 *   „Obserwuj”, „Ugotowałem” i powrót do komentarzy, a tamte linki — ten.
 */
final class ZamiarZapisu
{
    public const PARAMETR = 'save_recipe';

    /** Parametr strony przepisu, który tylko ROZWIJA wybór zeszytu. */
    public const ROZWIN = 'wybierz_zeszyt';

    private const KLUCZ = 'save_intent';

    /** Zamiary z pozostałych linków — nowszy jawny zamiar je wypiera. */
    private const INNE_KLUCZE = ['follow_intent', 'cook_intent', 'comment_intent'];

    /**
     * Ekran logowania: cel z linku idzie do `url.intended`. Bez parametru
     * niczego nie ruszamy — `intended` z middleware `auth` zostaje.
     */
    public function celDoLogowania(Request $request): ?string
    {
        if (! $request->query->has(self::PARAMETR)) {
            return null;
        }

        $zamiar = $this->zZapytania($request);

        return $zamiar !== null ? $this->cel($zamiar, null) : null;
    }

    /** Ekran rejestracji: zapisuje zamiar z linku przy formularzu. */
    public function zapamietaj(Request $request): void
    {
        if ($request->query->has('follow_user') || $request->query->has('cook_recipe') || $request->query->has(PowrotDoRozmowy::PARAMETR)) {
            $request->session()->forget(self::KLUCZ);

            return;
        }

        if (! $request->query->has(self::PARAMETR)) {
            return;
        }

        $request->session()->forget([self::KLUCZ, ...self::INNE_KLUCZE]);

        $zamiar = $this->zZapytania($request);
        if ($zamiar !== null) {
            $request->session()->put(self::KLUCZ, $zamiar);
        }
    }

    /** Wiąże zamiar z kontem założonym w tej sesji — tylko ono może go użyć. */
    public function przypiszKonto(Request $request): void
    {
        $zamiar = $request->session()->get(self::KLUCZ);
        if (is_array($zamiar) && $this->cel($zamiar, null) !== null) {
            $zamiar['owner'] = $request->user()->getKey();
            $request->session()->put(self::KLUCZ, $zamiar);
        }
    }

    /** Adres przepisu z rozwiniętym wyborem zeszytu — raz, z przepisu z bazy. */
    public function celPoOnboardingu(Request $request): ?string
    {
        $zamiar = $request->session()->pull(self::KLUCZ);
        $user = $request->user();

        if (! is_array($zamiar) || ! $user instanceof User || ($zamiar['owner'] ?? null) !== $user->getKey()) {
            return null;
        }

        return $this->cel($zamiar, $user);
    }

    /** @return array{recipe: string, expires: int}|null */
    private function zZapytania(Request $request): ?array
    {
        $id = $request->query(self::PARAMETR);
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        $zamiar = ['recipe' => $id, 'expires' => now()->addHours(2)->timestamp];

        return $this->cel($zamiar, null) !== null ? $zamiar : null;
    }

    /** @param array<string, mixed> $zamiar */
    private function cel(array $zamiar, ?User $user): ?string
    {
        $id = $zamiar['recipe'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)
            || ! is_int($zamiar['expires'] ?? null) || $zamiar['expires'] <= now()->timestamp) {
            return null;
        }

        $przepis = Recipe::query()->find($id);

        // Zamiar zapisał GOŚĆ, więc liczy się to, co widzi gość — także
        // w chwili powrotu. Konto dodatkowo sprawdzamy własną Policy
        // (blokada w obie strony, konto autora, widoczność „dla obserwujących”).
        if ($przepis === null || ! Gate::forUser(null)->allows('view', $przepis)
            || ($user !== null && ! Gate::forUser($user)->allows('view', $przepis))) {
            return null;
        }

        return route('recipes.show', ['recipe' => $przepis->slug, self::ROZWIN => 1]).'#'.self::kotwica($przepis);
    }

    /** Kotwica bloku wyboru zeszytu na stronie przepisu (`x-wybor-zeszytu`). */
    public static function kotwica(Recipe $przepis): string
    {
        return 'wybor-zeszytu-przepis-'.$przepis->getKey();
    }
}
