<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Zakładka feedu nie gubi spacji przed nazwą serwisu.
 *
 * CO SIĘ STAŁO
 * Na zrzucie z produkcji (Chrome na Androidzie, ~390 px) zakładka na `/home`
 * czytała się „Świeżo zkuKING" — bez spacji między „z" a nazwą.
 *
 * `.tab` jest `display: inline-flex` (`resources/css/app.css`). W kontenerze
 * flex każdy ciągły kawałek tekstu leżący obok elementu inline staje się
 * ANONIMOWYM elementem flex, a anonimowemu elementowi flex przycina się białe
 * znaki na początku i na końcu. Etykieta `Świeżo z <x-kuking-word />` to węzeł
 * tekstowy „Świeżo z " plus element — więc końcowa spacja tego węzła NIE JEST
 * RYSOWANA. W `.btn` ta sama sytuacja nie rzuca się w oczy tylko dlatego, że
 * `.btn` ma `gap` i ten `gap` wchodzi w miejsce zjedzonej spacji; `.tab`
 * `gap`-u nie ma (`column-gap: normal`).
 *
 * SKĄD WIADOMO, ŻE TO MIERZY WŁAŚCIWĄ RZECZ — ZMIERZONE W PRZEGLĄDARCE
 * Pomiar w Chromium na żywej instancji, `/home`, konto zalogowane. Miarą jest
 * szerokość, jaką NARYSOWANA końcowa spacja dokłada węzłowi tekstowemu:
 * zakres (`Range`) nad pełną treścią węzła kontra zakres nad tą samą treścią
 * bez końcowych białych znaków.
 *
 *   szerokość okna    przed poprawką    po poprawce
 *   390 px            0,00 px           4,27 px
 *   320 px            0,00 px           4,27 px
 *
 * Kontrola dodatnia tego samego pomiaru na tych samych ekranach: 149 zdrowych
 * par „tekst + element" przy 390 px, m.in. odnośnik stopki „O kuKING" (4,50 px)
 * i nagłówek `/odkryj` „Świeżo z kuKING" (6,13 px). Miernik potrafił więc
 * odróżnić spację narysowaną od zjedzonej, a nie zwracał zer wszędzie.
 *
 * DLACZEGO TEN TEST NIE SPRAWDZA TEKSTU ANI ŹRÓDŁA — I TO JEST CAŁA PUŁAPKA
 * Spacja JEST w `home.blade.php` i JEST w wysłanym HTML-u. Zginęła dopiero
 * przy składaniu strony przez przeglądarkę. Każda asercja po treści —
 * `assertSee('Świeżo z ')`, porównanie `textContent` po normalizacji białych
 * znaków, regeks po źródle blade'a — BYŁABY ZIELONA NAD DZISIEJSZĄ USTERKĄ,
 * bo wszystkie one czytają znaki, a usterka jest w tym, ile z tych znaków
 * przeglądarka narysowała. PHPUnit przeglądarki nie ma, więc ten test pilnuje
 * jedynej rzeczy, która w HTML-u odpowiada zmierzonemu zeru: UKŁADU WĘZŁÓW,
 * przy którym flex ma co przyciąć.
 *
 * REGUŁA, KTÓREJ PILNUJE TEN PLIK
 * Wewnątrz `.tab` żaden BEZPOŚREDNI węzeł tekstowy kończący się białym znakiem
 * nie może sąsiadować z elementem — i odwrotnie, żaden bezpośredni węzeł
 * tekstowy zaczynający się białym znakiem nie może stać po elemencie. Etykieta
 * złożona z tekstu i elementu ma być owinięta jednym `<span class="tab-napis">`;
 * wtedy elementem flex jest ten `<span>`, a w jego środku obowiązuje zwykły
 * skład tekstu, w którym spacja przed elementem inline zostaje.
 *
 *   [„Świeżo z ", <span class="kuking-word">]     → BŁĄD (spacja do przycięcia)
 *   [<span class="tab-napis">Świeżo z …</span>]   → w porządku
 *   [„Obserwowani"]                               → w porządku (sam tekst)
 *
 * CZEGO TEN TEST NIE OBIECUJE
 * Nie mierzy pikseli i nie zastąpi pomiaru w przeglądarce — pilnuje warunku
 * KONIECZNEGO, nie wystarczającego. Nie obejmuje też całego serwisu: sprawdza
 * `.tab` na ekranach z `ekrany()`. Zakładki poza tą listą (panel moderacji)
 * mają etykiety jednowęzłowe i tej choroby mieć nie mogą — gdy któraś dostanie
 * element w środku etykiety, dopisz jej ekran tutaj.
 *
 * Siostrzany strażnik dla przycisków: `PrzyciskiNieRozbijajaNapisuTest`
 * (issue #353). Tamten liczy DWA bezpośrednie węzły tekstowe i dlatego
 * dzisiejszej usterki NIE ŁAPIE — „Świeżo z " + element to jeden węzeł.
 */
class ZakladkaNieGubiSpacjiPrzedNazwaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /**
     * Ekrany z zakładkami — wymienione WPROST, nie odgadywane z tras.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ekrany(): array
    {
        return [
            'strona główna' => ['strona główna', '/home'],
        ];
    }

    #[DataProvider('ekrany')]
    public function test_zadna_zakladka_nie_ma_etykiety_rozcietej_elementem(string $nazwaEkranu, string $adres): void
    {
        $html = $this->actingAs($this->user('zakladki'))->get($adres)->assertOk()->getContent();

        $zakladki = $this->zakladkiZTresci($this->trescEkranu((string) $html));

        // Bez tego skan po zerze elementów byłby „sukcesem" (pułapka 2).
        $this->assertNotSame(
            [],
            $zakladki,
            "Ekran „{$nazwaEkranu}” ({$adres}) nie ma ani jednej zakładki (`.tab`) — ".
            'albo strażnik patrzy w złe miejsce, albo zakładki zniknęły z ekranu.',
        );

        $rozciete = [];

        foreach ($zakladki as $zakladka) {
            foreach ($this->etykietyDoPrzyciecia($zakladka) as $opis) {
                $rozciete[] = sprintf(
                    '<%s class="%s"> → %s',
                    $zakladka->tagName,
                    $zakladka->getAttribute('class'),
                    $opis,
                );
            }
        }

        $this->assertSame(
            [],
            $rozciete,
            "Na ekranie „{$nazwaEkranu}” ({$adres}) etykieta zakładki jest rozcięta elementem. ".
            '`.tab` jest `inline-flex`, więc tekst obok elementu staje się anonimowym elementem flex, '.
            "a temu przycina się spację na końcu — człowiek widzi „Świeżo zkuKING”, choć spacja jest w kodzie.\n".
            "Owiń CAŁĄ etykietę jednym `<span class=\"tab-napis\">`.\n".
            implode("\n", $rozciete),
        );
    }

    /**
     * Wszystkie elementy z klasą `tab` w treści ekranu.
     *
     * Dopasowanie po CAŁEJ nazwie klasy (`concat` ze spacjami), żeby nie
     * złapać `tabs`, `tab-napis` ani niczego innego z tym przedrostkiem.
     *
     * @return list<DOMElement>
     */
    private function zakladkiZTresci(string $html): array
    {
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $znalezione = (new DOMXPath($dokument))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' tab ')]");

        $zakladki = [];

        if ($znalezione !== false) {
            foreach ($znalezione as $element) {
                if ($element instanceof DOMElement) {
                    $zakladki[] = $element;
                }
            }
        }

        return $zakladki;
    }

    /**
     * Miejsca w zakładce, w których flex ma białą spację do przycięcia.
     *
     * Szukamy BEZPOŚREDNICH dzieci: tekst schowany w `<span>` nie jest osobnym
     * elementem flex zakładki, tylko treścią jednego z jej dzieci — i dokładnie
     * o to chodzi w poprawce.
     *
     * @return list<string>
     */
    private function etykietyDoPrzyciecia(DOMElement $zakladka): array
    {
        $dzieci = iterator_to_array($zakladka->childNodes);
        $znalezione = [];

        foreach ($dzieci as $i => $wezel) {
            if (! $wezel instanceof DOMText || trim($wezel->textContent) === '') {
                continue;
            }

            $poprzedni = $dzieci[$i - 1] ?? null;
            $nastepny = $dzieci[$i + 1] ?? null;

            if ($nastepny instanceof DOMElement && preg_match('/\s$/u', $wezel->textContent) === 1) {
                $znalezione[] = sprintf(
                    'węzeł tekstowy „%s” kończy się spacją tuż przed <%s> — ta spacja nie zostanie narysowana',
                    $this->skrot($wezel->textContent),
                    $nastepny->tagName,
                );
            }

            if ($poprzedni instanceof DOMElement && preg_match('/^\s/u', $wezel->textContent) === 1) {
                $znalezione[] = sprintf(
                    'węzeł tekstowy „%s” zaczyna się spacją tuż po <%s> — ta spacja nie zostanie narysowana',
                    $this->skrot($wezel->textContent),
                    $poprzedni->tagName,
                );
            }
        }

        return $znalezione;
    }

    private function skrot(string $tekst): string
    {
        $jedenWiersz = trim((string) preg_replace('/\s+/u', ' ', $tekst));

        return mb_strlen($jedenWiersz) > 40 ? mb_substr($jedenWiersz, 0, 40).'…' : $jedenWiersz;
    }
}
