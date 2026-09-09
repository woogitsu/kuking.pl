<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Retencja kohorty nie przekracza 100% (audyt zewnętrzny, G07).
 *
 * CO BYŁO ZŁE
 * `CookRetentionCohorts` dokumentowało formułę „dziel `active_users` z danego
 * `week_offset` przez `active_users` przy `week_offset = 0`", a `kuking:raport`
 * tak właśnie liczył. Zapytanie NIE wymaga jednak, żeby to byli ci sami
 * ludzie: liczy członków kohorty aktywnych w danym tygodniu, a zbiór
 * aktywnych w tygodniu 4 nie zawiera się w zbiorze aktywnych w tygodniu 0.
 *
 * ZMIERZONE, przez `kuking:raport`, na scenariuszu z audytu — troje ludzi
 * rejestruje się w tym samym tygodniu, JEDNA publikuje w tygodniu
 * rejestracji, DWIE INNE dopiero w tygodniu czwartym:
 *
 *     tydzień 2026-07-27: 1 na starcie · po tygodniu 0 (0,0%) · po miesiącu 2 (200,0%)
 *
 * DLACZEGO TO NIE JEST LITERÓWKA W PROCENCIE
 * Ta liczba istnieje po to, żeby właściciel podjął jedną decyzję: czy
 * `docs/ROADMAP.md` przepuszcza bramkę V1 („Planner/groups/forks dopiero gdy
 * WAC i D30 pokazują powroty"). 200% czyta się jako „ludzie wracają dwa razy
 * mocniej, niż przyszli". Prawdziwa odpowiedź na tym samym zestawie danych
 * brzmi: dwie osoby z trzech, czyli 66,7% — i nikt nie wrócił po tygodniu.
 *
 * MIANOWNIKIEM JEST ROZMIAR KOHORTY: wszyscy liczeni ludzie zarejestrowani
 * w danym tygodniu, niezależnie od tego, czy cokolwiek zrobili. Wtedy żaden
 * ofset nie ma prawa przekroczyć 100%, bo licznik jest podzbiorem mianownika
 * z definicji zapytania.
 */
class KohortaNieDajePonadStuProcentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Piątek 12:00 w strefie człowieka — ta sama kotwica co
     * `RaportPowrotowTest`, żeby „tydzień" znaczył to samo w obu plikach.
     */
    private function teraz(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-06-12 12:00:00', Czas::strefa());
    }

    public function test_scenariusz_z_audytu_nie_daje_dwustu_procent(): void
    {
        $this->travelTo($this->teraz());

        $rejestracja = $this->teraz()->subWeeks(5);

        $naStarcie = $this->user('nastarcie', ['created_at' => $rejestracja]);
        $pozniejsza = $this->user('pozniejsza', ['created_at' => $rejestracja]);
        $pozniejszy = $this->user('pozniejszy', ['created_at' => $rejestracja]);

        // Tylko jedna osoba jest aktywna w tygodniu rejestracji.
        Post::factory()->create([
            'author_id' => $naStarcie->getKey(),
            'published_at' => $rejestracja->addDay(),
        ]);

        // Dwie INNE odzywają się dopiero po miesiącu — i już nigdy ta pierwsza.
        foreach ([$pozniejsza, $pozniejszy] as $osoba) {
            Post::factory()->create([
                'author_id' => $osoba->getKey(),
                'published_at' => $rejestracja->addWeeks(4)->addDay(),
            ]);
        }

        Artisan::call('kuking:raport');
        $raport = Artisan::output();

        $this->assertStringContainsString(
            '3 w kohorcie · w pierwszym tygodniu 1 (33,3%) · po tygodniu 0 (0,0%) · po miesiącu 2 (66,7%)',
            $raport,
            'Raport liczy retencję od liczby aktywnych w tygodniu zerowym zamiast od '
            .'rozmiaru kohorty. Na tym zestawie danych daje to 200% — liczbę, na '
            .'podstawie której właściciel przepuściłby bramkę V1.',
        );
    }

    /**
     * Ogólniejsza wersja tego samego: ŻADNA liczba w raporcie nie jest
     * procentem powyżej stu.
     *
     * Pierwsza asercja pilnuje jednego zdania na jednym zestawie danych.
     * Ta pilnuje własności, która ma zachodzić zawsze — łapie też te miejsca
     * raportu, do których nikt nie napisał osobnego testu (D7, D30).
     */
    public function test_zaden_procent_w_calym_raporcie_nie_przekracza_stu(): void
    {
        $this->travelTo($this->teraz());

        $rejestracja = $this->teraz()->subWeeks(5);

        foreach (['a', 'b', 'c', 'd'] as $i => $imie) {
            $osoba = $this->user('osoba'.$imie, ['created_at' => $rejestracja]);

            // Pierwsza publikuje na starcie, pozostałe trzy dopiero po
            // miesiącu — czyli licznik przewyższa stary mianownik trzykrotnie.
            Post::factory()->create([
                'author_id' => $osoba->getKey(),
                'published_at' => $i === 0
                    ? $rejestracja->addDay()
                    : $rejestracja->addWeeks(4)->addDay(),
            ]);
        }

        Artisan::call('kuking:raport');
        $raport = Artisan::output();

        preg_match_all('/(\d+),(\d)%/u', $raport, $trafienia, PREG_SET_ORDER);

        // KONTROLA METODY POMIARU: gdyby wzorzec nic nie łapał, pętla niżej
        // przechodziłaby pusta i test nie mówiłby nic.
        $this->assertNotEmpty(
            $trafienia,
            'W raporcie nie znaleziono ANI JEDNEGO procentu. Albo zmienił się format '
            .'liczby (przecinek dziesiętny), albo raport przestał je wypisywać — '
            .'tak czy inaczej ten test przestał cokolwiek sprawdzać.',
        );

        foreach ($trafienia as $trafienie) {
            $wartosc = (float) ($trafienie[1].'.'.$trafienie[2]);

            $this->assertLessThanOrEqual(
                100.0,
                $wartosc,
                "Raport wypisał {$trafienie[0]}. Procent powyżej stu znaczy, że licznik "
                .'nie jest podzbiorem mianownika — czyli że liczba mierzy co innego, '
                .'niż mówi jej podpis.',
            );
        }
    }
}
