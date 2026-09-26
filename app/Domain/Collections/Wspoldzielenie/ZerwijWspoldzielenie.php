<?php

declare(strict_types=1);

namespace App\Domain\Collections\Wspoldzielenie;

use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Koniec wspólnego zeszytu przy blokadzie i przy usunięciu konta (D-302).
 *
 * BLOKADA — `miedzy()`, wołane przez `BlockUser` pod zamkiem pary kont.
 * Blokada w KTÓRĄKOLWIEK stronę kasuje członkostwo w obie strony (zeszyty
 * A z dostępem B i zeszyty B z dostępem A) i odwołuje oczekujące zaproszenia
 * po nazwie konta między nimi. Odblokowanie niczego nie przywraca — trzeba
 * zaprosić od nowa, tak jak po blokadzie trzeba od nowa zacząć obserwować.
 * Link bez adresata nie ma kogo wskazać; jego przyjęcie odmawia pod zamkiem
 * pary, gdy między stronami jest blokada (`OdpowiedzNaZaproszenie`).
 *
 * USUNIĘCIE KONTA — `przyWymazaniu()`, wołane z `EraseAccountData`
 * w transakcji wymazania, niezależnie od zakresu (`minimum`/`everything`):
 *  - znikają członkostwa tej osoby w cudzych zeszytach;
 *  - znikają członkostwa innych w JEJ zeszytach (zeszyt osoby, której już
 *    nie ma, nie jest wspólny — przy zakresie `everything` znika zresztą
 *    cały, razem z pozycjami);
 *  - znikają zaproszenia wysłane przez nią, do niej i do jej zeszytów;
 *  - pozycje, które dopisała w CUDZYCH zeszytach, zostają, ale tracą
 *    podpis: `added_by_id = NULL` („osoba, która usunęła konto").
 *
 * KOLEJNOŚĆ BLOKAD WIERSZY JEST USTALONA (D-093). Dwie równoległe egzekucje
 * kont, które miały dostęp do swoich zeszytów nawzajem, kasują te same
 * wiersze `collection_members`. Gołe `DELETE` bierze je w kolejności skanu
 * — różnej w obu transakcjach — i daje zakleszczenie. Dlatego najpierw
 * `SELECT … ORDER BY … FOR UPDATE`, potem `DELETE` po kluczu.
 */
final class ZerwijWspoldzielenie
{
    public function miedzy(User $a, User $b): void
    {
        $idA = (string) $a->getKey();
        $idB = (string) $b->getKey();

        $czlonkostwa = DB::table('collection_members')
            ->join('collections', 'collections.id', '=', 'collection_members.collection_id')
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('collection_members.user_id', $idA)->where('collections.owner_id', $idB))
                ->orWhere(fn ($x) => $x->where('collection_members.user_id', $idB)->where('collections.owner_id', $idA)))
            ->select('collection_members.collection_id', 'collection_members.user_id');

        $this->skasujCzlonkostwa($czlonkostwa);

        $zaproszenia = DB::table('collection_invitations')
            ->where('status', CollectionInvitation::STATUS_PENDING)
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('inviter_id', $idA)->where('invitee_id', $idB))
                ->orWhere(fn ($x) => $x->where('inviter_id', $idB)->where('invitee_id', $idA)));

        $this->odwolajZaproszenia($zaproszenia);
    }

    public function przyWymazaniu(User $user): void
    {
        $id = (string) $user->getKey();

        $moje = DB::table('collections')->where('owner_id', $id)->select('id');

        $this->skasujCzlonkostwa(DB::table('collection_members')
            ->where('user_id', $id)
            ->orWhereIn('collection_id', $moje)
            ->select('collection_id', 'user_id'));

        $zaproszenia = DB::table('collection_invitations')
            ->where('inviter_id', $id)
            ->orWhere('invitee_id', $id)
            ->orWhereIn('collection_id', $moje);

        $idZaproszen = (clone $zaproszenia)->orderBy('id')->lockForUpdate()->pluck('id')->all();

        if ($idZaproszen !== []) {
            DB::table('collection_invitations')->whereIn('id', $idZaproszen)->delete();
        }

        // Podpis „dodał" przy pozycjach w CUDZYCH zeszytach — w swoim
        // zeszycie osoba i tak jest właścicielem, a ten znika albo zostaje
        // według zakresu usunięcia.
        DB::table('collection_items')
            ->where('added_by_id', $id)
            ->whereNotIn('collection_id', $moje)
            ->update(['added_by_id' => null]);
    }

    private function skasujCzlonkostwa(\Illuminate\Database\Query\Builder $zapytanie): void
    {
        $wiersze = $zapytanie
            ->orderBy('collection_members.collection_id')
            ->orderBy('collection_members.user_id')
            ->lock('FOR UPDATE OF collection_members')
            ->get();

        foreach ($wiersze as $wiersz) {
            DB::table('collection_members')
                ->where('collection_id', $wiersz->collection_id)
                ->where('user_id', $wiersz->user_id)
                ->delete();
        }
    }

    private function odwolajZaproszenia(\Illuminate\Database\Query\Builder $zapytanie): void
    {
        $id = (clone $zapytanie)->orderBy('id')->lockForUpdate()->pluck('id')->all();

        if ($id === []) {
            return;
        }

        DB::table('collection_invitations')->whereIn('id', $id)->update([
            'status' => CollectionInvitation::STATUS_REVOKED,
            'token_hash' => null,
            'responded_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
