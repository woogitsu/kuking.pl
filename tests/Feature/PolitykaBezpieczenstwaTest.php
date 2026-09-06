<?php

declare(strict_types=1);

namespace Tests\Feature;

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

    public function test_wymuszana_polityka_nie_ma_default_src(): void
    {
        $csp = $this->naglowekCsp();

        // Najpierw: czy nagłówek W OGÓLE JEST. Bez tego test przechodzi
        // na kodzie sprzed zmiany — pusty nagłówek też „nie zawiera"
        // default-src, więc strażnik strzegłby pustego miejsca.
        $this->assertNotSame('', $csp, 'Nie ma wymuszanego nagłówka CSP.');

        // TO JEST NAJWAŻNIEJSZA ASERCJA W TYM PLIKU.
        //
        // `default-src` jest wartością zapasową dla `script-src` i `style-src`.
        // Dopisanie go do nagłówka WYMUSZAJĄCEGO wyłączyłoby inline'owe skrypty
        // Livewire i Alpine — czyli położyłoby kreator przepisu. Wygląda to
        // niewinnie („przecież domyślnie 'self'") i dlatego jest pilnowane
        // osobno, a nie tylko komentarzem w kodzie.
        $this->assertStringNotContainsString('default-src', $csp);
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

    public function test_polityka_docelowa_dalej_jest_tylko_mierzona(): void
    {
        $odpowiedz = $this->get(route('landing'))->assertOk();

        // Strażnik przed nadgorliwością. Ktoś mógłby „dokończyć zadanie",
        // przenosząc całą politykę do nagłówka wymuszającego — i wyłączyć
        // Livewire wszystkim naraz. Do tego trzeba najpierw pozbyć się
        // `unsafe-inline` i `unsafe-eval`, a nie przestawić nagłówek.
        $mierzona = (string) $odpowiedz->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $mierzona);

        $wymuszana = $this->naglowekCsp();
        $this->assertNotSame('', $wymuszana, 'Nie ma wymuszanego nagłówka CSP.');
        $this->assertStringNotContainsString('unsafe-inline', $wymuszana);
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
        Log::spy();

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
        Log::shouldHaveReceived('info')->once()->withArgs(
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
        $ogromne = json_encode(['csp-report' => ['document-uri' => str_repeat('a', 20000)]]);

        $this->call('POST', route('csp.report'), [], [], [], [], $ogromne)
            ->assertNoContent();
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
}
