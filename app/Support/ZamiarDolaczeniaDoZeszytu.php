<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Http\Request;

/** Powrót do podglądu zaproszenia po założeniu konta, bez przyjmowania go za człowieka. */
final class ZamiarDolaczeniaDoZeszytu
{
    private const KLUCZ = 'collection_join_intent';

    /** Link z middleware auth ma pierwszeństwo tylko przed zwykłą rejestracją. */
    public function zapamietaj(Request $request): void
    {
        if ($request->query->has('follow_user') || $request->query->has('cook_recipe')
            || $request->query->has(PowrotDoRozmowy::PARAMETR)
            || $request->query->has(ZamiarZapisu::PARAMETR)) {
            $request->session()->forget(self::KLUCZ);
            $intended = $request->session()->get('url.intended');
            if (is_string($intended) && $this->tokenZAdresu($intended) !== null) {
                $request->session()->forget('url.intended');
            }

            return;
        }

        $intended = $request->session()->get('url.intended');
        $token = is_string($intended) ? $this->tokenZAdresu($intended) : null;
        if ($token === null) {
            return;
        }

        // Nie zostawiamy po rejestracji starego celu dla innego konta.
        $request->session()->forget(['url.intended', self::KLUCZ]);
        $zaproszenie = $this->aktywne($token);
        if ($zaproszenie === null) {
            return;
        }

        // Nowszy jawny link wypiera wcześniejsze zamiary.
        $request->session()->forget(['follow_intent', 'cook_intent', 'comment_intent', 'save_intent']);
        $request->session()->put(self::KLUCZ, [
            'token' => $token,
            'expires' => min(now()->addHours(2)->timestamp, $zaproszenie->expires_at->timestamp),
        ]);
    }

    public function przypiszKonto(Request $request): void
    {
        $zamiar = $request->session()->get(self::KLUCZ);
        if (is_array($zamiar) && $this->cel($zamiar) !== null) {
            $zamiar['owner'] = $request->user()->getKey();
            $request->session()->put(self::KLUCZ, $zamiar);
        }
    }

    public function celPoOnboardingu(Request $request): ?string
    {
        $zamiar = $request->session()->pull(self::KLUCZ);
        $user = $request->user();

        return is_array($zamiar) && $user instanceof User
            && ($zamiar['owner'] ?? null) === $user->getKey()
            ? $this->cel($zamiar) : null;
    }

    private function tokenZAdresu(string $adres): ?string
    {
        $wzorzec = '\A'.str_replace('TOKEN', '([A-Za-z0-9]{40})', preg_quote(route('collections.link.show', 'TOKEN'), '~')).'\z';

        return preg_match('~'.$wzorzec.'~D', $adres, $trafienia) === 1 ? $trafienia[1] : null;
    }

    private function aktywne(string $token): ?CollectionInvitation
    {
        $zaproszenie = CollectionInvitation::query()
            ->where('token_hash', CollectionInvitation::skrotTokenu($token))
            ->where('via_link', true)
            ->with('collection')
            ->first();

        return $zaproszenie !== null && $zaproszenie->czeka() && $zaproszenie->collection !== null
            ? $zaproszenie : null;
    }

    /** @param array<string, mixed> $zamiar */
    private function cel(array $zamiar): ?string
    {
        $token = $zamiar['token'] ?? null;
        if (! is_string($token) || ! preg_match('/\A[A-Za-z0-9]{40}\z/D', $token)
            || ! is_int($zamiar['expires'] ?? null) || $zamiar['expires'] <= now()->timestamp
            || $this->aktywne($token) === null) {
            return null;
        }

        return route('collections.link.show', $token);
    }
}
