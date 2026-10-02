<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Recipe;
use App\Models\User;
use App\Support\CytatKroku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Zapytaj o ten krok” (#2556, V2): zwykły link przy kroku wstawia do ISTNIEJĄCEGO
 * formularza komentarza krótki cytat. Bez nowych tabel i bez JavaScriptu; komentarz
 * idzie tą samą trasą, więc moderacja, limity i powiadomienia zostają bez zmian.
 */
class ZapytajOKrokPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(string $krok1 = 'Mieszaj, aż zgęstnieje.', string $visibility = 'public'): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $visibility,
        ]);
        $przepis->steps()->create(['position' => 0, 'instruction' => $krok1]);
        $przepis->steps()->create(['position' => 1, 'instruction' => 'Podawaj ciepłe.']);

        return $przepis;
    }

    private function adres(Recipe $przepis, ?string $krok = null): string
    {
        return route('recipes.show', $krok === null ? ['recipe' => $przepis->slug] : ['recipe' => $przepis->slug, 'krok' => $krok]);
    }

    public function test_zalogowana_osoba_widzi_link_przy_kazdym_kroku_z_numerem_w_adresie(): void
    {
        $przepis = $this->przepis();

        $html = $this->actingAs($this->user('pytajaca'))->get($this->adres($przepis))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'Zapytaj o ten krok'));
        $this->assertStringContainsString('krok=1#nowy-komentarz', $html);
        $this->assertStringContainsString('krok=2#nowy-komentarz', $html);
        $this->assertStringContainsString('(krok 2)', $html);
    }

    public function test_gosc_autor_i_konto_zawieszone_nie_dostaja_linku_ani_cytatu(): void
    {
        $przepis = $this->przepis();

        $gosc = $this->get($this->adres($przepis, '1'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Zapytaj o ten krok', $gosc);
        $this->assertStringNotContainsString('Pytanie o krok', $gosc);

        $autor = $this->actingAs($przepis->author)->get($this->adres($przepis))->assertOk()->getContent();
        $this->assertStringNotContainsString('Zapytaj o ten krok', $autor);

        $zawieszona = $this->user('zawieszona', ['status' => User::STATUS_SUSPENDED]);
        $html = $this->actingAs($zawieszona)->get($this->adres($przepis, '1'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Zapytaj o ten krok', $html);
        $this->assertStringNotContainsString('Pytanie o krok', $html);
    }

    public function test_adres_z_krokiem_wstawia_do_pola_cytat_a_nic_nie_wysyla(): void
    {
        $przepis = $this->przepis();

        $html = $this->actingAs($this->user('pytajaca'))->get($this->adres($przepis, '1'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<textarea[^>]*name="body"[^>]*>Pytanie o krok 1: „Mieszaj, aż zgęstnieje\.”/u', $html);
        $this->assertStringContainsString('Nic jeszcze nie zostało wysłane', $html);
        $this->assertSame(0, Comment::count());
    }

    public function test_zwykla_strona_i_bledne_numery_zostawiaja_pole_puste(): void
    {
        $przepis = $this->przepis();
        $pytajaca = $this->user('pytajaca');

        foreach ([null, '0', '3', '-1', '1.5', 'abc', '1; drop', '99999'] as $krok) {
            $html = $this->actingAs($pytajaca)->get($this->adres($przepis, $krok))->assertOk()->getContent();
            $this->assertStringNotContainsString('Pytanie o krok', $html, 'krok='.var_export($krok, true));
            $this->assertStringNotContainsString('Nic jeszcze nie zostało wysłane', $html);
        }
    }

    public function test_tablica_w_parametrze_kroku_nie_psuje_strony(): void
    {
        $przepis = $this->przepis();

        $this->actingAs($this->user('pytajaca'))
            ->get(route('recipes.show', $przepis->slug).'?krok[]=1')
            ->assertOk();
    }

    public function test_cytat_jest_krotki_i_tnie_na_granicy_slowa(): void
    {
        $dlugi = str_repeat('Mieszaj powoli drewnianą łyżką. ', 40);
        $przepis = $this->przepis($dlugi);

        $html = $this->actingAs($this->user('pytajaca'))->get($this->adres($przepis, '1'))->assertOk()->getContent();

        preg_match('/Pytanie o krok 1: „(.*?)”/su', $html, $m);
        $this->assertNotEmpty($m, 'Cytatu nie ma w polu.');
        $this->assertLessThanOrEqual(CytatKroku::LIMIT_ZNAKOW + 1, mb_strlen(html_entity_decode($m[1])));
        $this->assertStringEndsWith('…', $m[1]);
    }

    public function test_cytat_jest_renderowany_jako_tekst_bez_html(): void
    {
        $przepis = $this->przepis('<script>alert(1)</script> Mieszaj.');

        $html = $this->actingAs($this->user('pytajaca'))->get($this->adres($przepis, '1'))->assertOk()->getContent();

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Mieszaj.', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }

    public function test_wpisany_tekst_po_bledzie_walidacji_nie_jest_nadpisany_cytatem(): void
    {
        $przepis = $this->przepis();
        $pytajaca = $this->user('pytajaca');
        $zaDlugi = 'Pytanie własne. '.str_repeat('a', 4001);

        $this->actingAs($pytajaca)->from($this->adres($przepis, '1'))
            ->post(route('recipes.comment', $przepis->slug), ['body' => $zaDlugi])
            ->assertRedirect($this->adres($przepis, '1'))
            ->assertSessionHasErrors('body');

        $html = $this->actingAs($pytajaca)->get($this->adres($przepis, '1'))->assertOk()->getContent();

        $this->assertStringContainsString('Pytanie własne. aaaa', $html);
        $this->assertStringNotContainsString('Pytanie o krok 1', $html);
        $this->assertStringNotContainsString('Nic jeszcze nie zostało wysłane', $html);
        $this->assertSame(0, Comment::count());
    }

    public function test_pytanie_z_cytatem_to_zwykly_komentarz_a_zmiana_kroku_go_nie_zmienia(): void
    {
        $przepis = $this->przepis();
        $pytajaca = $this->user('pytajaca');
        $tresc = "Pytanie o krok 1: „Mieszaj, aż zgęstnieje.”\n\nJak poznać, że już?";

        $this->actingAs($pytajaca)->post(route('recipes.comment', $przepis->slug), ['body' => $tresc])
            ->assertRedirect();

        $przepis->steps()->where('position', 0)->update(['instruction' => 'Zupełnie inny krok.']);
        $przepis->steps()->where('position', 1)->update(['position' => 5]);

        $this->assertSame($tresc, Comment::query()->sole()->body);
        $html = $this->actingAs($pytajaca)->get($this->adres($przepis))->assertOk()->getContent();
        $this->assertStringContainsString('Mieszaj, aż zgęstnieje.', $html);
    }

    public function test_komentarz_bez_cytatu_nadal_dziala(): void
    {
        $przepis = $this->przepis();

        $this->actingAs($this->user('pytajaca'))->post(route('recipes.comment', $przepis->slug), ['body' => 'Smaczne!'])
            ->assertRedirect();

        $this->assertSame('Smaczne!', Comment::query()->sole()->body);
    }

    public function test_przepis_niewidoczny_dla_obcego_nie_ujawnia_cytatu(): void
    {
        $przepis = $this->przepis('Tajny krok babci.', 'private');

        $odpowiedz = $this->actingAs($this->user('obca'))->get($this->adres($przepis, '1'));

        $odpowiedz->assertForbidden();
        $this->assertStringNotContainsString('Tajny krok babci', $odpowiedz->getContent());
        $this->actingAs($this->user('obca2'))->post(route('recipes.comment', $przepis->slug), ['body' => 'x'])->assertForbidden();
    }
}
