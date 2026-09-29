<?php

declare(strict_types=1);

namespace App\Support;

/** Niesekretne pola po wygaśnięciu domknięcia. Szkic nie uwierzytelnia. */
final class ExternalRegistrationDraft
{
    public static function remember(ZadanieDomenowe $zadanie, string $provider, mixed $identity): void
    {
        if (! is_string($identity) || $identity === '') {
            return;
        }

        $fields = [];
        foreach (['display_name' => (int) config('kuking.profil.dlugosc_nazwy'), 'username' => NazwaUzytkownika::MAX] as $field => $limit) {
            $value = $zadanie->pole($field);
            if (is_string($value) && mb_strlen($value) <= $limit) {
                $fields[$field] = $value;
            }
        }
        $zadanie->sesja()->put("registration_draft.$provider", [
            'identity' => $identity, 'expires' => now()->addMinutes(30)->getTimestamp(), 'fields' => $fields,
        ]);
    }

    /** @return array<string, string> */
    public static function restore(ZadanieDomenowe $zadanie, string $provider, string $identity): array
    {
        $draft = $zadanie->sesja()->get("registration_draft.$provider");
        if (! is_array($draft)) {
            return [];
        }
        if (($draft['identity'] ?? null) !== $identity) {
            self::forget($zadanie, $provider);

            return [];
        }
        if (($draft['expires'] ?? 0) <= now()->getTimestamp()) {
            self::forget($zadanie, $provider);
            Komunikat::wSesji($zadanie->sesja(), Komunikat::blad('Zapisane imię i nazwa wygasły. Wpisz je ponownie, żeby dokończyć zakładanie konta.'));

            return [];
        }

        return $draft['fields'];
    }

    public static function forget(ZadanieDomenowe $zadanie, string $provider): void
    {
        $zadanie->sesja()->forget("registration_draft.$provider");
    }
}
