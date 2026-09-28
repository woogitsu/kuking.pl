<?php

declare(strict_types=1);

namespace App\Domain\Security;

use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\FacebookConnectionProof;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Notifications\PotwierdzeniePolaczeniaFacebooka;
use App\Support\Skrot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** A fresh, purpose-bound proof of the existing Kuking account (#2085). */
final readonly class FacebookConnectionConfirmation
{
    public function __construct(private TwoFactorAuthenticator $totp) {}

    public function mayReactivate(User $user, string $facebookId): bool
    {
        return User::findByFacebookId($facebookId)?->getKey() === $user->getKey()
            && $user->dostepOdebranyU(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK)
            && ! $user->hasStaffRole()
            && ! in_array($user->status, User::STATUSY_ZAMKNIETEGO_KONTA, true);
    }

    public function reactivate(Request $request, User $user, string $facebookId): ?User
    {
        return ZamekKonta::zablokuj($user, function (?User $fresh) use ($request, $facebookId): ?User {
            if ($fresh === null || ! $this->mayReactivate($fresh, $facebookId)
                || ! $this->consume($request, $fresh, $facebookId)) {
                return null;
            }

            $fresh->cofnijOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);
            AuditLogEntry::record('account.facebook_reactivated', $fresh, $fresh, ip: $request->ip());

            return $fresh;
        });
    }

    public function requestEmailProof(Request $request, User $user, string $facebookId): bool
    {
        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        $token = FacebookConnectionProof::newToken();
        $created = ZamekKonta::zablokuj($user, function (?User $fresh) use ($request, $facebookId, $token): ?User {
            if ($fresh === null || ! $fresh->hasVerifiedEmail() || $fresh->hasStaffRole()
                || in_array($fresh->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)) {
                return null;
            }

            FacebookConnectionProof::query()->where('user_id', $fresh->getKey())->delete();
            $proof = new FacebookConnectionProof;
            $proof->forceFill([
                'user_id' => $fresh->getKey(),
                'token_hash' => FacebookConnectionProof::hashToken($token),
                'session_hash' => Skrot::hmac($request->session()->getId()),
                'facebook_id_hash' => Skrot::hmac($facebookId),
                'account_state_hash' => $this->accountState($fresh),
                'created_at' => now(),
                'expires_at' => now()->addMinutes(10),
            ])->save();

            AuditLogEntry::record('account.facebook_connection_proof_requested', $fresh, $fresh, ip: $request->ip());

            return $fresh;
        });

        if ($created === null) {
            return false;
        }

        $created->notify(new PotwierdzeniePolaczeniaFacebooka($token));

        return true;
    }

    public function emailProofIsAvailable(Request $request, User $user, string $facebookId, string $token): bool
    {
        if (! FacebookConnectionProof::validShape($token)) {
            return false;
        }

        $proof = FacebookConnectionProof::query()
            ->where('token_hash', FacebookConnectionProof::hashToken($token))->first();

        return $proof !== null && $proof->expires_at?->isFuture() === true
            && (string) $proof->user_id === (string) $user->getKey()
            && hash_equals($proof->session_hash, Skrot::hmac($request->session()->getId()))
            && hash_equals($proof->facebook_id_hash, Skrot::hmac($facebookId));
    }

    /** Called only while the fresh account row is locked, in the connection transaction. */
    public function consume(Request $request, User $fresh, string $facebookId): bool
    {
        $password = (string) $request->input('password', '');
        $token = (string) $request->input('proof_token', '');
        $confirmed = false;
        $proof = null;

        if ($password !== '' && Hash::check($password, $fresh->password)) {
            $confirmed = true;
        } elseif ($this->emailProofIsAvailable($request, $fresh, $facebookId, $token)) {
            $proof = FacebookConnectionProof::query()
                ->where('token_hash', FacebookConnectionProof::hashToken($token))
                ->lockForUpdate()->first();
            if ($proof !== null && $proof->expires_at?->isFuture() === true
                && hash_equals($proof->account_state_hash, $this->accountState($fresh))) {
                $confirmed = true;
            }
        }

        if (! $confirmed) {
            return false;
        }

        if ($fresh->hasTwoFactorConfirmed()) {
            $code = (string) $request->input('two_factor_code', '');
            if ($code === '' || (! $this->totp->verifyCode($fresh, (string) $fresh->two_factor_secret, $code)
                && ! $this->totp->consumeBackupCode($fresh, $code))) {
                return false;
            }
        }

        if ($proof !== null) {
            $proof->delete();
        } else {
            FacebookConnectionProof::query()->where('user_id', $fresh->getKey())->delete();
        }

        return true;
    }

    public function accountState(User $user): string
    {
        return Skrot::hmac((string) json_encode([
            (string) $user->getKey(), $user->password, $user->email,
            (string) $user->email_verified_at?->toISOString(),
            (string) $user->two_factor_secret,
            (string) $user->two_factor_confirmed_at?->toISOString(),
            $user->two_factor_backup_codes,
            (string) $user->role, (string) $user->status,
            (string) $user->session_generation,
        ], JSON_THROW_ON_ERROR));
    }
}
