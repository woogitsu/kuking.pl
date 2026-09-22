<?php

declare(strict_types=1);

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * OBCHÓD EKRANÓW — jeden przyrząd, sześć rodzajów martwego przycisku.
 *
 * PO CO TO JEST
 * D-053 zabrania przycisku, który nic nie robi albo kończy się odmową.
 * Pilnuje tego `scripts/martwe-przyciski.mjs` — ale ten skrypt wymaga
 * postawionego serwera, przeglądarki i własnej bazy, więc NIE CHODZI
 * w `php artisan test` i nie broni gałęzi przed scaleniem. Ten obchód robi
 * to samo w zwykłym przebiegu testów: bierze ekran tak, jak dostaje go
 * człowiek, i pyta o każdy odnośnik oraz każdy przycisk.
 *
 * CZEGO SZUKA (sześć rodzajów, bo `href="#"` to tylko jeden z nich)
 *   1. odnośnik pod adres, który odpowiada 404 albo 500;
 *   2. odnośnik pod adres, który odpowiada 403 TEJ OSOBIE, która go widzi
 *      — przycisk, którego nie wolno kliknąć, nie ma prawa być pokazany;
 *   3. formularz bez `action` albo z `action` do nieistniejącej trasy;
 *   4. `<button>` poza formularzem i bez czegokolwiek, co by go obsłużyło;
 *   5. odnośnik z pustą albo samą białą nazwą dostępną;
 *   6. dwa różne cele pod tą samą nazwą dostępną na jednym ekranie.
 *
 * CZEGO NIE ROBI I DLACZEGO
 * Nie wysyła formularzy zapisujących. Pytanie „czy ten przycisk ma dokąd
 * prowadzić" rozstrzyga tablica tras — dokładnie jak w skrypcie (granica 1).
 * Wysłanie `POST /wpisy/{p}/usun` kasowałoby dane, żeby dowiedzieć się rzeczy,
 * którą tablica tras mówi bez jednego zapisu.
 */
trait ObchodEkranow
{
    /** Ile ekranów obchód faktycznie otworzył (pułapka 2 — bez tego zero ekranów to sukces). */
    protected int $obchodEkranow = 0;

    /** Ile odnośników i przycisków sprawdził. */
    protected int $obchodOdnosnikow = 0;

    /** Ile formularzy sprawdził. */
    protected int $obchodFormularzy = 0;

    /** Ile przycisków `<button>` obejrzał pod kątem tego, czy cokolwiek je obsługuje. */
    protected int $obchodPrzyciskow = 0;

    /** Ile nazw dostępnych zebrał do sprawdzenia powtórzeń. */
    protected int $obchodNazw = 0;

    /** @var list<string> Znalezione usterki, każda z nazwą ekranu i napisem z przycisku. */
    protected array $obchodUsterki = [];

    /**
     * Odwiedza ekran i sprawdza na nim wszystkie sześć rodzajów.
     *
     * `$persona` trafia do komunikatu, bo ten sam ekran pokazuje co innego
     * gościowi, zalogowanemu i moderatorowi — a 403 pod odnośnikiem jest
     * usterką WŁAŚNIE dlatego, że zobaczyła go konkretna osoba.
     *
     * @param  array<int, int>  $dopuszczalneKody  kody, na których sam ekran wolno zastać
     */
    protected function obejdzEkran(string $adres, string $persona, array $dopuszczalneKody = [200]): void
    {
        $odpowiedz = $this->get($adres);
        $kod = $odpowiedz->getStatusCode();

        if (! in_array($kod, $dopuszczalneKody, true)) {
            $this->obchodUsterki[] = "EKRAN {$kod} ({$persona}): {$adres} — sam ekran obchodu nie wstał, "
                .'więc żaden jego przycisk nie został sprawdzony. Brak wyniku to nie jest sukces (pułapka 5).';

            return;
        }

        $this->obchodEkranow++;

        $html = $odpowiedz->getContent() ?: '';
        $dokument = new DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dokument);

