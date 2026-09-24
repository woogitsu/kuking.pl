<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1094: powtórne wysłanie komentarza, który w oknie powtórzenia
 * został ukryty albo usunięty przez moderację, NIE daje „Komentarz dodany.".
 *
 * Przed poprawką `PublishComment::komentarzZTegoSamegoWyslania()` zwracał
 * ukryty wiersz jako „to samo wysłanie", a kontroler pokazywał sukces, choć
 * lista komentarzy (`widoczneDla()`) go nie pokazuje. Poprawka nie może też
 * pójść w drugą stronę: drugi, identyczny komentarz nie może powstać obok
 * ukrytego i ominąć decyzji moderacji.
 *
 * Okno ustawiamy JAWNIE w `setUp()` — test nie zależy od chwilowej wartości
 * `KUKING_OKNO_POWTORZENIA_KOMENTARZA`.
 *
 * Kontrola ujemna: usunięcie warunku `status !== published` w `PublishComment`
 * sprawia, że testy ukrytego/usuniętego oblewają (sukces zamiast błędu).
 */
class PowtorkaNiewidocznegoKomentarzaTest extends TestCase
{
    use RefreshDatabase;

    private const TRESC = 'Wygląda przepięknie, muszę spróbować.';

    private const OKNO = 60;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.formularze.okno_powtorzenia_komentarza_sekund' => self::OKNO]);
        Queue::fake();
    }

    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function ukryj(Comment $komentarz, string $status): void
    {
        Comment::query()->whereKey($komentarz->getKey())->update(['status' => $status]);
    }

    private function ilePowiadomien(User $odbiorca): int
    {
        return Notification::query()->where('user_id', $odbiorca->getKey())->count();
    }

    /** @return array<string, array{string, string}> */
    public static function przypadki(): array
    {
        return [
            'wpis, ukryty' => ['wpis', Comment::STATUS_HIDDEN],
            'wpis, usunięty' => ['wpis', Comment::STATUS_REMOVED],
            'przepis, ukryty' => ['przepis', Comment::STATUS_HIDDEN],
            'ugotowałem, usunięty' => ['ugotowalem', Comment::STATUS_REMOVED],
        ];
    }

    #[DataProvider('przypadki')]
    public function test_powtorka_niewidocznego_komentarza_nie_mowi_dodano_i_nic_nie_tworzy(string $miejsce, string $status): void
    {
        $autor = $this->user('autortresci');
        $osoba = $this->user('komentujaca');

        $trasa = match ($miejsce) {
            'wpis' => route('posts.comment', $this->wpis($autor)),
            'przepis' => route('recipes.comment', $this->przepis($autor)->slug),
            'ugotowalem' => route('cooked.comment', CookedEvent::factory()->create([
                'user_id' => $autor->getKey(),
                'recipe_id' => $this->przepis($this->user('autorprzepisu'))->getKey(),
            ])),
        };

        $this->actingAs($osoba)->post($trasa, ['body' => self::TRESC])
            ->assertSessionHas('status', 'Komentarz dodany.');
        $this->ukryj(Comment::query()->sole(), $status);

        $drugie = $this->actingAs($osoba)->post($trasa, ['body' => self::TRESC]);

        $drugie->assertSessionHasErrors(['body' => PublishComment::NIEWIDOCZNY]);
        $drugie->assertSessionMissing('status');
        $this->assertSame(1, Comment::query()->count(), 'Powtórka ominęła moderację i zapisała drugi komentarz.');
        $this->assertSame($status, Comment::query()->sole()->status);
        $this->assertSame(1, $this->ilePowiadomien($autor), 'Powtórka wysłała drugie powiadomienie.');
        Queue::assertPushedTimes(PrzeanalizujTresc::class, 1);
    }

    public function test_akcja_domenowa_odmawia_przy_ukrytym_i_oddaje_ten_sam_wiersz_przy_opublikowanym(): void
    {
        $autor = $this->user('autorwpisu');
        $osoba = $this->user('klikajaca');
        $wpis = $this->wpis($autor);
        $akcja = app(PublishComment::class);

        // KONTROLA DODATNIA: przy opublikowanym dwa kliknięcia to nadal
        // jeden komentarz, bez błędu, i jedno powiadomienie.
        $pierwszy = $akcja->handle($osoba, $wpis, self::TRESC);
        $drugi = $akcja->handle($osoba, $wpis, self::TRESC);
        $this->assertSame($pierwszy->getKey(), $drugi->getKey());
        $this->assertFalse($drugi->wasRecentlyCreated);
        $this->assertSame(1, $this->ilePowiadomien($autor));

        $this->ukryj($pierwszy, Comment::STATUS_HIDDEN);

        try {
            $akcja->handle($osoba, $wpis, self::TRESC);
            $this->fail('Akcja oddała ukryty komentarz jak sukces.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(PublishComment::NIEWIDOCZNY, $e->getMessage());
        }

        $this->assertSame(1, Comment::query()->count());
        $this->assertSame(1, $this->ilePowiadomien($autor));
    }

    public function test_dwa_klikniecia_przy_opublikowanym_nadal_sa_sukcesem(): void
    {
        // KONTROLA DODATNIA ścieżki HTTP: warunek na status nie może
        // zamienić zwykłego podwójnego kliknięcia w błąd.
        $autor = $this->user('autorwpisu2');
        $osoba = $this->user('dwaklikniecia');
        $wpis = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);
        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Komentarz dodany.');

        $this->assertSame(1, Comment::query()->count());
        $this->assertSame(1, $this->ilePowiadomien($autor));
    }

    public function test_po_uplywie_okna_to_samo_zdanie_jest_nowym_komentarzem_i_idzie_do_analizy(): void
    {
        // Jawna reguła po oknie: to samo zdanie po upływie okna jest nową
        // wypowiedzią. Nie wskrzesza ukrytego wiersza — powstaje nowy
        // i przechodzi tę samą analizę co każdy nowy komentarz.
        $autor = $this->user('autorwpisu3');
        $osoba = $this->user('wracajaca');
        $wpis = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);
        $ukryty = Comment::query()->sole();
        $this->ukryj($ukryty, Comment::STATUS_HIDDEN);

        $this->travel(self::OKNO + 1)->seconds();

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Komentarz dodany.');

        $this->assertSame(2, Comment::query()->count());
        $this->assertSame(Comment::STATUS_HIDDEN, $ukryty->fresh()->status, 'Nowe wysłanie wskrzesiło ukryty komentarz.');
        $this->assertSame(1, Comment::query()->where('status', Comment::STATUS_PUBLISHED)->count());
        Queue::assertPushedTimes(PrzeanalizujTresc::class, 2);
    }
}
