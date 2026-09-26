<?php

declare(strict_types=1);

namespace App\Domain\Collections\Wspoldzielenie;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Przyjęcie albo odrzucenie zaproszenia do wspólnego zeszytu (#1743).
 *
 * IDEMPOTENTNE I ODPORNE NA RÓWNOLEGŁE ŻĄDANIA
 * Podwójne kliknięcie „Dołączam" (w grupie 50+ norma) i dwie karty naraz
 * mają dać JEDNO członkostwo i JEDNO powiadomienie dla właściciela.
 *
 * Kolejność zamków jest ta sama co w reszcie serwisu (`ZamekPary`, D-080):
 * najpierw OBA konta rosnąco po id, potem wiersz zaproszenia, potem zeszyt.
 * Dzięki zamkom kont przyjęcie nie mija się z równoległą blokadą: `BlockUser`
 * bierze te same dwa wiersze, więc albo blokada jest pierwsza i przyjęcie
 * widzi ją pod zamkiem (odmowa), albo przyjęcie jest pierwsze, a blokada
 * zaraz potem kasuje świeże członkostwo (`ZerwijWspoldzielenie`).
 * Nigdy nie zostaje członkostwo obok blokady.
 *
 * Drugie przyjęcie tego samego zaproszenia przez tę samą osobę to sukces bez
 * skutku. Link przyjęty przez kogoś innego — odmowa (link jest jednorazowy).
 */
final class OdpowiedzNaZaproszenie
{
    public const NIEAKTUALNE = 'To zaproszenie jest już nieaktualne — wygasło, zostało odwołane albo ktoś już z niego skorzystał. Poproś o nowe.';

    public function __construct(private readonly NotifyUser $notify) {}

    /**
     * @return Collection zeszyt, do którego osoba ma teraz dostęp
     */
    public function przyjmij(User $osoba, CollectionInvitation $zaproszenie): Collection
    {
        $wlasciciel = $zaproszenie->collection?->owner
            ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

        /** @var array{0: Collection, 1: bool} $wynik */
        $wynik = ZamekPary::zablokuj($osoba, $wlasciciel, function (?User $swiezaOsoba, ?User $swiezyWlasciciel) use ($zaproszenie): array {
            if ($swiezaOsoba === null || $swiezyWlasciciel === null) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            $swieze = CollectionInvitation::query()->whereKey($zaproszenie->getKey())->lockForUpdate()->first()
                ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

            // Już przyjęte przez TĘ osobę — drugie kliknięcie, nic nie robimy.
            if ($swieze->status === CollectionInvitation::STATUS_ACCEPTED
                && $swieze->invitee_id === $swiezaOsoba->getKey()) {
                $zeszyt = Collection::query()->whereKey($swieze->collection_id)->first()
                    ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

                return [$zeszyt, false];
            }

            $this->sprawdzAdresata($swieze, $swiezaOsoba);

            if (! $swieze->czeka()) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            $zeszyt = Collection::query()->whereKey($swieze->collection_id)->lock('FOR NO KEY UPDATE')->first()
                ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

            if ($zeszyt->owner_id !== $swiezyWlasciciel->getKey() || $zeszyt->is_default) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            if ($swiezaOsoba->getKey() === $zeszyt->owner_id) {
                throw new BladDlaCzlowieka('To jest Twój zeszyt — nie musisz do niego dołączać.');
            }

            // Stan kont i blokada — na wierszach odczytanych pod zamkami.
            if (! $swiezaOsoba->mozeCzytac() || ! $swiezyWlasciciel->mozeCzytac()
                || $swiezaOsoba->hasBlockRelationWith($swiezyWlasciciel)) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // Członek był już wcześniej (np. drugi link) — dostęp jest,
            // zaproszenie zamykamy jako przyjęte, bez drugiego powiadomienia.
            $nowe = DB::table('collection_members')->insertOrIgnore([
                'collection_id' => $zeszyt->getKey(),
                'user_id' => $swiezaOsoba->getKey(),
                'created_at' => now(),
            ]) === 1;

            $swieze->forceFill([
                'status' => CollectionInvitation::STATUS_ACCEPTED,
                'invitee_id' => $swiezaOsoba->getKey(),
                'token_hash' => null,
                'responded_at' => now(),
            ])->save();

            if ($nowe) {
                $this->notify->handle(
                    recipient: $swiezyWlasciciel,
                    type: Notification::TYPE_COLLECTION_JOINED,
                    actor: $swiezaOsoba,
                    data: ['collection_id' => (string) $zeszyt->getKey(), 'zeszyt' => $zeszyt->name],
                );
            }

            return [$zeszyt, $nowe];
        });

        return $wynik[0];
    }

    public function odrzuc(User $osoba, CollectionInvitation $zaproszenie): void
    {
        DB::transaction(function () use ($osoba, $zaproszenie): void {
            $swieze = CollectionInvitation::query()->whereKey($zaproszenie->getKey())->lockForUpdate()->first();

            // Już odrzucone przez tę osobę albo nie ma czego odrzucać — cisza.
            if ($swieze === null || $swieze->status === CollectionInvitation::STATUS_DECLINED) {
                return;
            }

            $this->sprawdzAdresata($swieze, $osoba);

            if ($swieze->status !== CollectionInvitation::STATUS_PENDING) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // Odrzucony link przestaje działać także dla innych — to jest
            // „nie, dziękuję" od osoby, która go dostała. Właściciel może
            // utworzyć nowy.
            $swieze->forceFill([
                'status' => CollectionInvitation::STATUS_DECLINED,
                'invitee_id' => $swieze->invitee_id ?? $osoba->getKey(),
                'token_hash' => null,
                'responded_at' => now(),
            ])->save();
        });
    }

    /**
     * Zaproszenie po nazwie konta jest dla JEDNEJ osoby. Inne zalogowane
     * konto, które trafi na jego adres, dostaje odmowę — i ten sam komunikat
     * co przy nieaktualnym, żeby adres niczego nie potwierdzał.
     */
    private function sprawdzAdresata(CollectionInvitation $zaproszenie, User $osoba): void
    {
        if ($zaproszenie->invitee_id !== null && $zaproszenie->invitee_id !== $osoba->getKey()) {
            throw new BladDlaCzlowieka(self::NIEAKTUALNE);
        }
    }
}
