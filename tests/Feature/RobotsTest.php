<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * robots.txt — plik, który przez cały czas serwował co innego, niż myśleliśmy.
 *
 * `SitemapController::robots()` generował poprawną treść: adres mapy strony
 * i listę adresów, których roboty mają nie indeksować. Tyle że w `public/`
 * leżał STATYCZNY `robots.txt` z dwiema linijkami — a serwer WWW oddaje plik
 * z dysku, nie pytając Laravela. Trasa nie była wywoływana ani razu.
 *
 * Widać to było w nagłówkach: odpowiedź miała `cache-control` serwera plików
 * i NIE MIAŁA `Content-Security-Policy`, które nasze middleware dokłada
 * do każdej odpowiedzi aplikacji.
 *
 * Skutek: znikała linia `Sitemap:` (Google nie dowiadywał się, gdzie jest
 * mapa) i wszystkie `Disallow` — wyszukiwarka indeksowała `/ustawienia`
 * i `/admin`. (`/szukaj` celowo nie ma `Disallow` — patrz issue #964 niżej.)
 */
class RobotsTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_wskazuje_mape_strony_i_odcina_adresy_prywatne(): void
    {
        $tresc = $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->getContent();

        $this->assertStringContainsString('Sitemap: '.route('sitemap'), $tresc);

        // Druga strona reguły: adresy po angielsku byłyby znakiem, że ktoś
        // przywrócił listę spod złej konwencji.
        foreach (['/search', '/add'] as $nieistniejacy) {
            $this->assertStringNotContainsString(
                "Disallow: {$nieistniejacy}",
                $tresc,
                "robots.txt blokuje adres {$nieistniejacy}, którego serwis nie ma.",
            );
        }

        // ADRESY POLSKIE, BO TAKIE MA TEN SERWIS.
        //
        // Kontroler wypisywał wcześniej `/search`, `/home` i `/add` — czyli
        // adresy, których w Kuking nie ma. Wyszukiwarka i ekran dodawania
        // NIE BYŁY wyłączone z indeksowania, a plik twierdził, że są.
        foreach (['/dodaj', '/witaj', '/ustawienia', '/admin', '/zglos'] as $adres) {
            $this->assertStringContainsString("Disallow: {$adres}", $tresc);
        }
    }

    public function test_robot_moze_wejsc_na_szukaj_a_strona_kaze_jej_nie_indeksowac(): void
    {
        // ODWRÓCONE PO ISSUE #964. Ten test wymagał kiedyś linii
        // `Disallow: /szukaj` i utrwalał sprzeczność: `/szukaj` wysyła
        // `noindex`, ale robot, któremu robots.txt zabrania wejścia, nigdy tej
        // reguły nie zobaczy — a adres odkryty z zewnętrznego linku zostaje
        // w indeksie jako goły URL. Google Search Central: `noindex` działa
        // tylko na stronie NIEzablokowanej w robots.txt
        // (https://developers.google.com/search/docs/crawling-indexing/block-indexing),
        // tak samo `docs/seo/SEO_TECHNICAL.md` §1.3 i §3.1.
        //
        // Obie strony kontraktu w jednym teście: robot może wejść, a odpowiedź
        // każe nie indeksować. Ponowne dopisanie `Disallow: /szukaj` oblewa
        // pierwszą asercję.
        $tresc = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '~^Disallow:\s*/szukaj~mi',
            (string) $tresc,
            'robots.txt blokuje /szukaj — robot nie odczyta wtedy noindex tej strony (issue #964).',
        );

        foreach ([route('search'), route('search', ['q' => 'rosol'])] as $adres) {
            $this->get($adres)
                ->assertOk()
                ->assertSee('<meta name="robots" content="noindex', false)
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        }
    }

    public function test_publiczny_zeszyt_nie_jest_zablokowany_przedrostkiem(): void
    {
        // Issue #965: `Disallow: /zeszyt` jako przedrostek blokował też
        // `/zeszyt/{uuid}` — publiczny zeszyt „Wszyscy". Zostaje zablokowana
        // sama lista (za logowaniem) i podstrony zeszytu (edycja).
        $linie = explode("\n", (string) $this->get('/robots.txt')->getContent());

        $this->assertNotContains('Disallow: /zeszyt', $linie);
        $this->assertNotContains('Disallow: /zeszyt/', $linie);
        $this->assertContains('Disallow: /zeszyt$', $linie);
        $this->assertContains('Disallow: /zeszyt/*/', $linie);
    }

    public function test_zaden_statyczny_plik_nie_przykrywa_trasy_aplikacji(): void
    {
        // TO JEST TEST, KTÓRY BY TAMTO ZŁAPAŁ.
        //
        // Test wyżej przechodzi w PHPUnicie także wtedy, gdy plik statyczny
        // istnieje — bo `$this->get()` idzie prosto do routingu Laravela
        // i nigdy nie dotyka katalogu `public/`. Awaria była widoczna
        // wyłącznie na żywym serwerze.
        foreach (['robots.txt', 'sitemap.xml'] as $nazwa) {
            $this->assertFileDoesNotExist(
                public_path($nazwa),
                "public/{$nazwa} przykrywa trasę aplikacji — serwer WWW odda plik z dysku, "
                .'a kontroler nie zostanie wywołany ani razu.',
            );
        }
    }
}
