<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProfilResource;
use App\Models\Profile;
use Illuminate\Http\Request;

/**
 * `GET /api/v1/profile/{username}` (D-272). To samo wyszukanie po nazwie co
 * `/@nazwa` na WWW i ta sama bramka: `UserPolicy::viewProfile` (blokada
 * w którąkolwiek stronę i konto zamknięte → odmowa).
 */
class ProfilController extends Controller
{
    public function show(Request $request, string $username): ProfilResource
    {
        $profil = Profile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower($username)])
            ->with(['user', 'avatar'])
            ->firstOrFail();

        $osoba = $profil->user;
        $osoba->setRelation('profile', $profil);

        $this->authorize('viewProfile', $osoba);

        return new ProfilResource($osoba);
    }
}
