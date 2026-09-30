<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\LimitImportowOsoby;
use App\Domain\Import\LimitImportu;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #2072: doba i miesiąc limitu importu liczą się w strefie `kuking.strefa`
 * (Europe/Warsaw), nie w UTC. Próba o 23:59 czasu polskiego dnia D nie
 * zajmuje miejsca w dniu D+1, a próba z 00:30 czasu polskiego dnia D+1 —
 * zajmuje, choć w UTC to jeszcze dzień D. Ten sam układ dla miesiąca,
 * latem (UTC+2), zimą (UTC+1) i w noce zmiany czasu (29.03 i 25.10.2026).
 */
final class LimitImportuGranicaDobyIMiesiacaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, string, bool}>
     *                                                            [próba (UTC), teraz (UTC), limit, czy próba zajmuje miejsce teraz]
     */
    public static function granice(): array
    {
        return [
            // Lato, UTC+2: północ w Warszawie to 22:00 UTC.
            'doba, lato: 23:59 lokalnie dnia D nie liczy się do D+1' => ['2026-07-10 21:59:00', '2026-07-10 22:01:00', LimitImportowOsoby::DZIEN, false],
            'doba, lato: 00:30 lokalnie dnia D+1 liczy się, choć w UTC to jeszcze D' => ['2026-07-10 22:30:00', '2026-07-11 08:00:00', LimitImportowOsoby::DZIEN, true],
            'doba, lato: 22:30 UTC i 23:30 UTC to dwie różne doby lokalne' => ['2026-07-10 22:30:00', '2026-07-10 23:30:00', LimitImportowOsoby::DZIEN, true],
            // Zima, UTC+1: północ w Warszawie to 23:00 UTC.
            'doba, zima: 23:59 lokalnie dnia D nie liczy się do D+1' => ['2026-01-10 22:59:00', '2026-01-10 23:01:00', LimitImportowOsoby::DZIEN, false],
            'doba, zima: 22:30 UTC to jeszcze dzień D (23:30 lokalnie)' => ['2026-01-10 22:30:00', '2026-01-10 23:30:00', LimitImportowOsoby::DZIEN, false],
            'doba, zima: 23:30 UTC to już dzień D+1 (00:30 lokalnie)' => ['2026-01-10 23:30:00', '2026-01-11 09:00:00', LimitImportowOsoby::DZIEN, true],
            // Noc zmiany czasu na letni: 29.03.2026 doba zaczyna się o 23:00 UTC dnia poprzedniego.
            'doba, wiosenna zmiana czasu: 23:59 lokalnie 28.03 nie liczy się do 29.03' => ['2026-03-28 22:59:00', '2026-03-28 23:01:00', LimitImportowOsoby::DZIEN, false],
            'doba, wiosenna zmiana czasu: 23:30 UTC 28.03 to 00:30 lokalnie 29.03' => ['2026-03-28 23:30:00', '2026-03-29 12:00:00', LimitImportowOsoby::DZIEN, true],
            // Noc zmiany czasu na zimowy: doba 25.10.2026 zaczyna się o 22:00 UTC dnia poprzedniego.
            'doba, jesienna zmiana czasu: 23:59 lokalnie 24.10 nie liczy się do 25.10' => ['2026-10-24 21:59:00', '2026-10-24 22:01:00', LimitImportowOsoby::DZIEN, false],
            'doba, jesienna zmiana czasu: 00:30 lokalnie 25.10 liczy się do 25.10' => ['2026-10-24 22:30:00', '2026-10-25 09:00:00', LimitImportowOsoby::DZIEN, true],
            'doba, jesienna zmiana czasu: koniec doby 25.10 (23:30 lokalnie) liczy się jeszcze do 25.10' => ['2026-10-25 09:00:00', '2026-10-25 22:30:00', LimitImportowOsoby::DZIEN, true],
            'doba, jesienna zmiana czasu: 00:01 lokalnie 26.10 to nowa doba' => ['2026-10-25 22:30:00', '2026-10-25 23:01:00', LimitImportowOsoby::DZIEN, false],

            // Miesiąc, lato: 1 sierpnia 00:00 lokalnie to 31 lipca 22:00 UTC.
            'miesiąc, lato: 23:59 lokalnie 31.07 nie liczy się do sierpnia' => ['2026-07-31 21:59:00', '2026-07-31 22:01:00', LimitImportowOsoby::MIESIAC, false],
            'miesiąc, lato: 00:30 lokalnie 1.08 liczy się do sierpnia, choć w UTC to lipiec' => ['2026-07-31 22:30:00', '2026-08-15 10:00:00', LimitImportowOsoby::MIESIAC, true],
            // Miesiąc, zima: 1 lutego 00:00 lokalnie to 31 stycznia 23:00 UTC.
            'miesiąc, zima: 22:30 UTC 31.01 to jeszcze styczeń' => ['2026-01-31 22:30:00', '2026-01-31 23:30:00', LimitImportowOsoby::MIESIAC, false],
            'miesiąc, zima: 23:30 UTC 31.01 to już luty' => ['2026-01-31 23:30:00', '2026-02-10 10:00:00', LimitImportowOsoby::MIESIAC, true],
            // Miesiące z przejściem czasu: 1 kwietnia (CEST) i 1 listopada (CET).
            'miesiąc, po wiosennej zmianie: 23:59 lokalnie 31.03 nie liczy się do kwietnia' => ['2026-03-31 21:59:00', '2026-03-31 22:01:00', LimitImportowOsoby::MIESIAC, false],
            'miesiąc, po jesiennej zmianie: 23:59 lokalnie 31.10 nie liczy się do listopada' => ['2026-10-31 22:59:00', '2026-10-31 23:01:00', LimitImportowOsoby::MIESIAC, false],
            'miesiąc, po jesiennej zmianie: 00:30 lokalnie 1.11 liczy się do listopada' => ['2026-10-31 23:30:00', '2026-11-20 10:00:00', LimitImportowOsoby::MIESIAC, true],
            'miesiąc, przełom roku: 23:59 lokalnie 31.12 nie liczy się do stycznia' => ['2026-12-31 22:59:00', '2026-12-31 23:01:00', LimitImportowOsoby::MIESIAC, false],
        ];
    }

    #[DataProvider('granice')]
    public function test_granica_doby_i_miesiaca_jest_liczona_w_strefie_lokalnej(string $proba, string $teraz, string $limit, bool $zajmuje): void
    {
        $this->assertSame('Europe/Warsaw', config('kuking.strefa'));
        // Sprawdzany jest tylko jeden limit naraz; drugi jest poza zasięgiem.
        config([
            'kuking.import.limity.na_osobe_dzien' => $limit === LimitImportowOsoby::DZIEN ? 1 : 100,
            'kuking.import.limity.na_osobe_miesiac' => $limit === LimitImportowOsoby::MIESIAC ? 1 : 100,
        ]);
        $osoba = $this->user();

        $this->travelTo(Carbon::parse($proba, 'UTC'));
        app(LimitImportu::class)->zuzyj($osoba, 'url', (string) Str::uuid());

        $this->travelTo(Carbon::parse($teraz, 'UTC'));
        $this->assertSame($zajmuje ? $limit : null, app(LimitImportowOsoby::class)->przekroczony($osoba));

        try {
            app(LimitImportu::class)->zuzyj($osoba, 'pdf', (string) Str::uuid());
            $this->assertFalse($zajmuje, 'Próba z tej samej doby/miesiąca lokalnego powinna zajmować miejsce.');
        } catch (ImportOdrzucony $e) {
            $this->assertTrue($zajmuje, 'Próba z poprzedniej doby/miesiąca lokalnego nie może zajmować miejsca w nowej.');
            $this->assertSame(
                $limit === LimitImportowOsoby::DZIEN ? ImportOdrzucony::LIMIT_OSOBY : ImportOdrzucony::LIMIT_OSOBY_MIESIAC,
                $e->kod,
            );
        }
    }
}
