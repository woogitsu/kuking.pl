<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Dobowy sufit listów OSTRZEGA, ZANIM SIĘ SKOŃCZY (issue #234).
 *
 * PO CO TO JEST
 * Sufit z D-057 chroni cudzy kawałek wiadra 300 listów, ale w dniu, w którym
 * się kończy, po prostu zamyka drzwi: logowanie linkiem przestaje wysyłać
 * listy i dla człowieka po drugiej stronie jest to nie do odróżnienia od
 * awarii. Issue #234 prosi wprost, żeby przejście na płatny plan dało się
 * zrobić DZIEŃ WCZEŚNIEJ, nie w dniu awarii — a jedynym sposobem, żeby ktoś
 * o tym wiedział dzień wcześniej, jest sygnał przy przekroczeniu progu.
 *
 * Ostrzeżenie idzie na poziomie `error`, bo to poziom decyduje, czy wpis
 * pójdzie na webhook błędów (D-041) i do Sentry — czyli czy właściciel ma
 * szansę dowiedzieć się BEZ zaglądania w dziennik.
 */
class SufitPocztyOstrzegaZawczasuTest extends TestCase
{
    /** @var list<MessageLogged> */
    private array $wpisy = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpisy = [];

        Log::listen(function (MessageLogged $wpis): void {
            $this->wpisy[] = $wpis;
        });

        config([
            'kuking.login_link.dzienny_budzet' => 10,
            'kuking.poczta.prog_ostrzezenia_procent' => 80,
        ]);
    }

    public function test_ostrzezenie_pada_po_przekroczeniu_progu_a_nie_wczesniej(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 7; $list++) {
            $budzet->zajmij();
        }

        $this->assertFalse(
            $this->ostrzegano(),
            'Siedem listów z dziesięciu to 70% — ostrzegać przed progiem znaczy uczyć ignorowania alarmu.',
        );

        $budzet->zajmij(); // ósmy, czyli 80%

        $this->assertTrue(
            $this->ostrzegano(),
            'Przy 80% sufitu właściciel ma się dowiedzieć, że pula się kończy — ZANIM listy zaczną odbijać.',
        );
    }

    /**
     * Ostrzeżenie leci RAZ NA DOBĘ NA FUNKCJĘ. Przy sufitcie 120 listów
     * powtarzanie go przy każdym kolejnym liście dałoby dwadzieścia cztery
     * identyczne wpisy — a alarm, który się powtarza, uczy się ignorować.
     */
    public function test_ostrzezenie_nie_powtarza_sie_przy_kazdym_liscie(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 10; $list++) {
            $budzet->zajmij();
        }

        $this->assertSame(1, $this->ileOstrzezen());
    }

    /** Ostrzeżenie nie niesie ani adresu, ani nazwy konta — sam dziennik idzie na webhook (AGENTS.md §7). */
    public function test_ostrzezenie_niesie_wylacznie_liczby(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 9; $list++) {
            $budzet->zajmij();
        }

        $wpis = $this->pierwszeOstrzezenie();

        $this->assertNotNull($wpis);
        $this->assertSame('link-logowania', $wpis->context['funkcja'] ?? null);
        // Ostrzeżenie pada przy ÓSMYM liście (80% z dziesięciu) i jest jedno
        // na dobę, więc liczby opisują tę chwilę, a nie koniec pętli.
        $this->assertSame(8, $wpis->context['zuzyte'] ?? null);
        $this->assertSame(10, $wpis->context['budzet'] ?? null);
        $this->assertSame(2, $wpis->context['zostalo'] ?? null);
        $this->assertSame('error', $wpis->level, 'Poziom niższy niż `error` nie wychodzi na webhook błędów (D-041).');
    }

    /** Wyłącznik: próg 100 albo więcej znaczy „nie ostrzegaj" — sufit i tak zatrzyma wysyłkę. */
    public function test_prog_setny_wylacza_ostrzeganie(): void
    {
        config(['kuking.poczta.prog_ostrzezenia_procent' => 100]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 10; $list++) {
            $budzet->zajmij();
        }

        $this->assertFalse($this->ostrzegano());
    }

    private function ostrzegano(): bool
    {
        return $this->ileOstrzezen() > 0;
    }

    private function ileOstrzezen(): int
    {
        return count(array_filter(
            $this->wpisy,
            static fn (MessageLogged $wpis): bool => str_contains($wpis->message, 'dobowy sufit listów jest prawie wyczerpany'),
        ));
    }

    private function pierwszeOstrzezenie(): ?MessageLogged
    {
        foreach ($this->wpisy as $wpis) {
            if (str_contains($wpis->message, 'dobowy sufit listów jest prawie wyczerpany')) {
                return $wpis;
            }
        }

        return null;
    }
}
