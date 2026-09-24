<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Canonical i `og:url` ze stałego hosta i zapisanej nazwy (issues #1311, #1369).
 *
 * Layout brał adres z żądania, więc `/@basia_1971` ogłaszało się kanonicznym
 * obok `/@Basia_1971`, a wejście przez `www` (bez reguły Cloudflare) — obok
 * apexu z sitemapy. Kontrola ujemna: powrót do `$request->url()`
 * w `KanonicznyAdresStrony::dla()` oblewa test wariantów pisowni i test
 * `www`; usunięcie `ustawSciezke()` z `ProfileController` — te same dwa.
 *
 * Linki „Podziel się" (WhatsApp, e-mail, Facebook, arkusz systemowy) muszą
 * wskazywać ten sam host co canonical i sitemapa. Kontrola ujemna: zdjęcie
 * `AdresKanoniczny::zbuduj()` z `Udostepnianie::adres()` oblewa test `www`
 * dla linków udostępniania; test apeksu zostaje zielony.
 */
class CanonicalProfiluIHostaTest extends TestCase
{
    use RefreshDatabase;

    private function assertKanoniczny(string $adres, string $oczekiwany): void
    {
        $html = $this->get($adres)->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.e($oczekiwany).'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.e($oczekiwany).'">', $html);
    }

    public function test_warianty_wielkosci_liter_wskazuja_zapisana_nazwe(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        $this->user('Basia_1971');

        foreach (['/@Basia_1971', '/@basia_1971', '/@BASIA_1971'] as $sciezka) {
            $this->assertKanoniczny('https://kuking.pl'.$sciezka, 'https://kuking.pl/@Basia_1971');
        }

        $this->assertKanoniczny(
            'https://kuking.pl/@basia_1971?utm_source=x&page=2&zakladka=przepisy',
            'https://kuking.pl/@Basia_1971?zakladka=przepisy&page=2',
        );
        $this->assertKanoniczny('https://kuking.pl/@BASIA_1971?rok=2025', 'https://kuking.pl/@Basia_1971?rok=2025');
    }

    public function test_poprawna_pisownia_nie_przekierowuje_a_wariant_dalej_dziala(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        $this->user('Basia_1971');

        $this->get('https://kuking.pl/@Basia_1971')->assertOk();
        $this->get('https://kuking.pl/@basia_1971')->assertOk()->assertSee('@Basia_1971');
    }

    public function test_wejscie_przez_www_wskazuje_apex_z_app_url(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        $this->user('Basia_1971');

        $this->assertKanoniczny('https://www.kuking.pl/@basia_1971?page=2', 'https://kuking.pl/@Basia_1971?page=2');
        $this->assertKanoniczny('https://www.kuking.pl/odkryj?utm_source=x', 'https://kuking.pl/odkryj');
        $this->assertKanoniczny('https://www.kuking.pl/', 'https://kuking.pl');
    }

    public function test_apex_zostaje_samokanoniczny(): void
    {
        config(['app.url' => 'https://kuking.pl']);

        $this->assertKanoniczny('https://kuking.pl/odkryj', 'https://kuking.pl/odkryj');
    }

    public function test_staging_wskazuje_wlasny_host_z_app_url(): void
    {
        config(['app.url' => 'https://staging.kuking.pl']);

        $this->assertKanoniczny('https://staging.kuking.pl/odkryj', 'https://staging.kuking.pl/odkryj');
    }

    public function test_odmowa_profilu_nie_ujawnia_zapisanej_nazwy(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        $konto = $this->user('Zamkniete_Konto');
        $konto->forceFill(['status' => User::STATUS_BANNED])->save();

        $odpowiedz = $this->get('https://kuking.pl/@zamkniete_konto');

        $odpowiedz->assertForbidden()->assertHeaderMissing('Location');
        $this->assertStringNotContainsString('Zamkniete_Konto', (string) $odpowiedz->getContent());
    }

    /**
     * Adres treści musi trafić w każde miejsce „Podziel się": arkusz
     * systemowy (`data-podziel-adres`), WhatsApp, e-mail i Facebook.
     */
    private function assertLinkiUdostepniania(string $adresStrony, string $oczekiwanyAdresTresci, ?string $zakazanyHost = null): void
    {
        $html = (string) $this->get($adresStrony)->assertOk()->getContent();
        $zakodowany = preg_quote(e(rawurlencode($oczekiwanyAdresTresci)), '~');

        $this->assertStringContainsString('data-podziel-adres="'.e($oczekiwanyAdresTresci).'"', $html);
        $this->assertMatchesRegularExpression('~href="'.preg_quote(e('https://wa.me/?text='), '~').'[^"]*'.$zakodowany.'"~', $html);
        $this->assertMatchesRegularExpression('~href="'.preg_quote(e('mailto:?subject='), '~').'[^"]*'.$zakodowany.'"~', $html);
        $this->assertStringContainsString(
            'href="'.e('https://www.facebook.com/sharer/sharer.php?u='.rawurlencode($oczekiwanyAdresTresci)).'"',
            $html,
        );

        if ($zakazanyHost !== null) {
            $this->assertStringNotContainsString('data-podziel-adres="https://'.$zakazanyHost, $html);
            $this->assertStringNotContainsString(rawurlencode('https://'.$zakazanyHost), $html);
        }
    }

    /** @return array{0: Recipe, 1: Post} */
    private function publiczneTresci(): array
    {
        $autor = $this->user('Basia_1971');

        return [
            Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']),
            Post::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public', 'body' => 'Obiad']),
        ];
    }

    public function test_linki_udostepniania_przy_wejsciu_przez_www_wskazuja_host_z_app_url(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        [$przepis, $wpis] = $this->publiczneTresci();

        foreach ([route('recipes.show', $przepis), $wpis->url()] as $adres) {
            $sciezka = (string) parse_url($adres, PHP_URL_PATH);

            $this->assertLinkiUdostepniania('https://www.kuking.pl'.$sciezka, 'https://kuking.pl'.$sciezka, 'www.kuking.pl');
        }
    }

    public function test_linki_udostepniania_na_apeksie_wskazuja_apex(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        [$przepis, $wpis] = $this->publiczneTresci();

        foreach ([route('recipes.show', $przepis), $wpis->url()] as $adres) {
            $sciezka = (string) parse_url($adres, PHP_URL_PATH);

            $this->assertLinkiUdostepniania('https://kuking.pl'.$sciezka, 'https://kuking.pl'.$sciezka);
        }
    }

    public function test_po_linkach_udostepniania_reszta_strony_zostaje_na_hoscie_zadania(): void
    {
        config(['app.url' => 'https://kuking.pl']);
        [$przepis] = $this->publiczneTresci();
        $sciezka = (string) parse_url(route('recipes.show', $przepis), PHP_URL_PATH);

        $this->get('https://www.kuking.pl'.$sciezka)->assertOk();

        // Wymuszony korzeń nie przecieka poza budowę adresu do udostępnienia.
        $this->assertSame('https://www.kuking.pl/odkryj', url('/odkryj'));
    }
}
