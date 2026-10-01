<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie\Wspolne;

use App\Domain\Users\KoniecWspolnegoGotowania;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Koniec wspólnego gotowania przy blokadzie i wymazaniu konta (#2385).
 *
 * BLOKADA (`miedzy`, pod zamkiem pary kont): znika udział pomocnika w sesji
 * drugiej strony — w obie strony — a revision tych sesji rośnie, żeby gospodarz
 * zobaczył zmianę. Odhaczenia zostają (to praca w gotowaniu), z podpisem do
 * końca sesji. Oczekujący link nie ma adresata, więc go nie ruszamy: jego
 * przyjęcie odmawia pod tym samym zamkiem pary, gdy między stronami jest
 * blokada (`ZaproszenieDoGotowania::dolacz`).
 *
 * WYMAZANIE (`przyWymazaniu`): sesje gospodarza znikają w całości (klucze
 * obce), udziały pomocnika znikają, podpis przy odhaczonych krokach →
 * NULL (konto się anonimizuje, wiersza `users` nie usuwa, więc
 * `ON DELETE SET NULL` sam by nie zadziałał).
 *
 * KOLEJNOŚĆ BLOKAD WIERSZY JEST USTALONA (jak D-093): najpierw
 * `SELECT … ORDER BY … FOR UPDATE`, potem `DELETE` po kluczu — dwie równoległe
 * egzekucje nie zakleszczają się na tych samych wierszach.
 */
final class KoniecWspolnegoGotowaniaImpl implements KoniecWspolnegoGotowania
{
    public function miedzy(User $a, User $b): void
    {
        $idA = (string) $a->getKey();
        $idB = (string) $b->getKey();

        $udzialy = DB::table('cooking_session_participants as p')
            ->join('cooking_sessions as s', 's.id', '=', 'p.session_id')
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('p.user_id', $idA)->where('s.host_id', $idB))
                ->orWhere(fn ($x) => $x->where('p.user_id', $idB)->where('s.host_id', $idA)))
            ->orderBy('p.session_id')
            ->orderBy('p.user_id')
            ->lock('FOR UPDATE OF p')
            ->select('p.session_id', 'p.user_id')
            ->get();

        foreach ($udzialy as $udzial) {
            DB::table('cooking_session_participants')
                ->where('session_id', $udzial->session_id)
                ->where('user_id', $udzial->user_id)
                ->delete();

            DB::table('cooking_sessions')->where('id', $udzial->session_id)->update([
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now(),
            ]);
        }
    }

    public function przyWymazaniu(User $user): void
    {
        $id = (string) $user->getKey();

        $sesje = DB::table('cooking_sessions')->where('host_id', $id)->orderBy('id')->lockForUpdate()->pluck('id')->all();

        if ($sesje !== []) {
            DB::table('cooking_sessions')->whereIn('id', $sesje)->delete();
        }

        $udzialy = DB::table('cooking_session_participants')
            ->where('user_id', $id)
            ->orderBy('session_id')
            ->lockForUpdate()
            ->pluck('session_id')
            ->all();

        foreach ($udzialy as $sesja) {
            DB::table('cooking_session_participants')->where('session_id', $sesja)->where('user_id', $id)->delete();
            DB::table('cooking_sessions')->where('id', $sesja)->update([
                'revision' => DB::raw('revision + 1'),
                'updated_at' => now(),
            ]);
        }

        DB::table('cooking_session_invitations')->where('accepted_by_id', $id)->update(['accepted_by_id' => null]);

        $kroki = DB::table('cooking_session_steps')
            ->where('done_by_id', $id)
            ->orderBy('session_id')
            ->orderBy('step_id')
            ->lockForUpdate()
            ->get(['session_id', 'step_id']);

        foreach ($kroki as $krok) {
            DB::table('cooking_session_steps')
                ->where('session_id', $krok->session_id)
                ->where('step_id', $krok->step_id)
                ->update(['done_by_id' => null]);
        }
    }
}
