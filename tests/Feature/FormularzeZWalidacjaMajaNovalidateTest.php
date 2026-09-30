<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „NOVALIDATE WSZĘDZIE” (D-333, decyzja właściciela z 30.09.2026).
 *
 * PROBLEM. Pole z `required`, `type="email"`, `min`/`max`, `pattern` itp.
 * bez `novalidate` na formularzu zatrzymuje wysłanie W PRZEGLĄDARCE: dymek
 * w języku przeglądarki, o jednym polu naraz, znikający po chwili — a do
 * serwera nic nie idzie, więc `x-error-summary` na górze i polskie błędy
 * przy polach, mówiące co zrobić, nigdy się nie pokazują (#2243 na
 * rejestracji). `required` na polach ZOSTAJE: to ono mówi czytnikowi ekranu
 * „wymagane” i idzie w parze z „(wymagane)” przy etykiecie.
 *
 * DWA SPOJRZENIA, BO KAŻDE WIDZI CO INNEGO.
 *
 *  1. Wyrenderowany HTML tras — to, co dostaje przeglądarka, z polami
 *     z `x-field` rozwiniętymi do `<input required>`. Obejmuje ekrany gościa
 *     (w tym te z Turnstile) i ustawienia zalogowanej osoby.
 *  2. Szablony — WSZYSTKIE `<form>` w `resources/views`, także te, na które
 *     trzeba by zbudować stan (panel moderacji, wątek komentarzy, odwołanie
 *     po decyzji). Pola z komponentów (`<x-…>`) i `@include` są rozwijane
 *     rekurencyjnie; `x-field` liczy się po atrybutach wywołania.
 *
 * WYJĄTKI: brak. Formularz, który naprawdę potrzebuje dymka przeglądarki,
 * dopisuje się do `wyjatki()` z uzasadnieniem — i z decyzją właściciela,
 * bo zmienia regułę z D-333.
 *
 * Kontrola ujemna: `scripts/kontrole-negatywne-alfa08.py` zdejmuje
 * `novalidate` z formularza logowania i z formularza w komponencie.
 */
class FormularzeZWalidacjaMajaNovalidateTest extends TestCase
{
    use RefreshDatabase;

    private const KATALOG = 'resources/views';

    /**
     * Świadome wyjątki: `ścieżka widoku => uzasadnienie`. Pusta lista znaczy,
     * że każdy formularz z natywną walidacją ma `novalidate`.
     *
     * Metoda, nie stała: pusta stała ma dla analizy typ `array{}`, a warunek
     * z nią byłby „zawsze fałszywy” — lista jest jednak po to, żeby rosła.
     *
     * @return array<string, string>
     */
    private static function wyjatki(): array
    {
        return [];
    }

    /** Atrybuty, przy których przeglądarka sama zatrzymuje wysłanie. */
    private const ATRYBUT = '/(?<![\w-]):?(?:required|pattern|minlength|min|max|step)(?=[\s=>\/])|(?<![\w-])@required\b|\btype="(?:email|url|number|date|time|datetime-local|month|week)"/';

    /** Znacznik z atrybutami, także z `>` w cudzysłowie. */
    private const ZNACZNIK = '/<(input|textarea|select|x-[\w.\-:]+)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s';

    private const FORMULARZ = '/<form\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s';

    /** @var array<string, string|null> */
    private array $pamiec = [];

    // ---------------------------------------------------------------------
    // 1. Wyrenderowany HTML
    // ---------------------------------------------------------------------

