<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #911: usunięcie komentarza jest jedną decyzją, także gdy żądanie
 * przyjdzie dwa razy (druga karta, ponowione wysłanie).
 *
 * Przed zmianą drugie DELETE korzenia z odpowiedziami ustawiało placeholder
 * jeszcze raz i wysyłało autorowi komentarza drugie powiadomienie MODERATION
 * — z cytatem „Komentarz usunięty.” zamiast jego słów i z drugim powodem.
 */
class PowtorneUsuniecieKomentarzaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, User, Comment} */
    private function korzenZOdpowiedzia(): array
    {
        $wlascicielka = $this->user('wlascicielka');
        $autor = $this->user('autorkomentarza');
        $post = Post::factory()->create(['author_id' => $wlascicielka->getKey()]);

        $korzen = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Moje pierwotne słowa.',
        ]);
        Comment::factory()->create(['post_id' => $post->getKey(), 'parent_id' => $korzen->getKey()]);

        return [$wlascicielka, $autor, $korzen];
    }

    public function test_dwa_udane_delete_daja_jedno_powiadomienie_i_brak_zmiany_stanu(): void
    {
        [$wlascicielka, $autor, $korzen] = $this->korzenZOdpowiedzia();

        $this->actingAs($wlascicielka)
            ->delete(route('comments.destroy', $korzen), ['reason' => 'Pierwszy powód.'])
            ->assertRedirect()
            ->assertSessionHas('status', 'Komentarz usunięty.');

        $poPierwszym = $korzen->fresh();
        $this->assertSame('Komentarz usunięty.', $poPierwszym->body);
        $this->assertNotNull($poPierwszym->body_removed_at);

        $this->travel(5)->minutes();

        $this->actingAs($wlascicielka)
            ->delete(route('comments.destroy', $korzen), ['reason' => 'Drugi powód.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Ten komentarz był już usunięty. Nic więcej nie trzeba robić.');

        $poDrugim = $korzen->fresh();
        $this->assertSame($poPierwszym->getRawOriginal('body_removed_at'), $poDrugim->getRawOriginal('body_removed_at'));
        $this->assertSame($poPierwszym->getRawOriginal('updated_at'), $poDrugim->getRawOriginal('updated_at'));
        $this->assertNotSoftDeleted($korzen);

        $powiadomienia = Notification::query()->where('user_id', $autor->getKey())->get();
        $this->assertCount(1, $powiadomienia);
        $this->assertStringContainsString('Moje pierwotne słowa.', $powiadomienia[0]->data['message']);
        $this->assertStringContainsString('Pierwszy powód.', $powiadomienia[0]->data['message']);
    }

    /** Stary formularz bez powodu nie każe uzasadniać czegoś, co już się stało. */
    public function test_powtorzenie_bez_powodu_nie_zada_uzasadnienia(): void
    {
        [$wlascicielka, , $korzen] = $this->korzenZOdpowiedzia();

        $this->actingAs($wlascicielka)
            ->delete(route('comments.destroy', $korzen), ['reason' => 'Nie na temat.'])
            ->assertSessionHasNoErrors();

        $this->actingAs($wlascicielka)
            ->delete(route('comments.destroy', $korzen))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Ten komentarz był już usunięty. Nic więcej nie trzeba robić.');

        $this->assertSame(1, Notification::query()->count());
    }

    /**
     * Wyścig: oba żądania przeszły wiązanie modelu, zanim pierwsze zapisało.
     * Akcja dostaje nieaktualny model — decyzja zapada pod zamkiem.
     */
    public function test_akcja_z_nieaktualnym_modelem_nie_usuwa_drugi_raz(): void
    {
        [$wlascicielka, $autor, $korzen] = $this->korzenZOdpowiedzia();
        $staryModel = Comment::query()->findOrFail($korzen->getKey());

        $this->assertTrue(app(DeleteComment::class)->handle($wlascicielka, $korzen, 'Pierwszy.'));
        $this->assertFalse(app(DeleteComment::class)->handle($wlascicielka, $staryModel, 'Drugi.'));

        $this->assertSame(1, Notification::query()->where('user_id', $autor->getKey())->count());
    }

    /** To samo bez odpowiedzi: drugi wątek trafia na miękko usunięty wiersz. */
    public function test_akcja_na_miekko_usunietym_komentarzu_nic_nie_robi(): void
    {
        $wlascicielka = $this->user('wlascicielka');
        $autor = $this->user('autorkomentarza');
        $post = Post::factory()->create(['author_id' => $wlascicielka->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $autor->getKey(), 'post_id' => $post->getKey()]);
        $staryModel = Comment::query()->findOrFail($komentarz->getKey());

        $this->assertTrue(app(DeleteComment::class)->handle($wlascicielka, $komentarz, 'Pierwszy.'));
        $this->assertFalse(app(DeleteComment::class)->handle($wlascicielka, $staryModel, 'Drugi.'));

        $this->assertSoftDeleted($komentarz);
        $this->assertSame(1, Notification::query()->count());
    }

    /**
     * Komentarz bez odpowiedzi jest usuwany miękko. Przed poprawką wiązanie
     * trasy pomijało miękko usunięte wiersze i drugie DELETE dawało 404
     * zamiast komunikatu, który obiecuje CHANGELOG.
     */
    public function test_drugie_delete_komentarza_bez_odpowiedzi_daje_komunikat(): void
    {
        $wlascicielka = $this->user('wlascicielka');
        $autor = $this->user('autorkomentarza');
        $post = Post::factory()->create(['author_id' => $wlascicielka->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $autor->getKey(), 'post_id' => $post->getKey()]);

        $this->actingAs($wlascicielka)
            ->delete(route('comments.destroy', $komentarz), ['reason' => 'Pierwszy powód.'])
            ->assertSessionHas('status', 'Komentarz usunięty.');

        $poPierwszym = Comment::withTrashed()->findOrFail($komentarz->getKey());
        $this->assertSoftDeleted($komentarz);

        $this->travel(5)->minutes();

        $this->actingAs($wlascicielka)
            ->delete(route('comments.destroy', $komentarz), ['reason' => 'Drugi powód.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Ten komentarz był już usunięty. Nic więcej nie trzeba robić.');

        $poDrugim = Comment::withTrashed()->findOrFail($komentarz->getKey());
        $this->assertSame($poPierwszym->getRawOriginal('deleted_at'), $poDrugim->getRawOriginal('deleted_at'));
        $this->assertSame($poPierwszym->getRawOriginal('updated_at'), $poDrugim->getRawOriginal('updated_at'));
        $this->assertSame(1, Notification::query()->where('user_id', $autor->getKey())->count());

        // Autor komentarza też dostaje komunikat, a nie 404.
        $this->actingAs($autor)
            ->delete(route('comments.destroy', $komentarz))
            ->assertSessionHas('status', 'Ten komentarz był już usunięty. Nic więcej nie trzeba robić.');

        // Obcy nie dowiaduje się, że komentarz istniał — Policy idzie pierwsza.
        $this->actingAs($this->user('obcy'))
            ->delete(route('comments.destroy', $komentarz))
            ->assertForbidden();

        // withTrashed() jest tylko na trasie usunięcia — edycja nadal 404.
        $this->actingAs($autor)
            ->put(route('comments.update', $komentarz), ['body' => 'Wskrzeszam.'])
            ->assertNotFound();
        $this->assertSoftDeleted($komentarz);
    }

    /** Obca osoba nadal dostaje odmowę — idempotencja nie omija Policy. */
    public function test_obca_osoba_nadal_dostaje_odmowe_na_usunietym(): void
    {
        [$wlascicielka, , $korzen] = $this->korzenZOdpowiedzia();
        $this->actingAs($wlascicielka)->delete(route('comments.destroy', $korzen), ['reason' => 'Powód.']);

        $this->actingAs($this->user('obcy'))
            ->delete(route('comments.destroy', $korzen))
            ->assertForbidden();
    }

    /** Awaria zapisu powiadomienia cofa usunięcie — jedna transakcja. */
    public function test_awaria_zapisu_powiadomienia_nic_nie_zapisuje(): void
    {
        [$wlascicielka, , $korzen] = $this->korzenZOdpowiedzia();

        Notification::creating(function (): void {
            throw new RuntimeException('Symulowana awaria zapisu powiadomienia.');
        });

        try {
            app(DeleteComment::class)->handle($wlascicielka, $korzen, 'Powód.');
            $this->fail('Awaria zapisu powiadomienia nie wyszła na zewnątrz akcji.');
        } catch (RuntimeException $e) {
            // Dokładnie nasza awaria, nie inny RuntimeException po drodze.
            $this->assertSame('Symulowana awaria zapisu powiadomienia.', $e->getMessage());
        } finally {
            Notification::flushEventListeners();
        }

        $poAwarii = $korzen->fresh();
        $this->assertSame('Moje pierwotne słowa.', $poAwarii->body);
        $this->assertNull($poAwarii->body_removed_at);
        $this->assertSame(0, Notification::query()->count());

        // Kontrola dodatnia: ponowienie po awarii dowozi decyzję z pierwotnym tekstem.
        $this->assertTrue(app(DeleteComment::class)->handle($wlascicielka, $korzen, 'Powód.'));
        $this->assertStringContainsString('Moje pierwotne słowa.', Notification::query()->sole()->data['message']);
    }

    /** To samo bez odpowiedzi: awaria cofa miękkie usunięcie. */
    public function test_awaria_zapisu_powiadomienia_bez_odpowiedzi_nie_usuwa(): void
    {
        $wlascicielka = $this->user('wlascicielka');
        $autor = $this->user('autorkomentarza');
        $post = Post::factory()->create(['author_id' => $wlascicielka->getKey()]);
        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
            'body' => 'Słowa bez odpowiedzi.',
        ]);

        Notification::creating(function (): void {
            throw new RuntimeException('Symulowana awaria zapisu powiadomienia.');
        });

        try {
            app(DeleteComment::class)->handle($wlascicielka, $komentarz, 'Powód.');
            $this->fail('Awaria zapisu powiadomienia nie wyszła na zewnątrz akcji.');
        } catch (RuntimeException $e) {
            $this->assertSame('Symulowana awaria zapisu powiadomienia.', $e->getMessage());
        } finally {
            Notification::flushEventListeners();
        }

        $poAwarii = Comment::withTrashed()->findOrFail($komentarz->getKey());
        $this->assertNull($poAwarii->deleted_at);
        $this->assertNotSoftDeleted($komentarz);
        $this->assertSame('Słowa bez odpowiedzi.', $poAwarii->body);
        $this->assertSame(0, Notification::query()->count());

        $this->assertTrue(app(DeleteComment::class)->handle($wlascicielka, $komentarz->fresh(), 'Powód.'));
        $this->assertSoftDeleted($komentarz);
        $this->assertStringContainsString('Słowa bez odpowiedzi.', Notification::query()->sole()->data['message']);
    }
}
