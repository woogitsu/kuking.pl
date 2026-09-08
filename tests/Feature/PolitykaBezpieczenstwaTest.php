<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Polityka bezpieczeństwa treści (CSP) — issue #12.
 *
 * CO BYŁO NIE TAK
 * Serwis wysyłał wyłącznie `Content-Security-Policy-Report-Only`
 * z komentarzem, że tryb wymuszający czeka na „listę naruszeń zebranych
 * z produkcji". Tyle że polityka NIE PODAWAŁA ADRESU, pod który przeglądarka
 * miałaby zgłosić naruszenie — więc tryb Report-Only nie zbierał niczego
 * i zadanie „włącz na podstawie danych" nie mogło ruszyć nigdy.
 *
 * Do tego polityka dopuszczała `unsafe-inline` i `unsafe-eval`, więc jej
 * włączenie w tej postaci i tak nie dałoby prawie nic. Czekanie z CAŁOŚCIĄ
 * na rozwiązanie najtrudniejszej części znaczyło, że nie działa też część
 * łatwa i wartościowa.
 *
 * DLACZEGO TEGO NIE DA SIĘ SPRAWDZIĆ INACZEJ NIŻ TESTEM
 * Nagłówki są niewidoczne. Zła polityka nie psuje niczego, co widać —
 * po prostu nie chroni. Jedyny sposób, żeby zauważyć, że `report-uri`
 * wypadło z nagłówka, to test.
 */
class PolitykaBezpieczenstwaTest extends TestCase
{
    use RefreshDatabase;

    private function naglowekCsp(): string
    {
        return (string) $this->get(route('landing'))
            ->assertOk()
            ->headers->get('Content-Security-Policy');
    }

