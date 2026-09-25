<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\Actions\UnfollowUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Obserwuj / przestań obserwować (D-273) — `FollowUser` i `UnfollowUser`,
 * te same akcje co WWW (powiadomienie „obserwuje Cię", blokady), pod
 * `UserPolicy::follow`/`unfollow`.
 *
 * PO UUID OSOBY, NIE PO NAZWIE: nazwa użytkownika może przejść na inne konto
 * między wczytaniem ekranu a kliknięciem (#793 — WWW łata to ukrytym polem
 * `oczekiwany_id`). Aplikacja trzyma identyfikator, więc ten problem tu
 * w ogóle nie powstaje.
 */
class ObserwowanieController extends Controller
{
    public function store(Request $request, User $osoba, FollowUser $obserwuj): JsonResponse
    {
        $this->authorize('follow', $osoba);

        $nowe = $obserwuj->handle($request->user(), $osoba);

        return new JsonResponse(['data' => ['following' => true]], $nowe ? 201 : 200);
    }

    public function destroy(Request $request, User $osoba, UnfollowUser $przestan): JsonResponse
    {
        $this->authorize('unfollow', $osoba);

        $przestan->handle($request->user(), $osoba);

        return new JsonResponse(['data' => ['following' => false]]);
    }
}
