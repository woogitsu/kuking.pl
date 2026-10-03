<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\UpdateCollectionItemNote;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NotatkaZDalszejPorcjiZeszytuTest extends TestCase
{
    use RefreshDatabase;

    public function test_blad_dlugosci_na_dalszej_porcji_przepisow_zachowuje_wpisany_tekst(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->id, 'name' => 'Na później', 'visibility' => 'private']);

        foreach (range(1, 13) as $numer) {
            $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'visibility' => 'public', 'title' => 'Przepis '.$numer]);
            $zeszyt->recipes()->attach($przepis->id, ['note' => 'Stara notatka']);
        }

        $cel = $zeszyt->recipes()->skip(12)->firstOrFail();
        $stronaDruga = route('collections.show', ['collection' => $zeszyt, 'page' => 2]);
        $this->actingAs($wlasciciel)->get($stronaDruga)->assertOk()
            ->assertSee($cel->title)
            ->assertSee('name="strona_notatki" value="2"', false);

        $tekst = str_repeat('ż', 501);
        $odpowiedz = $this->actingAs($wlasciciel)
            ->from(route('collections.show', ['collection' => $zeszyt, 'porcje_page' => 1]))
            ->patch(route('collections.note', ['collection' => $zeszyt, 'typ' => 'przepis', 'pozycja' => $cel->id]), [
                'note' => $tekst,
                '_wiersz' => 'notatka-przepis-'.$cel->id,
                'notatka_przed' => UpdateCollectionItemNote::odcisk('Stara notatka'),
                'strona_notatki' => 2,
            ]);

        $this->assertSame($stronaDruga, $odpowiedz->headers->get('Location'), 'NOTATKA_2829_DRUGA_STRONA');
        $html = (string) $this->actingAs($wlasciciel)->get($stronaDruga)->assertOk()->getContent();
        $this->assertStringContainsString($tekst, $html, 'NOTATKA_2829_TEKST');
        $this->assertStringContainsString('Skróć ją o 1 znak', $html, 'Błąd walidacji ma wrócić razem z tekstem.');
        $this->assertStringContainsString('notatka-przepis-'.$cel->id, $html);
        $this->assertStringContainsString('name="notatka_przed" value="'.UpdateCollectionItemNote::odcisk('Stara notatka').'"', $html);
        $this->assertSame('Stara notatka', DB::table('collection_items')->where('collection_id', $zeszyt->id)->where('recipe_id', $cel->id)->value('note'));

        // Błąd w walidacji samego żądania ma tę samą drogę powrotu.
        $this->actingAs($wlasciciel)->from(route('collections.show', $zeszyt))
            ->patch(route('collections.note', ['collection' => $zeszyt, 'typ' => 'przepis', 'pozycja' => $cel->id]), [
                'note' => 'Tekst po błędzie odcisku',
                '_wiersz' => 'notatka-przepis-'.$cel->id,
                'notatka_przed' => str_repeat('x', 129),
                'strona_notatki' => 2,
            ])->assertRedirect($stronaDruga);
        $this->actingAs($wlasciciel)->get($stronaDruga)->assertOk()->assertSee('Tekst po błędzie odcisku');

        // Poprawny zapis z dalszej porcji zachowuje zwykły powrót.
        $adresDokumentu = route('collections.show', ['collection' => $zeszyt, 'porcje_page' => 1]);
        $this->actingAs($wlasciciel)->from($adresDokumentu)
            ->patch(route('collections.note', ['collection' => $zeszyt, 'typ' => 'przepis', 'pozycja' => $cel->id]), [
                'note' => 'Nowa poprawna notatka',
                'notatka_przed' => UpdateCollectionItemNote::odcisk('Stara notatka'),
                'strona_notatki' => 2,
            ])->assertRedirect($adresDokumentu);
        $this->assertSame('Nowa poprawna notatka', DB::table('collection_items')->where('collection_id', $zeszyt->id)->where('recipe_id', $cel->id)->value('note'));
    }

    public function test_konflikt_notatki_wpisu_na_dalszej_porcji_nie_przenosi_tekstu_do_innego_wpisu(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->id, 'name' => 'Wpisy na potem', 'visibility' => 'private']);

        foreach (range(1, 14) as $numer) {
            $wpis = Post::factory()->create(['author_id' => $autor->id]);
            $zeszyt->posts()->attach($wpis->id, ['note' => 'Notatka '.$numer]);
        }

        $cel = $zeszyt->posts()->skip(12)->firstOrFail();
        $drugi = $zeszyt->posts()->skip(13)->firstOrFail();
        $pierwotna = (string) $cel->pivot->note;
        $stronaDruga = route('collections.show', ['collection' => $zeszyt, 'wpisy' => 2]);
        $this->actingAs($wlasciciel)->get($stronaDruga)->assertOk()
            ->assertSee('name="strona_notatki" value="2"', false);
        DB::table('collection_items')->where('collection_id', $zeszyt->id)->where('post_id', $cel->id)->update(['note' => 'Nowsza notatka']);

        $tekst = 'Mój tekst sprzed zmiany';
        $odpowiedz = $this->actingAs($wlasciciel)
            ->from(route('collections.show', ['collection' => $zeszyt, 'porcje_wpisy' => 1]))
            ->patch(route('collections.note', ['collection' => $zeszyt, 'typ' => 'wpis', 'pozycja' => $cel->id]), [
                'note' => $tekst,
                '_wiersz' => 'notatka-wpis-'.$cel->id,
                'notatka_przed' => UpdateCollectionItemNote::odcisk($pierwotna),
                'strona_notatki' => 2,
            ]);

        $this->assertSame($stronaDruga, $odpowiedz->headers->get('Location'), 'NOTATKA_2829_KONFLIKT_STRONA');
        $html = (string) $this->actingAs($wlasciciel)->get($stronaDruga)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $akcja = route('collections.note', ['collection' => $zeszyt, 'typ' => 'wpis', 'pozycja' => $cel->id]);
        $formularz = $xpath->query('//form[@action="'.$akcja.'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $formularz, 'NOTATKA_2829_FORMULARZ_CELU');
        $this->assertSame($tekst, $xpath->query('.//textarea[@name="note"]', $formularz)->item(0)?->textContent, 'NOTATKA_2829_TEKST_KONFLIKTU');
        $odcisk = $xpath->query('.//input[@name="notatka_przed"]', $formularz)->item(0);
        $this->assertInstanceOf(DOMElement::class, $odcisk);
        $this->assertSame(UpdateCollectionItemNote::odcisk('Nowsza notatka'), $odcisk->getAttribute('value'));
        $this->assertInstanceOf(DOMElement::class, $formularz->parentNode);
        $this->assertTrue($formularz->parentNode->hasAttribute('open'), 'Formularz z błędem ma być rozwinięty.');

        $obcaAkcja = route('collections.note', ['collection' => $zeszyt, 'typ' => 'wpis', 'pozycja' => $drugi->id]);
        $obcyFormularz = $xpath->query('//form[@action="'.$obcaAkcja.'"]')->item(0);
        $this->assertNotNull($obcyFormularz);
        $this->assertSame((string) $drugi->pivot->note, $xpath->query('.//textarea[@name="note"]', $obcyFormularz)->item(0)?->textContent, 'NOTATKA_2829_BEZ_PRZECIEKU');
        $this->assertSame('Nowsza notatka', DB::table('collection_items')->where('collection_id', $zeszyt->id)->where('post_id', $cel->id)->value('note'));
    }
}
