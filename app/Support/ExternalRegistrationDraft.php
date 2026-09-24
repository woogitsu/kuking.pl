<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/** Niesekretne pola po wygaśnięciu domknięcia. Szkic nie uwierzytelnia. */
final class ExternalRegistrationDraft
{
    public static function remember(Request $request, string $provider, mixed $identity): void
    {
        if (! is_string($identity) || $identity === '') {
            return;
        }

        $fields = [];
        foreach (['display_name' => (int) config('kuking.profil.dlugosc_nazwy'), 'username' => NazwaUzytkownika::MAX] as $field => $limit) {
            $value = $request->input($field);
            if (is_string($value) && mb_strlen($value) <= $limit) {
                $fields[$field] = $value;
            }
        }
        $request->session()->put("registration_draft.$provider", [
            'identity' => $identity, 'expires' => now()->addMinutes(30)->getTimestamp(), 'fields' => $fields,
        ]);
    }

    /** @return array<string, string> */
    public static function restore(Request $request, string $provider, string $identity): array
    {
        $draft = $request->session()->get("registration_draft.$provider");
        if (! is_array($draft)) {
            return [];
        }
        if (($draft['identity'] ?? null) !== $identity) {
            self::forget($request, $provider);

            return [];
        }
        if (($draft['expires'] ?? 0) <= now()->getTimestamp()) {
            self::forget($request, $provider);
            $request->session()->flash('status', 'Zapisane imię i nazwa wygasły. Wpisz je ponownie, żeby dokończyć zakładanie konta.');

            return [];
        }

        return $draft['fields'];
    }

    public static function forget(Request $request, string $provider): void
    {
        $request->session()->forget("registration_draft.$provider");
    }
}
