<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Block;
use App\Models\Comment;
use App\Models\CommentThank;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Komunikat;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #2355 (F11) — „Dziękuję” jednym kliknięciem pod komentarzem.
 *
 * Kontrole ujemne (sprawdzone przy pisaniu, patrz opis PR):
 *  - `insertOrIgnore` zastąpione zwykłym `insert` w `ThankForComment` →
 *    `test_drugie_klikniecie…` oblewa (wyjątek UNIQUE albo drugie powiadomienie);
 *  - warunek `$powstalo` przed `notify` zdjęty → `test_drugie_klikniecie…`
 *    liczy dwa powiadomienia;
 *  - `user->getKey() === notifiableUserId()` zdjęte z `CommentPolicy::offerThank()`
 *    → `test_dziekuje_tylko_autor_tresci…` przepuszcza osobę trzecią;
 *  - `denies('view')` zdjęte z `PodziekowanieZaKomentarzRequest` → testy blokady
 *    i ukrytego komentarza dostają 403/302 zamiast 404;
 *  - `throw` zdjęty z `down()` migracji → test rollbacku oblewa;
 *  - `CommentThank` zdjęte z `EraseAccountData` → test wymazania oblewa.
 */
class DziekujePodKomentarzemTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $komentujaca;

    private Post $wpis;

    private Comment $komentarz;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autor');
        $this->komentujaca = $this->user('komentujaca', ['display_name' => 'Basia']);
        $this->wpis = Post::factory()->create(['author_id' => $this->autor->id, 'published_at' => now()->subHour()]);
        $this->komentarz = Comment::factory()->create([
            'author_id' => $this->komentujaca->id,
            'post_id' => $this->wpis->id,
            'body' => 'Bardzo pomocna uwaga o soli',
        ]);
    }

    public function test_autor_wpisu_dziekuje_raz_a_komentujaca_dostaje_jedno_powiadomienie(): void
    {
        $this->actingAs($this->autor)->from(route('posts.show', $this->wpis))
            ->post(route('comments.thank', $this->komentarz))
            ->assertRedirect()
            ->assertSessionHas(Komunikat::KLUCZ_RODZAJU, Komunikat::SUKCES);

        $this->assertSame(1, CommentThank::query()->count());
        $wiersz = CommentThank::query()->firstOrFail();
        $this->assertSame($this->komentarz->id, $wiersz->comment_id);
        $this->assertSame($this->autor->id, $wiersz->thanker_id);

        $powiadomienie = Notification::query()->where('type', Notification::TYPE_COMMENT_THANKED)->sole();
        $this->assertSame($this->komentujaca->id, $powiadomienie->user_id);
        $this->assertSame($this->autor->id, $powiadomienie->actor_id);
        $this->assertSame($this->komentarz->id, $powiadomienie->data['comment_id']);

        // To NIE jest odpowiedź: nie powstał żaden komentarz i edycja komentarza
        // (zamykana pierwszą odpowiedzią, #1337) nadal jest otwarta.
        $this->assertSame(1, Comment::query()->count());
        $this->assertFalse($this->komentarz->replies()->exists());
        $this->assertTrue($this->komentujaca->can('update', $this->komentarz));
    }

    public function test_powiadomienie_trafia_na_liste_z_wycinkiem_i_linkiem_do_komentarza(): void
    {
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz));

        $this->actingAs($this->komentujaca)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('dziękuje Ci za komentarz')
            ->assertSee('Bardzo pomocna uwaga o soli');

        // „Zobacz” prowadzi do KONKRETNEGO komentarza, nie tylko do wpisu.
        $powiadomienie = Notification::query()->where('type', Notification::TYPE_COMMENT_THANKED)->sole();
        $this->post(route('notifications.open', $powiadomienie))
            ->assertRedirect()
            ->assertRedirectContains('#komentarz-'.$this->komentarz->id);
    }

    public function test_drugie_klikniecie_nie_dubluje_wiersza_ani_powiadomienia(): void
    {
        $this->actingAs($this->autor);

        $this->post(route('comments.thank', $this->komentarz))->assertRedirect();
        $this->post(route('comments.thank', $this->komentarz))->assertRedirect()->assertSessionHas(Komunikat::KLUCZ_RODZAJU, Komunikat::INFORMACJA);

        $this->assertSame(1, CommentThank::query()->count());
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_COMMENT_THANKED)->count());

        // Niezależnie od PHP: baza sama odrzuca drugi wiersz tej pary.
        $this->expectException(QueryException::class);
        CommentThank::query()->insert([
            'id' => (string) Str::uuid7(),
            'comment_id' => $this->komentarz->id,
            'thanker_id' => $this->autor->id,
            'created_at' => now(),
        ]);
    }

    public function test_wycofania_nie_ma_i_jest_to_jawna_decyzja(): void
    {
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz));

        // Brak trasy usuwającej podziękowanie: DELETE na adres podziękowania to 405.
        $this->delete('/komentarze/'.$this->komentarz->id.'/dziekuje')->assertStatus(405);
        $this->assertSame(1, CommentThank::query()->count());
    }

    public function test_dziekuje_tylko_autor_tresci_i_nigdy_wlasnemu_komentarzowi(): void
    {
        $trzecia = $this->user('trzecia');

        // Komentująca pod cudzym wpisem nie dziękuje sobie ani innym.
        $this->actingAs($this->komentujaca)->post(route('comments.thank', $this->komentarz))->assertForbidden();
        // Osoba trzecia, która widzi komentarz, też nie.
        $this->actingAs($trzecia)->post(route('comments.thank', $this->komentarz))->assertForbidden();

        // Autor wpisu nie dziękuje własnemu komentarzowi pod własnym wpisem.
        $wlasny = Comment::factory()->create(['author_id' => $this->autor->id, 'post_id' => $this->wpis->id]);
        $this->actingAs($this->autor)->post(route('comments.thank', $wlasny))->assertForbidden();

        $this->assertSame(0, CommentThank::query()->count());
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_COMMENT_THANKED)->count());
    }

    public function test_gosc_jest_odsylany_do_logowania_i_nic_nie_zapisuje(): void
    {
        $this->post(route('comments.thank', $this->komentarz))->assertRedirect(route('login'));

        $this->assertSame(0, CommentThank::query()->count());
    }

    public function test_dziekuje_takze_pod_przepisem_i_pod_wykonaniem_ale_nie_pod_cudzym(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->autor->id]);
        $pod = Comment::factory()->create(['author_id' => $this->komentujaca->id, 'post_id' => null, 'recipe_id' => $przepis->id]);
        $this->actingAs($this->autor)->post(route('comments.thank', $pod))->assertRedirect();

        $wykonanie = CookedEvent::factory()->create(['user_id' => $this->autor->id, 'recipe_id' => $przepis->id]);
        $podWykonaniem = Comment::factory()->create(['author_id' => $this->komentujaca->id, 'post_id' => null, 'cooked_event_id' => $wykonanie->id]);
        $this->actingAs($this->autor)->post(route('comments.thank', $podWykonaniem))->assertRedirect();

        $this->assertSame(2, CommentThank::query()->count());

        // Komentarz pod wpisem KOMUŚ INNEGO: autor przepisu nie jest tu autorem treści.
        $cudzyWpis = Post::factory()->create(['author_id' => $this->komentujaca->id, 'published_at' => now()->subHour()]);
        $cudzy = Comment::factory()->create(['author_id' => $this->user('inna')->id, 'post_id' => $cudzyWpis->id]);
        $this->actingAs($this->autor)->post(route('comments.thank', $cudzy))->assertForbidden();
    }

    public function test_blokada_w_obie_strony_zamyka_wejscie_i_nie_wysyla_powiadomienia(): void
    {
        Block::create(['blocker_id' => $this->komentujaca->id, 'blocked_id' => $this->autor->id, 'created_at' => now()]);
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz))->assertNotFound();

        Block::query()->delete();
        Block::create(['blocker_id' => $this->autor->id, 'blocked_id' => $this->komentujaca->id, 'created_at' => now()]);
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz))->assertNotFound();

        $this->assertSame(0, CommentThank::query()->count());
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_COMMENT_THANKED)->count());
    }

    public function test_komentarz_ukryty_usuniety_albo_bez_tresci_jest_niedostepny(): void
    {
        $this->actingAs($this->autor);

        Comment::query()->whereKey($this->komentarz->id)->update(['status' => Comment::STATUS_HIDDEN]);
        $this->post(route('comments.thank', $this->komentarz))->assertNotFound();

        Comment::query()->whereKey($this->komentarz->id)->update(['status' => Comment::STATUS_PUBLISHED]);
        $this->komentarz->delete();
        $this->post(route('comments.thank', $this->komentarz->id))->assertNotFound();
        $this->komentarz->restore();

        // Usunięty z odpowiedziami: zostaje ślad „Komentarz usunięty.” bez autora treści do kwitowania.
        Comment::query()->whereKey($this->komentarz->id)->update(['body_removed_at' => now(), 'body' => Comment::DELETED_PLACEHOLDER]);
        $this->post(route('comments.thank', $this->komentarz))->assertForbidden();

        $this->assertSame(0, CommentThank::query()->count());
    }

    public function test_konto_komentujacej_niedostepne_jako_autor_zamyka_wejscie(): void
    {
        $this->komentujaca->forceFill(['status' => User::STATUS_BANNED])->save();

        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz))->assertNotFound();

        $this->assertSame(0, CommentThank::query()->count());
    }

    public function test_przycisk_dziala_bez_js_jako_zwykly_formularz_tylko_dla_autora_tresci(): void
    {
        $adres = route('posts.show', $this->wpis);

        $html = (string) $this->actingAs($this->autor)->get($adres)->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '~<form method="POST" action="'.preg_quote(route('comments.thank', $this->komentarz), '~').'" novalidate>\s*<input type="hidden" name="_token"[^>]*>\s*<button class="btn btn-quiet" type="submit">Dziękuję<span class="visually-hidden"> za komentarz od Basia</span></button>~',
            $html,
        );

        // Komentująca, osoba trzecia i gość nie mają przycisku.
        $this->actingAs($this->komentujaca)->get($adres)->assertDontSee('Dziękuję<span', false);
        $this->actingAs($this->user('trzecia'))->get($adres)->assertDontSee('Dziękuję<span', false);
        auth()->logout();
        $this->get($adres)->assertOk()->assertDontSee('Dziękuję<span', false);
    }

    public function test_po_podziekowaniu_widza_je_tylko_dwie_osoby_i_bez_licznika(): void
    {
        $adres = route('posts.show', $this->wpis);
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz));

        $autor = (string) $this->actingAs($this->autor)->get($adres)->getContent();
        $this->assertStringContainsString('Podziękowano za ten komentarz.', $autor);
        $this->assertStringNotContainsString('Dziękuję<span', $autor);

        $this->actingAs($this->komentujaca)->get($adres)->assertSee('Podziękowano za ten komentarz.');

        $trzecia = $this->actingAs($this->user('trzecia'))->get($adres)->assertOk()->getContent();
        $this->assertStringNotContainsString('Podziękowano', (string) $trzecia);
        auth()->logout();
        $this->get($adres)->assertOk()->assertDontSee('Podziękowano');

        // Nigdzie żadnej liczby podziękowań.
        $this->assertDoesNotMatchRegularExpression('/\d+\s*(podziękowa|podziekowa)/iu', $autor);
    }

    public function test_odpowiedz_pod_komentarzem_tez_mozna_podziekowac(): void
    {
        $odpowiedz = Comment::factory()->create([
            'author_id' => $this->komentujaca->id,
            'post_id' => $this->wpis->id,
            'parent_id' => $this->komentarz->id,
            'body' => 'Dopisek do soli',
        ]);

        $html = (string) $this->actingAs($this->autor)->get(route('posts.show', $this->wpis))->getContent();
        $this->assertStringContainsString(route('comments.thank', $odpowiedz), $html);

        $this->post(route('comments.thank', $odpowiedz))->assertRedirect();
        $this->assertSame(1, CommentThank::query()->where('comment_id', $odpowiedz->id)->count());
    }

    public function test_powiadomienie_znika_razem_z_komentarzem(): void
    {
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz));
        $this->komentarz->delete();

        $this->actingAs($this->komentujaca)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('dziękuje Ci za komentarz')
            ->assertDontSee('Bardzo pomocna uwaga o soli');
    }

    public function test_eksport_ma_podziekowania_dane_przez_osobe_a_wymazanie_konta_je_kasuje(): void
    {
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz));

        $paczka = app(CollectUserExportData::class)->handle($this->autor, new ExportPhotoPlan($this->autor), now());
        $this->assertCount(1, $paczka['moje_podziekowania']);
        $this->assertSame($this->wpis->url(), $paczka['moje_podziekowania'][0]['rozmowa']);
        // Komentującej paczka nie nazywa i nie cytuje jej słów.
        $this->assertStringNotContainsString('Bardzo pomocna uwaga o soli', json_encode($paczka['moje_podziekowania'], JSON_UNESCAPED_UNICODE) ?: '');
        $this->assertSame([], app(CollectUserExportData::class)->handle($this->komentujaca, new ExportPhotoPlan($this->komentujaca), now())['moje_podziekowania']);

        $this->autor->forceFill([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ])->save();
        $this->assertTrue(app(EraseAccountData::class)->handle($this->autor->fresh()));

        $this->assertSame(0, CommentThank::query()->count(), 'Po wymazaniu konta zostały jego podziękowania.');
    }

    public function test_wymazanie_konta_komentujacej_kasuje_podziekowania_za_jej_komentarze(): void
    {
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz));

        $this->komentujaca->forceFill([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ])->save();
        $this->assertTrue(app(EraseAccountData::class)->handle($this->komentujaca->fresh()));

        $this->assertSame(0, CommentThank::query()->count());
    }

    public function test_rollback_odmawia_gdy_sa_podziekowania_i_przechodzi_na_pustej(): void
    {
        $sciezka = 'database/migrations/2026_10_01_113000_create_comment_thanks_table.php';
        $this->actingAs($this->autor)->post(route('comments.thank', $this->komentarz));

        try {
            Artisan::call('migrate:rollback', ['--path' => $sciezka]);
            $this->fail('Rollback przeszedł mimo podziękowań w tabeli.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }
        $this->assertSame(1, CommentThank::query()->count());

        CommentThank::query()->delete();
        Artisan::call('migrate:rollback', ['--path' => $sciezka]);
        $this->assertFalse(Schema::hasTable('comment_thanks'));
        Artisan::call('migrate', ['--path' => $sciezka]);
        $this->assertTrue(Schema::hasTable('comment_thanks'));
    }
}
