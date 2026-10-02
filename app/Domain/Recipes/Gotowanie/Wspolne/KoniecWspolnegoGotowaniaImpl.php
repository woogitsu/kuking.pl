<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Gotowanie\Wspolne;

use App\Domain\Users\KoniecWspolnegoGotowania;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Koniec wspólnego gotowania przy blokadzie i wymazaniu konta (#2385).
 *
 * BLOKADA (`miedzy`, pod zamkiem pary kont) — sesja ma gospodarza i do trzech
 * pomocników, więc są DWA przypadki, obie strony blokady widzą go tak samo:
 *  1. GOSPODARZ i POMOCNIK (w którąkolwiek stronę): znika udział pomocnika.
 *  2. DWÓCH POMOCNIKÓW tej samej sesji: wypada ZABLOKOWANY, a blokujący
 *     zostaje. Powód wyboru: blokujący chroni się przed kontaktem, więc to
 *     nie on ma tracić sesję; wypadnięcie zablokowanego wygląda dla niego tak
 *     samo jak „gospodarz usunął mnie z sesji” (to samo zdanie, brak słowa
 *     o blokadzie), więc nie zdradza, że ktoś go zablokował. Nowe przyjęcie
 *     linku przez którąkolwiek ze stron odmawia, dopóki blokada trwa
 *     (`ZaproszenieDoGotowania::dolacz`).
 * Revision dotkniętych sesji rośnie, żeby pozostali zobaczyli zmianę.
 * Odhaczenia zostają (to praca w gotowaniu), z podpisem do końca sesji.
 * Oczekujący link nie ma adresata, więc go nie ruszamy.
 *
 * WYŚCIGI: przyjęcie linku przez osobę, która jest stroną blokady, dzieli
 * z blokadą wiersz TEJ osoby w `users` (zamek pary), więc obie operacje
 * ustawiają się w kolejce; przyjęcie widzi po blokadzie świeży zapis blokady,
 * a blokada — po przyjęciu — świeży udział. Wiersze SESJI blokujemy tu przed
 * ruszeniem udziałów, w tej samej kolejności co `ZaproszenieDoGotowania`,
 * `SesjaWspolnegoGotowania` i `PostepWspolnegoGotowania` (konta → sesja →
 * udziały, sesje rosnąco po id), więc usunięcie pomocnika przez gospodarza
 * w tej samej chwili nie zakleszcza się z blokadą.
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
    public function miedzy(User $blokujacy, User $blokowany): void
    {
        $idA = (string) $blokujacy->getKey();
        $idB = (string) $blokowany->getKey();

        // Kandydaci: sesje, w których obie osoby się spotykają (bez blokady
        // wierszy — te bierzemy dopiero niżej, rosnąco po id).
        $gospodarzIPomocnik = DB::table('cooking_session_participants as p')
            ->join('cooking_sessions as s', 's.id', '=', 'p.session_id')
            ->where(fn ($q) => $q
                ->where(fn ($x) => $x->where('p.user_id', $idA)->where('s.host_id', $idB))
                ->orWhere(fn ($x) => $x->where('p.user_id', $idB)->where('s.host_id', $idA)))
            ->pluck('p.session_id')
            ->all();

        $dwajPomocnicy = DB::table('cooking_session_participants as pa')
            ->join('cooking_session_participants as pb', 'pb.session_id', '=', 'pa.session_id')
            ->where('pa.user_id', $idA)
            ->where('pb.user_id', $idB)
            ->pluck('pa.session_id')
            ->all();

        $kandydaci = array_values(array_unique([...$gospodarzIPomocnik, ...$dwajPomocnicy]));

        if ($kandydaci === []) {
            return;
        }

        $sesje = DB::table('cooking_sessions')
            ->whereIn('id', $kandydaci)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'host_id']);

        foreach ($sesje as $sesja) {
            // Odczyt udziałów PO blokadzie sesji — świeży stan.
            $pomocnicy = DB::table('cooking_session_participants')
                ->where('session_id', $sesja->id)
                ->whereIn('user_id', [$idA, $idB])
                ->pluck('user_id')
                ->map(fn ($id): string => (string) $id)
                ->all();

            $doUsuniecia = match (true) {
                (string) $sesja->host_id === $idA => array_intersect([$idB], $pomocnicy),
                (string) $sesja->host_id === $idB => array_intersect([$idA], $pomocnicy),
                default => in_array($idA, $pomocnicy, true) && in_array($idB, $pomocnicy, true) ? [$idB] : [],
            };

            if ($doUsuniecia === []) {
                continue;
            }

            DB::table('cooking_session_participants')
                ->where('session_id', $sesja->id)
                ->whereIn('user_id', array_values($doUsuniecia))
                ->delete();

            DB::table('cooking_sessions')->where('id', $sesja->id)->update([
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
