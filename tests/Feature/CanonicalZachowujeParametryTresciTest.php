<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Tests\TestCase;

/**
 * Canonical i `og:url` zachowują parametry wybierające treść (issue #963).
 *
 * Layout brał `url()->current()`, więc druga strona profilu, zakładka
 * „Przepisy" czy filtr Poradźcie wskazywały jako kanoniczną stronę pierwszą
 * — wbrew `docs/seo/SEO_TECHNICAL.md` §1.2. Parametry śledzące i nieznane
 * nadal mają znikać. Kontrola ujemna: powrót do `url()->current()` oblewa
 * przypadki dalszych stron, zakładek i filtrów.
 */
class CanonicalZachowujeParametryTresciTest extends TestCase
{
    use RefreshDatabase;

    private function assertKanoniczny(string $adres, string $oczekiwany): void
    {
        $html = $this->get($adres)->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.e($oczekiwany).'">', $html);
        $this->assertStringContainsString('<meta property="og:url" content="'.e($oczekiwany).'">', $html);
    }

    public function test_druga_strona_profilu_ma_wlasny_canonical_a_pierwsza_bez_parametrow(): void
    {
        $this->user('basia');
        $profil = route('profile.show', 'basia');

        $this->assertKanoniczny($profil, $profil);
        $this->assertKanoniczny($profil.'?page=1', $profil);
        $this->assertKanoniczny($profil.'?page=2', $profil.'?page=2');
    }

    public function test_zakladka_i_rok_profilu_zostaja_w_stalej_kolejnosci_a_utm_znika(): void
    {
        $this->user('basia');
        $profil = route('profile.show', 'basia');

        $this->assertKanoniczny($profil.'?zakladka=przepisy', $profil.'?zakladka=przepisy');
        $this->assertKanoniczny(
            $profil.'?utm_source=facebook&page=2&smiec=1&zakladka=ugotowane',
            $profil.'?zakladka=ugotowane&page=2',
        );
        $this->assertKanoniczny($profil.'?rok=2025&fbclid=abc', $profil.'?rok=2025');
    }

    public function test_wartosci_ignorowane_przez_kontroler_nie_trafiaja_do_canonical(): void
    {
        $this->user('basia');
        $profil = route('profile.show', 'basia');

        // Kontroler pokazuje wtedy zakładkę domyślną i całe archiwum.
        $this->assertKanoniczny($profil.'?zakladka=cokolwiek&rok=12', $profil);
        $this->assertKanoniczny($profil.'?page=abc', $profil);
    }

    public function test_druga_strona_spisu_tagow_ma_wlasny_canonical(): void
    {
        $this->assertKanoniczny(route('tags.index').'?page=2&utm_medium=email', route('tags.index').'?page=2');
    }

    public function test_odkrywaj_zachowuje_kursor(): void
    {
        $kursor = (new Cursor(['published_at' => '2026-09-01 12:00:00', 'id' => '9f2c1e2a-1b3d-4e5f-8a6b-7c8d9e0f1a2b']))->encode();

        $this->assertKanoniczny(route('discover').'?cursor='.$kursor.'&utm_source=x', route('discover').'?cursor='.$kursor);
        $this->assertKanoniczny(route('discover').'?cursor=nieczytelny', route('discover'));
    }

    public function test_poradzcie_zachowuja_filtr_tag_i_kursor_w_stalej_kolejnosci(): void
    {
        config(['kuking.questions.enabled' => true]);
        $tag = Tag::factory()->create();
        $kursor = (new Cursor(['published_at' => '2026-09-01 12:00:00', 'id' => '9f2c1e2a-1b3d-4e5f-8a6b-7c8d9e0f1a2b']))->encode();
        $pytania = route('questions.index');

        $this->assertKanoniczny(
            $pytania.'?cursor='.$kursor.'&tag='.$tag->slug.'&utm_campaign=x&filtr=bez-odpowiedzi',
            $pytania.'?filtr=bez-odpowiedzi&tag='.$tag->slug.'&cursor='.$kursor,
        );
        // „najnowsze" to widok domyślny, nie osobna strona.
        $this->assertKanoniczny($pytania.'?filtr=najnowsze', $pytania);
    }

    public function test_parametry_obce_dla_trasy_nie_trafiaja_do_canonical(): void
    {
        // `?page=` nic nie zmienia na stronie Odkrywaj (kursor), więc odpada.
        $this->assertKanoniczny(route('discover').'?page=2&zakladka=przepisy', route('discover'));
    }
}
