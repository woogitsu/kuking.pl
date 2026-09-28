<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * R1 — polityka nie może obiecywać „pełnej kopii" danych.
 *
 * DLACZEGO TO JEST STRAŻNIK, A NIE JEDNORAZOWA POPRAWKA.
 * Rozjazd R1 polegał na tym, że polityka obiecywała **pełną kopię**, a paczka
 * eksportu świadomie pomijała osiem kategorii danych, które ta sama polityka
 * gdzie indziej wymienia jako przechowywane. Właściciel rozstrzygnął to
 * 20.09.2026 na wariant A: zawężamy obietnicę, paczki nie poszerzamy.
 *
 * Obietnica w dokumencie prawnym to jedno zdanie, które ktoś za pół roku
 * „poprawi na ładniejsze", nie wiedząc, że było wybrane. Ten test pilnuje
 * obu stron tej decyzji naraz: że słowo o pełnej kopii nie wróciło **i** że
 * droga po resztę danych nadal jest wskazana. Sama pierwsza asercja
 * przechodziłaby także wtedy, gdyby ktoś usunął całe zdanie — a wtedy
 * człowiek nie miałby już ani paczki, ani drogi.
 *
 * CZEGO TEN TEST NIE PILNUJE, ŚWIADOMIE.
 * Nie sprawdza, czy ktoś te ręczne prośby **obsługuje**. Warunek 1 wariantu A
 * (procedura i licznik terminu z art. 12 ust. 3) nie jest dziś spełniony —
 * to zaległość właściciela, ta sama co R7, i opisana jest w
 * `docs/legal/DECYZJE_WLASCICIELA_R1_R6_DPA.md`. Asercja na istnienie
 * procedury zabetonowałaby coś, czego nikt jeszcze nie zaprojektował.
 */
final class PolitykaNieObiecujePelnejKopiiTest extends TestCase
{
    private function polityka(): string
    {
        $sciezka = resource_path('legal/polityka-prywatnosci.md');

        $this->assertFileExists($sciezka, 'Polityka prywatności zniknęła — reszta tego testu nie sprawdzałaby niczego.');

        return (string) file_get_contents($sciezka);
    }

    public function test_polityka_nie_obiecuje_pelnej_kopii_danych(): void
    {
        $tresc = $this->polityka();

        // Kontrola dodatnia: plik musi być tym, za co się podaje. Pusty albo
        // podmieniony plik przeszedłby asercję niżej, nic nie mierząc.
        $this->assertStringContainsString('Twoje prawa', $tresc, 'To nie wygląda na politykę prywatności.');

        $this->assertStringNotContainsString(
            'pełną kopię',
            $tresc,
            'Polityka znowu obiecuje „pełną kopię" danych, a paczka eksportu świadomie pełna nie jest '
            .'(osiem kategorii — patrz docs/legal/DECYZJE_WLASCICIELA_R1_R6_DPA.md, R1). '
            .'Właściciel wybrał 20.09.2026 zawężenie obietnicy, nie poszerzenie paczki.',
        );
    }

    public function test_polityka_wskazuje_droge_po_dane_spoza_paczki(): void
    {
        $tresc = $this->polityka();

        // Druga strona tej samej decyzji. Bez tego zawężenie obietnicy
        // znaczyłoby, że człowiek po prostu dostaje mniej i nie wie dokąd iść.
        $this->assertStringContainsString(
            'kontakt@kuking.pl',
            $tresc,
            'Polityka zawęziła obietnicę, ale nie mówi, gdzie prosić o dane spoza paczki.',
        );
        $this->assertStringContainsString(
            'art. 12 ust. 3',
            $tresc,
            'Brakuje terminu, w którym przygotujemy dane ręcznie — bez niego „napisz do nas" jest obietnicą bez końca.',
        );
    }
}
