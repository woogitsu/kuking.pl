<?php

declare(strict_types=1);

namespace App\Domain\Collections\Wspoldzielenie;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Notification;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Zaproszenie do wspólnego zeszytu — po nazwie konta albo linkiem (#1743).
 *
 * CO JEST WSPÓLNE DLA OBU DRÓG
 *  - tylko właściciel, tylko aktywny, nigdy do domyślnego „Zapisane"
 *    (`CollectionPolicy::share()`);
 *  - pod blokadą wiersza zeszytu liczymy miejsca: członkowie + oczekujące
 *    zaproszenia nie przekraczają `kuking.collections.max_members`. Bez
 *    blokady dwa szybkie kliknięcia przeskoczyłyby limit o jeden;
 *  - zaproszenie wygasa samo (`expires_at`), da się je odwołać.
 *
 * PO NAZWIE KONTA
 * Druga identyczna prośba nie tworzy drugiego zaproszenia ani drugiego
 * powiadomienia — oddaje istniejące (unikalny indeks
 * `collection_invitations_one_pending_idx` pilnuje tego też przy wyścigu).
 * Blokada w którąkolwiek stronę, konto zamknięte i nieistniejąca nazwa dają
 * JEDNO zdanie — nie zdradzamy, że ktoś Cię zablokował.
 *
 * LINKIEM
 * Token (40 znaków losowych) trafia wyłącznie do odpowiedzi — w bazie leży
 * jego SHA-256. Kto zgubi link, tworzy nowy; starego nie da się odczytać.
 */
final class ZaprosDoZeszytu
{
    public const NIE_DA_SIE = 'Nie możemy zaprosić tej osoby do zeszytu. Sprawdź nazwę konta — jest na stronie profilu, po znaku @.';

    public const PELNY = 'Ten zeszyt ma już najwięcej osób, ile się da — razem z oczekującymi zaproszeniami. Odbierz komuś dostęp albo odwołaj zaproszenie i spróbuj jeszcze raz.';

    public function __construct(private readonly NotifyUser $notify) {}

    public function poNazwie(User $wlasciciel, Collection $zeszyt, string $nazwa): CollectionInvitation
    {
        Gate::forUser($wlasciciel)->authorize('share', $zeszyt);

        $profil = Profile::poNazwie(ltrim(trim($nazwa), '@'));
        $adresat = $profil?->user;

        if ($adresat === null || ! $adresat->mozeCzytac() || $wlasciciel->hasBlockRelationWith($adresat)) {
            throw new BladDlaCzlowieka(self::NIE_DA_SIE);
        }

        if ($adresat->getKey() === $wlasciciel->getKey()) {
            throw new BladDlaCzlowieka('To jest Twój zeszyt — masz do niego dostęp. Wpisz nazwę konta osoby, którą chcesz zaprosić.');
        }

        return DB::transaction(function () use ($wlasciciel, $zeszyt, $adresat): CollectionInvitation {
            $swiezy = $this->zablokujZeszyt($zeszyt);

            if ($swiezy->maCzlonka($adresat)) {
                throw new BladDlaCzlowieka('Ta osoba ma już dostęp do tego zeszytu.');
            }

            $istniejace = $this->oczekujace($swiezy)->where('invitee_id', $adresat->getKey())->first();

            if ($istniejace !== null) {
                // Idempotentnie: to samo zaproszenie, bez drugiego powiadomienia.
                return $istniejace;
            }

            // Wygasłe, ale formalnie „pending" zaproszenie tej samej osoby
            // zajmuje unikalny indeks — zamykamy je, zanim wyślemy nowe.
            $swiezy->invitations()
                ->where('invitee_id', $adresat->getKey())
                ->where('status', CollectionInvitation::STATUS_PENDING)
                ->update(['status' => CollectionInvitation::STATUS_REVOKED, 'token_hash' => null, 'responded_at' => now(), 'updated_at' => now()]);

            $this->sprawdzMiejsce($swiezy);

            $zaproszenie = new CollectionInvitation;
            $zaproszenie->forceFill([
                'collection_id' => $swiezy->getKey(),
                'inviter_id' => $wlasciciel->getKey(),
                'invitee_id' => $adresat->getKey(),
                'via_link' => false,
                'status' => CollectionInvitation::STATUS_PENDING,
                'expires_at' => now()->addDays((int) config('kuking.collections.invitation_days')),
            ]);

            try {
                DB::transaction(fn () => $zaproszenie->save());
            } catch (UniqueConstraintViolationException) {
                // Równoległa prośba zdążyła pierwsza — oddajemy jej wiersz.
                return $this->oczekujace($swiezy)->where('invitee_id', $adresat->getKey())->firstOrFail();
            }

            $this->notify->handle(
                recipient: $adresat,
                type: Notification::TYPE_COLLECTION_INVITED,
                actor: $wlasciciel,
                data: ['invitation_id' => (string) $zaproszenie->getKey(), 'zeszyt' => $swiezy->name],
            );

            return $zaproszenie;
        });
    }

    /**
     * @return array{0: CollectionInvitation, 1: string} zaproszenie i JAWNY token — jedyny raz, kiedy istnieje
     */
    public function linkiem(User $wlasciciel, Collection $zeszyt): array
    {
        Gate::forUser($wlasciciel)->authorize('share', $zeszyt);

        return DB::transaction(function () use ($wlasciciel, $zeszyt): array {
            $swiezy = $this->zablokujZeszyt($zeszyt);

            $this->sprawdzMiejsce($swiezy);

            $token = Str::random(40);

            $zaproszenie = new CollectionInvitation;
            $zaproszenie->forceFill([
                'collection_id' => $swiezy->getKey(),
                'inviter_id' => $wlasciciel->getKey(),
                'invitee_id' => null,
                'via_link' => true,
                'token_hash' => CollectionInvitation::skrotTokenu($token),
                'status' => CollectionInvitation::STATUS_PENDING,
                'expires_at' => now()->addDays((int) config('kuking.collections.link_days')),
            ])->save();

            return [$zaproszenie, $token];
        });
    }

    private function zablokujZeszyt(Collection $zeszyt): Collection
    {
        return Collection::query()->whereKey($zeszyt->getKey())->lockForUpdate()->first()
            ?? throw new BladDlaCzlowieka('Tego zeszytu już nie ma — mógł zostać usunięty w innym oknie.');
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<CollectionInvitation, Collection> */
    private function oczekujace(Collection $zeszyt)
    {
        return $zeszyt->invitations()
            ->where('status', CollectionInvitation::STATUS_PENDING)
            ->where('expires_at', '>', now());
    }

    private function sprawdzMiejsce(Collection $zeszyt): void
    {
        $zajete = $zeszyt->members()->count() + $this->oczekujace($zeszyt)->count();

        if ($zajete >= (int) config('kuking.collections.max_members')) {
            throw new BladDlaCzlowieka(self::PELNY);
        }
    }
}
