<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ZESTAWIENIE DO RAPORTU PRZEJRZYSTOŚCI MODERACJI (issue #1860, luka po #989).
 *
 * PO CO
 * #989 dał decyzji podjętej po uznaniu odwołania własne powiązanie
 * (`moderation_actions.appeal_id`) i obiecał w kryteriach, że „raportowanie
 * przejrzystości potrafi policzyć odwrócenie decyzji oraz rzeczywisty rodzaj
 * nowego działania". Dane były w bazie, ale nic ich nie liczyło — właściciel,
 * który chciałby opublikować liczby (albo odpowiedzieć na pytanie organu),
 * musiałby pisać SQL na produkcji.
 *
 * Kuking jako mikroprzedsiębiorstwo jest zwolniony z pełnego sprawozdania
 * z art. 24 DSA (`docs/legal/COMPLIANCE.md` §1.2). Art. 15 obowiązuje
 * dostawców usług pośrednich, a zwolnienie z art. 19 jest warunkowe — ta
 * komenda daje liczby, z których taki raport się pisze, bez przesądzania,
 * czy i kiedy trzeba go publikować.
 *
 * CO LICZY (okno: `--od` włącznie, `--do` włącznie, daty w strefie serwisu)
 *  1. zgłoszenia wg źródła (`community`, `legal_notice`, `automat`);
 *  2. decyzje PIERWSZEJ INSTANCJI — ze zgłoszenia (`report_id`) — wg rodzaju;
 *  3. decyzje Z URZĘDU — bez zgłoszenia i bez odwołania, bez `unhide` (D-251);
 *  4. przywrócenia treści (`unhide`);
 *  5. odwołania ROZPATRZONE w oknie wg strony (autor / zgłaszający)
 *     i wyniku (podtrzymana / cofnięta);
 *  6. decyzje PO UZNANIU ODWOŁANIA (`appeal_id`) wg rodzaju — to jest
 *     „rzeczywisty rodzaj nowego działania" z kryteriów #989.
 *
 * CZEGO NIE LICZY I NIE POKAZUJE
 * Żadnego identyfikatora, nazwy konta, treści, uzasadnienia ani moderatora.
 * Same liczby — wynik wolno wkleić do dokumentu publicznego. Komenda jest
 * wyłącznie do CZYTANIA (same `SELECT`), więc wolno ją uruchomić na
 * produkcji bez pytania o zgodę (`AGENTS.md` §6 dotyczy operacji
 * destrukcyjnych).
 *
 * RETENCJA
 * `PrzedawnioneSprawyModeracyjne` kasuje sprawy po okresie retencji
 * (domyślnie 36 miesięcy). Okno starsze niż retencja daje liczby zaniżone —
 * komenda o tym mówi, zamiast udawać pełny obraz.
 */
class RaportPrzejrzystosci extends Command
{
    protected $signature = 'kuking:raport-przejrzystosci
                            {--od= : Pierwszy dzień okna, RRRR-MM-DD (domyślnie 1 stycznia bieżącego roku)}
                            {--do= : Ostatni dzień okna, RRRR-MM-DD (domyślnie dziś)}';

    protected $description = 'Liczby do raportu przejrzystości moderacji: zgłoszenia, decyzje, odwołania i decyzje po odwołaniu (tylko odczyt)';

    public function handle(): int
    {
        try {
            $od = $this->dzien((string) $this->option('od'), now()->startOfYear()->toDateString())->startOfDay();
            $do = $this->dzien((string) $this->option('do'), now()->toDateString())->endOfDay();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($od->greaterThan($do)) {
            $this->error('Data w --od jest późniejsza niż w --do. Podaj okno od wcześniejszego dnia do późniejszego.');

            return self::FAILURE;
        }

        $this->info('Raport przejrzystości moderacji: '.$od->toDateString().' – '.$do->toDateString());
        $this->line('Same liczby, bez identyfikatorów i treści. Komenda niczego nie zmienia.');

        $this->sekcja('1. Zgłoszenia według źródła', ['Źródło', 'Zgłoszeń'], $this->wiersze(
            DB::table('reports')->whereBetween('created_at', [$od, $do]),
            'source',
            [
                Report::SOURCE_COMMUNITY => 'społeczność (nasze zasady)',
                Report::SOURCE_LEGAL_NOTICE => 'zgłoszenie nielegalnej treści (DSA art. 16)',
                Report::SOURCE_AUTOMAT => 'oznaczenie automatu do przeglądu (D-052)',
            ],
        ));

        // „Przywróć treść” ma własny wiersz (4.) — w tabelach decyzji byłby
        // zawsze zerowym szumem.
        $rodzaje = array_diff_key(ModerationAction::ETYKIETY, [ModerationAction::ACTION_UNHIDE => true]);
        $decyzje = fn (): Builder => DB::table('moderation_actions')->whereBetween('created_at', [$od, $do]);

        $this->sekcja('2. Decyzje pierwszej instancji (ze zgłoszenia)', ['Decyzja', 'Liczba'], $this->wiersze(
            $decyzje()->whereNotNull('report_id'),
            'action',
            $rodzaje,
        ));

        $this->sekcja('3. Decyzje z urzędu (bez zgłoszenia, bez odwołania)', ['Decyzja', 'Liczba'], $this->wiersze(
            $decyzje()->whereNull('report_id')->whereNull('appeal_id')->where('action', '!=', ModerationAction::ACTION_UNHIDE),
            'action',
            $rodzaje,
        ));

        $this->newLine();
        $this->line('<options=bold>4. Przywrócenia treści („'.ModerationAction::ETYKIETY[ModerationAction::ACTION_UNHIDE].'”)</>: '
            .$decyzje()->where('action', ModerationAction::ACTION_UNHIDE)->count());

        $this->sekcja('5. Odwołania rozpatrzone w oknie', ['Kto się odwołał', 'Podtrzymane', 'Cofnięte'], $this->odwolania($od, $do));

        $this->sekcja('6. Decyzje po uznaniu odwołania (rodzaj nowego działania)', ['Decyzja', 'Liczba'], $this->wiersze(
            $decyzje()->whereNotNull('appeal_id'),
            'action',
            $rodzaje,
        ));

        $otwarte = DB::table('appeals')->where('status', Appeal::STATUS_OPEN)->count();
        $this->newLine();
        $this->line('Odwołania czekające na rozpatrzenie dziś (niezależnie od okna): '.$otwarte);

        $this->ostrzezenieORetencji($od);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $etykiety
     * @return list<array{0: string, 1: int}>
     */
    private function wiersze(Builder $zapytanie, string $kolumna, array $etykiety): array
    {
        /** @var array<string, int> $liczby */
        $liczby = $zapytanie
            ->select($kolumna, DB::raw('count(*) as ile'))
            ->groupBy($kolumna)
            ->pluck('ile', $kolumna)
            ->map(fn ($ile) => (int) $ile)
            ->all();

        $wiersze = [];

        // Najpierw znane wartości w stałej kolejności — ZERA TEŻ. Brak wiersza
        // w raporcie czyta się jak „tego nie liczymy", a zero mówi „nie było".
        foreach ($etykiety as $wartosc => $etykieta) {
            $wiersze[] = [$etykieta, $liczby[$wartosc] ?? 0];
            unset($liczby[$wartosc]);
        }

        // Wartość spoza słownika (nowy rodzaj decyzji bez etykiety) nie znika
        // z sumy — idzie surowym kodem, żeby było widać, że słownik jest stary.
        foreach ($liczby as $wartosc => $ile) {
            $wiersze[] = [(string) $wartosc, $ile];
        }

        $wiersze[] = ['RAZEM', array_sum(array_column($wiersze, 1))];

        return $wiersze;
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function odwolania(CarbonImmutable $od, CarbonImmutable $do): array
    {
        $liczby = DB::table('appeals')
            ->whereIn('status', [Appeal::STATUS_UPHELD, Appeal::STATUS_OVERTURNED])
            ->whereBetween('decided_at', [$od, $do])
            ->select('appellant', 'status', DB::raw('count(*) as ile'))
            ->groupBy('appellant', 'status')
            ->get();

        $ile = fn (string $strona, string $wynik): int => (int) ($liczby
            ->first(fn ($w) => $w->appellant === $strona && $w->status === $wynik)?->ile ?? 0);

        $wiersze = [];

        foreach ([Appeal::APPELLANT_AUTHOR => 'autor treści (od sankcji)', Appeal::APPELLANT_REPORTER => 'zgłaszający (od decyzji)'] as $strona => $etykieta) {
            $wiersze[] = [$etykieta, $ile($strona, Appeal::STATUS_UPHELD), $ile($strona, Appeal::STATUS_OVERTURNED)];
        }

        $wiersze[] = ['RAZEM', $wiersze[0][1] + $wiersze[1][1], $wiersze[0][2] + $wiersze[1][2]];

        return $wiersze;
    }

    /**
     * @param  list<string>  $naglowki
     * @param  list<array<int, int|string>>  $wiersze
     */
    private function sekcja(string $tytul, array $naglowki, array $wiersze): void
    {
        $this->newLine();
        $this->line('<options=bold>'.$tytul.'</>');
        $this->table($naglowki, $wiersze);
    }

    private function dzien(string $podany, string $domyslny): CarbonImmutable
    {
        $tekst = $podany !== '' ? $podany : $domyslny;
        $dzien = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tekst) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $tekst, (string) config('app.timezone'))
            : null;

        if (! $dzien instanceof CarbonImmutable || $dzien->format('Y-m-d') !== $tekst) {
            throw new InvalidArgumentException('Nie rozumiem daty „'.$tekst.'”. Podaj ją jako RRRR-MM-DD, np. 2026-01-31.');
        }

        return $dzien;
    }

    private function ostrzezenieORetencji(CarbonImmutable $od): void
    {
        $miesiace = (int) config('kuking.moderation.case_retention_months', 36);

        if ($od->lessThan(now()->subMonths($miesiace))) {
            $this->newLine();
            $this->warn('Okno zaczyna się wcześniej niż okres retencji spraw moderacyjnych ('.$miesiace.' mies.). '
                .'Starsze sprawy są już skasowane, więc liczby z początku okna są ZANIŻONE.');
        }
    }
}
