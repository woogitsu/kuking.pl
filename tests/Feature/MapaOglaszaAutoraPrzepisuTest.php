<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mapa strony ogłasza autora, który ma sam przepis.
 *
 * DLACZEGO TEN PLIK POWSTAŁ
 * `SitemapController` pytał o profile warunkiem `whereHas('user.posts')`
 * — czyli o WPISY. Przepisy nie liczyły się wcale, choć nagłówek tego samego
 * pliku obiecuje „profile, które mają co najmniej jedną publiczną treść",
 * a `docs/seo/SEO_TECHNICAL.md` §3 stawia w wierszu „Profil z ≥1 publiczną
 * treścią" wprost `index, follow`.
 *
 * WADA DŁUGO SIĘ NIE POKAZYWAŁA I TO JEST W NIEJ NAJGORSZE.
 * Publikacja przepisu zakłada zapowiedź — wpis z `recipe_id` i pustym
 * `body`, publiczny. Dopóki zapowiedź żyje, profil wchodzi do mapy „przy
 * okazji", więc pomiar na świeżym koncie niczego nie wykrywa. Wada wychodzi
 * dopiero wtedy, gdy zapowiedź zniknie: autor ją skasuje albo moderacja ją
 * ukryje. Wtedy mapa nadal ogłasza publiczny przepis, ale już nie autora,
 * który go napisał — i nikt tego nie zauważa, bo map nie odwiedzają ludzie.
 *
 * Dlatego dwa pierwsze testy poniżej celują dokładnie w te dwa stany, a nie
 * w „konto z samym przepisem". Na koncie z samym przepisem i żywą zapowiedzią
 * wada nie reprodukuje się i test byłby zielony bez poprawki.
 */
class MapaOglaszaAutoraPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Zwraca autora z jednym publicznym przepisem i nazwą profilu do szukania
     * w mapie. Zapowiedź zostaje nietknięta — kasuje ją dopiero test.
     *
     * @return array{0: User, 1: Recipe, 2: string}
     */
    private function autorZPublicznymPrzepisem(string $nazwaProfilu): array
    {
        $autor = User::factory()->create();
        $autor->profile()->update(['username' => $nazwaProfilu]);

        $przepis = app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: [
                'title' => 'Żurek na zakwasie',
                'visibility' => 'public',
                'source_type' => 'own',
            ],
            ingredients: [['text' => 'zakwas żytni']],
            steps: [['instruction' => 'Zagotuj wywar i wlej zakwas.']],
            publish: true,
        );

        return [$autor, $przepis, $nazwaProfilu];
    }

    private function mapa(): string
    {
        $odpowiedz = $this->get('/sitemap.xml');
        $odpowiedz->assertOk();

        return (string) $odpowiedz->getContent();
    }

    public function test_profil_zostaje_w_mapie_gdy_autor_skasowal_zapowiedz_przepisu(): void
    {
        [$autor, $przepis, $nazwa] = $this->autorZPublicznymPrzepisem('zurkowa');

        // Autor kasuje zapowiedź. Przepis zostaje publiczny.
        Post::query()->where('author_id', $autor->getKey())->delete();

        $this->assertSame(
            0,
            Post::query()->where('author_id', $autor->getKey())->count(),
            'Zapowiedź miała zniknąć — bez tego test nie mierzy tego, co trzeba.',
        );

        $mapa = $this->mapa();

        $this->assertStringContainsString(
            $przepis->slug,
            $mapa,
            'Publiczny przepis wypadł z mapy — to już inna wada niż badana.',
        );
        $this->assertStringContainsString(
            $nazwa,
            $mapa,
            'Mapa ogłasza przepis, ale nie autora, który go napisał. '
            .'Profil z publiczną treścią ma być w mapie (SEO_TECHNICAL.md §3).',
        );
    }

    public function test_profil_zostaje_w_mapie_gdy_moderacja_ukryla_zapowiedz(): void
    {
        [$autor, $przepis, $nazwa] = $this->autorZPublicznymPrzepisem('pierogowa');

        // Moderacja chowa zapowiedź. Przepis zostaje publiczny.
        Post::query()
            ->where('author_id', $autor->getKey())
            ->update(['status' => 'hidden']);

        $mapa = $this->mapa();

        $this->assertStringContainsString($przepis->slug, $mapa);
        $this->assertStringContainsString(
            $nazwa,
            $mapa,
            'Ukryta zapowiedź wypchnęła autora z mapy, choć jego przepis '
            .'nadal jest publiczny.',
        );
    }

    /**
     * Granica nie przesunęła się w drugą stronę: puste konto to nadal cienka
     * treść i mapa go NIE ogłasza (SEO_TECHNICAL.md §3, wiersz „Profil bez
     * żadnej publicznej treści").
     */
    public function test_profil_bez_zadnej_publicznej_tresci_nadal_nie_wchodzi_do_mapy(): void
    {
        $autor = User::factory()->create();
        $autor->profile()->update(['username' => 'popatrze']);

        $this->assertStringNotContainsString(
            'popatrze',
            $this->mapa(),
            'Konto bez żadnej publicznej treści trafiło do mapy — to cienka treść.',
        );
    }

    /**
     * Przepis nie-publiczny też nie otwiera drogi do mapy: liczy się
     * `publiclyVisible()`, a nie samo istnienie przepisu.
     */
    public function test_przepis_dla_obserwujacych_nie_wprowadza_profilu_do_mapy(): void
    {
        $autor = User::factory()->create();
        $autor->profile()->update(['username' => 'tylkodlaswoich']);

        app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: [
                'title' => 'Bigos z kapusty kiszonej',
                'visibility' => 'followers',
                'source_type' => 'own',
            ],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        // Zapowiedź takiego przepisu jest publiczna, więc żeby zmierzyć
        // SAM przepis, trzeba ją usunąć — inaczej to ona wpuszcza profil.
        Post::query()->where('author_id', $autor->getKey())->delete();

        $this->assertStringNotContainsString(
            'tylkodlaswoich',
            $this->mapa(),
            'Przepis widoczny tylko dla obserwujących wpuścił profil do mapy.',
        );
    }
}
