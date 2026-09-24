<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Paginator;
use Tests\TestCase;

/**
 * Znacznik `<x-show-more>`, na którym opiera się dokładanie porcji (#986).
 *
 * Samo dokładanie — kliknięcia, fokus, `aria-live`, odświeżenie, powrót,
 * błąd — sprawdza w prawdziwym Chromium `scripts/przegladarka/pokaz-wiecej.test.mjs`
 * na atrapie serwera. Ten test pilnuje drugiej połowy umowy: że PRAWDZIWE
 * ekrany Laravela dają skryptowi to, czego on potrzebuje, i że bez skryptu
 * odnośnik mówi prawdę.
 *
 *  1. Etykieta bez skryptu to „Następna strona …”, bo odnośnik otwiera stronę
 *     z samą kolejną porcją. „Pokaż więcej …” stoi tylko w atrybucie, który
 *     skrypt zamienia na przycisk.
 *  2. `data-pokaz-wiecej-lista` wskazuje element, który NAPRAWDĘ jest na
 *     stronie — i na następnej też, bo stamtąd skrypt wyjmuje nową porcję.
 *     Literówka w `id` zostawiłaby sam odnośnik (skrypt odmawia ulepszenia),
 *     więc tego nie widać gołym okiem.
 *  3. Każdy bezpośredni element listy ma tożsamość (`data-klucz` albo `id`),
 *     a przejście przez dwie porcje daje zbiory rozłączne w stałej kolejności.
 *  4. Dwie listy pod przepisem mają różne klucze paginatora, więc każda
 *     pamięta w adresie własną liczbę porcji.
 */
class PokazWiecejDokladaPorcjeTest extends TestCase
{
    use RefreshDatabase;

