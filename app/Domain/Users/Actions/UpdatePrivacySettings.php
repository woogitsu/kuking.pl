<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdatePrivacySettings
{
    public function __construct(private readonly PrzestawZgodeNaDigest $consent) {}

    public function handle(User $user, bool $digest, bool $memories, bool $originalDigest, bool $originalMemories): void
    {
        DB::transaction(function () use ($user, $digest, $memories, $originalDigest, $originalMemories): void {
            $current = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $errors = [];
            if ($current->wants_weekly_digest !== $originalDigest) {
                $errors['wants_weekly_digest'] = 'Ustawienie tygodniowego e-maila zmieniło się od otwarcia formularza. Otwórz aktualne ustawienia i wybierz ponownie.';
            }
            if ($current->memories_enabled !== $originalMemories) {
                $errors['memories_enabled'] = 'Ustawienie wspomnień zmieniło się od otwarcia formularza. Otwórz aktualne ustawienia i wybierz ponownie.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            // Sprawdzenie i oba zapisy pod tą samą blokadą: konflikt nie
            // zapisuje połowy formularza ani dowodu nieaktualnej zgody.
            $this->consent->handle($current, $digest, WpisZgody::ZRODLO_USTAWIENIA);
            $current->update(['memories_enabled' => $memories]);
        });
    }
}
