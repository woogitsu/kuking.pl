<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ModerationAction;
use App\Models\Report;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CZY WYKRYWACZ POMAGA, CZY ZAŚMIECA KOLEJKĘ (D-052).
 *
 * DLACZEGO TO W OGÓLE ISTNIEJE
 * Narzędzie, które produkuje więcej fałszywych alarmów, niż moderator zdąży
 * przejrzeć, jest gorsze niż jego brak — a bez pomiaru nikt się o tym nie
 * dowie, bo automat nigdy nie skarży się na siebie. Ta komenda odpowiada na
 * dwa pytania i tylko na te dwa:
 *
 *   1. ILE tego jest — pozycji dziennie, w rozbiciu na sygnały;
 *   2. ILE OKAZAŁO SIĘ NICZYM — odsetek decyzji „bez działania".
 *
 * JAK CZYTAĆ WYNIK (progi z `docs/legal/SYGNALY_AUTOMATU.md`)
 *  - odsetek fałszywych alarmów powyżej 70% dla któregoś sygnału znaczy, że
 *    ten sygnał kosztuje więcej uwagi, niż jest wart — trzeba zaostrzyć próg
 *    albo go wyłączyć;
 *  - więcej niż 30 pozycji dziennie łącznie to więcej, niż jedna osoba
 *    przejrzy między innymi obowiązkami;
 *  - duży odsetek pozycji NIEROZPATRZONYCH starszych niż tydzień znaczy, że
 *    kolejka rośnie szybciej, niż jest opróżniana, i liczby wyżej przestają
 *    cokolwiek mówić.
 *
 * Komenda jest wyłącznie do CZYTANIA — niczego nie zmienia i nie kasuje,
 * więc wolno ją uruchomić na produkcji bez pytania nikogo o zgodę
 * (`AGENTS.md` §6 dotyczy operacji destrukcyjnych).
 */
class RaportSygnalow extends Command
{
    protected $signature = 'kuking:raport-sygnalow {--dni=30 : Ile ostatnich dni podsumować}';

    protected $description = 'Pokazuje, ile treści oznaczył automat i jaka część okazała się niczym';

    public function handle(): int
    {
        $dni = max(1, (int) $this->option('dni'));
        $od = now()->subDays($dni);

        $wszystkie = Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('created_at', '>=', $od)
            ->count();

        if ($wszystkie === 0) {
            $this->info('W ostatnich '.$dni.' dniach automat nie oznaczył ani jednej treści.');
            $this->line('To znaczy albo, że nie ma spamu, albo że wykrywacz jest wyłączony '
                .'(KUKING_SYGNALY_AUTOMATU) — sprawdź jedno i drugie, zanim uznasz to za dobrą wiadomość.');

            return self::SUCCESS;
        }

        $this->info('Sygnały automatu, ostatnie '.$dni.' dni');
        $this->line('Oznaczeń łącznie: '.$wszystkie.' (średnio '.round($wszystkie / $dni, 1).' dziennie)');
        $this->newLine();

        $this->table(
            ['Sygnał', 'Oznaczeń', 'Rozpatrzonych', 'Fałszywy alarm', 'Odsetek'],
            $this->wiersze($od),
        );

        $czekaja = Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
            ->count();

        $stare = Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->whereIn('status', [Report::STATUS_OPEN, Report::STATUS_TRIAGE, Report::STATUS_REVIEWING])
            ->where('created_at', '<', now()->subWeek())
            ->count();

        $this->newLine();
        $this->line('Czeka w kolejce: '.$czekaja.', w tym starszych niż tydzień: '.$stare.'.');

        if ($stare > 0) {
            $this->warn('Kolejka rośnie szybciej, niż jest opróżniana — odsetki wyżej liczą się '
                .'wtedy tylko z tego, co ktoś zdążył przejrzeć, i są zaniżone.');
        }

        return self::SUCCESS;
    }

    /**
     * Jeden wiersz na sygnał.
     *
     * „Fałszywy alarm" to decyzja `no_action` — jedyna, która znaczy „automat
     * się pomylił, treść zostaje". Każda inna (ostrzeżenie, ukrycie,
     * usunięcie, zawieszenie, blokada) znaczy, że pozycja była warta
     * przeczytania, nawet jeśli skutek był łagodny.
     *
     * Odsetek liczymy z ROZPATRZONYCH, a nie ze wszystkich oznaczeń: pozycja,
     * której nikt jeszcze nie obejrzał, nie jest ani trafieniem, ani pomyłką,
     * a wliczona do mianownika udawałaby sukces wykrywacza.
     *
     * @return list<array<int, string>>
     */
    private function wiersze(CarbonInterface $od): array
    {
        $wiersze = [];

        foreach (array_keys(Report::REASONS_AUTOMAT) as $kod) {
            $ile = Report::query()
                ->where('source', Report::SOURCE_AUTOMAT)
                ->where('reason', $kod)
                ->where('created_at', '>=', $od)
                ->count();

            $rozpatrzone = DB::table('reports')
                ->join('moderation_actions', 'moderation_actions.report_id', '=', 'reports.id')
                ->where('reports.source', Report::SOURCE_AUTOMAT)
                ->where('reports.reason', $kod)
                ->where('reports.created_at', '>=', $od)
                ->count();

            $nic = DB::table('reports')
                ->join('moderation_actions', 'moderation_actions.report_id', '=', 'reports.id')
                ->where('reports.source', Report::SOURCE_AUTOMAT)
                ->where('reports.reason', $kod)
                ->where('reports.created_at', '>=', $od)
                ->where('moderation_actions.action', ModerationAction::ACTION_NONE)
                ->count();

            $wiersze[] = [
                Report::REASONS_AUTOMAT[$kod],
                (string) $ile,
                (string) $rozpatrzone,
                (string) $nic,
                $rozpatrzone === 0 ? '—' : round($nic / $rozpatrzone * 100).'%',
            ];
        }

        return $wiersze;
    }
}
