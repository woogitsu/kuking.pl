<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Social\Actions\BlockUser;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Komunikat przy odmowie komentowania mówi, CO ZROBIĆ (issue #38, przy
 * okazji przenoszenia PR #254 do #288; `docs/UX_50_PLUS.md`: „błędy po
 * polsku mówiące co zrobić").
 *
 * PRZED TĄ POPRAWKĄ wszystkie trzy miejsca w `PublishComment::handle()`
 * rzucały „Nie można tu komentować." — zdanie bez podmiotu, mówiące tylko,
 * CO się stało. Człowiek, który trafił na blokadę (własną albo cudzą, przy
 * odpowiadaniu — audyt W7-06), nie miał z tego komunikatu żadnej wskazówki,
 * co zrobić dalej.
 *
 * Test bierze ŚCIEŻKĘ PRZEZ RODZICA (blokada z autorem komentarza, pod
 * którym się odpowiada), nie ścieżkę przez właściciela treści: ta druga
 * jest już wcześniej ucięta przez `PostPolicy::view()` (blokada z autorem
 * WPISU chowa cały wpis pod 403, więc `PublishComment` nigdy jej nie
 * dostaje przez HTTP) — ale trzy rzuty w kodzie dzielą JEDNĄ stałą, więc
 * ten sam test dowodzi treści komunikatu dla wszystkich trzech.
 */
class KomentarzKomunikatBlokadyTest extends TestCase
{
    use RefreshDatabase;

    public function test_odmowa_komentowania_przez_blokade_z_autorem_rodzica_mowi_co_zrobic(): void
    {
        $autorWpisu = $this->user('halina');
        $autorRodzica = $this->user('marek');
        $piszacy = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);

        $rodzic = app(PublishComment::class)->handle($autorRodzica, $post, 'Pierwszy komentarz.');

        app(BlockUser::class)->handle($piszacy, $autorRodzica);

        try {
            app(PublishComment::class)->handle($piszacy, $post, 'Próba odpowiedzi mimo blokady.', $rodzic);
            $this->fail('Odpowiedź osoby zablokowanej z autorem rodzica miała zostać odrzucona.');
        } catch (BladDlaCzlowieka $e) {
            // POZYTYWNA: komunikat mówi, co zrobić dalej.
            $this->assertStringContainsString('Odśwież stronę', $e->getMessage());

            // NEGATYWNA: komunikat nie zdradza, KOMU chodzi o blokadę — inaczej
            // osoba próbująca to obejść dostałaby potwierdzenie, kto kogo
            // zablokował (patrz komentarz w `PublishComment::handle()`).
            $this->assertStringNotContainsString('zablokował', $e->getMessage());
            $this->assertStringNotContainsString('blokad', $e->getMessage());
        }
    }
}
