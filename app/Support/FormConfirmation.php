<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class FormConfirmation
{
    // Krótki podgląd wysłania, nie historia korespondencji. Każda karta ma swój klucz.
    private const SESSION_KEY = 'form_confirmations';

    public function issue(Request $request, string $form, array $data): string
    {
        $receipts = $this->active($request);
        $key = (string) Str::uuid();
        $receipts[$key] = [
            'form' => $form,
            'data' => $data,
            'user_id' => $request->user()?->getKey(),
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];
        $request->session()->put(self::SESSION_KEY, array_slice($receipts, -10, null, true));

        return $key;
    }

    public function read(Request $request, string $form): ?array
    {
        $receipts = $this->active($request);
        $key = $request->query('potwierdzenie');

        // Klucz bez własnej sesji nie daje dostępu do żadnych danych ani rekordu.
        $receipt = is_string($key) ? ($receipts[$key] ?? null) : null;

        return ($receipt['form'] ?? null) === $form ? $receipt['data'] : null;
    }

    private function active(Request $request): array
    {
        $receipts = array_filter($request->session()->get(self::SESSION_KEY, []),
            fn (array $receipt): bool => $receipt['expires_at'] > now()->timestamp
                && $receipt['user_id'] === $request->user()?->getKey());
        $request->session()->put(self::SESSION_KEY, $receipts);

        return $receipts;
    }
}
