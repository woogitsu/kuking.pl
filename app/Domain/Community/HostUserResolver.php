<?php

declare(strict_types=1);

namespace App\Domain\Community;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Str;

/** Jedno źródło tożsamości gospodarza dla funkcji społecznościowych. */
final class HostUserResolver
{
    public function resolve(): ?User
    {
        $userId = trim((string) config('kuking.community.host_user_id'));

        if ($userId !== '') {
            // Ustawiony identyfikator jest rozstrzygający. Nie cofamy się do
            // nazwy, bo po zmianie profilu stara nazwa może należeć do kogoś
            // innego i oddać mu obserwujących albo alerty gospodarza.
            return Str::isUuid($userId) ? User::query()->find($userId) : null;
        }

        $username = trim((string) config('kuking.community.host_username'));

        if ($username === '') {
            return null;
        }

        // Zgodność przejściowa dla wdrożeń sprzed #1089. Po ustawieniu
        // KUKING_HOST_USER_ID ta gałąź nie jest już używana.
        return Profile::poNazwie($username)?->user;
    }
}
