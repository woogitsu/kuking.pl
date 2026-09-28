<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * „Ugotowałem” przeżywa rejestrację gościa (#2058).
 *
 * Gość na ostatnim kroku trybu gotowania dostaje link do rejestracji
 * z IDENTYFIKATOREM przepisu (`cook_recipe`), nie z adresem powrotu. Po
 * rejestracji i pierwszych krokach otwieramy istniejący formularz
 * `cooked.create` — nic nie wysyłamy i nic nie zapisujemy za człowieka;
 * wykonanie i powiadomienie autora powstają dopiero po jego „Opublikuj”.
 *
 * Wzór i granice jak w `ZamiarObserwowania`, z trzema różnicami:
 * - zamiar jest JEDNORAZOWY — zużywa go pierwsze wejście na koniec
 *   onboardingu, bo formularz to czynność, a nie strona do obejrzenia;
 * - w chwili powrotu liczy się `RecipePolicy::cook` dla nowego konta
 *   (blokada, zawieszenie, zmiana widoczności między jednym a drugim);
 * - przepis wskazujemy UUID-em: slug nie jest tu tożsamością.
 */
final class ZamiarUgotowania
{
    private const KLUCZ = 'cook_intent';

    private const PARAMETR = 'cook_recipe';

    public function __construct(private readonly ZamiarObserwowania $obserwowanie) {}

    /**
     * Zapisuje zamiar z linku przy formularzu rejestracji.
     *
     * Obowiązuje NAJNOWSZY jawny zamiar: link „Obserwuj” kasuje wcześniejsze
     * „Ugotowałem”, a link „Ugotowałem” — wcześniejsze obserwowanie. Bez tego
     * na końcu onboardingu wygrywałby zamiar sprzed kilku ekranów.
     */
    public function zapamietaj(Request $request): void
    {
        if ($request->query->has('follow_user')) {
            $request->session()->forget(self::KLUCZ);

            return;
        }

        if (! $request->query->has(self::PARAMETR)) {
            return;
        }

        $request->session()->forget(self::KLUCZ);
        $this->obserwowanie->zapomnij($request);

        $id = $request->query(self::PARAMETR);
        if (! is_string($id) || ! Str::isUuid($id)) {
            return;
        }

        $zamiar = ['recipe' => $id, 'expires' => now()->addHours(2)->timestamp];
        if ($this->przepis($zamiar, null) !== null) {
            $request->session()->put(self::KLUCZ, $zamiar);
        }
    }

    /** Wiąże zamiar z kontem założonym w tej sesji — tylko ono może go użyć. */
    public function przypiszKonto(Request $request): void
    {
        $zamiar = $request->session()->get(self::KLUCZ);
        if (is_array($zamiar) && $this->przepis($zamiar, null) !== null) {
            $zamiar['owner'] = $request->user()->getKey();
            $request->session()->put(self::KLUCZ, $zamiar);
        }
    }

    /**
     * Adres formularza „Ugotowałem” — raz. Budowany wyłącznie z przepisu
     * z bazy (`route()`), nigdy z tego, co przyszło w żądaniu.
     */
    public function celPoOnboardingu(Request $request): ?string
    {
        $zamiar = $request->session()->pull(self::KLUCZ);
        $user = $request->user();

        if (! is_array($zamiar) || ($zamiar['owner'] ?? null) !== $user->getKey()) {
            return null;
        }

        $przepis = $this->przepis($zamiar, $user);

        return $przepis !== null ? route('cooked.create', $przepis->slug) : null;
    }

    /** @param array<string, mixed> $zamiar */
    private function przepis(array $zamiar, ?User $user): ?Recipe
    {
        $id = $zamiar['recipe'] ?? null;
        if (! is_string($id) || ! Str::isUuid($id)
            || ! is_int($zamiar['expires'] ?? null) || $zamiar['expires'] <= now()->timestamp) {
            return null;
        }

        // Publiczny, bo zamiar zapisuje GOŚĆ — a gość widzi tylko publiczne.
        // Reszta granic (autor zbanowany, blokada, konto nieaktywne) należy
        // do Policy, więc nie ma tu jej drugiej kopii.
        $przepis = Recipe::query()->publiclyVisible()->find($id);
        if ($przepis === null) {
            return null;
        }

        $dozwolone = $user === null
            ? Gate::forUser(null)->allows('view', $przepis)
            : Gate::forUser($user)->allows('cook', $przepis);

        return $dozwolone ? $przepis : null;
    }
}
