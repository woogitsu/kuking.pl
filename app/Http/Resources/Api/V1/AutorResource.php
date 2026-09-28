<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Autor treści — to, co WWW pokazuje przy wpisie: imię, nazwa, zdjęcie
 * profilowe. BEZ adresu e-mail, statusu konta, roli i dat (D-272).
 *
 * @mixin User
 */
class AutorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $autor */
        $autor = $this->resource;

        return [
            'id' => (string) $autor->getKey(),
            'username' => $autor->profile?->username,
            'display_name' => $autor->profile?->display_name,
            'avatar' => Zdjecie::z($autor->profile?->avatar),
        ];
    }
}