    public function test_formularze_z_natywna_walidacja_na_ekranach_maja_novalidate(): void
    {
        config([
            'kuking.login_link.wlaczone' => true,
            'kuking.questions.enabled' => true,
        ]);

        $gosc = [
            route('login'), route('register'), route('password.request'), route('login.link'),
            route('kontakt'), route('zglos.nielegalna'), route('appeals.guest'), route('account.delete.cancel'),
        ];
        $konto = [
            route('settings.profile'), route('settings.security'), route('settings.email'),
            route('settings.data'), route('settings.birthday'), route('settings.two_factor.enable'),
            route('collections.index'), route('recipes.create'), route('kontakt'),
        ];

        $sprawdzone = 0;
        $usterki = [];

        foreach ($gosc as $adres) {
            $sprawdzone += $this->sprawdzEkran($adres, 'gość', $usterki);
        }

        $this->actingAs($this->user('basia'));
        foreach ($konto as $adres) {
            $sprawdzone += $this->sprawdzEkran($adres, 'zalogowana', $usterki);
        }

        // Pułapka 2: zero sprawdzonych formularzy to nie jest zieleń.
        $this->assertGreaterThanOrEqual(13, $sprawdzone,
            "Sprawdzono tylko {$sprawdzone} formularzy z natywną walidacją — ekrany nie renderują pól albo XPath przestał je łapać.");
        $this->assertSame([], $usterki, "Formularze z natywną walidacją bez `novalidate` (D-333):\n  • ".implode("\n  • ", $usterki));
    }

    /** @param  list<string>  $usterki */
    private function sprawdzEkran(string $adres, string $kto, array &$usterki): int
    {
        $odpowiedz = $this->get($adres);
        $this->assertSame(200, $odpowiedz->getStatusCode(), "Ekran {$adres} ({$kto}) nie wstał — nic na nim nie zmierzono.");

        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.($odpowiedz->getContent() ?: ''), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);

        $zWalidacja = 0;
        foreach ($xpath->query('//form') ?: [] as $form) {
            if (! $form instanceof DOMElement) {
                continue;
            }

            $pola = $xpath->query(
                './/input[not(@type="hidden" or @type="submit" or @type="button")]'
                .'[@required or @pattern or @minlength or @min or @max or @step'
                .' or @type="email" or @type="url" or @type="number" or @type="date" or @type="time"'
                .' or @type="datetime-local" or @type="month" or @type="week"]'
                .'|.//textarea[@required or @minlength]|.//select[@required]',
                $form,
            );
            if ($pola === false || $pola->length === 0) {
                continue;
            }

            $zWalidacja++;
            if (! $form->hasAttribute('novalidate')) {
                $usterki[] = "{$adres} ({$kto}): <form action=\"{$form->getAttribute('action')}\"> ma pole "
                    ."„{$pola->item(0)?->attributes?->getNamedItem('name')?->nodeValue}” z natywną walidacją, a nie ma `novalidate`";
            }
        }

