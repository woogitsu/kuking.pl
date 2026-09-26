<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeanalizujTresc;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
 * schowanego i ominąć decyzji moderacji.
 *
 * STAN IDZIE PRZEZ PRAWDZIWĄ AKCJĘ MODERATORA, nie przez ręczny `status`.
 * „Usuń” moderacji to soft delete (`ModerationController::applyAction()`,
 * `ZdejmijZUrzedu`), a `Comment::STATUS_REMOVED` nie jest ustawiany nigdzie
 * w `app/`. Pierwsza wersja tego testu ustawiała `status = removed` ręcznie
 * i przechodziła, choć prawdziwe „Usuń” dawało duplikat (recenzja #1094).
 *
 * Okno ustawiamy JAWNIE w `setUp()` — test nie zależy od chwilowej wartości
 * `KUKING_OKNO_POWTORZENIA_KOMENTARZA`.
 *
 * Kontrole ujemne: `tests/mutacje/powtorka-komentarza.txt`.
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
        Mail::fake();
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

    /**
     * Decyzja moderatora na komentarzu — tą samą drogą, którą klika moderator.
     *
     * `zgloszenie-ukryj` i `zgloszenie-usun` idą przez `admin.reports.decide`,
     * `z-urzedu` przez `admin.z-urzedu.store` (`ZdejmijZUrzedu`).
     */
    private function moderacja(Comment $komentarz, string $droga): void
    {
        $moderator = $this->moderator();

        if ($droga === 'z-urzedu') {
            $this->actingAs($moderator)
                ->post(route('admin.z-urzedu.store', ['typ' => 'comment', 'id' => $komentarz->getKey()]), [
                    'reason_code' => 'spam-reklama',
                    'user_message' => 'Komentarz reklamuje sklep i nie dotyczy gotowania.',
                ])
                ->assertSessionHasNoErrors();

            return;
        }

        $zgloszenie = Report::create([
            'reporter_id' => $this->user('zglaszajaca'.substr(md5((string) $komentarz->getKey()), 0, 6))->getKey(),
            'target_type' => 'comment',
            'target_id' => $komentarz->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $droga === 'zgloszenie-ukryj' ? ModerationAction::ACTION_HIDE : ModerationAction::ACTION_REMOVE,
                'reason_code' => 'spam',
            ])
            ->assertSessionHasNoErrors();
    }

    private function ilePowiadomien(User $odbiorca): int
    {
        return Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->where('type', '!=', Notification::TYPE_MODERATION)
            ->count();
    }

    /** @return array<string, array{string, string}> */
    public static function przypadki(): array
    {
        return [
            'wpis, ukryty ze zgłoszenia' => ['wpis', 'zgloszenie-ukryj'],
            'wpis, usunięty ze zgłoszenia' => ['wpis', 'zgloszenie-usun'],
            'przepis, ukryty ze zgłoszenia' => ['przepis', 'zgloszenie-ukryj'],
            'przepis, zdjęty z urzędu' => ['przepis', 'z-urzedu'],
            'ugotowałem, usunięty ze zgłoszenia' => ['ugotowalem', 'zgloszenie-usun'],
        ];
    }

    #[DataProvider('przypadki')]
    public function test_powtorka_komentarza_schowanego_przez_moderacje_nie_mowi_dodano_i_nic_nie_tworzy(string $miejsce, string $droga): void
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
        $this->moderacja(Comment::query()->sole(), $droga);

        $drugie = $this->actingAs($osoba)->post($trasa, ['body' => self::TRESC]);

        $drugie->assertSessionHasErrors(['body' => PublishComment::NIEWIDOCZNY]);
        $drugie->assertSessionMissing('status');
        $this->assertSame(1, Comment::withTrashed()->count(), 'Powtórka ominęła moderację i zapisała drugi komentarz.');
        $this->assertSame(0, Comment::query()->where('status', Comment::STATUS_PUBLISHED)->count(), 'Po powtórce w rozmowie znów stoi opublikowany komentarz.');
        $this->assertSame(1, $this->ilePowiadomien($autor), 'Powtórka wysłała drugie powiadomienie.');
        Queue::assertPushedTimes(PrzeanalizujTresc::class, 1);
    }

    public function test_autor_usuwa_komentarz_sam_i_moze_napisac_go_od_nowa(): void
    {
        // KONTROLA DODATNIA: `withTrashed()` nie może zamienić usunięcia
        // przez SAMEGO AUTORA w blokadę. Autor wycofał słowa i pisze je od
        // nowa — to nowy komentarz, prawdziwe „dodany".
        $autor = $this->user('autorwpisu5');
        $osoba = $this->user('rozmyslila');
        $wpis = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);
        $pierwszy = Comment::query()->sole();
        $this->actingAs($osoba)->delete(route('comments.destroy', $pierwszy))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($pierwszy);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Komentarz dodany.');

        $this->assertSame(2, Comment::withTrashed()->count());
        $nowy = Comment::query()->sole();
        $this->assertNotSame($pierwszy->getKey(), $nowy->getKey());
        $this->assertSame(Comment::STATUS_PUBLISHED, $nowy->status);

        // Podwójne kliknięcie PO napisaniu od nowa trafia na nowy wiersz,
        // a nie na usunięty — inaczej powstałby trzeci komentarz.
        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC])
            ->assertSessionHas('status', 'Komentarz dodany.');
        $this->assertSame(2, Comment::withTrashed()->count(), 'Kliknięcie po napisaniu od nowa dało trzeci komentarz.');
        Queue::assertPushedTimes(PrzeanalizujTresc::class, 2);
    }

    public function test_autor_usuwa_komentarz_z_odpowiedziami_i_powtorka_tworzy_nowy(): void
    {
        // `body_removed_at`: autor usunął komentarz, który miał odpowiedzi.
        // Wiersz zostaje `published`, ale z napisem zastępczym — to już nie
        // jest wypowiedź autora, więc powtórka tworzy nowy komentarz, jak po
        // zwykłym usunięciu. Zdanie RÓWNE napisowi zastępczemu to przypadek,
        // w którym bez warunku na `body_removed_at` wyszukanie trafiało na
        // stary wiersz i dawało fałszywe „Komentarz dodany." bez komentarza.
        $autor = $this->user('autorwpisu6');
        $osoba = $this->user('zodpowiedzia');
        $wpis = $this->wpis($autor);
        $tresc = 'Komentarz usunięty.';

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => $tresc]);
        $pierwszy = Comment::query()->sole();
        $this->actingAs($autor)->post(route('posts.comment', $wpis), [
            'body' => 'Dziękuję, proszę koniecznie dać znać, jak wyszło.',
            'parent_id' => $pierwszy->getKey(),
        ])->assertSessionHasNoErrors();
        $this->assertSame(2, Comment::query()->count(), 'Odpowiedź nie powstała — test nie sprawdza gałęzi z odpowiedziami.');

        $this->actingAs($osoba)->delete(route('comments.destroy', $pierwszy))->assertSessionHasNoErrors();
        $this->assertNotNull($pierwszy->fresh()->body_removed_at);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => $tresc])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Komentarz dodany.');

        $nowe = Comment::query()->whereNull('parent_id')->whereNull('body_removed_at')->get();
        $this->assertCount(1, $nowe, 'Powtórka po usunięciu z odpowiedziami powiedziała „dodany", a komentarza nie ma.');
        $this->assertNotSame($pierwszy->getKey(), $nowe->sole()->getKey());
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

        $this->moderacja($pierwszy, 'zgloszenie-ukryj');

        try {
            $akcja->handle($osoba, $wpis, self::TRESC);
            $this->fail('Akcja oddała ukryty komentarz jak sukces.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(PublishComment::NIEWIDOCZNY, $e->getMessage());
        }

        $this->assertSame(1, Comment::withTrashed()->count());
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
        // wypowiedzią. Nie wskrzesza usuniętego wiersza — powstaje nowy
        // i przechodzi tę samą analizę co każdy nowy komentarz.
        $autor = $this->user('autorwpisu3');
        $osoba = $this->user('wracajaca');
        $wpis = $this->wpis($autor);

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC]);
        $usuniety = Comment::query()->sole();
        $this->moderacja($usuniety, 'zgloszenie-usun');

        $this->travel(self::OKNO + 1)->seconds();

        $this->actingAs($osoba)->post(route('posts.comment', $wpis), ['body' => self::TRESC])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Komentarz dodany.');

        $this->assertSame(2, Comment::withTrashed()->count());
        $this->assertSoftDeleted($usuniety);
        $this->assertSame(1, Comment::query()->where('status', Comment::STATUS_PUBLISHED)->count());
        Queue::assertPushedTimes(PrzeanalizujTresc::class, 2);
    }
}
