<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\DziennikWgladu;
use App\Models\AuditLogEntry;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dziennik wglądów moderatora (D-333, decyzja właściciela z 29.09.2026).
 *
 * Wgląd moderatora w treść albo zdjęcie niewidoczne publicznie zostawia
 * wpis w `audit_log` (kto, co, kiedy, powód w metadanych). Wyświetlenia
 * publiczne i wejścia autora nie zostawiają nic.
 *
 * Kontrola ujemna: usunięcie wywołania `DziennikWgladu::zdjecie()` z
 * `MediaController` oblewa testy zdjęć; usunięcie `przepis()` z
 * `RecipeController::show()` oblewa testy przepisu; wyjęcie okna 60 minut
 * z `DziennikWgladu::zdjecie()` oblewa `test_ponowne_otwarcie_..._w_oknie`.
 */
class DziennikWgladowModeratoraTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->autor = $this->user('autor_wgladu');
        $this->moderator = $this->moderator();
    }

    public function test_zdjecie_bedace_celem_zgloszenia_zostawia_slad_z_powodem(): void
    {
        $zdjecie = $this->zdjecie();
        $sprawa = $this->zglos($zdjecie);

        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);

        $wpis = AuditLogEntry::query()->where('action', DziennikWgladu::ZDJECIE)->sole();
        $this->assertSame($this->moderator->getKey(), $wpis->actor_id);
        $this->assertSame('Media', $wpis->subject_type);
        $this->assertSame($zdjecie->getKey(), $wpis->subject_id);
        $this->assertSame(['powod' => DziennikWgladu::POWOD_ZGLOSZENIE, 'sprawy' => [$sprawa->getKey()]], $wpis->metadata);
        $this->assertNotNull($wpis->created_at);
    }

    public function test_zdjecie_ukrytego_wpisu_zostawia_slad_z_powodem(): void
    {
        $zdjecie = $this->zdjecie();
        $wpisUkryty = $this->wpis($zdjecie, Post::STATUS_HIDDEN);

        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);

        $wpis = AuditLogEntry::query()->where('action', DziennikWgladu::ZDJECIE)->sole();
        $this->assertSame($zdjecie->getKey(), $wpis->subject_id);
        $this->assertSame(['powod' => DziennikWgladu::POWOD_UKRYTA_TRESC, 'sprawy' => ['Post:'.$wpisUkryty->getKey()]], $wpis->metadata);
    }

    /**
     * Przegląd integracyjny: okno 60 minut sklejało po samej parze
     * moderator–zdjęcie, więc wgląd w DRUGĄ sprawę o to samo zdjęcie w tej
     * samej godzinie przepadał bez śladu.
     */
    public function test_wglad_w_inna_sprawe_o_to_samo_zdjecie_w_oknie_to_osobny_wpis(): void
    {
        $zdjecie = $this->zdjecie();
        $pierwsza = $this->zglos($zdjecie);

        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);
        $this->assertSame(1, $this->liczbaWpisow());

        // Druga sprawa o to samo zdjęcie: zgłoszenie od osoby (automat ma
        // jedno oznaczenie na treść — `reports_jeden_automat_na_tresc`).
        $druga = Report::create([
            'reporter_id' => $this->user('zglaszajacy_wgladu')->getKey(),
            'autor_tresci_id' => $zdjecie->owner_id,
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'media',
            'target_id' => $zdjecie->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);
        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);

        $this->assertSame(2, $this->liczbaWpisow());
        $sprawy = [$pierwsza->getKey(), $druga->getKey()];
        sort($sprawy);
        $this->assertSame($sprawy, AuditLogEntry::query()->where('action', DziennikWgladu::ZDJECIE)->latest('id')->first()->metadata['sprawy']);
    }

    /** Przegląd integracyjny: zdjęcie KROKU przepisu ukrytego wisi pod `RecipeStep`, nie pod `Recipe`. */
    public function test_zdjecie_kroku_przepisu_ukrytego_zostawia_slad(): void
    {
        $zdjecie = $this->zdjecie();
        $przepis = $this->przepis(Recipe::STATUS_HIDDEN);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Krok.', 'media_id' => $zdjecie->getKey()]);

        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);

        $wpis = AuditLogEntry::query()->where('action', DziennikWgladu::ZDJECIE)->sole();
        $this->assertSame(['powod' => DziennikWgladu::POWOD_UKRYTA_TRESC, 'sprawy' => ['Recipe:'.$przepis->getKey()]], $wpis->metadata);
    }

    /** Przegląd integracyjny: `RecipePolicy` wpuszcza moderatora także do przepisu ZDJĘTEGO. */
    public function test_przepis_zdjety_i_tryb_gotowania_zostawiaja_slad(): void
    {
        $zdjety = $this->przepis(Recipe::STATUS_REMOVED);
        $this->actingAs($this->moderator)->get(route('recipes.show', $zdjety->slug))->assertOk();

        $wpis = AuditLogEntry::query()->where('action', DziennikWgladu::PRZEPIS_UKRYTY)->sole();
        $this->assertSame(['powod' => DziennikWgladu::POWOD_UKRYTA_TRESC, 'status' => Recipe::STATUS_REMOVED], $wpis->metadata);

        $ukryty = $this->przepis(Recipe::STATUS_HIDDEN);
        RecipeStep::create(['recipe_id' => $ukryty->getKey(), 'position' => 0, 'instruction' => 'Krok.']);
        $this->actingAs($this->moderator)->get(route('cooking.show', $ukryty->slug))->assertOk();

        $this->assertSame(2, $this->liczbaWpisow(DziennikWgladu::PRZEPIS_UKRYTY));
    }

    public function test_kopia_bez_roli_nie_zmienia_konta_moderatora(): void
    {
        $zwykle = DziennikWgladu::jakZwykleKonto($this->moderator);

        $this->assertFalse($zwykle->isModerator());
        $this->assertTrue($this->moderator->isModerator());
        $this->assertSame(User::ROLE_MODERATOR, $this->moderator->fresh()->role);
    }

    public function test_ponowne_otwarcie_tego_samego_zdjecia_w_oknie_to_jeden_wpis(): void
    {
        $zdjecie = $this->zdjecie();
        $this->wpis($zdjecie, Post::STATUS_HIDDEN);

        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);
        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);
        $this->assertSame(1, $this->liczbaWpisow());

        $this->travel(DziennikWgladu::OKNO_ZDJECIA_MINUTY + 1)->minutes();

        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);
        $this->assertSame(2, $this->liczbaWpisow());
    }

    public function test_wyswietlenia_publiczne_i_wejscia_autora_nie_zostawiaja_sladu(): void
    {
        $publiczne = $this->zdjecie();
        $this->wpis($publiczne, Post::STATUS_PUBLISHED);

        $ukryte = $this->zdjecie();
        $this->wpis($ukryte, Post::STATUS_HIDDEN);

        $obcy = $this->user('obcy_wgladu');

        // Gość, zwykły widz i moderator na zdjęciu JAWNYM — nic.
        $this->get($publiczne->url('thumb'))->assertStatus(302);
        $this->actingAs($obcy)->get($publiczne->url('thumb'))->assertStatus(302);
        $this->actingAs($this->moderator)->get($publiczne->url('thumb'))->assertStatus(302);

        // Autor na własnym zdjęciu ukrytego wpisu — nic.
        $this->actingAs($this->autor)->get($ukryte->url('thumb'))->assertStatus(302);

        // Zwykły widz na ukrytym — odmowa i nic.
        $this->actingAs($obcy)->get($ukryte->url('thumb'))->assertStatus(404);

        $this->assertSame(0, $this->liczbaWpisow());
    }

    public function test_zdjecie_ukrytego_wpisu_ktore_jest_tez_jawne_nie_zostawia_sladu(): void
    {
        // To samo zdjęcie w ukrytym i w opublikowanym wpisie: anonim je widzi,
        // więc moderator nie korzysta z żadnego nadzwyczajnego uprawnienia.
        $zdjecie = $this->zdjecie();
        $this->wpis($zdjecie, Post::STATUS_HIDDEN);
        $this->wpis($zdjecie, Post::STATUS_PUBLISHED);

        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);

        $this->assertSame(0, $this->liczbaWpisow());
    }

    public function test_odmowa_nie_zostawia_sladu(): void
    {
        // Szkic wpisu: moderator nie ma tu żadnego wglądu, więc 404 i cisza.
        $szkic = $this->zdjecie();
        $this->wpis($szkic, Post::STATUS_DRAFT);
        $this->actingAs($this->moderator)->get($szkic->url('thumb'))->assertStatus(404);

        // Cel zgłoszenia, ale moderator bez 2FA — 404 i cisza.
        $zgloszone = $this->zdjecie();
        $this->zglos($zgloszone);
        $bez2fa = $this->user('moderator_bez_2fa_wgladu', ['role' => User::ROLE_MODERATOR]);
        $this->actingAs($bez2fa)->get($zgloszone->url('thumb'))->assertStatus(404);

        $this->assertSame(0, $this->liczbaWpisow());
    }

    public function test_przepis_ukryty_otwarty_przez_moderatora_zostawia_slad_a_przez_autora_nie(): void
    {
        $przepis = $this->przepis(Recipe::STATUS_HIDDEN);

        $this->actingAs($this->autor)->get(route('recipes.show', $przepis->slug))->assertOk();
        $this->assertSame(0, $this->liczbaWpisow(DziennikWgladu::PRZEPIS_UKRYTY));

        $this->actingAs($this->moderator)->get(route('recipes.show', $przepis->slug))->assertOk();

        $wpis = AuditLogEntry::query()->where('action', DziennikWgladu::PRZEPIS_UKRYTY)->sole();
        $this->assertSame($this->moderator->getKey(), $wpis->actor_id);
        $this->assertSame('Recipe', $wpis->subject_type);
        $this->assertSame($przepis->getKey(), $wpis->subject_id);
        $this->assertSame(['powod' => DziennikWgladu::POWOD_UKRYTA_TRESC, 'status' => Recipe::STATUS_HIDDEN], $wpis->metadata);
    }

    public function test_przepis_opublikowany_i_odmowa_wobec_obcego_nie_zostawiaja_sladu(): void
    {
        $opublikowany = $this->przepis(Recipe::STATUS_PUBLISHED);
        $this->actingAs($this->moderator)->get(route('recipes.show', $opublikowany->slug))->assertOk();

        $ukryty = $this->przepis(Recipe::STATUS_HIDDEN);
        $this->actingAs($this->user('obcy_przepis_wgladu'))
            ->get(route('recipes.show', $ukryty->slug))
            ->assertForbidden();

        $this->assertSame(0, $this->liczbaWpisow(DziennikWgladu::PRZEPIS_UKRYTY));
    }

    public function test_wpisy_wgladu_podlegaja_zwyklej_retencji_dziennika(): void
    {
        $this->assertNotContains(DziennikWgladu::ZDJECIE, AuditLogEntry::NIGDY_NIE_KASUJ);
        $this->assertNotContains(DziennikWgladu::PRZEPIS_UKRYTY, AuditLogEntry::NIGDY_NIE_KASUJ);
    }

    private function liczbaWpisow(string $akcja = DziennikWgladu::ZDJECIE): int
    {
        return AuditLogEntry::query()->where('action', $akcja)->count();
    }

    private function zdjecie(): Media
    {
        $zdjecie = Media::factory()->create([
            'owner_id' => $this->autor->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'status' => Media::STATUS_READY,
        ]);

        foreach ($zdjecie->metadata['variants'] ?? [] as $wariant) {
            Storage::disk('public')->put($wariant['key'], 'udawane-bajty');
        }

        return $zdjecie;
    }

    private function wpis(Media $zdjecie, string $status): Post
    {
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => $status,
            'published_at' => $status === Post::STATUS_DRAFT ? null : now()->subDay(),
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return $wpis;
    }

    private function przepis(string $status): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
        ]);

        // `status` i `published_at` to pola sterujące — poza `$fillable`.
        $przepis->forceFill([
            'status' => $status,
            'published_at' => $status === Recipe::STATUS_PUBLISHED ? now() : null,
        ])->save();

        return $przepis->refresh();
    }

    private function zglos(Media $zdjecie): Report
    {
        return Report::create([
            'reporter_id' => null,
            'autor_tresci_id' => $zdjecie->owner_id,
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'media',
            'target_id' => $zdjecie->getKey(),
            'reason' => 'automat_model',
            'details' => 'Zdjęcie profilowe: treść seksualna (88%).',
            'status' => Report::STATUS_OPEN,
        ]);
    }
}
