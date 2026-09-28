<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Powrót do rozmowy po zalogowaniu albo założeniu konta (#2027).
 *
 * Gość czyta komentarze, klika „zaloguj się” albo „załóż konto” — i po
 * wejściu do serwisu ma wrócić do TEGO SAMEGO wątku (`#komentarze`), a nie
 * na Start. Wzór i granice jak w `ZamiarObserwowania`/`ZamiarUgotowania`:
 *
 * - link niesie RODZAJ i UUID treści (`comment_on=recipe:<uuid>`), nigdy
 *   adres. Adres powrotu budujemy wyłącznie z modelu z bazy (`url()`), więc
 *   `https://…`, `//host`, `/\host`, `javascript:` ani zakodowane obejścia
 *   nie mają którędy dojść do przekierowania;
 * - zapisujemy tylko treść, którą widzi GOŚĆ (Policy `view` bez konta);
 * - przy logowaniu cel trafia do `url.intended`, więc działa też po 2FA,
 *   linku do logowania i wejściu przez Google/Facebook istniejącym kontem;
 * - przy rejestracji zamiar wiąże się z kontem założonym w tej sesji
 *   i zużywa raz, na końcu pierwszych kroków, po ponownym sprawdzeniu
 *   Policy dla nowego konta.
 *
 * NIC NIE WYSYŁAMY ZA CZŁOWIEKA. Gość nie ma pola komentarza, więc nie ma
 * szkicu do przeniesienia; po powrocie komentarz pisze i wysyła sam.
 */
final class PowrotDoRozmowy
{
    public const PARAMETR = 'comment_on';

    private const KLUCZ = 'comment_intent';

    private const KOTWICA = '#komentarze';

    /** @var array<string, class-string<Model>> */
    private const RODZAJE = [
        'recipe' => Recipe::class,
        'post' => Post::class,
        'cooked' => CookedEvent::class,
    ];

    /**
     * Parametr linku dla wątku na bieżącej stronie — albo pusta tablica,
     * gdy strona nie jest jedną z treści z komentarzami.
     *
     * @return array<string, string>
     */
    public static function zapytanieDlaStrony(Request $request): array
    {
        $trasa = $request->route();
        $tresc = match (true) {
            $trasa === null => null,
            $request->routeIs('recipes.show') => self::przepisZeSluga($trasa->parameter('recipe')),
            $request->routeIs('posts.show', 'questions.show') => $trasa->parameter('post'),
            $request->routeIs('cooked.show') => $trasa->parameter('cookedEvent'),
            default => null,
        };

        $rodzaj = $tresc instanceof Model ? array_search($tresc::class, self::RODZAJE, true) : false;

        return is_string($rodzaj) ? [self::PARAMETR => $rodzaj.':'.$tresc->getKey()] : [];
    }

    /**
     * `RecipeController::show` przyjmuje slug jako tekst (stare adresy po
     * zmianie tytułu przekierowuje sam), więc trasa nie ma związanego modelu.
     */
    private static function przepisZeSluga(mixed $slug): ?Recipe
    {
        return is_string($slug) ? Recipe::query()->where('slug', $slug)->first() : null;
    }

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

    /**
     * Ekran rejestracji. Obowiązuje NAJNOWSZY jawny zamiar: link z wątku
     * kasuje wcześniejsze „Obserwuj”/„Ugotowałem”, a tamte linki — ten.
     */
    public function zapamietaj(Request $request): void
    {
        if ($request->query->has('follow_user') || $request->query->has('cook_recipe')) {
            $request->session()->forget(self::KLUCZ);

            return;
        }

        if (! $request->query->has(self::PARAMETR)) {
            return;
        }

        $request->session()->forget([self::KLUCZ, 'follow_intent', 'cook_intent']);

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

    /** Adres wątku po pierwszych krokach — raz, z modelu z bazy. */
    public function celPoOnboardingu(Request $request): ?string
    {
        $zamiar = $request->session()->pull(self::KLUCZ);
        $user = $request->user();

        if (! is_array($zamiar) || ! $user instanceof User || ($zamiar['owner'] ?? null) !== $user->getKey()) {
            return null;
        }

        return $this->cel($zamiar, $user);
    }

    /** @return array{type: string, id: string, expires: int}|null */
    private function zZapytania(Request $request): ?array
    {
        $wartosc = $request->query(self::PARAMETR);
        if (! is_string($wartosc) || strlen($wartosc) > 60 || ! str_contains($wartosc, ':')) {
            return null;
        }

        [$rodzaj, $id] = explode(':', $wartosc, 2);
        $zamiar = ['type' => $rodzaj, 'id' => $id, 'expires' => now()->addHours(2)->timestamp];

        return $this->cel($zamiar, null) !== null ? $zamiar : null;
    }

    /** @param array<string, mixed> $zamiar */
    private function cel(array $zamiar, ?User $user): ?string
    {
        $rodzaj = $zamiar['type'] ?? null;
        $id = $zamiar['id'] ?? null;

        if (! is_string($rodzaj) || ! isset(self::RODZAJE[$rodzaj])
            || ! is_string($id) || ! Str::isUuid($id)
            || ! is_int($zamiar['expires'] ?? null) || $zamiar['expires'] <= now()->timestamp) {
            return null;
        }

        /** @var Recipe|Post|CookedEvent|null $tresc */
        $tresc = self::RODZAJE[$rodzaj]::query()->find($id);

        // Zamiar zapisał GOŚĆ, więc liczy się to, co widzi gość — także
        // w chwili powrotu. Konto dodatkowo sprawdzamy własną Policy
        // (blokada w obie strony, AGENTS.md §4).
        if ($tresc === null || ! Gate::forUser(null)->allows('view', $tresc)
            || ($user !== null && ! Gate::forUser($user)->allows('view', $tresc))) {
            return null;
        }

        return $tresc->url().self::KOTWICA;
    }
}
