<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Napis przycisku nie rozpada się na kawałki (issue #353).
 *
 * CO SIĘ STAŁO
 * `.btn` jest `display: inline-flex` (`resources/css/tokens.css`), więc KAŻDY
 * kawałek tekstu leżący między elementami inline staje się OSOBNYM elementem
 * flex. Napis `Zostań <x-kuking-word forma="iem" /> — to darmowe` to trzy
 * węzły, czyli trzy niezależnie zawijane elementy rozdzielone `gap`. Do tego
 * `.btn` ma `overflow-wrap: anywhere` (obrona przed wypchnięciem strony w bok
 * przy 200% czcionki), więc każdy z tych kawałków, za wąski osobno, łamie się
 * w ŚRODKU WYRAZU.
 *
 * Zmierzone w Chromium, przycisk w ramce 320 px, `font-size: 24px`:
 * przed poprawką 5 kawałków w 5 wierszach i 114 px wysokości przycisku,
 * po owinięciu napisu jednym `<span class="btn-napis">` — 2 wiersze łamane
 * na spacjach i 84 px.
 *
 * REGUŁA, KTÓREJ PILNUJE TEN PLIK
 * Dla każdego elementu z klasą `btn` liczymy BEZPOŚREDNIE węzły tekstowe
 * o treści innej niż same białe znaki. Dwa lub więcej = błąd, bo dwa węzły
 * tekstowe mogą być rozdzielone wyłącznie elementem inline — czyli dokładnie
 * tą sytuacją, w której flex rozbija napis.
 *
 *   [svg, „ Usuń z zeszytu"]                 → 1 węzeł  → w porządku
 *   [„Zostań ", <span>, „ — to darmowe"]     → 2 węzły  → BŁĄD
 *   [<span class="btn-napis">…</span>]       → 0 węzłów → w porządku
 *
 * Przycisk z ikoną (`<x-ikona/>` + podpis) to POPRAWNE dwa elementy flex —
 * ikona ma stać obok podpisu, oddzielona `gap`. Ten test go nie łapie i nie
 * wolno takiego przycisku „naprawiać".
 *
 * DLACZEGO WYRENDEROWANY HTML, A NIE `grep` PO ŹRÓDŁACH
 * W blade'zie napisu nie widać: `<x-kuking-word/>` to jeden znacznik, który po
 * wyrenderowaniu jest trzema zagnieżdżonymi elementami, a `@if` rozcina tekst
 * tylko w niektórych gałęziach. Regeks po źródłach albo tego nie zobaczy, albo
 * zobaczy tam, gdzie tego nie ma. Liczymy na DOM-ie tego, co dostaje człowiek.
 *
 * CZEGO TEN TEST NIE OBIECUJE
 * Nie pilnuje CAŁEGO serwisu — sprawdza dokładnie te ekrany, które wymienia
 * `ekrany()`: stronę powitalną, logowanie i rejestrację. To są trzy ekrany,
 * na które człowiek trafia zanim ma konto, czyli te, na których rozbity
 * przycisk kosztuje najwięcej. Dokładasz nowy ekran z przyciskiem, w którym
 * element inline rozcina napis — dopisz go tutaj.
 */
class PrzyciskiNieRozbijajaNapisuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ekrany objęte strażnikiem — wymienione WPROST, nie odgadywane z tras.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function ekrany(): array
    {
        return [
            'strona powitalna' => ['strona powitalna', '/'],
            'logowanie' => ['logowanie', '/login'],
            'rejestracja' => ['rejestracja', '/register'],
        ];
    }

    #[DataProvider('ekrany')]
    public function test_zaden_przycisk_nie_ma_napisu_rozcietego_elementem_inline(string $nazwaEkranu, string $adres): void
    {
        $html = $this->get($adres)->assertOk()->getContent();

        $przyciski = $this->przyciskiZDokumentu((string) $html);

        $this->assertNotSame(
            [],
            $przyciski,
            "Ekran „{$nazwaEkranu}” ({$adres}) nie ma ani jednego elementu z klasą `btn` — ".
            'albo strażnik patrzy w złe miejsce, albo przyciski zniknęły z ekranu.',
        );

        $rozbite = [];

        foreach ($przyciski as $przycisk) {
            $wezly = $this->bezposrednieWezlyTekstowe($przycisk);

            if (count($wezly) >= 2) {
                $rozbite[] = sprintf(
                    '<%s class="%s"> → %d węzłów tekstowych: %s',
                    $przycisk->tagName,
                    $przycisk->getAttribute('class'),
                    count($wezly),
                    implode(' | ', array_map(static fn (string $t): string => '„'.$t.'”', $wezly)),
                );
            }
        }

        $this->assertSame(
            [],
            $rozbite,
            "Na ekranie „{$nazwaEkranu}” ({$adres}) przycisk ma napis rozcięty elementem inline. ".
            '`.btn` jest `inline-flex`, więc każdy taki kawałek zawija się osobno i łamie w środku wyrazu (issue #353). '.
            "Owiń CAŁY napis jednym `<span class=\"btn-napis\">`.\n".
            implode("\n", $rozbite),
        );
    }

    /**
     * Wszystkie elementy z klasą `btn` w dokumencie.
     *
     * Dopasowanie po CAŁEJ nazwie klasy (`concat` ze spacjami), a nie przez
     * `contains(@class, 'btn')` — inaczej złapałoby `btn-napis`, `btn-duzy`
     * i każdą inną klasę z tym przedrostkiem.
     *
     * @return list<DOMElement>
     */
    private function przyciskiZDokumentu(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        $xpath = new DOMXPath($dom);

        $znalezione = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' btn ')]");

        $przyciski = [];

        if ($znalezione !== false) {
            foreach ($znalezione as $element) {
                if ($element instanceof DOMElement) {
                    $przyciski[] = $element;
                }
            }
        }

        return $przyciski;
    }

    /**
     * Bezpośrednie dzieci będące tekstem innym niż same białe znaki.
     *
     * „Bezpośrednie" jest tu istotne: tekst schowany w `<span>` to nie jest
     * osobny element flex przycisku, tylko treść jednego z jego dzieci.
     *
     * @return list<string>
     */
    private function bezposrednieWezlyTekstowe(DOMElement $przycisk): array
    {
        $wezly = [];

        foreach ($przycisk->childNodes as $dziecko) {
            if (! $dziecko instanceof DOMText) {
                continue;
            }

            $tekst = trim(preg_replace('/\s+/u', ' ', $dziecko->textContent) ?? '');

            if ($tekst !== '') {
                $wezly[] = $tekst;
            }
        }

        return $wezly;
    }
}
