<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Moderator widzi SPRAWY, a nie wszystko (#1359, #1360 — audyt AUTHZ-01/02).
 *
 * Do 24.09.2026 sama rola moderatora otwierała:
 *  - każdy nieopublikowany przepis, także zwykły szkic (`RecipePolicy::view()`),
 *  - bajty każdego gotowego zdjęcia, zanim ktokolwiek zapytał o jego rodzica
 *    (`DostepDoZdjecia::wlascicielLubModerator()`).
 *
 * Teraz szkic jest wyłącznie autora, a zdjęcie moderator widzi przez Policy
 * rodzica — albo dlatego, że TO zdjęcie jest celem zgłoszenia. Każda odmowa
 * ma tu kontrolę dodatnią: ukryty przepis i jego zdjęcia dalej otwierają się
 * obsłudze, bo bez tego moderacja szłaby „w ciemno".
 */
class ModeratorWidziTylkoSprawyModeracyjneTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $obcy;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->autor = $this->user('autor_sprawy');
        $this->obcy = $this->user('obcy_sprawy');
        $this->moderator = $this->moderator();
    }

    /**
     * status × widz. Przepis ma `visibility = public`, żeby o wyniku
     * decydował wyłącznie STATUS, a nie krąg odbiorców.
     *
     * @return array<string, array{0: string, 1: array{autor: bool, moderator: bool, obcy: bool, gosc: bool}}>
     */
    public static function statusyPrzepisu(): array
    {
        return [
            'szkic' => [Recipe::STATUS_DRAFT, ['autor' => true, 'moderator' => false, 'obcy' => false, 'gosc' => false]],
            'opublikowany' => [Recipe::STATUS_PUBLISHED, ['autor' => true, 'moderator' => true, 'obcy' => true, 'gosc' => true]],
            'ukryty' => [Recipe::STATUS_HIDDEN, ['autor' => true, 'moderator' => true, 'obcy' => false, 'gosc' => false]],
            'zdjety' => [Recipe::STATUS_REMOVED, ['autor' => true, 'moderator' => true, 'obcy' => false, 'gosc' => false]],
        ];
    }

    /**
     * @param  array{autor: bool, moderator: bool, obcy: bool, gosc: bool}  $oczekiwane
     */
    #[DataProvider('statusyPrzepisu')]
    public function test_przepis_widz_status(string $status, array $oczekiwane): void
    {
        $przepis = $this->przepis($status);

        $widzowie = [
            'autor' => $this->autor,
            'moderator' => $this->moderator,
            'obcy' => $this->obcy,
            'gosc' => null,
        ];

        foreach ($widzowie as $kto => $widz) {
            $this->assertSame(
                $oczekiwane[$kto],
                Gate::forUser($widz)->allows('view', $przepis),
                "RecipePolicy::view: {$kto} × {$status}",
            );
        }
    }

    /**
     * Rodzaj rodzica × status przepisu, dla trzech dróg do zdjęcia przepisu:
     * zdjęcie główne, skan kartki (`source_scan_media_id`) i zdjęcie kroku.
     * Sprawdzamy `moze()` I `rozstrzygnij()` — trasa zdjęcia woła tę drugą.
     *
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function zdjeciaPrzepisu(): array
    {
        $przypadki = [];

        foreach (['hero', 'skan', 'krok'] as $rodzaj) {
            $przypadki["{$rodzaj} szkicu"] = [$rodzaj, Recipe::STATUS_DRAFT, false];
            $przypadki["{$rodzaj} ukrytego"] = [$rodzaj, Recipe::STATUS_HIDDEN, true];
            // Zdjęty = miękko usunięty, jak na produkcji (`$target->delete()`
            // w `RozstrzygnijZgloszenie::applyAction()` i `ZdejmijZUrzedu`).
            // Taki przepis nie jest już rodzicem zdjęcia (`rodzice()` idzie
            // przez scope `SoftDeletes`), więc moderator zdjęcia nie dostaje
            // — właściciel tak, skrótem właściciela.
            $przypadki["{$rodzaj} zdjetego"] = [$rodzaj, Recipe::STATUS_REMOVED, false];
            $przypadki["{$rodzaj} opublikowanego"] = [$rodzaj, Recipe::STATUS_PUBLISHED, true];
        }

        return $przypadki;
    }

    #[DataProvider('zdjeciaPrzepisu')]
    public function test_zdjecie_przepisu_dla_moderatora(string $rodzaj, string $status, bool $moderatorWidzi): void
    {
        $zdjecie = $this->zdjecie();
        $przepis = $this->przepis($status, match ($rodzaj) {
            'hero' => ['hero_media_id' => $zdjecie->getKey()],
            'skan' => ['source_scan_media_id' => $zdjecie->getKey()],
            'krok' => [],
        });

        if ($rodzaj === 'krok') {
            RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => 0,
                'instruction' => 'Zagotuj zakwas.',
                'media_id' => $zdjecie->getKey(),
            ]);
        }

        $this->assertDostep($zdjecie, $this->moderator, $moderatorWidzi, "moderator, {$rodzaj}, {$status}");

        // Kontrola dodatnia właściciela: autor widzi swoje zdjęcie w każdym stanie.
        $this->assertDostep($zdjecie, $this->autor, true, "autor, {$rodzaj}, {$status}");
    }

    public function test_zdjecie_prywatnego_opublikowanego_przepisu_nie_otwiera_sie_moderatorowi(): void
    {
        $zdjecie = $this->zdjecie();
        $this->przepis(Recipe::STATUS_PUBLISHED, [
            'visibility' => 'private',
            'source_scan_media_id' => $zdjecie->getKey(),
        ]);

        $this->assertDostep($zdjecie, $this->moderator, false, 'skan prywatnego przepisu');
        $this->assertDostep($zdjecie, $this->autor, true, 'skan prywatnego przepisu, autor');
    }

    public function test_zdjecie_szkicu_i_prywatnego_wpisu_nie_otwiera_sie_moderatorowi(): void
    {
        foreach ([
            'szkic wpisu' => Post::factory()->draft(),
            'prywatny wpis' => Post::factory()->state(['visibility' => Post::VISIBILITY_PRIVATE]),
        ] as $opis => $fabryka) {
            $zdjecie = $this->zdjecie();
            $wpis = $fabryka->create(['author_id' => $this->autor->getKey()]);
            $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

            $this->assertDostep($zdjecie, $this->moderator, false, $opis);
            $this->assertDostep($zdjecie, $this->autor, true, $opis.', autor');
        }
    }

    public function test_zdjecie_profilowe_widac_przez_profil(): void
    {
        $zdjecie = $this->zdjecie();
        $this->autor->profile->forceFill(['avatar_media_id' => $zdjecie->getKey()])->save();

        $this->assertDostep($zdjecie, $this->moderator, true, 'awatar czynnego konta');
    }

    public function test_osierocone_zdjecie_widzi_tylko_wlasciciel(): void
    {
        $zdjecie = $this->zdjecie();

        $this->assertDostep($zdjecie, $this->autor, true, 'osierocone, właściciel');
        $this->assertDostep($zdjecie, $this->moderator, false, 'osierocone, moderator');
        $this->assertDostep($zdjecie, $this->obcy, false, 'osierocone, obcy');
    }

    /**
     * Awatar oznaczony przez automat, a potem podmieniony: stary plik nie ma
     * już rodzica, a sprawa w `/admin/sygnaly` czeka. Obsługa z 2FA widzi
     * TEN plik — i tylko ten.
     */
    public function test_zdjecie_bedace_celem_zgloszenia_widzi_obsluga_z_2fa(): void
    {
        $zgloszone = $this->zdjecie();
        $inne = $this->zdjecie();
        $this->zglos($zgloszone);

        $this->assertDostep($zgloszone, $this->moderator, true, 'cel zgłoszenia, moderator z 2FA');
        $this->assertDostep($inne, $this->moderator, false, 'inne zdjęcie tej samej osoby');
        $this->assertDostep($zgloszone, $this->obcy, false, 'cel zgłoszenia, obcy');

        $bez2fa = $this->user('moderator_bez_2fa', ['role' => User::ROLE_MODERATOR]);
        $this->assertDostep($zgloszone, $bez2fa, false, 'cel zgłoszenia, moderator bez 2FA');

        $this->moderator->suspend(now()->addDays(3));
        $this->assertDostep($zgloszone, $this->moderator->refresh(), false, 'cel zgłoszenia, zawieszony moderator');
    }

    /**
     * Automat oznacza też wpisy „tylko dla obserwujących", a kolejka
     * `/admin/sygnaly` pokazuje ich miniaturę. Moderator zwykle autora nie
     * obserwuje — bez wyjątku sprawy dostawał 404 na zdjęciu, które ma ocenić.
     */
    public function test_zdjecie_zgloszonego_wpisu_dla_obserwujacych_widzi_obsluga_z_2fa(): void
    {
        $zdjecie = $this->zdjecie();
        $wpis = $this->wpis(Post::VISIBILITY_FOLLOWERS, $zdjecie);

        // Kontrola ujemna: bez sprawy wpis followers zostaje zamknięty.
        $this->assertDostep($zdjecie, $this->moderator, false, 'wpis followers bez zgłoszenia');

        $this->zglosWpis($wpis);

        $this->assertDostep($zdjecie, $this->moderator, true, 'zgłoszony wpis followers, moderator z 2FA');
        $this->actingAs($this->moderator)->get($zdjecie->url('thumb'))->assertStatus(302);

        $this->assertDostep($zdjecie, $this->obcy, false, 'zgłoszony wpis followers, obcy');
        $this->assertDostep($zdjecie, null, false, 'zgłoszony wpis followers, gość');

        $bez2fa = $this->user('moderator_bez_2fa_wpis', ['role' => User::ROLE_MODERATOR]);
        $this->assertDostep($zdjecie, $bez2fa, false, 'zgłoszony wpis followers, moderator bez 2FA');

        // Inne zdjęcie tej samej osoby, z innego (niezgłoszonego) wpisu.
        $inne = $this->zdjecie();
        $this->wpis(Post::VISIBILITY_FOLLOWERS, $inne);
        $this->assertDostep($inne, $this->moderator, false, 'inny wpis tej samej osoby');
    }

    /**
     * Sprawa zamknięta nie jest już sprawą. Wpis zostawiony w spokoju wraca
     * do zwykłej reguły kręgu odbiorców — kolejka i tak pokazuje wyłącznie
     * otwarte sygnały.
     */
    public function test_zamkniete_zgloszenie_wpisu_nie_otwiera_zdjecia(): void
    {
        foreach ([Report::STATUS_RESOLVED, Report::STATUS_REJECTED] as $status) {
            $zdjecie = $this->zdjecie();
            $this->zglosWpis($this->wpis(Post::VISIBILITY_FOLLOWERS, $zdjecie), $status);

            $this->assertDostep($zdjecie, $this->moderator, false, "zgłoszenie {$status}");
        }

        // Kontrola dodatnia: te same statusy co kolejka (`isOpen()`).
        foreach ([Report::STATUS_TRIAGE, Report::STATUS_REVIEWING] as $status) {
            $zdjecie = $this->zdjecie();
            $this->zglosWpis($this->wpis(Post::VISIBILITY_FOLLOWERS, $zdjecie), $status);

            $this->assertDostep($zdjecie, $this->moderator, true, "zgłoszenie {$status}");
        }
    }

    /**
     * Zgłoszenie nie jest wytrychem do treści, której automat w ogóle nie
     * ogląda: prywatny wpis, szkic i wpis już zdjęty zostają zamknięte.
     */
    public function test_zgloszenie_nie_otwiera_zdjecia_prywatnego_szkicu_ani_zdjetego_wpisu(): void
    {
        $prywatne = $this->zdjecie();
        $this->zglosWpis($this->wpis(Post::VISIBILITY_PRIVATE, $prywatne));
        $this->assertDostep($prywatne, $this->moderator, false, 'zgłoszony wpis prywatny');

        $szkicu = $this->zdjecie();
        $szkic = Post::factory()->draft()->create(['author_id' => $this->autor->getKey()]);
        $szkic->media()->attach($szkicu->getKey(), ['position' => 0]);
        $this->zglosWpis($szkic);
        $this->assertDostep($szkicu, $this->moderator, false, 'zgłoszony szkic wpisu');

        $zdjetego = $this->zdjecie();
        $zdjety = $this->wpis(Post::VISIBILITY_FOLLOWERS, $zdjetego);
        $this->zglosWpis($zdjety);
        $zdjety->delete();
        $this->assertDostep($zdjetego, $this->moderator, false, 'zgłoszony wpis zdjęty (deleted_at)');
    }

    public function test_trasa_zdjecia_szkicu_odmawia_moderatorowi_a_ukrytego_nie(): void
    {
        $zdjecieSzkicu = $this->zdjecie();
        $this->przepis(Recipe::STATUS_DRAFT, ['hero_media_id' => $zdjecieSzkicu->getKey()]);

        $zdjecieUkrytego = $this->zdjecie();
        $this->przepis(Recipe::STATUS_HIDDEN, ['hero_media_id' => $zdjecieUkrytego->getKey()]);

        $this->actingAs($this->moderator)->get($zdjecieSzkicu->url('feed'))->assertNotFound();
        $this->actingAs($this->moderator)->get($zdjecieUkrytego->url('feed'))->assertStatus(302);
        $this->actingAs($this->autor)->get($zdjecieSzkicu->url('feed'))->assertStatus(302);

        Auth::logout();
        $this->get($zdjecieUkrytego->url('feed'))->assertNotFound();
    }

    public function test_strona_szkicu_odmawia_moderatorowi_a_ukrytego_nie(): void
    {
        $szkic = $this->przepis(Recipe::STATUS_DRAFT);
        $ukryty = $this->przepis(Recipe::STATUS_HIDDEN);

        // Ta sama odpowiedź co dla obcej osoby — moderator nie ma tu żadnej
        // drogi, której nie miałby każdy inny zalogowany.
        $this->actingAs($this->obcy)->get($szkic->url())->assertForbidden();
        $this->actingAs($this->moderator)->get($szkic->url())->assertForbidden();
        $this->actingAs($this->moderator)->get($ukryty->url())->assertOk();
        $this->actingAs($this->autor)->get($szkic->url())->assertOk();
    }

    /**
     * Blokady ta poprawka NIE zmienia: gałąź ukrytego przepisu nie pytała
     * o nią przed #1359 i dalej nie pyta. Czy blokada ma odcinać moderatora
     * od sprawy, rozstrzyga osobno B-02 (`docs/AUDYT_BEZPIECZENSTWA_2026-09-15.md`).
     * Ten test pilnuje, żeby zawężenie do `hidden`/`removed` nie zabrało
     * przy okazji moderatorowi sprawy, w której autor go zablokował.
     */
    public function test_zawezenie_nie_zmienia_blokady_przy_ukrytym_przepisie(): void
    {
        $zdjecie = $this->zdjecie();
        $ukryty = $this->przepis(Recipe::STATUS_HIDDEN, ['hero_media_id' => $zdjecie->getKey()]);

        app(BlockUser::class)->handle($this->autor, $this->moderator);

        $this->assertTrue(Gate::forUser($this->moderator->refresh())->allows('view', $ukryty->refresh()));
        $this->assertDostep($zdjecie, $this->moderator, true, 'zdjęcie ukrytego przepisu, blokada');
    }

    /**
     * „Najszerszy rodzic wygrywa" zostaje: zdjęcie szkicu, które jest też
     * zdjęciem publicznego przepisu, widać dla każdego.
     */
    public function test_najszerszy_rodzic_dalej_wygrywa(): void
    {
        $zdjecie = $this->zdjecie();
        $this->przepis(Recipe::STATUS_DRAFT, ['hero_media_id' => $zdjecie->getKey()]);
        $this->przepis(Recipe::STATUS_PUBLISHED, ['hero_media_id' => $zdjecie->getKey()]);

        $this->assertDostep($zdjecie, $this->moderator, true, 'wspólne, moderator');
        $this->assertDostep($zdjecie, null, true, 'wspólne, gość');
    }

    private function assertDostep(Media $zdjecie, ?User $widz, bool $oczekiwane, string $opis): void
    {
        $dostep = app(DostepDoZdjecia::class);

        $this->assertSame($oczekiwane, $dostep->moze($widz, $zdjecie), "moze(): {$opis}");
        $this->assertSame($oczekiwane, $dostep->rozstrzygnij($widz, $zdjecie)->dlaWidza, "rozstrzygnij(): {$opis}");
    }

    /**
     * @param  array<string, mixed>  $atrybuty
     */
    private function przepis(string $status, array $atrybuty = []): Recipe
    {
        $opublikowany = $status === Recipe::STATUS_PUBLISHED;

        $przepis = Recipe::factory()->create(array_merge([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
        ], $atrybuty));

        // `status` i `published_at` to pola sterujące — poza `$fillable`.
        $przepis->forceFill([
            'status' => $status,
            'published_at' => $opublikowany ? now() : null,
        ])->save();

        // Zdejmowanie treści na produkcji to miękkie usunięcie — sam status
        // `removed` bez `deleted_at` byłby stanem, którego nikt nie tworzy,
        // a test na nim mierzyłby inną ścieżkę niż prawdziwa.
        if ($status === Recipe::STATUS_REMOVED) {
            $przepis->delete();
        }

        return $przepis->refresh();
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

    private function wpis(string $widocznosc, Media $zdjecie): Post
    {
        $wpis = Post::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => $widocznosc,
        ]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return $wpis;
    }

    private function zglosWpis(Post $wpis, string $status = Report::STATUS_OPEN): void
    {
        $zgloszenie = Report::create([
            'reporter_id' => null,
            'autor_tresci_id' => $wpis->author_id,
            'source' => Report::SOURCE_AUTOMAT,
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'automat_model',
            'details' => 'Wpis: znany wzorzec spamu.',
            'status' => Report::STATUS_OPEN,
        ]);

        // Rozstrzygnięcie idzie jednym zapisem ze znacznikiem czasu —
        // tego pilnuje `reports_resolution_complete_check`.
        if ($status !== Report::STATUS_OPEN) {
            $zamkniete = in_array($status, [Report::STATUS_RESOLVED, Report::STATUS_REJECTED], true);

            $zgloszenie->forceFill([
                'status' => $status,
                'resolved_at' => $zamkniete ? now() : null,
                'resolved_by' => $zamkniete ? $this->moderator->getKey() : null,
            ])->save();
        }
    }

    private function zglos(Media $zdjecie): void
    {
        Report::create([
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
