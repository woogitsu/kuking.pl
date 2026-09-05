<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DiscoverFeed;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Licznik komentarzy a blokady.
 *
 * W #47 naprawiliśmy listy i wyszukiwarkę, a przy okazji liczniki na profilu —
 * z uzasadnieniem, że „licznik zliczający treści, których nie wolno pokazać,
 * sam jest wyciekiem informacji".
 *
 * Ta sama zasada dotyczy licznika komentarzy na karcie wpisu. Feedy wołają
 * `withCount('comments')` bez `widoczneDla()`, więc komentarz osoby
 * zablokowanej nadal się liczy — mimo że po wejściu we wpis go nie widać.
 *
 * Skutek dla użytkownika: karta mówi „Komentarze (2)", po kliknięciu jest
 * jeden. Osoba, która kogoś zablokowała, dostaje sygnał „ta osoba tu jest
 * i coś napisała" — czyli dokładnie to, przed czym blokada ma chronić.
 *
 * UWAGA NA KONSTRUKCJĘ TESTU
 * Pierwsza wersja tego pliku wołała `withCount(['comments' => …widoczneDla])`,
 * czyli miała poprawkę wpisaną w sam test. Przechodziła i nie dowodziła
 * niczego o kodzie produkcyjnym. Teraz test idzie przez DiscoverFeed — tę samą
 * drogę, którą chodzi strona.
 */
class LicznikKomentarzyTest extends TestCase
{
    use RefreshDatabase;

    public function test_licznik_na_karcie_nie_zlicza_komentarzy_osoby_zablokowanej(): void
    {
        $autor = $this->user('autorka');
        $czytelnik = $this->user('czytelniczka');
        $natret = $this->user('natret');

        $post = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
        ]);

        Comment::create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Zwykły komentarz.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        Comment::create([
            'author_id' => $natret->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Zaczepka.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        app(BlockUser::class)->handle($czytelnik, $natret);
        $czytelnik->refresh();

        // Ile komentarzy czytelnik NAPRAWDĘ zobaczy po wejściu we wpis.
        $widoczne = $post->comments()->widoczneDla($czytelnik)->count();

        // Ile obiecuje mu karta wpisu w feedzie.
        $wFeedzie = app(DiscoverFeed::class)->paginate($czytelnik)
            ->getCollection()
            ->firstWhere('id', $post->getKey());

        $this->assertNotNull($wFeedzie, 'Wpis zniknął z feedu — test sprawdza co innego, niż zakłada.');

        $this->assertSame(
            $widoczne,
            (int) $wFeedzie->comments_count,
            'Licznik na karcie nie zgadza się z tym, ile komentarzy widać. '
            .'Karta obiecuje treść, której po wejściu nie ma — a osoba, która '
            .'kogoś zablokowała, dostaje sygnał, że tamta osoba tu pisze.',
        );
    }
}
