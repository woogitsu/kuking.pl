<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #932: moderator (z 2FA i bez) usuwał cudze treści zwykłym DELETE
 * ze strony treści — z pominięciem panelu, uzasadnienia, rejestru decyzji
 * i odwołania. Zwykły DELETE zostaje dla autora (i dla autora wątku przy
 * komentarzach); cudzą treść moderator zdejmuje wyłącznie decyzją „Usuń"
 * w `/admin/zgloszenia`.
 *
 * Kontrola ujemna: przywrócenie `|| $user->isModerator()` w którejkolwiek
 * z czterech Policy oblewa odpowiadający przypadek z macierzy niżej.
 */
class ModeratorUsuwaCudzaTrescTylkoZPaneluTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function typyTresci(): array
    {
        return [
            'wpis' => ['post'],
            'przepis' => ['recipe'],
            'komentarz' => ['comment'],
            'ugotowanie' => ['cooked_event'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function obslugaRazyTypy(): array
    {
        $wynik = [];
        foreach (['moderator bez 2FA', 'moderator z 2FA', 'administrator z 2FA', 'zwykły użytkownik'] as $kto) {
            foreach (array_keys(self::typyTresci()) as $nazwa) {
                $wynik["{$kto} / {$nazwa}"] = [$kto, self::typyTresci()[$nazwa][0]];
            }
        }

        return $wynik;
    }

    #[DataProvider('obslugaRazyTypy')]
    public function test_cudzej_tresci_nie_usuwa_zwyklym_delete(string $kto, string $typ): void
    {
        $aktor = match ($kto) {
            'moderator bez 2FA' => $this->user(null, ['role' => User::ROLE_MODERATOR]),
            'moderator z 2FA' => $this->moderator(),
            'administrator z 2FA' => $this->admin(),
            'zwykły użytkownik' => $this->user(),
        };
        $autor = $this->user('autor');
        $tresc = $this->tresc($typ, $autor);

        $this->actingAs($aktor)
            ->delete($this->adresUsuniecia($typ, $tresc))
            ->assertForbidden();

        $this->assertTrescIstnieje($typ, $tresc);
        $this->assertSame(0, ModerationAction::count());
    }

    #[DataProvider('typyTresci')]
    public function test_autor_usuwa_wlasna_tresc(string $typ): void
    {
        $autor = $this->user('autor');
        $tresc = $this->tresc($typ, $autor);

        $this->actingAs($autor)
            ->delete($this->adresUsuniecia($typ, $tresc))
            ->assertRedirect();

        $this->assertTrescZniknela($typ, $tresc);
    }

    public function test_autor_watku_dalej_usuwa_cudzy_komentarz_pod_swoim_wpisem(): void
    {
        $autorWpisu = $this->user('autor');
        $post = Post::factory()->create(['author_id' => $autorWpisu->getKey()]);
        $komentarz = Comment::factory()->create(['post_id' => $post->getKey()]);

        $this->actingAs($autorWpisu)
            ->delete(route('comments.destroy', $komentarz), ['reason' => 'Nie na temat.'])
            ->assertRedirect();

        $this->assertSoftDeleted($komentarz);
    }

    #[DataProvider('typyTresci')]
    public function test_moderator_z_2fa_zdejmuje_cudza_tresc_z_panelu_i_decyzja_jest_w_rejestrze(string $typ): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autor');
        $zglaszajacy = $this->user('zglaszajacy');
        $tresc = $this->tresc($typ, $autor);

        $report = Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => $typ,
            'target_id' => $tresc->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'spam_link',
                'note' => 'Link reklamowy.',
                'user_message' => 'Usunęliśmy tę treść, bo zawierała link reklamowy.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrescZniknela($typ, $tresc);
        $this->assertDatabaseHas('moderation_actions', [
            'report_id' => $report->getKey(),
            'moderator_id' => $moderator->getKey(),
            'action' => ModerationAction::ACTION_REMOVE,
        ]);
    }

    private function tresc(string $typ, User $autor): Post|Recipe|Comment|CookedEvent
    {
        return match ($typ) {
            'post' => Post::factory()->create(['author_id' => $autor->getKey()]),
            'recipe' => Recipe::factory()->create(['author_id' => $autor->getKey()]),
            'comment' => Comment::factory()->create(['author_id' => $autor->getKey()]),
            'cooked_event' => CookedEvent::factory()->create(['user_id' => $autor->getKey()]),
        };
    }

    private function adresUsuniecia(string $typ, Post|Recipe|Comment|CookedEvent $tresc): string
    {
        return match ($typ) {
            'post' => route('posts.destroy', $tresc),
            'recipe' => route('recipes.destroy', $tresc->slug),
            'comment' => route('comments.destroy', $tresc),
            'cooked_event' => route('cooked.destroy', $tresc),
        };
    }

    private function assertTrescIstnieje(string $typ, Post|Recipe|Comment|CookedEvent $tresc): void
    {
        $swieza = $tresc::query()->whereKey($tresc->getKey())->first();

        $this->assertNotNull($swieza, "{$typ} zniknął po odmowie.");
        if ($typ === 'comment') {
            $this->assertSame($tresc->body, $swieza->body, 'Odmowa zmieniła treść komentarza.');
        }
    }

    private function assertTrescZniknela(string $typ, Post|Recipe|Comment|CookedEvent $tresc): void
    {
        $this->assertNull($tresc::query()->whereKey($tresc->getKey())->first(), "{$typ} nie został zdjęty.");
    }
}
