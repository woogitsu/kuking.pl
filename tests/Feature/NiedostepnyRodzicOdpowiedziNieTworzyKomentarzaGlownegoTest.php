<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #761: odpowiedź wysłana ze starego formularza może zostać
 * opublikowana jako nowy komentarz główny, gdy wskazany rodzic zniknął albo
 * przestał być widoczny.
 *
 * PRZYCZYNA
 * Kontrolery szukają rodzica przez `whereKey($parentId)->widoczneDla($viewer)`
 * i przekazują `null`, gdy nic nie znajdą — dokładnie to samo `null`, które
 * `PublishComment::handle()` dostaje, gdy formularz w ogóle nie miał
 * `parent_id`. Te dwa przypadki są nierozróżnialne bez dodatkowej informacji,
 * więc odpowiedź wysłana pod zniknięty/ukryty/zablokowany/obcy identyfikator
 * publikowała się PO CICHU jako nowy komentarz główny: „Komentarz dodany”
 * wychodziło, tyle że tekst trafiał w inne miejsce rozmowy, niż zakładał
 * autor.
 *
 * NAPRAWA
 * Kontrolery przekazują teraz `parentRequested: $parentId !== null` — akcja
 * domenowa rozróżnia „rodzica nie podano” od „podano, ale nie da się go
 * użyć” i w drugim przypadku odmawia (ten sam neutralny komunikat co przy
 * blokadzie), zamiast ciszej zmiany adresata publikacji.
 *
 * KONTROLE DODATNIE (w tym pliku i w BlokadaObowiazujeTakzePrzyOdpowiadaniuTest)
 * - brak `parent_id` nadal tworzy komentarz główny,
 * - prawidłowy, widoczny rodzic nadal daje odpowiedź.
 */
class NiedostepnyRodzicOdpowiedziNieTworzyKomentarzaGlownegoTest extends TestCase
{
    use RefreshDatabase;

    public function test_odpowiedz_pod_usunietym_bezdzietnym_komentarzem_nie_powstaje_jako_komentarz_glowny(): void
    {
        $a = $this->user('autorwpisu761');
        $b = $this->user('komentujacyb761');
        $c = $this->user('odpowiadajacyc761');

        $wpis = Post::factory()->for($a, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        // B dodaje komentarz BEZ odpowiedzi — realny endpoint go usuwa
        // zwykłym soft delete (bez dzieci), więc znika naprawdę.
        $komentarzB = Comment::create([
            'author_id' => $b->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz B, ktory zaraz zniknie.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // C otworzył formularz odpowiedzi z parent_id=komentarzB, po czym
        // B usuwa swój komentarz przez rzeczywisty endpoint zanim C wysyła.
        $this->actingAs($b)->delete(route('comments.destroy', $komentarzB))->assertRedirect();

        $liczbaKomentarzyPrzed = Comment::count();

        $odpowiedz = $this->actingAs($c)->post(route('posts.comment', $wpis), [
            'body' => 'TEKST-C-KTORY-NIE-MA-PRAWA-TRAFIC-DO-KORZENIA',
            'parent_id' => $komentarzB->getKey(),
        ]);

        // Oczekiwane: kontrolowana odmowa, zachowany wpisany tekst, ZERO
        // nowych komentarzy — nie publikujemy automatycznie w korzeniu.
        $odpowiedz->assertSessionHasErrors('body');
        $odpowiedz->assertSessionHasInput('body', 'TEKST-C-KTORY-NIE-MA-PRAWA-TRAFIC-DO-KORZENIA');
        $this->assertDatabaseMissing('comments', ['body' => 'TEKST-C-KTORY-NIE-MA-PRAWA-TRAFIC-DO-KORZENIA']);
        $this->assertSame($liczbaKomentarzyPrzed, Comment::count(), 'Tekst C nie może utworzyć nowego komentarza głównego.');
        // Cicha zamiana w komentarz główny wysyłałaby powiadomienie do niewłaściwego miejsca.
        $this->assertDatabaseCount('notifications', 0);
    }

    /** To samo pod przepisem — RecipeController ma osobną, prawie identyczną ścieżkę. */
    public function test_odpowiedz_pod_usunietym_komentarzem_pod_przepisem_nie_powstaje_jako_komentarz_glowny(): void
    {
        $autor = $this->user('autorprzepisu761');
        $b = $this->user('komentujacyprzepis761');
        $c = $this->user('odpowiadajacyprzepis761');

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        $komentarz = Comment::create([
            'author_id' => $b->getKey(),
            'recipe_id' => $przepis->getKey(),
            'body' => 'Komentarz pod przepisem, ktory zaraz zniknie.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->actingAs($b)->delete(route('comments.destroy', $komentarz))->assertRedirect();

        $liczbaKomentarzyPrzed = Comment::count();

        $odpowiedz = $this->actingAs($c)->post(route('recipes.comment', $przepis->slug), [
            'body' => 'TEKST-C-PRZEPIS-KTORY-NIE-MA-PRAWA-TRAFIC-DO-KORZENIA',
            'parent_id' => $komentarz->getKey(),
        ]);

        $odpowiedz->assertSessionHasErrors('body');
        $this->assertDatabaseMissing('comments', ['body' => 'TEKST-C-PRZEPIS-KTORY-NIE-MA-PRAWA-TRAFIC-DO-KORZENIA']);
        $this->assertSame($liczbaKomentarzyPrzed, Comment::count());
    }

    /** Kontrola dodatnia: brak parent_id nadal tworzy zwykły komentarz główny. */
    public function test_brak_parent_id_nadal_tworzy_komentarz_glowny(): void
    {
        $autor = $this->user('autorkontrola761');
        $wpis = Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $this->actingAs($this->user('czytelnikkontrola761'))
            ->post(route('posts.comment', $wpis), ['body' => 'ZWYKLY-NOWY-KOMENTARZ-KONTROLNY'])
            ->assertRedirect();

        $this->assertDatabaseHas('comments', ['body' => 'ZWYKLY-NOWY-KOMENTARZ-KONTROLNY', 'parent_id' => null]);
    }

    /** Kontrola dodatnia: prawidłowy, widoczny rodzic nadal daje odpowiedź. */
    public function test_prawidlowy_widoczny_rodzic_nadal_daje_odpowiedz(): void
    {
        $autor = $this->user('autorkontrola761b');
        $b = $this->user('komentujacykontrola761b');

        $wpis = Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $komentarzB = Comment::create([
            'author_id' => $b->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz B, ktory zostaje.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->actingAs($this->user('odpowiadajacykontrola761b'))
            ->post(route('posts.comment', $wpis), [
                'body' => 'PRAWIDLOWA-ODPOWIEDZ-KONTROLNA',
                'parent_id' => $komentarzB->getKey(),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('comments', ['body' => 'PRAWIDLOWA-ODPOWIEDZ-KONTROLNA', 'parent_id' => $komentarzB->getKey()]);
    }
}