    public function test_odkrywanie_z_kursorem_daje_liste_do_dokladania(): void
    {
        $autor = $this->user('autor986');
        for ($i = 0; $i < 20; $i++) {
            Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'Wpis986-'.$i, 'published_at' => now()->subMinutes($i)]);
        }

        $pierwsza = $this->get(route('discover'))->assertOk()->getContent();
        [$blok, $lista] = $this->blok($pierwsza, 'cursor', 'lista-wpisow');

        $this->assertSame('Następna strona wpisów', $this->tekst($this->odnosnik($blok)));
        $this->assertSame('Pokaż więcej wpisów', $blok->getAttribute('data-pokaz-wiecej-etykieta'));
        $this->assertSame('wpisów', $blok->getAttribute('data-pokaz-wiecej-czego'));
        $this->assertSame(1, $this->xpath($pierwsza)->query('//*[@data-pokaz-wiecej]//*[@aria-live="polite"]')->length);

        $klucze1 = $this->klucze($lista);
        $druga = $this->get($this->odnosnik($blok)->getAttribute('href'))->assertOk()->getContent();
        [, $lista2] = $this->blok($druga, 'cursor', 'lista-wpisow', ostatnia: true);
        $klucze2 = $this->klucze($lista2);

        $this->assertSame([], array_values(array_intersect($klucze1, $klucze2)), 'Porcje nie mogą się pokrywać.');
        $this->assertCount(20, array_merge($klucze1, $klucze2));

        $oczekiwane = Post::query()->orderByDesc('published_at')->orderByDesc('id')->pluck('id')
            ->map(fn ($id) => 'wpis-'.$id)->all();
        $this->assertSame($oczekiwane, array_merge($klucze1, $klucze2), 'Porcja 1 + porcja 2 = cała lista w kolejności feedu.');
    }

    public function test_dwie_listy_pod_przepisem_maja_wlasne_klucze_i_kontenery(): void
    {
        $widz = $this->user('widz986');
        $autor = $this->user('autor986p');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $limit = (int) config('kuking.comments.page_size');
        for ($i = 0; $i < $limit + 2; $i++) {
            Comment::create(['recipe_id' => $przepis->getKey(), 'author_id' => $autor->getKey(), 'body' => 'Komentarz986-'.$i, 'status' => Comment::STATUS_PUBLISHED, 'created_at' => now()->subMinutes(100 - $i)]);
        }
        for ($i = 0; $i < 14; $i++) {
            CookedEvent::factory()->create(['recipe_id' => $przepis->getKey(), 'user_id' => $autor->getKey(), 'note' => 'Wykonanie986-'.$i, 'cooked_at' => now()->subMinutes($i)]);
        }

        $html = $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        [$komentarze, $listaKomentarzy] = $this->blok($html, 'komentarze', 'lista-komentarzy');
        [$wykonania, $listaWykonan] = $this->blok($html, 'wykonania', 'lista-wykonan');
        $this->assertSame('Następna strona komentarzy', $this->tekst($this->odnosnik($komentarze)));
        $this->assertSame('Następna strona wykonań', $this->tekst($this->odnosnik($wykonania)));
        $this->assertCount($limit, $this->klucze($listaKomentarzy));
        $this->assertCount(12, $this->klucze($listaWykonan));

        foreach ([['komentarze', 'lista-komentarzy', $komentarze, $listaKomentarzy], ['wykonania', 'lista-wykonan', $wykonania, $listaWykonan]] as [$klucz, $id, $blok, $lista]) {
            $dalej = $this->get($this->odnosnik($blok)->getAttribute('href'))->assertOk()->getContent();
            [, $nowa] = $this->blok($dalej, $klucz, $id, ostatnia: true);
            $this->assertCount(2, $this->klucze($nowa), 'Druga porcja listy '.$klucz);
            $this->assertSame([], array_values(array_intersect($this->klucze($lista), $this->klucze($nowa))));
        }
    }

    public function test_ekran_bez_wskazanej_listy_zostaje_przy_samym_odnosniku(): void
    {
        $paginator = new Paginator(range(1, 3), 2, 1, ['path' => '/lista']);
        $html = $this->blade('<x-show-more :paginator="$p" czego="osób" />', ['p' => $paginator]);

        $html->assertSee('Następna strona osób', false)
            ->assertSee('href="/lista?page=2"', false)
            ->assertSee('data-pokaz-wiecej="page"', false)
            ->assertDontSee('data-pokaz-wiecej-lista', false);
    }

    /** @return array{DOMElement, DOMElement} blok przycisku i lista, do której dokleja */
    private function blok(string $html, string $klucz, string $id, bool $ostatnia = false): array
    {
        $xpath = $this->xpath($html);
        $listy = $xpath->query('//*[@id="'.$id.'"]');
        $this->assertSame(1, $listy->length, 'Na stronie ma być dokładnie jedna lista #'.$id);
        $lista = $listy->item(0);
        $this->assertInstanceOf(DOMElement::class, $lista);

        $bloki = $xpath->query('//*[@data-pokaz-wiecej="'.$klucz.'"]');
        if ($ostatnia) {
            $this->assertSame(0, $bloki->length, 'Ostatnia porcja nie ma przycisku '.$klucz);

            return [$lista, $lista];
        }

        $this->assertSame(1, $bloki->length, 'Jeden przycisk dla klucza '.$klucz);
        $blok = $bloki->item(0);
        $this->assertInstanceOf(DOMElement::class, $blok);
        $this->assertSame($id, $blok->getAttribute('data-pokaz-wiecej-lista'));

        return [$blok, $lista];
    }

    /** @return list<string> */
    private function klucze(DOMElement $lista): array
    {
        $klucze = [];
        foreach ($lista->childNodes as $dziecko) {
            if (! $dziecko instanceof DOMElement) {
                continue;
            }
            $klucz = $dziecko->getAttribute('data-klucz') ?: $dziecko->getAttribute('id');
            $this->assertNotSame('', $klucz, 'Element listy #'.$lista->getAttribute('id').' bez `data-klucz` ani `id`.');
            $klucze[] = $klucz;
        }
        $this->assertSame($klucze, array_values(array_unique($klucze)), 'Klucze w jednej porcji się powtarzają.');

        return $klucze;
    }

    private function odnosnik(DOMElement $blok): DOMElement
    {
        $a = $blok->getElementsByTagName('a')->item(0);
        $this->assertInstanceOf(DOMElement::class, $a);

        return $a;
    }

    private function tekst(DOMElement $element): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($poprzednie);
        }

        return new DOMXPath($dom);
    }
}
