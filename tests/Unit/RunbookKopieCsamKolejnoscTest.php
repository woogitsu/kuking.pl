<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #2708: przywrócenie CSAM musi poprzedzać każde wymazanie i uruchomienie serwisu.
 * To test kolejności instrukcji awaryjnej, nie test wykonania działań w Railway.
 */
final class RunbookKopieCsamKolejnoscTest extends TestCase
{
    public function test_brak_zatrzymania_uslug_nie_pozwala_pominac_sprawdzenia_csam(): void
    {
        $dokument = file_get_contents(dirname(__DIR__, 2).'/docs/infra/KOPIE_I_ODTWORZENIE.md');
        $this->assertNotFalse($dokument);
        $this->assertStringNotContainsString('uruchom komendę natychmiast', $dokument, 'CSAM_AWARIA_NIE_POMIJA_SPRAWDZENIA: brak izolacji usług nie pozwala wymazać dowodu przed odtworzeniem decyzji.');
        $this->assertStringContainsString('Bez sprawdzenia decyzji CSAM', $dokument);
        $this->assertStringContainsString('nie wykonuj wymazania.', $dokument);
    }

    public function test_obie_kontrole_csam_poprzedzaja_wymazanie_i_podpiecie_bazy(): void
    {
        $sciezka = dirname(__DIR__, 2).'/docs/infra/KOPIE_I_ODTWORZENIE.md';
        $dokument = file_get_contents($sciezka);
        $this->assertNotFalse($dokument);

        $poczatek = strpos($dokument, '### 3(b) Utracona cała baza');
        $koniec = strpos($dokument, '### 3.1 Po KAŻDYM odtworzeniu');
        $this->assertNotFalse($poczatek);
        $this->assertNotFalse($koniec);
        $this->assertGreaterThan($poczatek, $koniec);
        $sekcja = substr($dokument, $poczatek, $koniec - $poczatek);

        $pierwszaKontrola = strpos($sekcja, '# 4. NAJPIERW odtwórz i zweryfikuj decyzje CSAM');
        $drugaKontrola = strpos($sekcja, '# 6. Wstrzymaj ruch usług web, worker i scheduler na starej bazie');
        $podpiecie = strpos($sekcja, 'railway variables --set "DB_URL=');
        $uruchomienie = strpos($sekcja, '# 8. Dopiero po sprawdzeniu DB_URL na trzech usługach');
        $this->assertNotFalse($pierwszaKontrola);
        $this->assertNotFalse($drugaKontrola);
        $this->assertNotFalse($podpiecie);
        $this->assertNotFalse($uruchomienie);

        preg_match_all('/^\s+php artisan kuking:wymaz-ponownie\b/m', $sekcja, $wymazania, PREG_OFFSET_CAPTURE);
        $this->assertCount(4, $wymazania[0], 'Runbook ma mieć podgląd i wykonanie przed przełączeniem oraz ponowienie po zamrożeniu decyzji.');

        $this->assertLessThan($wymazania[0][0][1], $pierwszaKontrola, 'CSAM_PRZED_PIERWSZYM_WYMAZANIEM: decyzje CSAM muszą poprzedzać podgląd i wymazanie.');
        $this->assertLessThan($drugaKontrola, $wymazania[0][1][1]);
        $this->assertLessThan($wymazania[0][2][1], $drugaKontrola, 'CSAM_PRZED_PONOWNYM_WYMAZANIEM: nowe decyzje muszą poprzedzać drugi podgląd i wymazanie.');
        $this->assertLessThan($podpiecie, $wymazania[0][3][1], 'OBA_WYMAZANIA_PRZED_PODPIECIEM: odtworzona baza nie może przyjąć ruchu przed obiema kontrolami.');
        $this->assertLessThan($uruchomienie, $podpiecie);

        $this->assertStringContainsString('bez publicznego ruchu oraz osobnych usług', $sekcja);
        $this->assertStringContainsString('Jeśli nie masz takiego dostępu albo pełnej listy decyzji,', $sekcja);
        $this->assertStringContainsString('terminalne przeniesienie zabezpieczonych', $sekcja);
        $this->assertStringContainsString('--service worker --environment production', $sekcja);
        $this->assertStringContainsString('--service scheduler --environment production', $sekcja);
    }
}
