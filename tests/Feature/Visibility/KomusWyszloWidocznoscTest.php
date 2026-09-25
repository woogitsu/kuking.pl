<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Widoczność ekranu „Komuś wyszło" (issue #17).
 *
 * Ten ekran ma WĘŻSZĄ widoczność niż zwykły `cooked.show`: nie „każdy, kto
 * widzi ten wpis", tylko WYŁĄCZNIE autor przepisu. Test w obie strony:
 * nie tylko „zablokowanego/obcego nie widać", ale i „naprawa nie jest zbyt
 * szeroka" — aktywna osoba nie traci dostępu przez cudzą karę w bazie.
 */
class KomusWyszloWidocznoscTest extends TestCase
{
    use RefreshDatabase;

    public function test_autor_przepisu_widzi_ekran(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->actingAs($autor)->get(route('cooked.celebrate', $event))->assertOk();
    }

    public function test_sam_kucharz_ktory_ugotowal_nie_widzi_ekranu(): void
    {
        // To jest EKRAN AUTORA PRZEPISU, nie potwierdzenie dla wykonawcy —
        // wykonawca ma swoje własne "Zapisane" po `cooked.store`.
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->actingAs($kucharz)->get(route('cooked.celebrate', $event))->assertForbidden();
    }

    public function test_przypadkowy_widz_nie_widzi_ekranu(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $widz = $this->user('widz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'visibility' => 'public']);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->actingAs($widz)->get(route('cooked.celebrate', $event))->assertForbidden();
    }

    public function test_moderator_nie_dostaje_ekranu_celebracji(): void
    {
        // Moderator ma prawo WEJŚĆ na wpis (`cooked.show`, `CookedEventPolicy::view`),
        // ale ten ekran to nie jest "wgląd moderacyjny" — to osobiste
        // podziękowanie autora. 403, nie cichy dostęp furtką moderacji.
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->actingAs($this->moderator())->get(route('cooked.celebrate', $event))->assertForbidden();
    }

    public function test_blokada_autora_wobec_kucharza_ukrywa_ekran(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        app(BlockUser::class)->handle($autor, $kucharz);

        $this->actingAs($autor->fresh())->get(route('cooked.celebrate', $event))->assertForbidden();
    }

    public function test_blokada_w_drugą_strone_tez_ukrywa_ekran(): void
    {
        // Blokada działa W OBIE STRONY (AGENTS.md §4) — tu to KUCHARZ
        // zablokował autora, nie odwrotnie.
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        app(BlockUser::class)->handle($kucharz, $autor);

        $this->actingAs($autor->fresh())->get(route('cooked.celebrate', $event))->assertForbidden();
    }

    public function test_blokada_niepowiazanej_osoby_nie_ukrywa_ekranu(): void
    {
        // NAPRAWA ZBYT SZEROKA: autor blokuje kogoś zupełnie innego —
        // filtr nie może przez pomyłkę potraktować tego jak blokadę kucharza.
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $spam = $this->user('spam');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        app(BlockUser::class)->handle($autor, $spam);

        $this->actingAs($autor->fresh())->get(route('cooked.celebrate', $event))->assertOk();
    }

    public function test_ekran_dziala_mimo_ze_ktos_inny_w_bazie_jest_ukarany(): void
    {
        // NAPRAWA ZBYT SZEROKA: kara na zupełnie innym koncie (banned) nie
        // może po drodze zgasić celebracji wykonania osoby AKTYWNEJ.
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->user('ukaranyniktgotunieDotyczy', ['status' => User::STATUS_BANNED]);

        $this->actingAs($autor)->get(route('cooked.celebrate', $event))->assertOk();
    }

    public function test_wykonanie_zbanowanego_kucharza_nie_dostaje_celebracji(): void
    {
        // Kucharz ugotował, ZANIM go zbanowano — dane zostają w bazie
        // (AGENTS.md: "poprawne dane nigdy nie znikają"), ale ekran, który
        // AKTYWNIE podsuwa to jako powód do radości, przestaje się pokazywać.
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharzdobanowania');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $kucharz->ban();

        $this->actingAs($autor)->get(route('cooked.celebrate', $event))->assertForbidden();

        // Od D-261 (audyt A5-07) znika też sam wpis pod bezpośrednim
        // adresem — tak jak profil i galeria tej osoby. Dane zostają w bazie
        // i wracają po zdjęciu bana (KarencjaUsunieciaChowaWykonanieTest).
        $this->actingAs($autor)->get(route('cooked.show', $event))->assertForbidden();
    }

    public function test_wykonanie_aktywnego_kucharza_dostaje_celebracje_mimo_ze_inny_jest_zbanowany(): void
    {
        $autor = $this->user('autorka');
        $kucharzAktywny = $this->user('kucharzaktywny');
        $kucharzZbanowany = $this->user('kucharzzbanowany');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $eventAktywny = app(RecordCookedEvent::class)->handle($kucharzAktywny, $recipe);
        $eventZbanowany = app(RecordCookedEvent::class)->handle($kucharzZbanowany, $recipe);
        $kucharzZbanowany->ban();

        $this->actingAs($autor)->get(route('cooked.celebrate', $eventAktywny))->assertOk();
    }
}
