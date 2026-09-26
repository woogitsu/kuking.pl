<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Collection;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Czy oglądany ekran jest OSOBISTYM obszarem zalogowanej osoby (issue #946).
 *
 * Pozycje „Mój zeszyt", „Moje" i „Profil" nazywają własny kąt konta, nie typ
 * dowolnego oglądanego obiektu. Do września 2026 layout wyznaczał je samą
 * nazwą trasy (`routeIs('collections.*')`, `routeIs('profile.show')`), więc
 * cudzy publiczny zeszyt podświetlał „Mój zeszyt", a każdy cudzy profil —
 * „Profil". `aria-current="page"` mówiło tę samą nieprawdę czytnikowi ekranu.
 *
 * Jedna reguła dla wszystkich trzech wariantów nawigacji (górny pasek
 * komputera, boczna, dolna) — layout woła te metody zamiast powielać warunek.
 * Żadnego zapytania: zeszyt jest już związany z trasą, a nazwa profilu
 * w adresie wskazuje konto jednoznacznie przez unikalny `lower(username)`
 * — dokładnie tak, jak rozwiązuje ją `ProfileController::show()`.
 */
final class NawigacjaOsobista
{
    /** Własna lista zeszytów albo własny zeszyt (podgląd, edycja). */
    public static function mojZeszyt(Request $request, ?User $user): bool
    {
        if ($user === null || ! $request->routeIs('collections.*')) {
            return false;
        }

        $zeszyt = $request->route('collection');

        // Bez zeszytu w adresie: `/zeszyt` — lista WŁASNYCH zeszytów.
        if ($zeszyt === null) {
            return true;
        }

        return $zeszyt instanceof Collection && $zeszyt->owner_id === $user->getKey();
    }

    /** Własny profil oraz jego listy obserwujących i obserwowanych. */
    public static function mojProfil(Request $request, ?User $user): bool
    {
        if ($user === null || ! $request->routeIs('profile.show', 'social.followers', 'social.following')) {
            return false;
        }

        $nazwa = $request->route('username');
        $mojaNazwa = $user->profile?->username;

        return is_string($nazwa)
            && is_string($mojaNazwa)
            && mb_strtolower(trim($nazwa)) === mb_strtolower($mojaNazwa);
    }
}
