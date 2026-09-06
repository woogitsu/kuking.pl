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
 * mapa) i wszystkie `Disallow` — wyszukiwarka indeksowała `/szukaj`,
 * `/ustawienia`, `/zeszyt` i `/admin`.
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
        foreach (['/szukaj', '/dodaj', '/witaj', '/ustawienia', '/zeszyt', '/admin', '/zglos'] as $adres) {
            $this->assertStringContainsString("Disallow: {$adres}", $tresc);
        }
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
