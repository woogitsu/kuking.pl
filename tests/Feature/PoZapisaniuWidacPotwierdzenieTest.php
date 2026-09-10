<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Po „Zapisuję" widać, że się zapisało (issue #275, część 1 — usterka
 * użyteczności, nie decyzja produktowa).
 *
 * STAN SPRZED ZMIANY, ZMIERZONY W KODZIE
 * Potwierdzenie ISTNIAŁO: `CollectionController::savePost()` ustawiał
 * komunikat „Zapisane w zeszycie …", a `components/layout.blade.php` pokazuje
 * go w `.flash` z `aria-live="polite"`. Czego NIE BYŁO: śladu w miejscu, gdzie
 * człowiek kliknął. Komunikat stoi na GÓRZE strony, a „Zapisuję" klika się
 * w połowie feedu, więc po powrocie karta wyglądała dokładnie tak samo jak
 * przed kliknięciem — z przyciskiem „Zapisuję" na swoim miejscu. Przy grupie
 * 50-75 to jest ta cisza, po której człowiek klika drugi raz albo uznaje, że
 * serwis nie działa.
 *
 * Dlatego ten plik pilnuje DWÓCH rzeczy naraz i obie muszą stać:
 *  1. komunikat po akcji nadal jest i jest w `.flash` (żeby nikt go po drodze
 *     nie zgubił „bo teraz jest stan na karcie");
 *  2. karta po zapisaniu mówi „Masz to w zeszycie" ZAMIAST „Zapisuję".
 *
 * BEZ JAVASCRIPTU (AGENTS.md §5)
 * Cały test to zwykłe żądania HTTP: formularz `POST`, przekierowanie, `GET`.
 * Żadnego skryptu nie ma jak wykonać, więc jeśli te asercje przechodzą,
 * potwierdzenie działa na wyłączonym JS. Dodatkowo
 * {@see self::test_formularz_zapisu_nie_zalezy_od_javascriptu()} sprawdza,
 * że sam formularz nie wisi na atrybutach sterowanych skryptem.
 */
class PoZapisaniuWidacPotwierdzenieTest extends TestCase
{
    use RefreshDatabase;

    private function wpisObcegoAutora(): Post
    {
        return Post::factory()->create([
            'author_id' => $this->user('autor_potwierdzenia')->getKey(),
            'visibility' => 'public',
        ]);
    }

    /** Wnętrze zielonej ramki komunikatu — nie całe HTML strony. */
    private function komunikat(string $html): ?string
    {
        $znalazl = preg_match('/<p class="flash">(.*?)<\/p>/su', $html, $trafienie);

        return $znalazl === 1 ? trim(html_entity_decode($trafienie[1])) : null;
    }

    /** Czy karta pokazuje stan „to jest w zeszycie". */
    private function stanZapisu(string $html): ?string
    {
        $znalazl = preg_match(
            '/<a[^>]*data-rola="stan-zapisu"[^>]*>(.*?)<\/a>/su',
            $html,
            $trafienie,
        );

        return $znalazl === 1 ? trim(strip_tags(html_entity_decode($trafienie[1]))) : null;
    }

    private function maPrzyciskZapisuje(string $html, Post $wpis): bool
    {
        $wzorzec = '/<form[^>]*action="[^"]*'.preg_quote(
            (string) parse_url(route('collections.save-post', $wpis), PHP_URL_PATH),
            '/',
        ).'"[^>]*>.*?<\/form>/su';

        return preg_match($wzorzec, $html) === 1;
    }

    public function test_po_kliknieciu_zapisuje_jest_komunikat_na_ekranie(): void
    {
        $widz = $this->user('widz_komunikatu');
        $wpis = $this->wpisObcegoAutora();

        $html = (string) $this->actingAs($widz)
            ->from(route('discover'))
            ->followingRedirects()
            ->post(route('collections.save-post', $wpis))
            ->assertOk()
            ->getContent();

        $this->assertNotNull(
            $this->komunikat($html),
            'Po „Zapisuję" nie ma żadnego komunikatu — to jest cisza ze zgłoszenia #275.',
        );

        $this->assertStringContainsString(
            'Zapisane w zeszycie',
            (string) $this->komunikat($html),
        );
    }

    public function test_po_zapisaniu_karta_mowi_ze_wpis_jest_w_zeszycie(): void
    {
        $widz = $this->user('widz_stanu');
        $wpis = $this->wpisObcegoAutora();

        // PRZED: karta ma przycisk „Zapisuję" i nie ma stanu. To jest kontrola
        // dodatnia dla asercji niżej — bez niej test przechodziłby także
        // wtedy, gdyby stan był na karcie ZAWSZE.
        $przed = (string) $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        $this->assertNull($this->stanZapisu($przed));
        $this->assertTrue($this->maPrzyciskZapisuje($przed, $wpis));

        app(SavePostToCollection::class)->handle($widz, $wpis);

        // PO: stan zamiast przycisku.
        $po = (string) $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        $this->assertSame(
            'Masz to w zeszycie',
            $this->stanZapisu($po),
            'Po zapisaniu karta musi to powiedzieć TAM, gdzie człowiek kliknął.',
        );

        $this->assertFalse(
            $this->maPrzyciskZapisuje($po, $wpis),
            'Przycisk „Zapisuję" nie ma stać pod wpisem, który już jest w zeszycie.',
        );
    }

    public function test_stan_dotyczy_tylko_tej_osoby_ktora_zapisala(): void
    {
        $ktoInny = $this->user('kto_inny');
        $widz = $this->user('widz_cudzego_zapisu');
        $wpis = $this->wpisObcegoAutora();

        app(SavePostToCollection::class)->handle($ktoInny, $wpis);

        $html = (string) $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        $this->assertNull(
            $this->stanZapisu($html),
            'Cudzy zapis nie może wyglądać na mój — „Masz to w zeszycie" znaczy „TY to masz".',
        );
        $this->assertTrue($this->maPrzyciskZapisuje($html, $wpis));
    }

    public function test_formularz_zapisu_nie_zalezy_od_javascriptu(): void
    {
        $widz = $this->user('widz_bez_js');
        $wpis = $this->wpisObcegoAutora();

        $html = (string) $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        $sciezka = (string) parse_url(route('collections.save-post', $wpis), PHP_URL_PATH);

        $this->assertSame(
            1,
            preg_match('/<form[^>]*action="[^"]*'.preg_quote($sciezka, '/').'"[^>]*>(.*?)<\/form>/su', $html, $formularz),
        );

        foreach (['wire:', 'x-on:', 'onclick', 'onsubmit', 'data-turbo'] as $zakazane) {
            $this->assertStringNotContainsString(
                $zakazane,
                $formularz[0],
                'Zapis do zeszytu musi działać bez JavaScriptu (AGENTS.md §5).',
            );
        }
    }
}
