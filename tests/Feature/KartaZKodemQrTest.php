<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Sharing\KartaZKodemQr;
use App\Models\Block;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\AdresKanoniczny;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Karta z kodem QR” publicznego przepisu i profilu (#2349, F10).
 *
 * Kod SVG nie jest tu dekodowany. Dowodem, że koduje dokładnie kanoniczny
 * adres, jest równość z `kodSvg($kanoniczny)` i RÓŻNICA względem kodu tego
 * samego adresu z parametrem — QR jest deterministyczny, więc inny napis
 * dałby inny obraz.
 */
class KartaZKodemQrTest extends TestCase
{
    use RefreshDatabase;

    private function kanoniczny(string $nazwaTrasy, string $parametr): string
    {
        return AdresKanoniczny::zbuduj(fn (): string => route($nazwaTrasy, $parametr));
    }

    private function svg(string $html): string
    {
        $this->assertSame(1, preg_match('#<div class="karta-qr-kod"[^>]*>\s*(<svg.*?</svg>)#s', $html, $m), 'Na karcie nie ma kodu SVG.');

        return $m[1];
    }

    private function publicznyPrzepis(array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create($atrybuty);
    }

    // ── przepis: pozytyw ────────────────────────────────────────────────

    public function test_karta_przepisu_ma_qr_i_adres_tekstem_z_tego_samego_kanonicznego_url(): void
    {
        $przepis = $this->publicznyPrzepis();
        $adres = $this->kanoniczny('recipes.show', $przepis->slug);

        $html = (string) $this->get(route('recipes.qr-card', $przepis->slug))
            ->assertOk()
            ->assertSee($przepis->title)
            ->assertSee('Konto nie jest potrzebne, żeby to przeczytać.')
            ->assertSee('<p class="karta-qr-adres">'.e($adres).'</p>', false)
            ->getContent();

        $svg = $this->svg($html);
        $this->assertSame(app(KartaZKodemQr::class)->kodSvg($adres), $svg, 'QR nie koduje kanonicznego adresu przepisu.');
        $this->assertNotSame(app(KartaZKodemQr::class)->kodSvg($adres.'?druk=1'), $svg);
        $this->assertStringStartsWith('<svg', $svg);
    }

    public function test_parametry_strony_karty_nie_wchodza_do_kodu_ani_do_adresu_tekstem(): void
    {
        $przepis = $this->publicznyPrzepis();
        $adres = $this->kanoniczny('recipes.show', $przepis->slug);

        $html = (string) $this->get(route('recipes.qr-card', $przepis->slug).'?druk=1&utm_source=kgw&token=abc&session=xyz')
            ->assertOk()
            ->getContent();

        $this->assertSame(app(KartaZKodemQr::class)->kodSvg($adres), $this->svg($html));
        $this->assertStringContainsString('<p class="karta-qr-adres">'.e($adres).'</p>', $html);
        $this->assertStringNotContainsString('utm_source', $html);
        $this->assertStringNotContainsString('token=abc', $html);
        $this->assertStringNotContainsString('session=xyz', $html);
        $this->assertStringNotContainsString('?', $adres, 'Kanoniczny adres przepisu nie ma parametrów.');
    }

    public function test_zalogowana_osoba_dostaje_ta_sama_karte_bez_swoich_danych(): void
    {
        $przepis = $this->publicznyPrzepis();
        $ola = $this->user('ola_wydruk', ['email' => 'ola.tajna@example.com']);

        $gosc = (string) $this->get(route('recipes.qr-card', $przepis->slug))->assertOk()->getContent();
        $zalogowana = (string) $this->actingAs($ola)->get(route('recipes.qr-card', $przepis->slug))->assertOk()->getContent();

        $this->assertSame($this->svg($gosc), $this->svg($zalogowana));
        // Belki serwisu (poza kartką) znają zalogowaną osobę; KARTKA — nie.
        $this->assertSame(1, preg_match('#<article class="sciagawka karta-qr.*?</article>#s', $zalogowana, $karta));
        $this->assertStringNotContainsString('ola.tajna@example.com', $karta[0]);
        $this->assertStringNotContainsString('ola_wydruk', $karta[0]);
        $this->assertStringNotContainsString('ola.tajna@example.com', $zalogowana);
    }

    public function test_strona_karty_ma_noindex_i_instrukcje_drukowania_bez_skryptu(): void
    {
        $przepis = $this->publicznyPrzepis();
        $odnosnik = route('recipes.qr-card', $przepis->slug).'?druk=1#jak-wydrukowac';

        $this->get(route('recipes.qr-card', $przepis->slug))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('href="'.e($odnosnik).'" rel="nofollow" data-drukuj-przepis>Wydrukuj kartę</a>', false)
            ->assertDontSee('id="jak-wydrukowac"', false);

        $this->get(route('recipes.qr-card', $przepis->slug).'?druk=1')
            ->assertOk()
            ->assertSee('<kbd>Ctrl</kbd> i <kbd>P</kbd>', false);
    }

    public function test_przepis_ma_odnosnik_do_karty_w_podziel_sie(): void
    {
        $przepis = $this->publicznyPrzepis();

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('href="'.e(route('recipes.qr-card', $przepis->slug)).'">Wydrukuj kartę z kodem</a>', false);
    }

    public function test_przepis_prywatny_nie_pokazuje_odnosnika_do_karty(): void
    {
        $autor = $this->user('autor_prywatnego');
        $przepis = $this->publicznyPrzepis(['author_id' => $autor->getKey(), 'visibility' => 'private']);

        $this->actingAs($autor)->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('Wydrukuj kartę z kodem');
    }

    // ── przepis: negatywy ───────────────────────────────────────────────

    public function test_przepis_prywatny_nie_ma_karty_nawet_u_autora(): void
    {
        $autor = $this->user('autor_priv');
        $przepis = $this->publicznyPrzepis(['author_id' => $autor->getKey(), 'visibility' => 'private']);

        $this->get(route('recipes.qr-card', $przepis->slug))->assertForbidden();
        $this->actingAs($autor)->get(route('recipes.qr-card', $przepis->slug))->assertNotFound();
    }

    public function test_przepis_tylko_dla_obserwujacych_nie_ma_karty(): void
    {
        $autor = $this->user('autor_obs');
        $przepis = $this->publicznyPrzepis(['author_id' => $autor->getKey(), 'visibility' => 'followers']);
        $obserwujaca = $this->user('obserwujaca');
        $obserwujaca->following()->attach($autor->getKey());

        $this->get(route('recipes.qr-card', $przepis->slug))->assertForbidden();
        $this->actingAs($obserwujaca)->get(route('recipes.qr-card', $przepis->slug))->assertNotFound();
        $this->actingAs($autor)->get(route('recipes.qr-card', $przepis->slug))->assertNotFound();
    }

    public function test_szkic_ukryty_i_zdjety_przepis_nie_maja_karty(): void
    {
        foreach ([Recipe::STATUS_DRAFT, Recipe::STATUS_HIDDEN, Recipe::STATUS_REMOVED] as $status) {
            $przepis = $this->publicznyPrzepis(['status' => $status]);

            $this->assertContains(
                $this->get(route('recipes.qr-card', $przepis->slug))->getStatusCode(),
                [403, 404],
                "Przepis w statusie {$status} dostał kartę.",
            );
        }
    }

    public function test_usuniety_przepis_nie_ma_karty(): void
    {
        $przepis = $this->publicznyPrzepis();
        $slug = $przepis->slug;
        $przepis->delete();

        $this->get(route('recipes.qr-card', $slug))->assertNotFound();
    }

    public function test_nieistniejacy_przepis_daje_404(): void
    {
        $this->get(route('recipes.qr-card', 'nie-ma-takiego-przepisu'))->assertNotFound();
    }

    public function test_przepis_zbanowanego_autora_nie_ma_karty(): void
    {
        $autor = $this->user('zbanowany', ['status' => User::STATUS_BANNED]);
        $przepis = $this->publicznyPrzepis(['author_id' => $autor->getKey()]);

        $this->get(route('recipes.qr-card', $przepis->slug))->assertForbidden();
    }

    public function test_przepis_autora_w_usuwaniu_nie_ma_karty(): void
    {
        $autor = $this->user('w_usuwaniu', ['status' => User::STATUS_PENDING_DELETE]);
        $przepis = $this->publicznyPrzepis(['author_id' => $autor->getKey()]);

        $this->get(route('recipes.qr-card', $przepis->slug))->assertForbidden();
    }

    public function test_osoba_zablokowana_przez_autora_nie_dostaje_karty(): void
    {
        $autor = $this->user('blokujacy');
        $przepis = $this->publicznyPrzepis(['author_id' => $autor->getKey()]);
        $zablokowana = $this->user('zablokowana');
        Block::create(['blocker_id' => $autor->getKey(), 'blocked_id' => $zablokowana->getKey()]);

        $this->actingAs($zablokowana)->get(route('recipes.qr-card', $przepis->slug))->assertForbidden();
    }

    // ── profil ──────────────────────────────────────────────────────────

    public function test_karta_profilu_z_publicznym_przepisem_ma_qr_i_kanoniczny_adres(): void
    {
        $autor = $this->user('Hanna_KGW', ['display_name' => 'Hanna z KGW']);
        $this->publicznyPrzepis(['author_id' => $autor->getKey()]);
        $adres = $this->kanoniczny('profile.show', 'Hanna_KGW');

        // Wielkość liter w adresie karty nie zmienia kodu: zapisana pisownia.
        $html = (string) $this->get('/@hanna_kgw/karta-qr?utm_campaign=x')
            ->assertOk()
            ->assertSee('Hanna z KGW')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('<p class="karta-qr-adres">'.e($adres).'</p>', false)
            ->getContent();

        $this->assertSame(app(KartaZKodemQr::class)->kodSvg($adres), $this->svg($html));
        $this->assertStringNotContainsString('utm_campaign', $html);
    }

    public function test_profil_ma_odnosnik_do_karty_gdy_jest_publiczna_tresc(): void
    {
        $autor = $this->user('ze_trescia');
        $this->publicznyPrzepis(['author_id' => $autor->getKey()]);

        $this->get(route('profile.show', 'ze_trescia'))
            ->assertOk()
            ->assertSee('href="'.e(route('profile.qr-card', 'ze_trescia')).'">Wydrukuj kartę z kodem</a>', false);
    }

    public function test_profil_bez_publicznej_tresci_nie_ma_karty_ani_odnosnika(): void
    {
        $this->user('pusty_profil');

        $this->get(route('profile.qr-card', 'pusty_profil'))->assertNotFound();
        $this->get(route('profile.show', 'pusty_profil'))
            ->assertOk()
            ->assertDontSee('Wydrukuj kartę z kodem');
    }

    public function test_profil_tylko_z_trescia_prywatna_nie_ma_karty_nawet_u_wlasciciela(): void
    {
        $autor = $this->user('tylko_prywatne');
        $this->publicznyPrzepis(['author_id' => $autor->getKey(), 'visibility' => 'private']);

        $this->get(route('profile.qr-card', 'tylko_prywatne'))->assertNotFound();
        $this->actingAs($autor)->get(route('profile.qr-card', 'tylko_prywatne'))->assertNotFound();
        $this->actingAs($autor)->get(route('profile.show', 'tylko_prywatne'))
            ->assertOk()
            ->assertDontSee('Wydrukuj kartę z kodem');
    }

    public function test_profil_zbanowanego_i_w_usuwaniu_nie_ma_karty(): void
    {
        foreach ([User::STATUS_BANNED, User::STATUS_PENDING_DELETE] as $i => $status) {
            $autor = $this->user("konto_{$i}", ['status' => $status]);
            $this->publicznyPrzepis(['author_id' => $autor->getKey()]);

            $this->assertContains(
                $this->get(route('profile.qr-card', "konto_{$i}"))->getStatusCode(),
                [403, 404],
                "Profil w statusie {$status} dostał kartę.",
            );
        }
    }

    public function test_profil_wymazanego_bez_tresci_nie_ma_karty(): void
    {
        $this->user('wymazany', ['status' => User::STATUS_ERASED, 'data_erased_at' => now()]);

        $this->get(route('profile.qr-card', 'wymazany'))->assertNotFound();
    }

    public function test_nieistniejacy_profil_daje_404(): void
    {
        $this->get(route('profile.qr-card', 'nikogo_nie_ma'))->assertNotFound();
    }

    public function test_profil_z_samym_publicznym_wpisem_ma_karte(): void
    {
        $autor = $this->user('wpisowa');
        Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->get(route('profile.qr-card', 'wpisowa'))->assertOk();
    }

    // ── kontrakt arkusza druku ──────────────────────────────────────────

    public function test_arkusz_druku_dzieli_rame_z_przepisem_i_mierzy_kod_w_centymetrach(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/wydruk-przepisu.css'));
        $druk = substr($css, (int) strpos($css, '@media print'));

        $this->assertStringContainsString('body:has(.karta-qr) .karta-qr-kod', $druk, 'Karta QR nie ma reguły druku kodu.');
        $this->assertMatchesRegularExpression('/\.karta-qr-kod \{[^}]*width: 9cm;/s', $druk, 'Kod QR na papierze ma mniej niż 9 cm.');
        $this->assertMatchesRegularExpression('/\.karta-qr-kod \{[^}]*background: #FFFFFF/s', $druk, 'Kod na papierze ma leżeć na białym polu.');
    }
}