    public function test_wymuszamy_dyrektywy_ktore_wzmacniaja_xss(): void
    {
        $csp = $this->naglowekCsp();

        // To NIE są dyrektywy dekoracyjne. Wstrzyknięty `<base href>`
        // przekierowuje każdy względny adres na stronie, razem z akcją
        // formularza logowania — czyli zamienia „skrypt się wykonał"
        // w „hasło poszło gdzie indziej". `form-action` zamyka drugą drogę
        // tego samego.
        $this->assertStringContainsString("base-uri 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_wymuszana_polityka_ma_juz_default_src(): void
    {
        $csp = $this->naglowekCsp();

        // TU BYŁ ODWROTNY STRAŻNIK — I MIAŁ RACJĘ, DOPÓKI JEJ NIE STRACIŁ.
        //
        // Do wersji sprzed issue #12 ten test pilnował, żeby `default-src`
        // NIE trafił do nagłówka wymuszającego. Powód był prawdziwy:
        // `default-src` jest wartością zapasową dla `script-src`, a Livewire
        // z Alpine wstawiały skrypt inline i liczyły wyrażenia przez
        // `new Function` — czyli dopisanie tej dyrektywy kładło kreator
        // przepisu.
        //
        // Powód zniknął, bo go usunęliśmy, a nie obeszliśmy: skrypty dostają
        // jednorazowy podpis (nonce), a Livewire chodzi w trybie `csp_safe`,
        // czyli na bundlu Alpine bez `new Function`. Dlatego strażnik jest
        // teraz odwrócony — bez `default-src` każdy rodzaj zasobu, którego
        // nie wymieniliśmy osobno, wjeżdżałby na stronę bez ograniczeń.
        $this->assertNotSame('', $csp, 'Nie ma wymuszanego nagłówka CSP.');
        $this->assertStringContainsString("default-src 'self'", $csp);
    }

    public function test_wymuszany_script_src_nie_dopuszcza_inline_ani_eval(): void
    {
        $csp = $this->naglowekCsp();

        // TO JEST NAJWAŻNIEJSZA ASERCJA W TYM PLIKU.
        //
        // Cała reszta polityki utrudnia atakującemu życie. Dopiero
        // `script-src` bez `unsafe-inline` sprawia, że wstrzyknięty
        // `<script>` NIE WYKONA SIĘ W OGÓLE. Gdyby ktoś dopisał tu
        // `unsafe-inline` „bo coś nie działa", zostałby nagłówek, który
        // wygląda na ochronę i nią nie jest.
        $this->assertMatchesRegularExpression("/script-src [^;]*'self'/", $csp);
        $this->assertStringNotContainsString('unsafe-inline', explode('style-src', $csp)[0]);
        $this->assertStringNotContainsString('unsafe-eval', $csp);

        // Podpis MUSI być w nagłówku — bez niego `script-src 'self'` wywala
        // JSON-LD i konfigurację Livewire, czyli poprawna polityka psuje
        // stronę zamiast jej bronić.
        $this->assertMatchesRegularExpression("/'nonce-[A-Za-z0-9]{8,}'/", $csp);
    }

    public function test_podpis_z_naglowka_zgadza_sie_z_kazdym_skryptem_na_stronie(): void
    {
        // Strona z JSON-LD, czyli jedynym skryptem inline w całym serwisie
        // (`<x-json-ld>`). Przeglądarka sprawdza `script-src` na KAŻDYM
        // elemencie `<script>`, także takim, którego nie umie wykonać —
        // więc blok bez podpisu po prostu znika z dokumentu i Google
        // przestaje widzieć przepis jako przepis.
        $autor = $this->user('kucharka');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $podpis = $this->podpisZNaglowka((string) $odpowiedz->headers->get('Content-Security-Policy'));
        $html = $odpowiedz->getContent();

        preg_match_all('/<script\b[^>]*>/i', (string) $html, $znaczniki);

        $this->assertNotEmpty(
            $znaczniki[0],
            'Na tej stronie nie ma ani jednego <script> — test nie sprawdza tego, po co powstał.',
        );

        foreach ($znaczniki[0] as $znacznik) {
            $this->assertStringContainsString(
                'nonce="'.$podpis.'"',
                $znacznik,
                'Skrypt bez podpisu z nagłówka CSP: '.$znacznik.
                ' — przeglądarka usunie go z dokumentu, a na ekranie nic tego nie pokaże.',
            );
        }
    }

    public function test_podpis_jest_inny_przy_kazdym_zadaniu(): void
    {
        // Podpis stały to podpis, który atakujący może odczytać raz
        // i wpisać na zawsze — czyli `unsafe-inline` napisane trudniej.
        $pierwszy = $this->podpisZNaglowka($this->naglowekCsp());
        $drugi = $this->podpisZNaglowka($this->naglowekCsp());

        $this->assertNotSame($pierwszy, $drugi);
    }

    public function test_livewire_chodzi_w_trybie_bez_new_function(): void
    {
        // `script-src` bez `unsafe-eval` i zwykły bundel Livewire wykluczają
        // się nawzajem: Alpine liczy w nim wyrażenia przez `new Function`.
        // Przestawienie tej opcji na `false` nie wywala żadnego testu
        // renderującego — kreator przepisu po prostu przestaje reagować
        // na kliknięcia u wszystkich naraz. Dlatego jest pilnowana wprost.
        $this->assertTrue(
            (bool) config('livewire.csp_safe'),
            'livewire.csp_safe jest wyłączone, a CSP wymusza script-src bez unsafe-eval — '.
            'kreator przepisu nie zadziała w żadnej przeglądarce.',
        );
    }

    public function test_kreator_przepisu_dostaje_skrypty_livewire_z_podpisem(): void
    {
        $odpowiedz = $this->actingAs($this->user('piekarz'))
            ->get(route('recipes.create'))
            ->assertOk();

        $podpis = $this->podpisZNaglowka((string) $odpowiedz->headers->get('Content-Security-Policy'));

        preg_match_all('/<script\b[^>]*>/i', (string) $odpowiedz->getContent(), $znaczniki);

        $this->assertNotEmpty($znaczniki[0], 'Kreator przepisu nie wciągnął ani jednego skryptu.');

        foreach ($znaczniki[0] as $znacznik) {
            $this->assertStringContainsString('nonce="'.$podpis.'"', $znacznik, 'Skrypt bez podpisu: '.$znacznik);
        }
    }

    public function test_strona_z_podpisem_nie_moze_byc_cachowana_publicznie(): void
    {
        // NAJGROŹNIEJSZY SPOSÓB, ŻEBY TO ZEPSUĆ, NIE DOTYKA WCALE CSP.
        //
        // Podpis jest jednorazowy: siedzi RAZEM w nagłówku i w HTML-u.
        // Gdyby ktoś kiedyś włączył publiczne cache'owanie stron (Cache Rule
        // w Cloudflare albo `Cache-Control: public` w Caddym), krawędź
        // zapamiętałaby HTML z jednym podpisem i podawała go ludziom razem
        // ze świeżym nagłówkiem, w którym jest już inny. Efekt: strona bez
        // ani jednego skryptu, u wszystkich naraz, bez żadnego błędu
        // w logach serwera.
        //
        // Dlatego pilnujemy tego TUTAJ, w teście o CSP — a nie tylko
        // komentarzem w Caddyfile, którego nikt nie czyta przy zmianie
        // ustawień na krawędzi.
        $cache = strtolower((string) $this->get(route('landing'))->assertOk()->headers->get('Cache-Control'));

        $this->assertNotSame('', $cache, 'Odpowiedź nie mówi nic o cache — krawędź może uznać, że wolno.');

        $this->assertMatchesRegularExpression(
            '/private|no-store|no-cache/',
            $cache,
            'Strona niosąca podpis nonce musi być oznaczona jako nie do współdzielenia. Jest: '.$cache,
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\bpublic\b|s-maxage/',
            $cache,
            'Strona niosąca podpis nonce jest oznaczona jako publicznie cache\'owalna — '.
            'zapamiętany HTML dostanie kiedyś nagłówek z innym podpisem i zostanie bez skryptów.',
        );
    }

    public function test_obie_polityki_podaja_adres_zgloszen(): void
    {
        $odpowiedz = $this->get(route('landing'))->assertOk();

        $adres = route('csp.report');

        // Bez tego tryb Report-Only nie zbiera NICZEGO, a właśnie na jego
        // danych ma się oprzeć decyzja o pełnym wymuszeniu polityki.
        $this->assertStringContainsString(
            'report-uri '.$adres,
            (string) $odpowiedz->headers->get('Content-Security-Policy-Report-Only'),
            'Polityka mierzona nie ma gdzie zgłaszać naruszeń — nie zbierze nic.',
        );

        $this->assertStringContainsString(
            'report-uri '.$adres,
            (string) $odpowiedz->headers->get('Content-Security-Policy'),
        );
    }

    public function test_styl_tez_jest_wymuszany_bez_unsafe_inline(): void
    {
        $odpowiedz = $this->get(route('landing'))->assertOk();

        $wymuszana = (string) $odpowiedz->headers->get('Content-Security-Policy');
        $mierzona = (string) $odpowiedz->headers->get('Content-Security-Policy-Report-Only');

        // OSTATNIA FURTKA TEJ POLITYKI, ZAMKNIĘTA W ISSUE #107.
        //
        // Do niedawna `style-src` musiał mieć `unsafe-inline`, bo w widokach
        // było 355 atrybutów `style="..."`, a nonce ich nie obejmuje — działa
        // na ELEMENT `<style>`, nie na atrybut. Teraz nie ma ani jednego,
        // więc dyrektywa jest domknięta.
        //
        // Gdyby ktoś dopisał `unsafe-inline` z powrotem „bo coś nie wygląda",
        // wróciłaby cała klasa ataków, którą to zamyka: zdalny arkusz
        // wyciągający dane selektorami atrybutów i przemalowanie strony pod
        // phishing. Poprawną drogą jest nazwana klasa w `app.css`, a dla
        // strony, która musi działać bez arkusza — blok `<style nonce>`.
        foreach (['wymuszana' => $wymuszana, 'mierzona' => $mierzona] as $nazwa => $polityka) {
            $this->assertStringNotContainsString(
                'unsafe-inline',
                $polityka,
                "Polityka {$nazwa} dopuszcza `unsafe-inline`. Zapisz styl klasą w app.css ".
                'albo blokiem <style nonce>, nie atrybutem style= (issue #107).',
            );

            $this->assertStringNotContainsString('unsafe-eval', $polityka);

            $this->assertMatchesRegularExpression(
                "/style-src [^;]*'nonce-[A-Za-z0-9]{8,}'/",
                $polityka,
                'style-src bez podpisu wywala <style> Livewire i blok na stronie awarii.',
            );
        }
    }

    public function test_polityka_mierzona_zostaje_jako_kanal_zgloszen(): void
    {
        $odpowiedz = $this->get(route('landing'))->assertOk();

        // Report-Only jest dziś TAKI SAM jak wymuszany i to jest w porządku —
        // wcześniej mierzył następny krok (`style-src` bez `unsafe-inline`),
        // a ten krok został zrobiony.
        //
        // Nagłówek zostaje, bo jest jedynym kanałem, którym zobaczymy
        // naruszenie niewidoczne w testach: pakiet dokładający własny `style=`,
        // wklejony fragment cudzego HTML-a, wtyczkę przeglądarki. Bez niego
        // dowiedzielibyśmy się o tym z pustej strony u użytkownika.
        $this->assertNotEmpty(
            (string) $odpowiedz->headers->get('Content-Security-Policy-Report-Only'),
            'Zniknął nagłówek mierzony — zostaliśmy bez kanału zgłoszeń z produkcji.',
        );
    }

    public function test_przegladarka_moze_zglosic_naruszenie_bez_tokenu(): void
    {
        Log::spy();

        // Zgłoszenie wysyła SAMA PRZEGLĄDARKA: bez sesji, bez tokenu CSRF.
        // Gdyby trasa go wymagała, każde zgłoszenie kończyłoby się błędem 419
        // i znowu nie zbieralibyśmy niczego — tym razem po cichu.
        $this->call('POST', route('csp.report'), [], [], [], [], json_encode([
            'csp-report' => [
                'document-uri' => 'https://kuking.pl/przepis/rosol',
                'effective-directive' => 'script-src',
                'blocked-uri' => 'https://obcy.example/skrypt.js',
            ],
        ]))->assertNoContent();
    }

    public function test_zgloszenie_nie_zapisuje_fragmentu_kodu_ze_strony(): void
    {
        // Szpieg przez ZMIENNĄ, nie przez fasadę. `Log::shouldHaveReceived()`
        // działa w czasie wykonania (fasada przekazuje wywołanie do obiektu
        // Mockery), ale analiza statyczna widzi tylko fasadę, na której takiej
        // metody nie ma — i to jeden z czterech błędów, które trzymały PHPStana
        // na poziomie 0 dla całego repozytorium.
        $log = Log::spy();

        $this->call('POST', route('csp.report'), [], [], [], [], json_encode([
            'csp-report' => [
                'document-uri' => 'https://kuking.pl/przepis/rosol',
                'effective-directive' => 'script-src',
                'blocked-uri' => 'inline',
                // `script-sample` niesie fragment kodu ze strony, a ten
                // fragment bywa TREŚCIĄ UŻYTKOWNIKA. Do policzenia, co psuje
                // politykę, nie jest potrzebny, a w logu byłby wyciekiem.
                'script-sample' => 'const hasloBasi = "tajne";',
            ],
        ]))->assertNoContent();

        // Pilnujemy, CO trafia do logu: trzy wybrane pola i ani jednego znaku
        // z `script-sample`.
        $log->shouldHaveReceived('info')->once()->withArgs(
            function (string $wiadomosc, array $kontekst): bool {
                return $wiadomosc === 'Naruszenie CSP'
                    && $kontekst === [
                        'dyrektywa' => 'script-src',
                        'zablokowane' => 'inline',
                        'strona' => 'https://kuking.pl/przepis/rosol',
                    ];
            },
        );
    }

    public function test_wielkie_zgloszenie_nie_zapycha_logu(): void
    {
        // Zgłoszenie CSP to kilkaset bajtów. Wszystko powyżej ośmiu kilobajtów
        // to albo pomyłka, albo próba zapchania logu. Przyjmujemy grzecznie
        // i wyrzucamy do kosza, zamiast parsować.
        //
        // TEN TEST PYTA O LOG, NIE O KOD ODPOWIEDZI. Endpoint oddaje 204
        // ZAWSZE I BEZWARUNKOWO (patrz `CspReportController::__invoke()`),
        // więc `assertNoContent()` przechodzi także wtedy, gdy limit zniknął
        // i dwadzieścia tysięcy znaków wylądowało w dzienniku. Zmierzone:
        // po podniesieniu `LIMIT_BAJTOW` z 8192 na 8192000 cały ten plik
        // był dalej zielony.
        $log = Log::spy();

        $ogromne = json_encode(['csp-report' => ['document-uri' => str_repeat('a', 20000)]]);

        $this->call('POST', route('csp.report'), [], [], [], [], $ogromne)
            ->assertNoContent();

        $log->shouldNotHaveReceived('info');
    }

    public function test_smiec_zamiast_zgloszenia_nie_wywala_serwera(): void
    {
        // Endpoint jest publiczny i bez tokenu, więc dostanie wszystko:
        // puste ciało, tekst, obcy JSON. Każda odpowiedź inna niż 204
        // zachęca do sondowania.
        foreach (['', 'to nie jest json', '[]', '{"cos":"innego"}', 'null'] as $smiec) {
            $this->call('POST', route('csp.report'), [], [], [], [], $smiec)
                ->assertNoContent();
        }
    }

    /**
     * Jednorazowy podpis wyjęty z nagłówka. Brak podpisu to błąd testu,
     * a nie „nic nie znaleziono" — inaczej porównanie z HTML-em przeszłoby
     * na pustym ciągu.
     */
    private function podpisZNaglowka(string $csp): string
    {
        if (preg_match("/'nonce-([A-Za-z0-9+\\/=_-]+)'/", $csp, $trafienie) !== 1) {
            $this->fail('W nagłówku CSP nie ma podpisu nonce: '.$csp);
        }

        return $trafienie[1];
    }
}
