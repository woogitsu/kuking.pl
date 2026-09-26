<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Limit importów na osobę — dzienny i miesięczny, WSPÓLNY dla adresu, PDF-a
 * i zdjęcia (D-300: „żadnego masowego importu, limit na osobę jak przy OCR").
 *
 * Liczy się ZLECENIE (wysłany formularz), nie wejście na stronę. Odmowa
 * wymienia limit i mówi, co zrobić. RateLimiter stoi na cache'u z bazy danych
 * (bez Redisa, AGENTS.md §3).
 */
final class LimitImportu
{
    /**
     * @throws ImportOdrzucony
     */
    public function zuzyj(User $user): void
    {
        $dzien = (int) config('kuking.import.limity.na_osobe_dzien', 5);
        $miesiac = (int) config('kuking.import.limity.na_osobe_miesiac', 30);

        $kluczDnia = 'import-przepisu:dzien:'.$user->getKey();
        $kluczMiesiaca = 'import-przepisu:miesiac:'.$user->getKey();

        if (RateLimiter::tooManyAttempts($kluczMiesiaca, $miesiac)) {
            throw new ImportOdrzucony(ImportOdrzucony::LIMIT_OSOBY_MIESIAC, ['limit' => $miesiac]);
        }

        if (RateLimiter::tooManyAttempts($kluczDnia, $dzien)) {
            throw new ImportOdrzucony(ImportOdrzucony::LIMIT_OSOBY, ['limit' => $dzien]);
        }

        RateLimiter::hit($kluczDnia, 86_400);
        RateLimiter::hit($kluczMiesiaca, 30 * 86_400);
    }
}
