<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #1801 — „Komentarze (N)” pod zwykłym wpisem liczy WSZYSTKO, co widz
 * przeczyta po rozwinięciu rozmowy: komentarze główne razem z odpowiedziami
 * (decyzja właściciela z 26.09.2026).
 *
 * Do tego dnia licznik szedł po `Post::comments()`, czyli po samych
 * korzeniach wątków. Wpis z jednym komentarzem i trzema odpowiedziami
 * pokazywał na karcie „Komentarze (1)”, a strona wpisu — cztery wypowiedzi.
 * `LicznikKomentarzyTest` tego nie łapał, bo porównywał licznik z tą samą
 * relacją ograniczoną do korzeni.
 *
 * Tu liczba oczekiwana jest wpisana z palca i sprawdzana w końcowym HTML-u:
 * karty (profil autora — prawdziwy strumień z `withVisibleCommentCount()`)
 * i nagłówka rozmowy na stronie wpisu.
 *
 * Pytania liczą dalej wyłącznie odpowiedzi najwyższego poziomu (#372).
 *
 * D-309: ta sama reguła obowiązuje WSZĘDZIE, gdzie serwis pokazuje liczbę
 * komentarzy — także nagłówek rozmowy pod przepisem i pod „Ugotowałem”.
 */
class LicznikKomentarzyLiczyOdpowiedziTest extends TestCase
{
    use RefreshDatabase;

    private function komentarz(Post $wpis, User $autor, ?Comment $rodzic = null, array $atrybuty = []): Comment
    {
        return Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'author_id' => $autor->getKey(),
            'parent_id' => $rodzic?->getKey(),
            'status' => Comment::STATUS_PUBLISHED,
            ...$atrybuty,
        ]);
    }

    /** Liczba z karty wpisu na profilu autora — tak, jak widzi ją człowiek. */
    private function licznikNaKarcie(User $widz, User $autor, string $etykieta = 'Komentarze'): ?int
    {
        $html = (string) $this->actingAs($widz)
            ->get(route('profile.show', $autor->profile->username))
            ->assertOk()
            ->getContent();

        return preg_match('/'.$etykieta.'\s*\((\d+)\)/u', $html, $m) === 1 ? (int) $m[1] : null;
    }

    private function naglowekNaStronie(User $widz, Post $wpis): ?int
    {
        $html = (string) $this->actingAs($widz)
            ->get(route('posts.show', $wpis))
            ->assertOk()
            ->getContent();

        return preg_match('/<h2 id="komentarze">\s*Komentarze\s*\((\d+)\)/u', $html, $m) === 1 ? (int) $m[1] : null;
    }

    public function test_zwykly_wpis_z_korzeniem_i_trzema_odpowiedziami_pokazuje_cztery(): void
    {
        $autor = $this->user('autorka');
        $widz = $this->user('czytelniczka');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $korzen = $this->komentarz($wpis, $this->user('pierwsza'));
        foreach (['druga', 'trzecia', 'czwarta'] as $kto) {
            $this->komentarz($wpis, $this->user($kto), $korzen);
        }

        $this->assertSame(4, $this->licznikNaKarcie($widz, $autor));
        $this->assertSame(4, $this->naglowekNaStronie($widz, $wpis));
    }

    public function test_licznik_pomija_te_same_odpowiedzi_co_widok(): void
    {
        $autor = $this->user('autorka');
        $widz = $this->user('czytelniczka');
        $natret = $this->user('natret');
        $zbanowany = $this->user('zbanowany');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        // Widoczne: korzeń + jedna odpowiedź = 2.
        $korzen = $this->komentarz($wpis, $this->user('pierwsza'));
        $this->komentarz($wpis, $this->user('druga'), $korzen, ['body' => 'Widoczna odpowiedź']);

        // Niewidoczne odpowiedzi pod widocznym korzeniem.
        $this->komentarz($wpis, $natret, $korzen, ['body' => 'Odpowiedź osoby zablokowanej']);
        $this->komentarz($wpis, $zbanowany, $korzen, ['body' => 'Odpowiedź zbanowanego']);
        $this->komentarz($wpis, $this->user('ukryta'), $korzen, ['status' => Comment::STATUS_HIDDEN, 'body' => 'Ukryta odpowiedź']);
        $this->komentarz($wpis, $this->user('usunieta'), $korzen, ['status' => Comment::STATUS_REMOVED, 'body' => 'Usunięta odpowiedź']);
        $this->komentarz($wpis, $this->user('skasowana'), $korzen, ['body' => 'Skasowana odpowiedź'])->delete();

        // Widoczna odpowiedź pod korzeniem, którego widz nie widzi — strona
        // jej nie pokazuje (#1396), więc licznik też nie.
        $korzenNatreta = $this->komentarz($wpis, $natret, null, ['body' => 'Korzeń osoby zablokowanej']);
        $this->komentarz($wpis, $this->user('trzecia'), $korzenNatreta, ['body' => 'Odpowiedź pod zablokowanym']);

        app(BlockUser::class)->handle($widz, $natret);
        $zbanowany->ban();

        $strona = $this->actingAs($widz->refresh())->get(route('posts.show', $wpis))->assertOk();
        $strona->assertSee('Widoczna odpowiedź')
            ->assertDontSee('Odpowiedź osoby zablokowanej')
            ->assertDontSee('Odpowiedź zbanowanego')
            ->assertDontSee('Ukryta odpowiedź')
            ->assertDontSee('Usunięta odpowiedź')
            ->assertDontSee('Skasowana odpowiedź')
            ->assertDontSee('Odpowiedź pod zablokowanym');

        $this->assertSame(2, $this->licznikNaKarcie($widz, $autor));
        $this->assertSame(2, $this->naglowekNaStronie($widz, $wpis));

        // Kontrola: ktoś spoza blokady widzi też korzeń natręta z odpowiedzią.
        $this->assertSame(5, $this->licznikNaKarcie($this->user('ktos'), $autor));
    }

    public function test_slad_usunietego_korzenia_liczy_sie_razem_z_odpowiedziami(): void
    {
        $autor = $this->user('autorka');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $korzen = $this->komentarz($wpis, $this->user('pierwsza'), null, [
            'body' => 'Komentarz usunięty.',
            'body_removed_at' => now(),
        ]);
        $this->komentarz($wpis, $this->user('druga'), $korzen);

        // Placeholder przy daniu zachowuje rozmowę i jest widoczny — jak dotąd.
        $this->assertSame(2, $this->licznikNaKarcie($this->user('czytelniczka'), $autor));
    }

    public function test_pytanie_liczy_tylko_odpowiedzi_glowne(): void
    {
        config(['kuking.questions.enabled' => true]);
        $autor = $this->user('pytajaca');
        $widz = $this->user('czytelniczka');
        $pytanie = Post::factory()->question()->create(['author_id' => $autor->getKey()]);

        $odpowiedz = $this->komentarz($pytanie, $this->user('pierwsza'));
        foreach (['druga', 'trzecia', 'czwarta'] as $kto) {
            $this->komentarz($pytanie, $this->user($kto), $odpowiedz);
        }

        $this->assertSame(1, $this->licznikNaKarcie($widz, $autor, 'Odpowiedzi'));

        $html = (string) $this->actingAs($widz)->get(route('questions.show', $pytanie))->assertOk()->getContent();
        preg_match_all('~<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>~s', $html, $skrypty);
        $schemat = collect(array_map(fn ($json) => json_decode($json, true, 512, JSON_THROW_ON_ERROR), $skrypty[1]))
            ->firstWhere('@type', 'QAPage');
        $this->assertSame(1, $schemat['mainEntity']['answerCount']);
    }

    /**
     * Rozmowa pod przepisem albo „Ugotowałem”: korzeń + dwie widoczne
     * odpowiedzi, odpowiedź osoby zablokowanej i korzeń osoby zablokowanej
     * z cudzą odpowiedzią. Widz widzi 3, ktoś spoza blokady 6.
     *
     * @param  array<string, string>  $podmiot
     */
    private function rozmowaZBlokada(array $podmiot, User $widz): void
    {
        $natret = $this->user('natret');
        $wstaw = fn (User $kto, ?Comment $rodzic = null, string $tresc = 'Tekst') => Comment::factory()->create([
            'post_id' => null,
            ...$podmiot,
            'author_id' => $kto->getKey(),
            'parent_id' => $rodzic?->getKey(),
            'status' => Comment::STATUS_PUBLISHED,
            'body' => $tresc,
        ]);

        $korzen = $wstaw($this->user('pierwsza'));
        $wstaw($this->user('druga'), $korzen);
        $wstaw($this->user('trzecia'), $korzen);
        $wstaw($natret, $korzen, 'Odpowiedź osoby zablokowanej');
        $korzenNatreta = $wstaw($natret, null, 'Korzeń osoby zablokowanej');
        $wstaw($this->user('czwarta'), $korzenNatreta, 'Odpowiedź pod zablokowanym');

        app(BlockUser::class)->handle($widz, $natret);
    }

    private function naglowekPod(User $widz, string $adres): ?int
    {
        $odpowiedz = $this->actingAs($widz)->get($adres)->assertOk();
        $html = (string) $odpowiedz->getContent();

        return preg_match('/<h2 id="komentarze">\s*Komentarze\s*\((\d+)\)/u', $html, $m) === 1 ? (int) $m[1] : null;
    }

    public function test_naglowek_pod_przepisem_liczy_odpowiedzi_jak_karta_wpisu(): void
    {
        $widz = $this->user('czytelniczka');
        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('kucharka')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
        $this->rozmowaZBlokada(['recipe_id' => $przepis->getKey()], $widz);

        $strona = $this->actingAs($widz->refresh())->get(route('recipes.show', $przepis->slug))->assertOk();
        $strona->assertDontSee('Odpowiedź osoby zablokowanej')->assertDontSee('Odpowiedź pod zablokowanym');

        $this->assertSame(3, $this->naglowekPod($widz, route('recipes.show', $przepis->slug)));
        $this->assertSame(6, $this->naglowekPod($this->user('ktos'), route('recipes.show', $przepis->slug)));
    }

    public function test_naglowek_pod_ugotowalem_liczy_odpowiedzi_jak_karta_wpisu(): void
    {
        $widz = $this->user('czytelniczka');
        $wykonanie = CookedEvent::factory()->create();
        $this->rozmowaZBlokada(['cooked_event_id' => $wykonanie->getKey()], $widz);

        $strona = $this->actingAs($widz->refresh())->get(route('cooked.show', $wykonanie))->assertOk();
        $strona->assertDontSee('Odpowiedź osoby zablokowanej')->assertDontSee('Odpowiedź pod zablokowanym');

        $this->assertSame(3, $this->naglowekPod($widz, route('cooked.show', $wykonanie)));
        $this->assertSame(6, $this->naglowekPod($this->user('ktos'), route('cooked.show', $wykonanie)));
    }
}
