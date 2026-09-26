<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Własne konto zalogowanej osoby — `GET /api/v1/ja` (D-270).
 *
 * TO JEST JEDYNY zasób, w którym wychodzi adres e-mail: człowiek widzi
 * własny, tak jak na ekranie ustawień. Zasoby CUDZYCH kont (etap 3) go nie
 * mają i mieć nie mogą.
 *
 * Czego tu nie ma nigdy: hasła, sekretu 2FA, kodów zapasowych,
 * `remember_token`, roli moderatora (aplikacja nie ma panelu moderacji —
 * moderacja zostaje na WWW, gdzie stoi za obowiązkowym 2FA).
 *
 * @mixin User
 */
class JaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $konto */
        $konto = $this->resource;

        return [
            'id' => $konto->getKey(),
            'username' => $konto->profile?->username,
            'display_name' => $konto->profile?->display_name,
            'email' => $konto->email,
            'email_verified' => $konto->hasVerifiedEmail(),
            'status' => $konto->isSuspended() ? 'suspended' : 'active',
            'suspended_until' => $konto->isSuspended() ? $konto->status_expires_at?->toIso8601String() : null,
            'two_factor_enabled' => $konto->hasTwoFactorConfirmed(),
        ];
    }
}
