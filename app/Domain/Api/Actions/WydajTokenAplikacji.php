<?php

declare(strict_types=1);

namespace App\Domain\Api\Actions;

use App\Models\AuditLogEntry;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\NewAccessToken;

/**
 * Wydanie tokenu aplikacji mobilnej po udanym logowaniu (D-270).
 *
 * Woła ją WYŁĄCZNIE kontroler API, i to dopiero po obu składnikach:
 * hasło (`SprawdzHasloPrzyLogowaniu`) i — gdy konto ma 2FA — kod
 * (`SprawdzKodDrugiegoSkladnika`). Sama tego nie sprawdza, bo nie wie,
 * którą drogą człowiek przyszedł; wie za to, że po niej powstaje
 * poświadczenie, więc zostawia ślad w dzienniku audytu.
 *
 * LIMIT URZĄDZEŃ (`config('kuking.api.max_urzadzen')`): nowy token ponad
 * próg odwołuje ten używany najdawniej. Odwrotność — odmowa logowania przy
 * pełnej liście — zamykałaby człowiekowi nowy telefon, dopóki nie znajdzie
 * na WWW ekranu, o którym nie wie.
 */
final class WydajTokenAplikacji
{
    public function handle(User $user, string $urzadzenie, ?string $ip): NewAccessToken
    {
        $nazwa = mb_substr(trim($urzadzenie), 0, 100);

        $nowy = DB::transaction(function () use ($user, $nazwa): NewAccessToken {
            // Blokada wiersza konta, zanim policzymy tokeny: dwa równoległe
            // logowania nie przeskoczą razem limitu. Kolejność blokad jak
            // w całym repozytorium — konto najpierw (docs/DATABASE.md, D-075).
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            $this->zrobMiejsce($user);

            return $user->createToken($nazwa);
        });

        AuditLogEntry::recordBezWywracania('account.api_token_created', $user, $nowy->accessToken, ip: $ip);

        return $nowy;
    }

    private function zrobMiejsce(User $user): void
    {
        $limit = max(1, (int) config('kuking.api.max_urzadzen'));

        $nadmiar = PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->getKey())
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->orderByDesc('id')
            ->offset($limit - 1)
            ->pluck('id');

        if ($nadmiar->isNotEmpty()) {
            PersonalAccessToken::query()->whereIn('id', $nadmiar)->delete();
        }
    }
}
