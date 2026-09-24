<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1378: odpowiedź widać wyłącznie wewnątrz wątku. Ekran treści
 * pobiera widoczne komentarze główne i dopiero pod nimi odpowiedzi, więc
 * niewidoczny korzeń zabiera z ekranu także odpowiedź osoby trzeciej.
 * Powiadomienie o tej odpowiedzi (z jej wycinkiem i „Zobacz”) ma zniknąć
 * razem z wątkiem — przy odczycie, bez kasowania wiersza — i wrócić,
 * gdy wątek znów jest widoczny.
 *
 * Każdy przypadek ma kontrolę dodatnią w tej samej treści: drugi wątek
 * z korzeniem osoby bez sankcji zostaje na liście i w liczniku.
 */
class PowiadomienieOdpowiedziZaNiewidocznymKorzeniemTest extends TestCase
{
    use RefreshDatabase;

    public static function tresci(): array
    {
        return ['wpis' => ['post'], 'przepis' => ['recipe'], 'ugotowałem' => ['cooked']];
    }

    private function tresc(string $rodzaj, User $wlasciciel): Post|Recipe|CookedEvent
    {
        $atrybuty = ['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()];

        return match ($rodzaj) {
            'post' => Post::factory()->for($wlasciciel, 'author')->create($atrybuty),
            'recipe' => Recipe::factory()->for($wlasciciel, 'author')->create($atrybuty),
            'cooked' => CookedEvent::factory()->for($wlasciciel, 'user')
                ->for(Recipe::factory()->for($this->user(), 'author')->create($atrybuty))->create(),
        };
    }

    /**
     * @return array{0: User, 1: User, 2: Comment, 3: Comment}
     */
    private function dwaWatki(string $rodzaj): array
    {
        $c = $this->user();
        $a = $this->user();
        $kontrolny = $this->user();
        $b = $this->user();
        $tresc = $this->tresc($rodzaj, $c);

        $korzenA = app(PublishComment::class)->handle($a, $tresc, 'Korzeń A.');
        $odpowiedz = app(PublishComment::class)->handle($b, $tresc, 'ODPOWIEDZ-POD-KORZENIEM-A', $korzenA);
        $korzenKontrolny = app(PublishComment::class)->handle($kontrolny, $tresc, 'Korzeń kontrolny.');
        $kontrola = app(PublishComment::class)->handle($b, $tresc, 'ODPOWIEDZ-KONTROLNA', $korzenKontrolny);

        return [$c, $a, $odpowiedz, $kontrola];
    }

    private function widoczneIdKomentarzy(User $odbiorca): array
    {
        return Notification::query()->where('user_id', $odbiorca->getKey())
            ->visibleTo($odbiorca->fresh())
            ->pluck('data')
            ->map(fn (array $data): ?string => $data['comment_id'] ?? null)
            ->all();
    }

    #[DataProvider('tresci')]
    public function test_blokada_autora_korzenia_chowa_powiadomienie_o_odpowiedzi_a_odblokowanie_je_przywraca(string $rodzaj): void
    {
        [$c, $a, $odpowiedz, $kontrola] = $this->dwaWatki($rodzaj);

        $this->assertEqualsCanonicalizing(
            [$odpowiedz->getKey(), $kontrola->getKey()],
            array_values(array_intersect($this->widoczneIdKomentarzy($c), [$odpowiedz->getKey(), $kontrola->getKey()])),
            'Przed blokadą oba powiadomienia o odpowiedziach są widoczne.',
        );

        app(BlockUser::class)->handle($c, $a);

        $widoczne = $this->widoczneIdKomentarzy($c);
        $this->assertNotContains($odpowiedz->getKey(), $widoczne);
        $this->assertContains($kontrola->getKey(), $widoczne);

        $lista = $this->actingAs($c)->get(route('notifications.index'));
        $lista->assertOk();
        $lista->assertDontSee('ODPOWIEDZ-POD-KORZENIEM-A');
        $lista->assertSee('ODPOWIEDZ-KONTROLNA');
        $this->assertSame(
            Notification::query()->where('user_id', $c->getKey())->visibleTo($c->fresh())->whereNull('read_at')->count(),
            $c->fresh()->unreadNotificationsCount(),
            'Licznik nieprzeczytanych liczy tą samą regułą co lista.',
        );

        app(UnblockUser::class)->handle($c, $a);

        $this->assertContains($odpowiedz->getKey(), $this->widoczneIdKomentarzy($c));
    }

    #[DataProvider('tresci')]
    public function test_korzen_ukryty_przez_moderacje_chowa_powiadomienie_o_odpowiedzi(string $rodzaj): void
    {
        [$c, , $odpowiedz, $kontrola] = $this->dwaWatki($rodzaj);

        Comment::query()->whereKey($odpowiedz->parent_id)->update(['status' => Comment::STATUS_HIDDEN]);

        $widoczne = $this->widoczneIdKomentarzy($c);
        $this->assertNotContains($odpowiedz->getKey(), $widoczne);
        $this->assertContains($kontrola->getKey(), $widoczne);
    }

    public function test_zbanowany_autor_korzenia_chowa_powiadomienie_o_odpowiedzi(): void
    {
        [$c, $a, $odpowiedz, $kontrola] = $this->dwaWatki('post');

        User::query()->whereKey($a->getKey())->update(['status' => User::STATUS_BANNED]);

        $widoczne = $this->widoczneIdKomentarzy($c);
        $this->assertNotContains($odpowiedz->getKey(), $widoczne);
        $this->assertContains($kontrola->getKey(), $widoczne);
    }

    /**
     * Kontrola dodatnia: blokada osoby spoza wątku nie rusza powiadomienia,
     * a powiadomienie o komentarzu głównym działa jak dotąd.
     */
    public function test_blokada_niepowiazanej_osoby_i_komentarz_glowny_bez_zmian(): void
    {
        [$c, $a, $odpowiedz] = $this->dwaWatki('post');

        app(BlockUser::class)->handle($c, $this->user());

        $widoczne = $this->widoczneIdKomentarzy($c);
        $this->assertContains($odpowiedz->getKey(), $widoczne);
        $this->assertContains($odpowiedz->parent_id, $widoczne, 'Powiadomienie o korzeniu A (komentarz główny) zostaje.');
    }
}
