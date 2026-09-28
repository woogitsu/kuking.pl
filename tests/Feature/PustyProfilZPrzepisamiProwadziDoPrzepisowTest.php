<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Brak WPISÓW to nie brak TREŚCI (#2047).
 *
 * Profil z publicznym przepisem i bez wpisów mówił na domyślnej zakładce
 * „Wszystko”: „Ta osoba jeszcze nic nie pokazała”, choć nagłówek liczył
 * przepis, a zakładka „Przepisy” go pokazywała. Odrębne od #1380 (pusty
 * ROK archiwum) — tamto pilnuje `WspomnieniaTest`.
 */
class PustyProfilZPrzepisamiProwadziDoPrzepisowTest extends TestCase
{
    use RefreshDatabase;

    private const STARY_TYTUL = 'Ta osoba jeszcze nic nie pokazała';

    private const NOWY_TYTUL = 'Ta osoba nie ma jeszcze wpisów';

    public function test_gosc_na_profilu_z_samym_przepisem_dostaje_droge_do_przepisow(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create(['author_id' => $basia->getKey(), 'title' => 'Barszcz ukraiński']);

        $html = $this->stronaGoscia($basia);
        $xpath = $this->dokument($html);
        $link = $this->pustyStan(self::NOWY_TYTUL).'//a[contains(concat(" ", normalize-space(@class), " "), " btn-primary ")]';

        $this->assertStringNotContainsString(self::STARY_TYTUL, $html);
        $this->assertSame(1, $xpath->query($link)->length, 'Pusty stan nie ma przycisku do zakładki „Przepisy”.');
        $this->assertSame(
            route('profile.show', ['username' => 'basia', 'zakladka' => 'przepisy']),
            $xpath->evaluate('string('.$link.'/@href)'),
        );
        $this->assertSame('Zobacz przepisy', trim($xpath->evaluate('string('.$link.')')));

        // Przycisk prowadzi do niepustej listy.
        $this->get(route('profile.show', ['username' => 'basia', 'zakladka' => 'przepisy']))
            ->assertOk()
            ->assertSee('Barszcz ukraiński', escape: false);
    }

    public function test_profil_bez_zadnych_tresci_zostaje_przy_starym_komunikacie(): void
    {
        $basia = $this->user('basia');

        $xpath = $this->dokument($this->stronaGoscia($basia));

        $this->assertSame(1, $xpath->query($this->pustyStan(self::STARY_TYTUL))->length);
        $this->assertSame(0, $xpath->query($this->pustyStan(self::STARY_TYTUL).'//a')->length);
        $this->assertSame(0, $xpath->query($this->pustyStan(self::NOWY_TYTUL))->length);
    }

    public function test_niepubliczny_przepis_nie_daje_przycisku_gosciowi_ani_obcej_osobie(): void
    {
        $basia = $this->user('basia');
        $halina = $this->user('halina');
        Recipe::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'private']);
        Recipe::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'followers']);
        Recipe::factory()->draft()->create(['author_id' => $basia->getKey()]);
        Recipe::factory()->create(['author_id' => $basia->getKey(), 'status' => Recipe::STATUS_HIDDEN]);

        foreach ([$this->stronaGoscia($basia), $this->actingAs($halina)->get('/@basia')->assertOk()->getContent()] as $html) {
            $xpath = $this->dokument($html);

            $this->assertSame(1, $xpath->query($this->pustyStan(self::STARY_TYTUL))->length);
            $this->assertSame(0, $xpath->query($this->pustyStan(self::STARY_TYTUL).'//a')->length);
            $this->assertStringNotContainsString(self::NOWY_TYTUL, $html);
        }
    }

    public function test_przepis_dla_obserwujacych_prowadzi_obserwujaca_osobe_do_przepisow(): void
    {
        // Kontrola dodatnia tej samej bramki: widz, który przepis widzi, dostaje przycisk.
        $basia = $this->user('basia');
        $halina = $this->user('halina');
        Recipe::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'followers']);
        $halina->following()->attach($basia->getKey());

        $xpath = $this->dokument($this->actingAs($halina)->get('/@basia')->assertOk()->getContent());

        $this->assertSame(1, $xpath->query($this->pustyStan(self::NOWY_TYTUL).'//a')->length);
    }

    public function test_wlasny_profil_z_przepisem_zachowuje_pusty_stan_wlasciciela(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create(['author_id' => $basia->getKey()]);

        $html = $this->actingAs($basia)->get('/@basia')->assertOk()->getContent();
        $xpath = $this->dokument($html);
        $link = $this->pustyStan('Twoje archiwum jest jeszcze puste').'//a';

        $this->assertSame(1, $xpath->query($link)->length);
        $this->assertSame(route('posts.create'), $xpath->evaluate('string('.$link.'/@href)'));
        $this->assertStringNotContainsString(self::NOWY_TYTUL, $html);
    }

    private function stronaGoscia(User $owner): string
    {
        Auth::logout();

        return $this->get('/@'.$owner->profile->username)->assertOk()->getContent();
    }

    private function dokument(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function pustyStan(string $title): string
    {
        return '//div[contains(concat(" ", normalize-space(@class), " "), " marka-profil-archiwum ")]'
            .'//div[contains(concat(" ", normalize-space(@class), " "), " empty-state ")]'
            .'[.//p[contains(concat(" ", normalize-space(@class), " "), " empty-state-title ") and normalize-space()="'.$title.'"]]';
    }
}
