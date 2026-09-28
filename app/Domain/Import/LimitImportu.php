<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Wspólna rezerwacja prób OCR, adresu strony i PDF-a (D-300).
 */
final class LimitImportu
{
    public function __construct(private readonly LimitImportowOsoby $limit) {}

    /**
     * Powtórzony POST z tym samym kluczem nie pobiera źródła ponownie.
     *
     * @return array{id: string, status: string, recipe_id: ?string, istnieje: bool}
     *
     * @throws ImportOdrzucony
     */
    public function zuzyj(User $user, string $zrodlo, string $klucz): array
    {
        return DB::transaction(function () use ($user, $zrodlo, $klucz): array {
            $proba = $this->limit->rezerwuj($user, $zrodlo, $klucz);
            if ($proba !== null) {
                return $proba;
            }

            $miesiac = $this->limit->przekroczony($user) === LimitImportowOsoby::MIESIAC;
            throw new ImportOdrzucony(
                $miesiac ? ImportOdrzucony::LIMIT_OSOBY_MIESIAC : ImportOdrzucony::LIMIT_OSOBY,
                ['limit' => (int) config($miesiac ? 'kuking.import.limity.na_osobe_miesiac' : 'kuking.import.limity.na_osobe_dzien')],
            );
        });
    }

    public function zakoncz(string $id, bool $powodzenie, ?string $recipeId = null): void
    {
        $this->limit->zakoncz($id, $powodzenie, $recipeId);
    }
}
