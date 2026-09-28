<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Dwa zadania harmonogramu nie dostają tego samego terminu przez przypadek.
 *
 * SKĄD TEN TEST. 25.09.2026 dwa niezależne PR-y (#1051 — dosyłka pilnych
 * alarmów, D-252 — dosyłka potwierdzeń zgłoszeń) wybrały „wolną" minutę 35.
 * Każdy z nich patrzył na `routes/console.php` sprzed drugiego i każdy
 * w komentarzu opisał, że minuta jest wolna, bo lista jest „świadomie
 * porozsuwana". Po scaleniu obu minuta 35 miała dwa zadania, a komentarze
 * twierdziły coś przeciwnego. Żaden pojedynczy PR nie mógł tego zobaczyć.
 *
 * REGUŁA. Identyczne wyrażenie cron dla dwóch zadań jest dozwolone tylko
 * wtedy, gdy ta para stoi w `DOZWOLONE_WSPOLNE` — czyli ktoś ją zobaczył
 * i nazwał. Zadania z jednej minuty wykonują się sekwencyjnie w jednym
 * procesie (to nie wyścig, patrz komentarz przy `kuking:zdejmij-wygasle-kary`),
 * więc wspólny termin bywa w porządku. Nie jest w porządku, gdy nikt o nim
 * nie wie.
 *
 * Test czyta zdarzenia z `Schedule::events()`, nie tekst `routes/console.php`.
 * Kontrolę dodatnią robi drugi test: ta sama reguła na sztucznym harmonogramie
 * z kolizją.
 */
class HarmonogramBezKolizjiTerminowTest extends TestCase
{
    /**
     * Wspólne terminy, które ktoś świadomie zaakceptował — z uzasadnieniem
     * w `routes/console.php`. Klucz: wyrażenie cron, wartość: posortowane
     * nazwy zadań.
     *
     * @var array<string, list<string>>
     */
    private const DOZWOLONE_WSPOLNE = [
        // Oba `hourly()`; opisane przy `kuking:doslij-pilne-alarmy`
        // („o 00 tykają zdejmowanie kar i licznik społeczności").
        '0 * * * *' => ['kuking:policz-kukingow', 'kuking:zdejmij-wygasle-kary'],
        // Liczniki i sondy o stałym rytmie — z natury trafiają w te same minuty.
        '*/5 * * * *' => ['kuking:policz-kolejki', 'kuking:puls-harmonogramu'],
        '*/15 * * * *' => ['kuking:sprawdz-kolejke', 'kuking:wyczysc-zalegle-cdn'],
    ];

    public function test_zadne_dwa_zadania_nie_dziela_terminu_bez_wpisu_na_liscie(): void
    {
        $events = app(Schedule::class)->events();
        $this->assertGreaterThanOrEqual(20, count($events), 'Nie wczytano harmonogramu aplikacji.');

        $this->assertSame([], $this->kolizje($events), 'Dwa zadania harmonogramu dzielą termin, choć nikt tego nie nazwał. '
            .'Przesuń jedno z nich na wolną minutę albo — jeśli wspólny termin jest zamierzony — dopisz parę do DOZWOLONE_WSPOLNE z uzasadnieniem w routes/console.php.');
    }

    public function test_lista_dozwolonych_nie_trzyma_nieaktualnych_par(): void
    {
        $wedlugTerminu = $this->wedlugTerminu(app(Schedule::class)->events());

        foreach (self::DOZWOLONE_WSPOLNE as $termin => $zadania) {
            $this->assertSame($zadania, $wedlugTerminu[$termin] ?? [], "Para na liście dozwolonych dla '{$termin}' nie odpowiada już harmonogramowi — usuń ją albo popraw.");
        }
    }

    public function test_kontrola_dodatnia_kolizja_jest_wykrywana(): void
    {
        $schedule = new Schedule('UTC');
        $schedule->call(fn () => null)->name('kontrola-a')->hourlyAt(35);
        $schedule->call(fn () => null)->name('kontrola-b')->hourlyAt(35);
        $schedule->call(fn () => null)->name('kontrola-c')->hourlyAt(45);

        $this->assertSame(['35 * * * *' => ['kontrola-a', 'kontrola-b']], $this->kolizje($schedule->events()));
    }

    /**
     * @param  array<int, Event>  $events
     * @return array<string, list<string>>
     */
    private function kolizje(array $events): array
    {
        return array_filter(
            $this->wedlugTerminu($events),
            fn (array $zadania, string $termin): bool => count($zadania) > 1
                && (self::DOZWOLONE_WSPOLNE[$termin] ?? null) !== $zadania,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @param  array<int, Event>  $events
     * @return array<string, list<string>>
     */
    private function wedlugTerminu(array $events): array
    {
        $wynik = [];
        foreach ($events as $event) {
            $wynik[$event->expression][] = (string) ($event->description ?? $event->getSummaryForDisplay());
        }

        return array_map(function (array $zadania): array {
            sort($zadania);

            return $zadania;
        }, $wynik);
    }
}
