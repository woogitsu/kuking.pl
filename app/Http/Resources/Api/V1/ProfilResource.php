<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cudzy (albo własny) profil w API (D-272) — to, co widać na `/@nazwa`.
 * Bez adresu e-mail, statusu, roli i dat logowania: własne dane konta daje
 * wyłącznie `GET /api/v1/ja`.
 *
 * @mixin User
 */
class ProfilResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $osoba */
        $osoba = $this->resource;
        $widz = $request->user();
        $toJa = $widz !== null && $widz->getKey() === $osoba->getKey();

        return [
            ...(new AutorResource($osoba))->resolve($request),
            'bio' => $osoba->profile?->bio,
            'region' => $osoba->profile?->region,
            'speciality' => $osoba->profile?->speciality,
            'is_me' => $toJa,
            'is_following' => $widz !== null && ! $toJa && $widz->isFollowing($osoba),
        ];
    }
}