        $this->sprawdzOdnosniki($xpath, $adres, $persona);
        $this->sprawdzFormularze($xpath, $adres, $persona);
        $this->sprawdzPrzyciskiBezObslugi($xpath, $adres, $persona);
        $this->sprawdzPowtorzoneNazwy($xpath, $adres, $persona);
    }

    /**
     * ZAMKNIĘCIE OBCHODU — i jedyne miejsce, w którym ten przyrząd mówi
     * „przeszło".
     *
     * PROGI MINIMALNE SĄ OBOWIĄZKOWE (docs/PULAPKI_TESTOW.md, pułapka 2).
     * Obchód, który odwiedził zero ekranów albo nie znalazł ani jednego
     * odnośnika, wygląda dokładnie tak samo jak obchód, który sprawdził
     * wszystko i wszystko było w porządku: w obu razach lista usterek jest
     * pusta. Bez tych czterech asercji przeniesienie widoku, zmiana układu
     * albo literówka w zapytaniu XPath wyłączyłyby ten test bez jednego
     * czerwonego przebiegu.
     *
     * Progi są ustawione TROCHĘ NIŻEJ od liczb zmierzonych, żeby zwykła
     * zmiana w interfejsie ich nie zapaliła — mają łapać skan, który
     * przestał chodzić, a nie ekran, z którego zniknął jeden przycisk.
     */
    protected function zakonczObchod(
        int $minEkranow,
        int $minOdnosnikow,
        int $minFormularzy,
        int $minPrzyciskow,
    ): void {
        $this->assertGreaterThanOrEqual($minEkranow, $this->obchodEkranow,
            "Obchód otworzył tylko {$this->obchodEkranow} ekranów, a miał co najmniej {$minEkranow}. "
            .'Skan nie chodzi po serwisie — a pusta lista usterek znaczy wtedy „nic nie zmierzyłem", '
            .'nie „wszystko w porządku" (pułapka 2).');

        $this->assertGreaterThanOrEqual($minOdnosnikow, $this->obchodOdnosnikow,
            "Obchód wszedł tylko w {$this->obchodOdnosnikow} odnośników, a miał w co najmniej {$minOdnosnikow}. "
            .'Wyrażenie przestało je łapać albo ekrany renderują się puste.');

        $this->assertGreaterThanOrEqual($minFormularzy, $this->obchodFormularzy,
            "Obchód sprawdził tylko {$this->obchodFormularzy} formularzy, a miał co najmniej {$minFormularzy}.");

        $this->assertGreaterThanOrEqual($minPrzyciskow, $this->obchodPrzyciskow,
            "Obchód obejrzał tylko {$this->obchodPrzyciskow} przycisków, a miał co najmniej {$minPrzyciskow}.");

        $this->assertSame([], $this->obchodUsterki,
            'Obchód znalazł '.count($this->obchodUsterki).' martwych przycisków '
            ."(ekranów: {$this->obchodEkranow}, odnośników: {$this->obchodOdnosnikow}, "
            ."formularzy: {$this->obchodFormularzy}, przycisków: {$this->obchodPrzyciskow}):\n  • "
            .implode("\n  • ", $this->obchodUsterki)
            ."\n\nKażda z tych rzeczy to przycisk, który po kliknięciu nic nie robi albo kończy się "
            .'odmową (AGENTS.md §5, D-053). Jeśli któraś ma być nieaktywna celowo — ma być WIDOCZNIE '
            .'nieaktywna (`disabled`) i mieć w kodzie napisane, dlaczego.');
    }

    // ---------------------------------------------------------------------
    // 1, 2 i 5: odnośniki
    // ---------------------------------------------------------------------

    private function sprawdzOdnosniki(DOMXPath $xpath, string $ekran, string $persona): void
    {
        /** @var list<string> $juzSprawdzone */
        $juzSprawdzone = [];

        /*
         * Identyfikatory zbieramy RAZ, zamiast pytać XPath przy każdej kotwicy.
         * Nie chodzi o szybkość: identyfikator bywa wpisany z ręki i potrafi
         * zawierać apostrof albo cudzysłów, a wtedy sklejenie go w wyrażenie
         * XPath tworzy zapytanie, które albo nie wykonuje się wcale, albo
         * odpowiada na inne pytanie. Zbiór w PHP nie ma tego problemu.
         */
        $identyfikatory = [];

        foreach ($xpath->query('//*[@id]') ?: [] as $zId) {
            if ($zId instanceof DOMElement) {
                $identyfikatory[$zId->getAttribute('id')] = true;
            }
        }

        foreach ($xpath->query('//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement || $this->schowany($a)) {
                continue;
            }

            $href = html_entity_decode(trim((string) $a->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $nazwa = $this->nazwaDostepna($a);

            // 5. Odnośnik bez nazwy dostępnej jest przyciskiem, o którym
            //    człowiek nie wie ani czym jest, ani dokąd prowadzi.
            if ($nazwa === '') {
                $this->obchodUsterki[] = "ODNOŚNIK BEZ NAPISU ({$persona}) na {$ekran}: href=\"{$href}\" "
                    .'nie ma ani tekstu, ani `aria-label`, ani `alt` w środku.';
            }

            if ($href === '' || $href === '#') {
                $this->obchodUsterki[] = "ODNOŚNIK DONIKĄD ({$persona}) na {$ekran}: „{$nazwa}\" ma href=\"{$href}\".";

                continue;
            }

            if (str_starts_with($href, '#')) {
                $cel = rawurldecode(substr($href, 1));

                if (! isset($identyfikatory[$cel])) {
                    $this->obchodUsterki[] = "KOTWICA BEZ CELU ({$persona}) na {$ekran}: „{$nazwa}\" "
                        ."prowadzi na #{$cel}, a takiego identyfikatora na tym ekranie nie ma.";
                }

                continue;
            }

            $sciezka = $this->naszaSciezka($href);

            if ($sciezka === null) {
                continue;
            }

            if (in_array($sciezka, $juzSprawdzone, true)) {
                continue;
            }

            $juzSprawdzone[] = $sciezka;
            $this->obchodOdnosnikow++;

            $kod = $this->kodPod($sciezka);

            if ($kod === 403) {
                $this->obchodUsterki[] = "403 ({$persona}) na {$ekran}: „{$nazwa}\" prowadzi na {$sciezka}, "
                    .'a ta osoba dostaje tam odmowę. Przycisk, którego nie wolno kliknąć, nie ma prawa '
                    .'być pokazany (AGENTS.md §5, D-053).';

                continue;
            }

            if ($kod >= 400) {
                $this->obchodUsterki[] = "{$kod} ({$persona}) na {$ekran}: „{$nazwa}\" prowadzi na {$sciezka}. "
                    .'To jest martwy przycisk (AGENTS.md §5, D-053).';
            }
        }
    }

    // ---------------------------------------------------------------------
    // 3: formularze
    // ---------------------------------------------------------------------

    private function sprawdzFormularze(DOMXPath $xpath, string $ekran, string $persona): void
    {
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (! $form instanceof DOMElement || $this->schowany($form)) {
                continue;
            }

            $metodaAtrybutu = strtoupper(trim((string) $form->getAttribute('method')) ?: 'GET');

            // `<form method="dialog">` nie wysyła do serwera ani jednego bajta
            // — to natywne zamknięcie `<dialog>`. Pytanie o trasę nie ma tu sensu.
            if ($metodaAtrybutu === 'DIALOG') {
                continue;
            }

            $przycisk = ($xpath->query('.//button|.//input[@type="submit"]', $form) ?: null)?->item(0);
            $napis = $przycisk instanceof DOMElement ? $this->nazwaDostepna($przycisk) : '(bez przycisku)';

            $podmiana = ($xpath->query('.//input[@name="_method"]', $form) ?: null)?->item(0);
            $metoda = $podmiana instanceof DOMElement
                ? strtoupper((string) $podmiana->getAttribute('value'))
                : $metodaAtrybutu;

            $action = html_entity_decode(trim((string) $form->getAttribute('action')), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // Formularz bez `action` wysyła pod bieżący adres. Dla GET (szukajka)
            // to zachowanie poprawne; dla metody zapisującej — prawie zawsze
            // przeoczenie, bo bieżący adres rzadko przyjmuje POST.
            $sciezka = $action === ''
                ? (string) parse_url($ekran, PHP_URL_PATH)
                : $this->naszaSciezka($action);

            if ($sciezka === null) {
                continue;
            }

            $this->obchodFormularzy++;

            if (! $this->trasaIstnieje($metoda, $sciezka)) {
                $puste = $action === '' ? ' (formularz NIE MA atrybutu `action`, więc celuje w sam ekran)' : '';

                $this->obchodUsterki[] = "FORMULARZ BEZ TRASY ({$persona}) na {$ekran}: przycisk „{$napis}\" "
                    ."wysyła {$metoda} {$sciezka}{$puste}, a takiej trasy nie ma. Po kliknięciu człowiek "
                    .'dostanie 404 albo 405.';
            }
        }
    }

    // ---------------------------------------------------------------------
    // 4: przyciski bez formularza i bez skryptu
    // ---------------------------------------------------------------------

    /**
     * Atrybuty, które NAPRAWDĘ obsługują przycisk w tym repozytorium.
     *
     * Lista jest zamknięta i wypisana z nazwy, bo „ma jakiś atrybut zaczynający
     * się od data-" przepuściłoby każdy martwy przycisk z klasą pomocniczą.
     */
    private const OBSLUGA_PRZYCISKU = [
        'onclick', 'wire:click', 'wire:confirm', 'x-on:click', '@click', 'x-data',
        'popovertarget', 'commandfor', 'form', 'data-cel', 'aria-controls',
    ];

    private function sprawdzPrzyciskiBezObslugi(DOMXPath $xpath, string $ekran, string $persona): void
    {
        foreach ($xpath->query('//button') ?: [] as $przycisk) {
            if (! $przycisk instanceof DOMElement || $this->schowany($przycisk)) {
                continue;
            }

            $this->obchodPrzyciskow++;

            // Wyłączony przycisk jest widocznie nieaktywny — to jest odpowiedź
            // na „celowo nieaktywny", a nie martwy przycisk.
            if ($przycisk->hasAttribute('disabled')) {
                continue;
            }

            if ($this->wSrodku($przycisk, 'form')) {
                continue;
            }

            foreach (self::OBSLUGA_PRZYCISKU as $atrybut) {
                if ($przycisk->hasAttribute($atrybut)) {
                    continue 2;
                }
            }

            $nazwa = $this->nazwaDostepna($przycisk);

            $this->obchodUsterki[] = "PRZYCISK BEZ OBSŁUGI ({$persona}) na {$ekran}: „{$nazwa}\" nie stoi "
                .'w żadnym formularzu i nie ma ani jednego atrybutu, który by go obsłużył. Kliknięcie '
                .'nic nie robi.';
        }
    }

    // ---------------------------------------------------------------------
    // 6: ta sama nazwa, dwa różne cele
    // ---------------------------------------------------------------------

    /**
     * Znaczniki, które NADAJĄ PRZYCISKOWI KONTEKST.
     *
     * Dziesięć kart wpisu na tablicy ma dziesięć przycisków „Otwórz wpis"
     * i dziesięć różnych celów — i to jest w porządku, bo każdy stoi we
     * WŁASNEJ karcie, razem ze zdjęciem, nazwiskiem i datą tego wpisu.
     * Ta sama nazwa dwa razy w JEDNEJ karcie, w jednym pasku albo w jednym
     * formularzu kontekstu już nie ma: człowiek widzi dwa identyczne napisy
     * obok siebie i musi zgadywać.
     *
     * Bez tego rozróżnienia sprawdzenie meldowałoby każdą listę w serwisie
     * i zrobiłoby się szumem, który się wycisza — zmierzone: 41 meldunków,
     * z czego 41 z list.
     */
    private const KONTEKST_PRZYCISKU = ['li', 'article', 'tr', 'form', 'nav', 'section', 'aside', 'header', 'footer', 'details', 'main'];

    private function sprawdzPowtorzoneNazwy(DOMXPath $xpath, string $ekran, string $persona): void
    {
        /** @var array<string, array<string, list<string>>> $cele */
        $cele = [];

        foreach ($xpath->query('//a[@href]|//button') ?: [] as $element) {
            if (! $element instanceof DOMElement || $this->schowany($element)) {
                continue;
            }

            $nazwa = mb_strtolower($this->nazwaDostepna($element));

            if ($nazwa === '') {
                continue;
            }

            $cel = $element->tagName === 'a'
                ? html_entity_decode(trim((string) $element->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                : 'przycisk:'.$element->getAttribute('name').'='.$element->getAttribute('value')
                    .$element->getAttribute('wire:click').$element->getAttribute('formaction');

            $this->obchodNazw++;
            $cele[$this->kontekst($element)][$nazwa][] = $cel;
        }

        foreach ($cele as $gdzie => $wKontekscie) {
            foreach ($wKontekscie as $nazwa => $lista) {
                $rozne = array_values(array_unique($lista));

                if (count($rozne) > 1) {
                    $this->obchodUsterki[] = "DWA RÓŻNE CELE POD TĄ SAMĄ NAZWĄ ({$persona}) na {$ekran}: "
                        ."w jednym <{$gdzie}> stoi „{$nazwa}\" prowadzące na ".implode(' oraz na ', $rozne)
                        .'. Człowiek czytający samą nazwę nie wie, który jest który.';
                }
            }
        }
    }

    /**
     * Nazwa najbliższego kontenera nadającego kontekst, razem z jego
     * położeniem w dokumencie — żeby dwie różne karty nie zlały się w jedną.
     */
    private function kontekst(DOMElement $element): string
    {
        for ($w = $element->parentNode; $w instanceof DOMElement; $w = $w->parentNode) {
            if (in_array($w->tagName, self::KONTEKST_PRZYCISKU, true)) {
                return $w->tagName.'#'.$w->getNodePath();
            }
        }

        return 'dokument';
    }

    // ---------------------------------------------------------------------
    // Narzędzia
    // ---------------------------------------------------------------------

    /**
     * Nazwa dostępna elementu: to, co przeczyta człowiek ALBO czytnik ekranu.
     *
     * Kolejność jak w specyfikacji: `aria-label` wygrywa z treścią, a treść
     * z `title`. Do treści wliczamy `alt` zagnieżdżonych obrazków i `<title>`
     * w środku `<svg>` — inaczej każdy odnośnik z samą ikoną wyszedłby jako
     * „bez napisu", choć czytnik go nazywa.
     */
    private function nazwaDostepna(DOMElement $element): string
    {
        $etykieta = trim((string) $element->getAttribute('aria-label'));

        if ($etykieta !== '') {
            return $this->jednaLinia($etykieta);
        }

        $tekst = (string) $element->textContent;

        $dokument = $element->ownerDocument;

        if ($dokument !== null) {
            foreach ((new DOMXPath($dokument))->query('.//img[@alt]|.//title', $element) ?: [] as $wewnetrzny) {
                $tekst .= ' '.($wewnetrzny instanceof DOMElement && $wewnetrzny->tagName === 'img'
                    ? $wewnetrzny->getAttribute('alt')
                    : $wewnetrzny->textContent);
            }
        }

        $tekst = $this->jednaLinia($tekst);

        return $tekst !== '' ? $tekst : $this->jednaLinia((string) $element->getAttribute('title'));
    }

    private function jednaLinia(string $tekst): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $tekst));
    }

    /**
     * Element schowany przed wszystkimi — nie jest przyciskiem, bo nikt go nie widzi.
     *
     * `aria-hidden` NIE jest tu powodem do pominięcia: element z `aria-hidden`
     * dalej widzi oko. Pomijamy tylko to, czego nie widzi nikt.
     */
    private function schowany(DOMElement $element): bool
    {
        for ($w = $element; $w instanceof DOMElement; $w = $w->parentNode) {
            if ($w->hasAttribute('hidden')) {
                return true;
            }

            if (str_contains(str_replace(' ', '', (string) $w->getAttribute('style')), 'display:none')) {
                return true;
            }
        }

        return false;
    }

    private function wSrodku(DOMElement $element, string $znacznik): bool
    {
        for ($w = $element->parentNode; $w instanceof DOMElement; $w = $w->parentNode) {
            if ($w->tagName === $znacznik) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ścieżka razem z zapytaniem, gdy adres należy do tego serwisu; `null`,
     * gdy prowadzi na cudzy serwer, do poczty albo do telefonu.
     */
    private function naszaSciezka(string $href): ?string
    {
        if (str_starts_with($href, '//')) {
            return null;
        }

        if (str_starts_with($href, '/')) {
            return $href;
        }

        $czesci = parse_url($href);

        if (! is_array($czesci) || ($czesci['host'] ?? null) !== parse_url(url('/'), PHP_URL_HOST)) {
            return null;
        }

        return ($czesci['path'] ?? '/').(isset($czesci['query']) ? '?'.$czesci['query'] : '');
    }

    /** Kod odpowiedzi pod adresem, z przejściem przekierowań. */
    private function kodPod(string $sciezka): int
    {
        return $this->followingRedirects()->get($sciezka)->getStatusCode();
    }

    /** Czy tablica tras ma tę parę (metoda, ścieżka) — bez wysyłania zapisu. */
    private function trasaIstnieje(string $metoda, string $sciezka): bool
    {
        try {
            Route::getRoutes()->match(Request::create($sciezka, $metoda));

            return true;
        } catch (NotFoundHttpException|MethodNotAllowedHttpException|UrlGenerationException) {
            return false;
        }
    }
}
