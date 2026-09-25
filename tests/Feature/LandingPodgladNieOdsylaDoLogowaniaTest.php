<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1289: strona powitalna opisywała funkcje odnośnikami „Zobacz…”,
 * „Zajrzyj…”, „Dodaj…”, a prowadziła je wprost do tras z grupy `auth`
 * (`posts.create`, `recipes.create`, `collections.index`). Gość, który
 * chciał obejrzeć funkcję, lądował na logowaniu.
 *
 * Landing widzi wyłącznie gość (`FeedController::landing()` zalogowanemu
 * oddaje `home()`), więc wariantu „zalogowany” w tym widoku nie ma.
 *
 * Test nie wylicza etykiet ręcznie: zbiera KAŻDY wewnętrzny odnośnik
 * opowieści o funkcjach i sprawdza odpowiedź, jaką dostaje gość. Jedynym
 * dozwolonym celem za logowaniem są jawne wejścia do konta (rejestracja
 * i logowanie), których etykieta mówi o koncie przed kliknięciem.
 *
 * KONTROLA DODATNIA: wpis „Landing: podgląd prowadzi do trasy auth”
 * w `scripts/kontrole-negatywne-alfa08.py` przywraca
 * `route('posts.create')` pod „Zobacz, kto może widzieć wpis” i ten test
 * ma wtedy oblać.
 */
class LandingPodgladNieOdsylaDoLogowaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_odnosniki_podgladu_na_landingu_nie_odsylaja_goscia_do_logowania(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $odnosniki = $this->odnosnikiOpowiesci($html);
        $this->assertNotEmpty($odnosniki, 'Nie znaleziono odnośników w sekcjach opowieści.');

        foreach ($odnosniki as $etykieta => $adres) {
            $sciezka = parse_url($adres, PHP_URL_PATH) ?: '/';
            $zapytanie = parse_url($adres, PHP_URL_QUERY);
            $odpowiedz = $this->get($sciezka.($zapytanie ? '?'.$zapytanie : ''));

            $this->assertSame(
                200,
                $odpowiedz->getStatusCode(),
                "Odnośnik „{$etykieta}” ({$adres}) nie pokazuje gościowi treści — "
                .'status '.$odpowiedz->getStatusCode().' '.($odpowiedz->headers->get('Location') ?? ''),
            );

            $kotwica = parse_url($adres, PHP_URL_FRAGMENT);
            if ($kotwica !== null && $kotwica !== false) {
                $this->assertStringContainsString(
                    'id="'.$kotwica.'"',
                    $odpowiedz->getContent(),
                    "Odnośnik „{$etykieta}” wskazuje kotwicę #{$kotwica}, której nie ma na stronie docelowej.",
                );
            }
        }
    }

    public function test_podglad_widocznosci_i_dodawania_zdjecia_prowadzi_do_wlasciwej_pomocy(): void
    {
        $odnosniki = $this->odnosnikiOpowiesci($this->get('/')->assertOk()->getContent());

        $this->assertSame(route('help').'#kto-widzi', $odnosniki['Zobacz, kto może widzieć wpis'] ?? null);
        $this->assertSame(route('help').'#dodawanie-zdjecia', $odnosniki['Zobacz, jak dodać zdjęcie'] ?? null);

        $this->get(route('help'))->assertOk()
            ->assertSee('<h2 id="kto-widzi">Kto widzi to, co publikuję?</h2>', false)
            ->assertSee('<h2 id="dodawanie-zdjecia">Jak dodać zdjęcie swojego dania?</h2>', false);
    }

    /**
     * @return array<string, string> etykieta => href
     */
    private function odnosnikiOpowiesci(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new DOMXPath($dom);

        $wezly = $xpath->query(
            '//section[@id="jak-dziala" or @id="ugotowalem" or @aria-labelledby="wlasne-tresci-tytul"]//a[@href]',
        );

        $wynik = [];
        foreach ($wezly as $a) {
            $wynik[trim(preg_replace('/\s+/u', ' ', $a->textContent))] = $a->getAttribute('href');
        }

        return $wynik;
    }
}
