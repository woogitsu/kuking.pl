<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „WRÓĆ" W FORMULARZU ZGŁOSZENIA PROWADZI DO ZGŁASZANEJ TREŚCI (issue #795).
 *
 * CO BYŁO PRZEDTEM
 * `resources/views/pages/report.blade.php` linia 34 miała
 * `href="{{ url()->previous() }}"`. `previous()` bierze nagłówek `Referer`,
 * a gdy go nie ma — adres z sesji. Na stronę `/zglos/{type}/{id}` można
 * wejść wprost (odnośnik z powiadomienia, wklejony adres, odświeżenie,
 * a przede wszystkim powrót `back()` po błędzie walidacji), i wtedy
 * „poprzednim" adresem jest TEN SAM formularz. Przycisk „Wróć" zawracał
 * na siebie: człowiek, który właśnie zgłasza cudzą treść i chce się
 * wycofać, klikał i nic się nie zmieniało.
 *
 * CO JEST TERAZ
 * Cel „Wróć" wynika z tego, CO zgłaszamy — kontroler liczy go z celu
 * zgłoszenia (`ReportController::adresTresci()`), a nie z historii
 * przeglądarki. Zgłaszana treść jest jedynym miejscem, o którym wiadomo
 * na pewno, że istnieje i że człowiek chciał tam być.
 *
 * DLACZEGO TEST SPRAWDZA `href`, A NIE PRZEKIEROWANIE
 * Bo usterka jest w widoku. Asercja na samym „nie prowadzi do siebie"
 * przeszłaby też dla `href="/"` — czyli dla wyrzucenia człowieka na stronę
 * główną, co jest innym zachowaniem niż tu wybrane. Dlatego sprawdzamy
 * dokładny adres.
 */
class PowrotZeZgloszeniaProwadziDoTresciTest extends TestCase
{
    use RefreshDatabase;

    private function zglaszajacy(string $nazwa): User
    {
        return $this->user($nazwa);
    }

    private function wpis(): Post
    {
        return Post::factory()->for($this->user(), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    /** Adres z atrybutu `href` przycisku „Wróć" na wyrenderowanym formularzu. */
    private function adresPowrotu(string $html): string
    {
        $trafienia = [];
        preg_match('/<a[^>]*href="([^"]*)"[^>]*>\s*Wróć\s*<\/a>/u', $html, $trafienia);

        $this->assertNotEmpty(
            $trafienia,
            'Na formularzu zgłoszenia nie ma odnośnika „Wróć" — test stracił swój przedmiot.',
        );

        return html_entity_decode($trafienia[1], ENT_QUOTES, 'UTF-8');
    }

    private function formularz(User $kto, string $type, string $id, ?string $skad = null): string
    {
        $adres = route('reports.create', ['type' => $type, 'id' => $id]);

        $test = $this->actingAs($kto);

        if ($skad !== null) {
            $test = $test->from($skad);
        }

        return $test->get($adres)->assertOk()->getContent();
    }

    // ------------------------------------------------------------------
    // Sedno zgłoszenia #795: wejście wprost, bez dokąd wracać
    // ------------------------------------------------------------------

    public function test_powrot_nie_prowadzi_do_samego_formularza_przy_wejsciu_wprost(): void
    {
        $wpis = $this->wpis();
        $formularz = route('reports.create', ['type' => 'post', 'id' => $wpis->getKey()]);

        // `from($formularz)` odtwarza dokładnie sytuację z #795: przeglądarka
        // (albo `back()` po błędzie walidacji) podaje jako „poprzedni" ten sam
        // adres, na którym stoimy.
        $powrot = $this->adresPowrotu(
            $this->formularz($this->zglaszajacy('wprost795'), 'post', $wpis->getKey(), $formularz),
        );

        $this->assertNotSame(
            $formularz,
            $powrot,
            '„Wróć" prowadzi do formularza zgłoszenia, czyli donikąd (issue #795).',
        );
        $this->assertSame(route('posts.show', $wpis), $powrot);
    }

    public function test_powrot_bez_naglowka_referer_prowadzi_do_zglaszanego_wpisu(): void
    {
        $wpis = $this->wpis();

        $powrot = $this->adresPowrotu(
            $this->formularz($this->zglaszajacy('bezreferera795'), 'post', $wpis->getKey()),
        );

        $this->assertSame(route('posts.show', $wpis), $powrot);
    }

    // ------------------------------------------------------------------
    // Cel zależy od tego, CO zgłaszamy
    // ------------------------------------------------------------------

    public function test_powrot_ze_zgloszenia_przepisu_prowadzi_do_przepisu(): void
    {
        $przepis = Recipe::factory()->for($this->user(), 'author')->create();

        $powrot = $this->adresPowrotu(
            $this->formularz($this->zglaszajacy('przepis795'), 'recipe', $przepis->slug),
        );

        $this->assertSame(route('recipes.show', $przepis), $powrot);
    }

    public function test_powrot_ze_zgloszenia_ugotowania_prowadzi_do_ugotowania(): void
    {
        $ugotowanie = CookedEvent::factory()->create();

        $powrot = $this->adresPowrotu(
            $this->formularz($this->zglaszajacy('ugotowane795'), 'cooked_event', $ugotowanie->getKey()),
        );

        $this->assertSame(route('cooked.show', $ugotowanie), $powrot);
    }

    public function test_powrot_ze_zgloszenia_profilu_prowadzi_do_profilu(): void
    {
        $zglaszany = $this->user('zglaszany795');

        $powrot = $this->adresPowrotu(
            $this->formularz($this->zglaszajacy('profil795'), 'user', 'zglaszany795'),
        );

        $this->assertSame(route('profile.show', $zglaszany->profile->username), $powrot);
    }

    /**
     * Komentarz nie ma własnej strony — wraca się na treść, pod którą wisi,
     * z kotwicą na sam komentarz (`id="komentarz-{id}"`
     * w `components/comment-thread.blade.php`). Bez kotwicy człowiek
     * lądowałby na górze długiego wpisu i musiał szukać od nowa.
     */
    public function test_powrot_ze_zgloszenia_komentarza_prowadzi_do_tresci_z_kotwica(): void
    {
        $wpis = $this->wpis();
        $komentarz = Comment::factory()->for($this->user(), 'author')->create([
            'post_id' => $wpis->getKey(),
        ]);

        $powrot = $this->adresPowrotu(
            $this->formularz($this->zglaszajacy('komentarz795'), 'comment', $komentarz->getKey()),
        );

        $this->assertSame(route('posts.show', $wpis).'#komentarz-'.$komentarz->getKey(), $powrot);
    }
}