        return $zWalidacja;
    }

    // ---------------------------------------------------------------------
    // 2. Szablony
    // ---------------------------------------------------------------------

    public function test_kazdy_formularz_w_szablonach_z_natywna_walidacja_ma_novalidate(): void
    {
        $pliki = $this->szablony();
        // Pułapka 2b: porównanie ze spisem niezależnym od iteratora.
        $spis = array_filter(explode("\n", (string) shell_exec('find '.escapeshellarg(base_path(self::KATALOG)).' -name "*.blade.php" -type f')));
        $this->assertCount(count($spis), $pliki, 'Skaner widzi inną liczbę szablonów niż `find` — czyta tylko część katalogu.');
        $this->assertGreaterThan(200, count($pliki), 'Skan nie czyta szablonów — zła ścieżka?');

        $formularzy = 0;
        $zWalidacja = 0;
        $usterki = [];

        foreach ($pliki as $sciezka) {
            foreach ($this->formularzeWSzablonie($sciezka) as [$linia, $maNovalidate, $powody]) {
                $formularzy++;
                if ($powody === []) {
                    continue;
                }
                $zWalidacja++;
                if (! $maNovalidate && ! array_key_exists($sciezka, self::wyjatki())) {
                    $usterki[] = "{$sciezka}:{$linia} — ".implode(', ', array_slice($powody, 0, 3));
                }
            }
        }

        $this->assertGreaterThan(150, $formularzy, "Skaner znalazł tylko {$formularzy} formularzy — wyrażenie przestało je łapać.");
        $this->assertGreaterThanOrEqual(45, $zWalidacja,
            "Tylko {$zWalidacja} formularzy z natywną walidacją — rozwijanie `x-field`/komponentów przestało działać.");
        $this->assertSame([], $usterki,
            "Formularz z natywną walidacją bez `novalidate` (D-333) — przeglądarka zatrzyma go dymkiem, zanim serwer odeśle polskie podsumowanie:\n  • "
            .implode("\n  • ", $usterki));
    }

    /**
     * Kontrola dodatnia samego skanera: sztuczny szablon z polem wymaganym
     * w komponencie i z adresem e-mail ma zostać zauważony, a formularz
     * z samymi przyciskami — nie. Bez tego skaner, który przestał widzieć
     * pola, dawałby zieleń na wszystkim.
     */
    public function test_skaner_widzi_pola_wprost_w_x_field_i_w_komponencie(): void
    {
        $szablon = <<<'BLADE'
            {{-- <form><input required></form> w komentarzu się nie liczy --}}
            <form method="POST" action="{{ route('login') }}" @if($errors->any()) data-x @endif>
                <x-field name="login" label="Login" required />
            </form>
            <form method="POST" action="/a" novalidate><input type="email" name="e"></form>
            <form method="POST" action="/b"><x-wybor-zeszytu :action="'/x'" wiersz="1" /></form>
            <form method="POST" action="/c"><button type="submit">Usuń</button></form>
            BLADE;

        $wynik = $this->przeanalizuj($szablon, 'test.blade.php');

        $this->assertCount(4, $wynik);
        [$pierwszy, $drugi, $trzeci, $czwarty] = $wynik;
        $this->assertFalse($pierwszy[1]);
        $this->assertNotSame([], $pierwszy[2], 'x-field z `required` nie został zauważony.');
        $this->assertTrue($drugi[1]);
        $this->assertNotSame([], $drugi[2], 'Pole `type="email"` nie zostało zauważone.');
        $this->assertNotSame([], $trzeci[2], 'Pole wymagane w komponencie `x-wybor-zeszytu` nie zostało zauważone.');
        $this->assertSame([], $czwarty[2], 'Formularz z samym przyciskiem nie ma natywnej walidacji.');
    }

    /** @return list<string> */
    private function szablony(): array
    {
        $pliki = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path(self::KATALOG), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $plik) {
            if ($plik instanceof \SplFileInfo && str_ends_with($plik->getFilename(), '.blade.php')) {
                $pliki[] = substr($plik->getPathname(), strlen(base_path()) + 1);
            }
        }
        sort($pliki);

        return $pliki;
    }

    /** @return list<array{0: int, 1: bool, 2: list<string>}> */
    private function formularzeWSzablonie(string $sciezka): array
    {
        return $this->przeanalizuj((string) file_get_contents(base_path($sciezka)), $sciezka);
    }

    /** @return list<array{0: int, 1: bool, 2: list<string>}> [linia, novalidate, powody] */
    private function przeanalizuj(string $surowy, string $sciezka): array
    {
        $tekst = $this->oczysc($surowy);
        $wynik = [];

        preg_match_all(self::FORMULARZ, $tekst, $trafienia, PREG_OFFSET_CAPTURE);
        foreach ($trafienia[0] as [$znacznik, $pozycja]) {
            $poczatek = $pozycja + strlen($znacznik);
            $koniec = strpos($tekst, '</form>', $poczatek);
            $cialo = substr($tekst, $poczatek, $koniec === false ? null : $koniec - $poczatek);

            $powody = $this->natywnaWalidacja($cialo, [$sciezka => true]);
            // Formularz w komponencie z `{{ $slot }}`: pola przychodzą z miejsca
            // wywołania (np. `x-confirm-button`), więc patrzymy i tam.
            if (str_contains($cialo, '$slot') && str_starts_with($sciezka, self::KATALOG.'/components/')) {
                $powody = [...$powody, ...$this->polaZeSlotow($sciezka)];
            }

            $wynik[] = [
                substr_count(substr($tekst, 0, $pozycja), "\n") + 1,
                (bool) preg_match('/(?<![\w-])novalidate\b/', $znacznik),
                $powody,
            ];
        }

        return $wynik;
    }

    private function oczysc(string $tekst): string
    {
        $tekst = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tekst);
        $tekst = (string) preg_replace('/<!--.*?-->/s', '', $tekst);

        // `->` i `=>` w Blade wewnątrz znacznika urywałyby go na `>`.
        return str_replace(['->', '=>'], '~~', $tekst);
    }

    /**
     * @param  array<string, true>  $odwiedzone
     * @return list<string>
     */
    private function natywnaWalidacja(string $tekst, array $odwiedzone, int $glebokosc = 0): array
    {
        $powody = [];

        preg_match_all(self::ZNACZNIK, $tekst, $znaczniki, PREG_SET_ORDER);
        foreach ($znaczniki as [$znacznik, $nazwa]) {
            if (in_array($nazwa, ['input', 'textarea', 'select', 'x-field'], true)) {
                if ($nazwa === 'input' && preg_match('/\btype="(?:hidden|submit|button)"/', $znacznik)) {
                    continue;
                }
                if (preg_match(self::ATRYBUT, $znacznik, $atrybut)) {
                    $powody[] = "<{$nazwa}> {$atrybut[0]}";
                }

                continue;
            }

            $plik = $this->plikKomponentu(substr($nazwa, 2));
            if ($plik !== null && ! isset($odwiedzone[$plik]) && $glebokosc < 5) {
                foreach ($this->natywnaWalidacja($this->tresc($plik), $odwiedzone + [$plik => true], $glebokosc + 1) as $powod) {
                    $powody[] = "<{$nazwa}> → {$powod}";
                }
            }
        }

        preg_match_all("/@include(?:If)?\\(\\s*'([^']+)'/", $tekst, $wlaczenia);
        foreach ($wlaczenia[1] as $widok) {
            $plik = self::KATALOG.'/'.str_replace('.', '/', $widok).'.blade.php';
            if (is_file(base_path($plik)) && ! isset($odwiedzone[$plik]) && $glebokosc < 5) {
                foreach ($this->natywnaWalidacja($this->tresc($plik), $odwiedzone + [$plik => true], $glebokosc + 1) as $powod) {
                    $powody[] = "@include({$widok}) → {$powod}";
                }
            }
        }

        return $powody;
    }

    /** @return list<string> */
    private function polaZeSlotow(string $sciezkaKomponentu): array
    {
        $nazwa = str_replace('/', '.', substr($sciezkaKomponentu, strlen(self::KATALOG.'/components/'), -strlen('.blade.php')));
        $powody = [];

        foreach ($this->szablony() as $plik) {
            $tekst = $this->tresc($plik);
            if (! str_contains($tekst, '<x-'.$nazwa)) {
                continue;
            }
            preg_match_all('/<x-'.preg_quote($nazwa, '/').'\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*(?<!\/)>(.*?)<\/x-'.preg_quote($nazwa, '/').'>/s', $tekst, $uzycia);
            foreach ($uzycia[1] as $slot) {
                foreach ($this->natywnaWalidacja($slot, [$plik => true]) as $powod) {
                    $powody[] = "slot z {$plik} → {$powod}";
                }
            }
        }

        return $powody;
    }

    private function plikKomponentu(string $nazwa): ?string
    {
        $sciezka = str_replace('.', '/', $nazwa);
        foreach ([self::KATALOG."/components/{$sciezka}.blade.php", self::KATALOG."/components/{$sciezka}/index.blade.php"] as $plik) {
            if (is_file(base_path($plik))) {
                return $plik;
            }
        }

        return null;
    }

    private function tresc(string $plik): string
    {
        return $this->pamiec[$plik] ??= $this->oczysc((string) file_get_contents(base_path($plik)));
    }
}
