<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ukryta odpowiedź chroni korzeń przed usunięciem i wraca widocznie (#1317).
 *
 * CO SIĘ DZIAŁO
 * `DeleteComment` wybierał „ślad albo kosz” po `replies()->exists()`, a
 * `replies()` liczy tylko odpowiedzi opublikowane. Odpowiedź ukryta przez
 * moderację nie chroniła korzenia: szedł do kosza. Po przywróceniu odpowiedź
 * miała status `published`, ale wątek pokazuje odpowiedzi tylko wewnątrz
 * żywego korzenia — więc nikt jej nie widział. Przywrócenie było pozorne.
 *
 * Kontrola ujemna: powrót do `replies()->exists()` w `DeleteComment` oblewa
 * `test_ukryta_odpowiedz_chroni_korzen_i_wraca_widocznie` (korzeń trafia do
 * kosza). Usunięcie `przywrocKorzenJakoSlad()` z `RestoreContent` oblewa
 * `test_odpowiedz_zdjeta_przez_moderacje_wraca_z_korzeniem_jako_sladem`.
 */
class KorzenZUkrytaOdpowiedziaTest extends TestCase
{
    use RefreshDatabase;

    private const TEKST_KORZENIA = 'Czy do bigosu dajecie suszone śliwki?';

    private const TEKST_ODPOWIEDZI = 'Tak, garść na duży garnek, pod koniec.';

    /** @return array<string, array{string}> */
    public static function miejsca(): array
    {
        return [
            'wpis' => ['post'],
            'przepis' => ['recipe'],
            'wykonanie' => ['cooked'],
        ];
    }

    /**
     * @return array{0: Model, 1: array<string, string>}
     */
    private function miejsce(string $rodzaj): array
    {
        $gospodarz = $this->user('gospodarz');

        return match ($rodzaj) {
            'post' => [$post = Post::factory()->create(['author_id' => $gospodarz->getKey()]), ['post_id' => $post->getKey()]],
            'recipe' => [$recipe = Recipe::factory()->create(['author_id' => $gospodarz->getKey()]), ['post_id' => null, 'recipe_id' => $recipe->getKey()]],
            'cooked' => [
                $event = CookedEvent::factory()->create([
                    'recipe_id' => Recipe::factory()->create(['author_id' => $this->user('autorka')->getKey()])->getKey(),
                    'user_id' => $gospodarz->getKey(),
                ]),
                ['post_id' => null, 'cooked_event_id' => $event->getKey()],
            ],
        };
    }

    /**
     * @param  array<string, string>  $kolumny
     * @return array{0: User, 1: Comment, 2: Comment}
     */
    private function watek(array $kolumny): array
    {
        $autorKorzenia = $this->user('pytajaca');

        $korzen = Comment::factory()->create($kolumny + [
            'author_id' => $autorKorzenia->getKey(),
            'body' => self::TEKST_KORZENIA,
        ]);

        $odpowiedz = Comment::factory()->create($kolumny + [
            'author_id' => $this->user('odpowiadajacy')->getKey(),
            'parent_id' => $korzen->getKey(),
            'body' => self::TEKST_ODPOWIEDZI,
        ]);

        return [$autorKorzenia, $korzen, $odpowiedz];
    }

    private function zdecyduj(User $moderator, Comment $komentarz, string $akcja): Report
    {
        $report = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'comment',
            'target_id' => $komentarz->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => $akcja,
                'reason_code' => 'spam',
                'user_message' => 'Odpowiedź wygląda na reklamę.',
            ])
            ->assertRedirect(route('admin.reports'));

        return $report;
    }

    private function przywroc(User $moderator, Report $report): void
    {
        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $report), [
                'reason_code' => 'autor_poprawil',
                'user_message' => 'Sprawdziliśmy jeszcze raz. Odpowiedź wróciła.',
            ])
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasNoErrors();
    }

    /**
     * Wartość `parent_restored_as_placeholder` z wpisu audytu przywrócenia
     * tej odpowiedzi — albo `null`, gdy wpisu nie ma.
     */
    private function korzenJakoSladWAudycie(Comment $odpowiedz): ?bool
    {
        $wpis = AuditLogEntry::query()
            ->where('action', 'moderation.restored')
            ->where('metadata->target_id', (string) $odpowiedz->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($wpis, 'Przywrócenie odpowiedzi musi zostawić wpis w audycie.');

        $wartosc = $wpis->metadata['parent_restored_as_placeholder'] ?? null;

        return is_bool($wartosc) ? $wartosc : null;
    }

    #[DataProvider('miejsca')]
    public function test_ukryta_odpowiedz_chroni_korzen_i_wraca_widocznie(string $rodzaj): void
    {
        [$miejsce, $kolumny] = $this->miejsce($rodzaj);
        [$autorKorzenia, $korzen, $odpowiedz] = $this->watek($kolumny);
        $moderator = $this->moderator();
        $widz = $this->user('widz');

        $report = $this->zdecyduj($moderator, $odpowiedz, ModerationAction::ACTION_HIDE);
        $this->assertSame(Comment::STATUS_HIDDEN, $odpowiedz->refresh()->status);

        $this->actingAs($autorKorzenia)->delete(route('comments.destroy', $korzen))->assertRedirect();

        $korzen->refresh();
        $this->assertNull($korzen->deleted_at, 'Pod korzeniem jest ukryta odpowiedź — korzeń nie może iść do kosza.');
        $this->assertSame('Komentarz usunięty.', $korzen->body);
        $this->assertNotNull($korzen->body_removed_at);

        // Przed decyzją o przywróceniu treść ukrytej odpowiedzi się nie pokazuje.
        $this->actingAs($widz)->get($miejsce->url())
            ->assertOk()
            ->assertDontSee(self::TEKST_ODPOWIEDZI)
            ->assertDontSee(self::TEKST_KORZENIA);

        $this->przywroc($moderator, $report);

        $this->assertSame(Comment::STATUS_PUBLISHED, $odpowiedz->refresh()->status);
        $this->assertSame($korzen->getKey(), $odpowiedz->parent_id);
        // Korzeń nie był w koszu — nie było czego wracać jako ślad.
        $this->assertFalse($this->korzenJakoSladWAudycie($odpowiedz));

        $this->actingAs($widz)->get($miejsce->url())
            ->assertOk()
            ->assertSeeInOrder(['Komentarz usunięty.', self::TEKST_ODPOWIEDZI])
            ->assertDontSee(self::TEKST_KORZENIA);
    }

    #[DataProvider('miejsca')]
    public function test_odpowiedz_zdjeta_przez_moderacje_wraca_z_korzeniem_jako_sladem(string $rodzaj): void
    {
        [$miejsce, $kolumny] = $this->miejsce($rodzaj);
        [$autorKorzenia, $korzen, $odpowiedz] = $this->watek($kolumny);
        $moderator = $this->moderator();

        // `remove` usuwa odpowiedź miękko — żywych dzieci brak, więc korzeń
        // autora idzie do kosza. Odwołanie może jednak odpowiedź przywrócić.
        $report = $this->zdecyduj($moderator, $odpowiedz, ModerationAction::ACTION_REMOVE);
        $this->assertTrue($odpowiedz->refresh()->trashed());

        $this->actingAs($autorKorzenia)->delete(route('comments.destroy', $korzen))->assertRedirect();
        $this->assertTrue($korzen->refresh()->trashed());

        $this->przywroc($moderator, $report);

        $korzen->refresh();
        $this->assertFalse($korzen->trashed(), 'Bez korzenia przywrócona odpowiedź nie ma gdzie się pokazać.');
        $this->assertTrue($this->korzenJakoSladWAudycie($odpowiedz), 'Powrót korzenia jako śladu musi być widać w audycie.');
        $this->assertSame('Komentarz usunięty.', $korzen->body);
        $this->assertNotNull($korzen->body_removed_at);

        $this->actingAs($this->user('widz'))->get($miejsce->url())
            ->assertOk()
            ->assertSeeInOrder(['Komentarz usunięty.', self::TEKST_ODPOWIEDZI])
            ->assertDontSee(self::TEKST_KORZENIA);
    }

    public function test_korzen_zdjety_przez_moderacje_nie_wraca_przy_przywroceniu_odpowiedzi(): void
    {
        [, $kolumny] = $this->miejsce('post');
        [, $korzen, $odpowiedz] = $this->watek($kolumny);
        $moderator = $this->moderator();

        $reportOdpowiedzi = $this->zdecyduj($moderator, $odpowiedz, ModerationAction::ACTION_HIDE);
        $this->zdecyduj($moderator, $korzen, ModerationAction::ACTION_REMOVE);
        $this->assertTrue($korzen->refresh()->trashed());

        $this->przywroc($moderator, $reportOdpowiedzi);

        $this->assertSame(Comment::STATUS_PUBLISHED, $odpowiedz->refresh()->status);
        $korzen->refresh();
        $this->assertTrue($korzen->trashed(), 'O korzeniu zdjętym przez moderację rozstrzyga osobna decyzja.');
        $this->assertFalse($this->korzenJakoSladWAudycie($odpowiedz));
        $this->assertSame(self::TEKST_KORZENIA, $korzen->body, 'Treść zdjęta decyzją musi zostać nietknięta na wypadek odwołania.');
    }

    /** Kontrola dodatnia: zwykły korzeń bez odpowiedzi dalej idzie do kosza. */
    public function test_korzen_bez_odpowiedzi_jest_usuwany(): void
    {
        [, $kolumny] = $this->miejsce('post');
        $autor = $this->user('pytajaca');
        $korzen = Comment::factory()->create($kolumny + ['author_id' => $autor->getKey(), 'body' => self::TEKST_KORZENIA]);

        $this->actingAs($autor)->delete(route('comments.destroy', $korzen))->assertRedirect();

        $korzen->refresh();
        $this->assertTrue($korzen->trashed());
        $this->assertSame(self::TEKST_KORZENIA, $korzen->body);
    }
}
