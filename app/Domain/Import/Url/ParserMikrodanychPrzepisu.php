<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\OdczytanyPrzepis;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Odczyt przepisu z mikrodanych schema.org `Recipe` (`itemscope`,
 * `itemtype`, `itemprop`) — LOKALNIE, bez modelu i bez kosztu (#28).
 *
 * To druga droga po `ParserJsonLdPrzepisu`: starsze blogi i motywy nie mają
 * JSON-LD, ale mają mikrodane. Wynik ma ten sam kształt (`OdczytanyPrzepis`)
 * i trafia do tego samego prywatnego szkicu.
 *
 * Zasady:
 *  - właściwości przepisu to WYŁĄCZNIE te `itemprop`, które nie leżą wewnątrz
 *    zagnieżdżonego `itemscope` — autor (`Person`), ocena (`AggregateRating`),
 *    wartości odżywcze (`NutritionInformation`) i ich `name` nie mieszają się
 *    z polami przepisu. Wyjątek: kroki (`recipeInstructions`) w `HowToStep`,
 *    `HowToSection` i `ItemList`;
 *  - `image`, `video`, `author`, `review`, `nutrition` — jak w JSON-LD:
 *    nie czytamy i nie pobieramy niczego (zdjęć z cudzych stron nie importujemy);
 *  - nic się nie pobiera: `itemref`, adresy i `<img>` są ignorowane;
 *  - limity: rozmiar HTML, liczba odwiedzanych węzłów i liczba pozycji.
 */
final class ParserMikrodanychPrzepisu
{
    public const MAKS_BAJTOW = 2_000_000;

    private const MAKS_WEZLOW = 20_000;

    private const MAKS_POZYCJI = 300;

    private const MAKS_GLEBOKOSC = 8;

    /** Elementy, które nigdy nie niosą tekstu przepisu. */
    private const POMIJANE = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'img', 'picture', 'video', 'audio', 'form', 'button', 'select'];

    private const BLOKOWE = ['br', 'p', 'li', 'div', 'tr', 'ul', 'ol', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private int $odwiedzone = 0;

    public function odczytaj(string $html): ?OdczytanyPrzepis
    {
        $html = (string) preg_replace('/^\xEF\xBB\xBF/', '', $html);

        if (trim($html) === '' || strlen($html) > self::MAKS_BAJTOW || ! mb_check_encoding($html, 'UTF-8')) {
            return null;
        }

        // Tani próg przed parsowaniem: bez tych słów nie ma czego szukać.
        if (stripos($html, 'itemscope') === false || stripos($html, 'Recipe') === false) {
            return null;
        }

        // Treść jest już UTF-8 (PobieraczStron ją przekodował). Deklaracja
        // `<meta ... charset>` z cudzej strony bywa błędna, a libxml by jej
        // posłuchał mimo `<?xml encoding>` i zamieniłby polskie znaki w krzaki.
        $html = (string) preg_replace('/<meta\b[^>]*\bcharset\b[^>]*>/i', '', $html);

        $dokument = new DOMDocument;
        $poprzedni = libxml_use_internal_errors(true);

        try {
            $wczytano = $dokument->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($poprzedni);
        }

        if (! $wczytano) {
            return null;
        }

        $this->odwiedzone = 0;

        foreach ((new DOMXPath($dokument))->query('//*[@itemscope][@itemtype]') ?: [] as $wezel) {
            if (! $wezel instanceof DOMElement || ! $this->maTyp($wezel, ['Recipe'])) {
                continue;
            }

            $przepis = $this->zWezla($wezel);

            if ($przepis !== null) {
                return $przepis;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $typy
     */
    private function maTyp(DOMElement $wezel, array $typy): bool
    {
        $typy = array_map('strtolower', $typy);

        foreach (preg_split('/\s+/', trim($wezel->getAttribute('itemtype'))) ?: [] as $typ) {
            if (preg_match('#^https?://(?:www\.)?schema\.org/(\w+)$#i', $typ, $m) === 1
                && in_array(strtolower($m[1]), $typy, true)) {
                return true;
            }
        }

        return false;
    }

    private function zWezla(DOMElement $wezel): ?OdczytanyPrzepis
    {
        $wlasciwosci = $this->wlasciwosci($wezel);

        $skladniki = [];

        foreach ($this->elementyAliasow($wlasciwosci, 'recipeingredient', 'ingredients') as $el) {
            $tekst = $this->jednaLinia($this->wartosc($el));

            if ($tekst !== '') {
                $skladniki[] = $tekst;
            }

            if (count($skladniki) >= self::MAKS_POZYCJI) {
                break;
            }
        }

        $kroki = [];

        foreach ($wlasciwosci['recipeinstructions'] ?? [] as $el) {
            array_push($kroki, ...$this->kroki($el, 0));

            if (count($kroki) >= self::MAKS_POZYCJI) {
                $kroki = array_slice($kroki, 0, self::MAKS_POZYCJI);

                break;
            }
        }

        if ($skladniki === [] && $kroki === []) {
            return null;
        }

        $tytul = $this->pierwszy($wlasciwosci, 'name') ?? $this->pierwszy($wlasciwosci, 'headline') ?? '';
        $czasPrzygotowania = $this->pierwszy($wlasciwosci, 'preptime');
        $czasGotowania = $this->pierwszy($wlasciwosci, 'cooktime');
        $przygotowanieMinut = ParserJsonLdPrzepisu::minuty($czasPrzygotowania);
        $gotowanieMinut = ParserJsonLdPrzepisu::minuty($czasGotowania);
        $ostrzezenia = [];
        if (ParserJsonLdPrzepisu::odrzuconyCzas($czasPrzygotowania, $przygotowanieMinut)) {
            $ostrzezenia['przygotowanie'] = true;
        }
        if (ParserJsonLdPrzepisu::odrzuconyCzas($czasGotowania, $gotowanieMinut)) {
            $ostrzezenia['gotowanie'] = true;
        }

        return new OdczytanyPrzepis(
            tytul: $tytul !== '' ? $tytul : 'Przepis ze strony',
            opis: $this->pierwszy($wlasciwosci, 'description'),
            porcje: ParserJsonLdPrzepisu::porcje($this->pierwszy($wlasciwosci, 'recipeyield')),
            przygotowanieMinut: $przygotowanieMinut,
            gotowanieMinut: $gotowanieMinut,
            lacznieMinut: ParserJsonLdPrzepisu::minuty($this->pierwszy($wlasciwosci, 'totaltime')),
            skladniki: $skladniki,
            kroki: $kroki,
            ostrzezeniaParsera: $ostrzezenia,
        );
    }

    /**
     * @param  array<string, list<DOMElement>>  $wlasciwosci
     */
    private function pierwszy(array $wlasciwosci, string $nazwa): ?string
    {
        foreach ($wlasciwosci[$nazwa] ?? [] as $el) {
            $tekst = $this->jednaLinia($this->wartosc($el));

            if ($tekst !== '') {
                return $tekst;
            }
        }

        return null;
    }

    /**
     * Właściwości danego zakresu (`itemscope`): potomkowie z `itemprop`, do
     * których nie da się dojść przez inny `itemscope`. Klucze małymi literami.
     *
     * @return array<string, list<DOMElement>>
     */
    private function wlasciwosci(DOMElement $zakres): array
    {
        $wynik = [];
        $this->zbierz($zakres, $wynik, 0);

        return $wynik;
    }

    /**
     * Jeden element może mieć kilka nazw `itemprop`. Łączymy aliasy według
     * tożsamości węzła, nie według tekstu, i zachowujemy kolejność dokumentu.
     *
     * @param  array<string, list<DOMElement>>  $wlasciwosci
     * @return list<DOMElement>
     */
    private function elementyAliasow(array $wlasciwosci, string $pierwszy, string $drugi): array
    {
        $unikalne = [];

        foreach ([$pierwszy, $drugi] as $nazwa) {
            foreach ($wlasciwosci[$nazwa] ?? [] as $element) {
                $unikalne[spl_object_id($element)] = $element;
            }
        }

        $elementy = array_values($unikalne);
        usort($elementy, static function (DOMElement $a, DOMElement $b): int {
            $relacja = $a->compareDocumentPosition($b);

            return match (true) {
                ($relacja & DOMNode::DOCUMENT_POSITION_FOLLOWING) !== 0 => -1,
                ($relacja & DOMNode::DOCUMENT_POSITION_PRECEDING) !== 0 => 1,
                default => 0,
            };
        });

        return $elementy;
    }

    /**
     * @param  array<string, list<DOMElement>>  $wynik
     */
    private function zbierz(DOMNode $rodzic, array &$wynik, int $glebokosc): void
    {
        if ($glebokosc > 64) {
            return;
        }

        foreach ($rodzic->childNodes as $dziecko) {
            if (! $dziecko instanceof DOMElement || ++$this->odwiedzone > self::MAKS_WEZLOW) {
                continue;
            }

            if (in_array(strtolower($dziecko->nodeName), self::POMIJANE, true)) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($dziecko->getAttribute('itemprop'))) ?: [] as $nazwa) {
                if ($nazwa !== '') {
                    $wynik[strtolower($nazwa)][] = $dziecko;
                }
            }

            // Zagnieżdżony zakres to osobny obiekt — jego pól tu nie zbieramy.
            if (! $dziecko->hasAttribute('itemscope')) {
                $this->zbierz($dziecko, $wynik, $glebokosc + 1);
            }
        }
    }

    /**
     * Kroki z `recipeInstructions`: tekst, lista wierszy, `HowToStep`,
     * `HowToSection`/`ItemList` z `itemListElement`.
     *
     * @return list<string>
     */
    private function kroki(DOMElement $el, int $glebokosc): array
    {
        if ($glebokosc > self::MAKS_GLEBOKOSC) {
            return [];
        }

        if ($el->hasAttribute('itemscope')) {
            $wlasciwosci = $this->wlasciwosci($el);
            $wynik = [];

            // ListItem opisuje pozycję listy, a instrukcję niesie jego item.
            // Sama etykieta „Krok 1” lub zewnętrzny URL nie jest instrukcją.
            if ($this->maTyp($el, ['ListItem'])) {
                foreach ($this->elementyAliasow($wlasciwosci, 'item', 'item') as $item) {
                    if ($item->hasAttribute('itemscope')
                        && $this->maTyp($item, ['HowToStep', 'HowToDirection', 'HowToSection', 'ItemList', 'ListItem'])) {
                        array_push($wynik, ...$this->kroki($item, $glebokosc + 1));
                    }
                }

                return array_slice($wynik, 0, self::MAKS_POZYCJI);
            }

            foreach ($this->elementyAliasow($wlasciwosci, 'itemlistelement', 'step') as $dziecko) {
                array_push($wynik, ...$this->kroki($dziecko, $glebokosc + 1));
            }

            if ($wynik !== []) {
                return $wynik;
            }

            foreach (['text', 'name'] as $nazwa) {
                foreach ($wlasciwosci[$nazwa] ?? [] as $pole) {
                    array_push($wynik, ...$this->wiersze($this->wartosc($pole)));
                }

                if ($wynik !== []) {
                    return $wynik;
                }
            }

            return $this->wiersze($this->tekstElementu($el));
        }

        // Kontener bez własnego typu, w którym każdy krok jest osobnym zakresem
        // (`<ol itemprop="recipeInstructions"><li itemscope itemtype=HowToStep>`).
        $podkroki = $this->podkroki($el, 0);

        if ($podkroki !== []) {
            $wynik = [];

            foreach ($podkroki as $podkrok) {
                array_push($wynik, ...$this->kroki($podkrok, $glebokosc + 1));
            }

            return $wynik;
        }

        return $this->wiersze($this->wartosc($el));
    }

    /**
     * Najwyżej położone potomki z `itemscope` typu kroku albo listy kroków.
     *
     * @return list<DOMElement>
     */
    private function podkroki(DOMNode $rodzic, int $glebokosc): array
    {
        if ($glebokosc > 16) {
            return [];
        }

        $wynik = [];

        foreach ($rodzic->childNodes as $dziecko) {
            if (! $dziecko instanceof DOMElement || ++$this->odwiedzone > self::MAKS_WEZLOW) {
                continue;
            }

            if ($dziecko->hasAttribute('itemscope') && $this->maTyp($dziecko, ['HowToStep', 'HowToSection', 'HowToDirection', 'ItemList', 'ListItem'])) {
                $wynik[] = $dziecko;

                continue;
            }

            array_push($wynik, ...$this->podkroki($dziecko, $glebokosc + 1));
        }

        return $wynik;
    }

    /** Wartość właściwości wg reguł mikrodanych (meta, time, data), w przeciwnym razie tekst. */
    private function wartosc(DOMElement $el): string
    {
        $nazwa = strtolower($el->nodeName);

        if ($nazwa === 'meta' && $el->hasAttribute('content')) {
            return $el->getAttribute('content');
        }

        if ($nazwa === 'time' && $el->hasAttribute('datetime')) {
            return $el->getAttribute('datetime');
        }

        if (($nazwa === 'data' || $nazwa === 'meter') && $el->hasAttribute('value')) {
            return $el->getAttribute('value');
        }

        return $this->tekstElementu($el);
    }

    /** Tekst elementu z podziałem na wiersze w miejscach bloków (`<br>`, `<p>`, `<li>`). */
    private function tekstElementu(DOMNode $el): string
    {
        $tekst = '';
        $this->dopisz($el, $tekst, 0);

        return $tekst;
    }

    private function dopisz(DOMNode $wezel, string &$tekst, int $glebokosc): void
    {
        if ($glebokosc > 64 || strlen($tekst) > 200_000) {
            return;
        }

        foreach ($wezel->childNodes as $dziecko) {
            if ($dziecko instanceof DOMElement) {
                $nazwa = strtolower($dziecko->nodeName);

                if (in_array($nazwa, self::POMIJANE, true) || ++$this->odwiedzone > self::MAKS_WEZLOW) {
                    continue;
                }

                $blok = in_array($nazwa, self::BLOKOWE, true);
                $tekst .= $blok ? "\n" : '';
                $this->dopisz($dziecko, $tekst, $glebokosc + 1);
                $tekst .= $blok ? "\n" : '';
            } elseif ($dziecko->nodeType === XML_TEXT_NODE || $dziecko->nodeType === XML_CDATA_SECTION_NODE) {
                $tekst .= $dziecko->nodeValue ?? '';
            }
        }
    }

    /**
     * @return list<string>
     */
    private function wiersze(string $tekst): array
    {
        $wynik = [];

        foreach (preg_split('/\R/u', $tekst) ?: [] as $wiersz) {
            $wiersz = $this->jednaLinia($wiersz);

            if ($wiersz !== '') {
                $wynik[] = $wiersz;
            }
        }

        return $wynik;
    }

    private function jednaLinia(string $tekst): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $tekst));
    }
}
