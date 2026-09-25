<?php

declare(strict_types=1);

namespace App\Domain\Contact\Actions;

use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateContactMessage
{
    public function handle(ContactMessage $message, User $operator, int $version, string $status, ?string $note): void
    {
        DB::transaction(function () use ($message, $operator, $version, $status, $note): void {
            $current = ContactMessage::query()->whereKey($message->getKey())->lockForUpdate()->firstOrFail();
            if ($current->version !== $version) {
                throw ValidationException::withMessages([
                    'version' => 'Wiadomość zmieniła się od otwarcia tej karty. Porównaj bieżącą notatkę ze swoim tekstem, wybierz stan i zapisz ponownie.',
                ]);
            }

            $current->forceFill(['handler_note' => $note]);
            // Jeden zapis: awaria nie może pozostawić samej notatki bez stanu.
            $current->oznaczJako($status, $operator);
            $message->setRawAttributes($current->getAttributes(), true);
        });
    }
}
