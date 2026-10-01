<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneSprawyModeracyjne;
use App\Domain\Compliance\PrzedawnioneUsunieteTresci;
use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Media\KasujZdjecie;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\Actions\ZabezpieczDowodCsam;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Report;
use App\Models\User;
use App\Models\ZabezpieczenieDowodu;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „CSAM — natychmiast ukryj i zabezpiecz” (D-333, 1 października 2026).
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić), wykonane ręcznie:
 *  - `Media::maWariantDoPokazania()` bez `STATUS_SECURED` — oblewa test
 *    „zdjęcie nie jest pokazywane nikomu”;
 *  - `ZabezpieczoneDowody::dotyczy()` w `PrzedawnioneUsunieteTresci::zModeracja()`
 *    usunięte — oblewa test retencji po wyczyszczeniu spraw;
 *  - warunek `konto()` w `EraseAccountData` usunięty — oblewa test wymazania konta;
 *  - `Gate secureCsam` bez `hasTwoFactorConfirmed()` / dla zwykłego użytkownika;
 *  - guard w `RestoreContent` usunięty — oblewa test przywracania;
 *  - `zabezpieczony()` w `PrzedawnioneSprawyModeracyjne` usunięty — oblewa test spraw.
 */
class ZabezpieczenieDowoduCsamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        config([
            'kuking.media.disk' => 'public',
            'kuking.media.public_disk' => 'public',
            'kuking.usuniete_tresci.retention_days' => 30,
        ]);
        Storage::fake('public');
    }

    // ───────────────────────── Policy ─────────────────────────

    public function test_policy_wpuszcza_moderatora_i_admina_z_2fa_a_nikogo_innego(): void
    {
        $this->assertTrue(Gate::forUser($this->moderator())->allows('secureCsam', User::class));
        $this->assertTrue(Gate::forUser($this->admin())->allows('secureCsam', User::class));

        $this->assertFalse(Gate::forUser($this->user('zwykla'))->allows('secureCsam', User::class));

        // Moderator BEZ potwierdzonego 2FA — ta sama bramka co panel.
        $bez2fa = $this->user('bez2fa', ['role' => User::ROLE_MODERATOR]);
        $this->assertFalse(Gate::forUser($bez2fa)->allows('secureCsam', User::class));
    }

    public function test_zwykly_uzytkownik_nie_wejdzie_ani_na_ekran_ani_nie_wykona_akcji(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zwykly = $this->user('zwykly');

        $this->actingAs($zwykly)->get(route('admin.csam.create', ['typ' => 'post', 'id' => $wpis->getKey()]))->assertNotFound();
        $this->actingAs($zwykly)->post(route('admin.csam.store', ['typ' => 'post', 'id' => $wpis->getKey()]), ['potwierdzam' => '1'])->assertNotFound();

        $this->assertSame(0, ZabezpieczenieDowodu::count());
        $this->assertFalse($wpis->fresh()->trashed());
    }

    public function test_akcja_wywolana_wprost_tez_odmawia_zwyklemu_uzytkownikowi(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->expectException(AuthorizationException::class);

        app(ZabezpieczDowodCsam::class)->handle($this->user('zwykly'), 'post', $wpis->getKey());
    }

    // ───────────────────────── ukrycie + wynik ─────────────────────────

    /** @return array<string, array{0: string}> */
    public static function typy(): array
    {
        return ['wpis' => ['post'], 'przepis' => ['recipe'], 'komentarz' => ['comment']];
    }

    #[DataProvider('typy')]
    public function test_tresc_znika_z_serwisu_a_dane_zostaja(string $typ): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('autor');
        $tresc = $this->tresc($typ, $autor);

        $this->wykonaj($moderator, $typ, $tresc)->assertRedirect();

        $this->assertSoftDeleted($tresc);
        $this->assertDatabaseHas($tresc->getTable(), ['id' => $tresc->getKey(), 'author_id' => $autor->getKey()]);

        $wpis = ZabezpieczenieDowodu::where('target_type', $typ)->sole();
        $this->assertSame($tresc->getKey(), $wpis->target_id);
        $this->assertSame($moderator->getKey(), $wpis->secured_by);
        $this->assertSame($autor->getKey(), $wpis->subject_user_id);

        $decyzja = ModerationAction::where('target_id', $tresc->getKey())->sole();
        $this->assertSame(ModerationAction::ACTION_REMOVE, $decyzja->action);
        $this->assertSame('krzywdzenie-dzieci', $decyzja->reason_code);
        $this->assertSame($decyzja->getKey(), $wpis->moderation_action_id);
    }

    public function test_wpis_ze_zdjeciem_ukryty_publicznie_a_zdjecie_zabezpieczone(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->wykonaj($this->moderator(), 'post', $wpis);

        $this->get(route('posts.show', $wpis))->assertNotFound();

        $zdjecie->refresh();
        $this->assertSame(Media::STATUS_SECURED, $zdjecie->status);
        $this->assertDatabaseHas('zabezpieczenia_dowodow', [
            'target_type' => 'media',
            'target_id' => $zdjecie->getKey(),
            'previous_media_status' => Media::STATUS_READY,
        ]);
        // Pliki NIE są kasowane — to dowód.
        Storage::disk('public')->assertExists($this->pliki($zdjecie));
    }

    public function test_przepis_zabezpiecza_tez_zdjecie_glowne_i_zdjecie_kroku(): void
    {
        $autor = $this->user('autor');
        $glowne = $this->zdjecie($autor);
        $krok = $this->zdjecie($autor);
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'hero_media_id' => $glowne->getKey()]);
        DB::table('recipe_steps')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'recipe_id' => $przepis->getKey(),
            'position' => 1,
            'instruction' => 'Wymieszaj.',
            'media_id' => $krok->getKey(),
        ]);

        $this->wykonaj($this->moderator(), 'recipe', $przepis)->assertRedirect();

        $this->assertSame(Media::STATUS_SECURED, $glowne->fresh()->status);
        $this->assertSame(Media::STATUS_SECURED, $krok->fresh()->status);
        $this->assertSame(3, ZabezpieczenieDowodu::count());
    }

    // ───────────────────────── brak podglądu ─────────────────────────

    public function test_zdjecie_nie_jest_pokazywane_nikomu_takze_wlascicielowi_i_moderatorowi(): void
    {
        $autor = $this->user('autor');
        $moderator = $this->moderator();
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
        $adres = '/zdjecia/'.$zdjecie->getKey().'/large';

        // KONTROLA DODATNIA: zanim cokolwiek zrobimy, moderator i autor to zdjęcie widzą.
        $this->actingAs($autor)->get($adres)->assertRedirect();
        $this->actingAs($moderator)->get($adres)->assertRedirect();

        $this->wykonaj($moderator, 'post', $wpis);

        $this->actingAs($moderator)->get($adres)->assertNotFound();
        // Autor jest już zablokowany (trasa odsyła go do ekranu blokady), więc właściciela
        // sprawdzamy tą samą bramką, którą idzie trasa zdjęcia.
        $this->assertFalse(app(DostepDoZdjecia::class)->moze($autor->fresh(), $zdjecie->fresh()));
        $this->actingAs($this->user('inna'))->get($adres)->assertNotFound();
        auth()->logout();
        $this->get($adres)->assertNotFound();

        $this->assertFalse($zdjecie->fresh()->maWariantDoPokazania('large'));
    }

    public function test_ekran_potwierdzenia_i_wyniku_nie_zawieraja_tresci_ani_zdjecia(): void
    {
        $autor = $this->user('autor');
        $moderator = $this->moderator();
        $wpis = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'SEKRETNA-TRESC-WPISU']);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->actingAs($moderator)
            ->get(route('admin.csam.create', ['typ' => 'post', 'id' => $wpis->getKey()]))
            ->assertOk()
            ->assertSee('CSAM — natychmiast ukryj i zabezpiecz')
            ->assertDontSee('SEKRETNA-TRESC-WPISU')
            ->assertDontSee('/zdjecia/'.$zdjecie->getKey(), false);

        $this->wykonaj($moderator, 'post', $wpis);
        $wpisDowodu = ZabezpieczenieDowodu::where('target_type', 'post')->sole();

        $this->actingAs($moderator)
            ->get(route('admin.csam.wynik', $wpisDowodu))
            ->assertOk()
            ->assertDontSee('SEKRETNA-TRESC-WPISU')
            ->assertDontSee('/zdjecia/'.$zdjecie->getKey(), false);
    }

    // ───────────────────────── instrukcja zgłoszenia ─────────────────────────

    public function test_wynik_pokazuje_instrukcje_z_dokumentow_i_niczego_nie_wysyla(): void
    {
        $moderator = $this->moderator();
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $odpowiedz = $this->wykonaj($moderator, 'post', $wpis);
        $odpowiedz->assertRedirect();

        $this->actingAs($moderator)
            ->get($odpowiedz->headers->get('Location'))
            ->assertOk()
            ->assertSee('Dyżurnet.pl')
            ->assertSee('https://www.dyzurnet.pl/', false)
            ->assertSee('997 albo 112')
            ->assertSee('116 123')
            ->assertSee('Nie kopiuj, nie pobieraj, nie przesyłaj dalej')
            ->assertSee('Bez kopii materiału.')
            ->assertSee('Serwis niczego za Ciebie nie wysłał.')
            ->assertSee('art. 18 DSA');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    // ───────────────────────── retencja ─────────────────────────

    public function test_zabezpieczony_wpis_i_zdjecie_przezywaja_retencje_tresci_i_retencje_spraw(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
        $pliki = $this->pliki($zdjecie);

        $this->wykonaj($this->moderator(), 'post', $wpis);

        // 5 lat później: i okno usuniętych treści (30 dni), i retencja spraw (36 mies.) dawno minęły.
        $this->travel(5)->years();
        (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);
        app(PrzedawnioneUsunieteTresci::class)->posprzataj(30);

        $this->assertDatabaseHas('posts', ['id' => $wpis->getKey()]);
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertExists($pliki);
        // Ślad, kto i dlaczego, też został — razem z decyzją i dziennikiem.
        $this->assertSame(1, ModerationAction::where('target_id', $wpis->getKey())->count());
        $this->assertSame(2, ZabezpieczenieDowodu::count());
    }

    public function test_retencja_tresci_szanuje_rejestr_nawet_gdy_spraw_juz_nie_ma(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $autor->getKey(), 'post_id' => Post::factory()->create()->getKey()]);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->wykonaj($this->moderator(), 'post', $wpis);
        $this->wykonaj($this->moderator(), 'comment', $komentarz);

        // Sprawy, które normalnie chronią usuniętą treść, zniknęły (np. po 36 miesiącach).
        DB::table('appeals')->delete();
        DB::table('moderation_actions')->delete();
        DB::table('reports')->delete();

        $this->travel(5)->years();
        app(PrzedawnioneUsunieteTresci::class)->posprzataj(30);

        $this->assertDatabaseHas('posts', ['id' => $wpis->getKey()]);
        $this->assertDatabaseHas('comments', ['id' => $komentarz->getKey()]);
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey()]);
        Storage::disk('public')->assertExists($this->pliki($zdjecie));
    }

    public function test_retencja_spraw_nie_zabiera_zgloszenia_decyzji_i_odwolania_zabezpieczonego_dowodu(): void
    {
        $autor = $this->user('autor');
        $zglaszajacy = $this->user('zglaszajaca');
        $moderator = $this->moderator();

        // Dwie sprawy: jedna bez odwołania (chronią ją wiersze zgłoszenia i decyzji),
        // druga z odwołaniem (chroni je wiersz odwołania).
        $bezOdwolania = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zOdwolaniem = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zgloszenia = [];

        foreach ([$bezOdwolania, $zOdwolaniem] as $wpis) {
            $zgloszenia[$wpis->getKey()] = Report::create([
                'reporter_id' => $zglaszajacy->getKey(),
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'reason' => 'child_safety',
                'status' => Report::STATUS_OPEN,
            ]);
            $this->wykonaj($moderator, 'post', $wpis, ['zgloszenie' => $zgloszenia[$wpis->getKey()]->getKey()]);
        }

        $decyzja = ModerationAction::where('target_id', $zOdwolaniem->getKey())->where('action', ModerationAction::ACTION_REMOVE)->sole();
        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Nie zgadzam się z decyzją.',
            'status' => Appeal::STATUS_UPHELD,
            'decided_at' => now(),
            'decision_note' => 'Podtrzymane.',
        ]);

        $this->travel(5)->years();
        DB::table('appeals')->update(['decided_at' => now()->subMonths(40)]);
        $raport = (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        // Wszystko, co dotyczy zabezpieczonych wpisów, zostaje.
        $this->assertSame(2, Report::count());
        $this->assertSame(2, ModerationAction::where('action', ModerationAction::ACTION_REMOVE)->count());
        $this->assertNotNull(Appeal::find($odwolanie->getKey()));
        $this->assertSame(0, $raport->usunieteZgloszenia);
        $this->assertSame(0, $raport->usunieteOdwolania);

        // KONTROLA DODATNIA: ta sama retencja, gdy zabezpieczenia nie ma, sprawy zabiera.
        DB::table('zabezpieczenia_dowodow')->delete();
        (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);

        $this->assertSame(0, Report::count());
        $this->assertNull(Appeal::find($odwolanie->getKey()));
    }

    public function test_kontrola_ujemna_bez_zabezpieczenia_ta_sama_retencja_kasuje_wszystko(): void
    {
        // Ten sam scenariusz, ale zabezpieczenie usunięte z rejestru ręcznie —
        // dowodzi, że poprzedni test mierzy rejestr, a nie przypadek.
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->wykonaj($this->moderator(), 'post', $wpis);

        DB::table('zabezpieczenia_dowodow')->delete();
        Media::query()->whereKey($zdjecie->getKey())->update(['status' => Media::STATUS_READY]);

        $this->travel(5)->years();
        (new PrzedawnioneSprawyModeracyjne)->posprzataj(36);
        app(PrzedawnioneUsunieteTresci::class)->posprzataj(30);

        $this->assertDatabaseMissing('posts', ['id' => $wpis->getKey()]);
        $this->assertDatabaseMissing('media', ['id' => $zdjecie->getKey()]);
    }

    public function test_autor_nie_skasuje_zabezpieczonego_zdjecia_a_sprzatanie_osieroconych_go_nie_rusza(): void
    {
        $autor = $this->user('autor');
        $zdjecie = $this->zdjecie($autor);

        $this->wykonaj($this->moderator(), 'media', $zdjecie)->assertSessionHasNoErrors()->assertRedirectContains('/csam/wynik/');

        $this->assertFalse(app(KasujZdjecie::class)->jesliNieuzywane($zdjecie->fresh()));
        $this->assertNull(app(KasujZdjecie::class)->przejmijDoWymazania($zdjecie->fresh()));

        $this->travel(5)->years();
        $this->artisan('kuking:sprzataj-osierocone-zdjecia')->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey(), 'status' => Media::STATUS_SECURED]);
        Storage::disk('public')->assertExists($this->pliki($zdjecie));
    }

    public function test_przepis_zabezpieczony_zachowuje_cala_historie_wersji(): void
    {
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        foreach (range(1, 6) as $numer) {
            $this->wersja($przepis, $numer);
        }
        $inny = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        foreach (range(1, 6) as $numer) {
            $this->wersja($inny, $numer);
        }

        $this->wykonaj($this->moderator(), 'recipe', $przepis);

        // Sprawy (które same chronią przepis na 36 miesięcy) znikają — zostaje
        // wyłącznie rejestr, więc test mierzy rejestr, a nie ślad sprawy.
        DB::table('moderation_actions')->delete();
        DB::table('reports')->delete();

        $this->travel(3)->years();
        (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertSame(6, RecipeVersion::where('recipe_id', $przepis->getKey())->count());
        // Kontrola dodatnia: przepis bez zabezpieczenia traci stare wersje.
        $this->assertSame(3, RecipeVersion::where('recipe_id', $inny->getKey())->count());
    }

    // ───────────────────────── wymazanie konta ─────────────────────────

    public function test_wymazanie_konta_autora_jest_wstrzymane_a_dowod_zostaje(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = $this->zdjecie($autor);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);
        $email = $autor->email;

        // Autor prosi o usunięcie WSZYSTKIEGO, po czym wychodzi karencja.
        $autor->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->travel(31)->days();

        $this->wykonaj($this->moderator(), 'post', $wpis);

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $autor = $autor->fresh();
        $this->assertNull($autor->data_erased_at, 'Konto z zabezpieczonym dowodem nie może zostać wymazane.');
        $this->assertSame($email, $autor->email);
        $this->assertDatabaseHas('posts', ['id' => $wpis->getKey()]);
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey(), 'status' => Media::STATUS_SECURED]);
        Storage::disk('public')->assertExists($this->pliki($zdjecie));
    }

    public function test_kontrola_dodatnia_konto_bez_zabezpieczenia_jest_wymazywane(): void
    {
        $autor = $this->user('autor');
        $autor->markForDeletion();
        $this->travel(31)->days();

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertNotNull($autor->fresh()->data_erased_at);
    }

    // ───────────────────────── blokada konta, powiadomienie ─────────────────────────

    public function test_konto_autora_zostaje_zablokowane_a_powiadomienie_jest_jedno_i_neutralne(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->wykonaj($this->moderator(), 'post', $wpis);

        $this->assertSame(User::STATUS_BANNED, $autor->fresh()->status);

        $powiadomienia = Notification::where('user_id', $autor->getKey())->get();
        $this->assertCount(1, $powiadomienia);
        $this->assertSame(
            'Twoje konto zostało trwale zablokowane z powodu naruszenia prawa.',
            $powiadomienia->first()->data['message'],
        );

        // Dwie decyzje: usunięcie treści i blokada — ta druga z urzędu.
        $this->assertSame(2, ModerationAction::count());
        $this->assertSame(1, ModerationAction::where('action', ModerationAction::ACTION_BAN)->whereNull('report_id')->count());
    }

    public function test_moderator_nie_zablokuje_administratora_ale_tresc_jest_ukryta_i_zabezpieczona(): void
    {
        $admin = $this->admin();
        $wpis = Post::factory()->create(['author_id' => $admin->getKey()]);
        $moderator = $this->moderator();

        $this->wykonaj($moderator, 'post', $wpis);

        $this->assertSoftDeleted($wpis);
        $this->assertSame(1, ZabezpieczenieDowodu::where('target_type', 'post')->count());
        $this->assertNotSame(User::STATUS_BANNED, $admin->fresh()->status);

        $dowod = ZabezpieczenieDowodu::where('target_type', 'post')->sole();
        $this->actingAs($moderator)
            ->get(route('admin.csam.wynik', $dowod))
            ->assertOk()
            ->assertSee('Konto autora NIE jest zablokowane.');
    }

    public function test_wlasnej_tresci_nie_zabezpieczysz(): void
    {
        $moderator = $this->moderator();
        $wpis = Post::factory()->create(['author_id' => $moderator->getKey()]);

        $this->wykonaj($moderator, 'post', $wpis)->assertSessionHasErrors('potwierdzam');

        $this->assertFalse($wpis->fresh()->trashed());
        $this->assertSame(0, ZabezpieczenieDowodu::count());
    }

    // ───────────────────────── zgłoszenie, audyt, potwierdzenie ─────────────────────────

    public function test_ze_zgloszenia_zamyka_je_odpowiada_zglaszajacemu_i_wiaze_decyzje(): void
    {
        $zglaszajacy = $this->user('zglaszajaca');
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zgloszenie = Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'child_safety',
            'status' => Report::STATUS_OPEN,
        ]);
        $moderator = $this->moderator();

        // Przycisk jest w kolejce zgłoszeń.
        $this->actingAs($moderator)
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('CSAM — natychmiast ukryj i zabezpiecz');

        $this->wykonaj($moderator, 'post', $wpis, ['zgloszenie' => $zgloszenie->getKey()])->assertRedirect();

        $zgloszenie->refresh();
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->status);
        $this->assertSame($moderator->getKey(), $zgloszenie->resolved_by);
        $this->assertSame($zgloszenie->getKey(), ModerationAction::where('action', ModerationAction::ACTION_REMOVE)->sole()->report_id);
        $this->assertSame(1, Notification::where('user_id', $zglaszajacy->getKey())->count(), 'Zgłaszający dostaje odpowiedź (DSA art. 16 ust. 5).');
        $this->assertSame($zgloszenie->getKey(), ZabezpieczenieDowodu::where('target_type', 'post')->sole()->report_id);
    }

    public function test_zgloszenie_o_innej_tresci_jest_odrzucone_i_nic_sie_nie_dzieje(): void
    {
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $inny = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zgloszenie = Report::create([
            'reporter_id' => $this->user('zglaszajaca')->getKey(),
            'target_type' => 'post',
            'target_id' => $inny->getKey(),
            'reason' => 'child_safety',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->wykonaj($this->moderator(), 'post', $wpis, ['zgloszenie' => $zgloszenie->getKey()])
            ->assertSessionHasErrors('potwierdzam');

        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->fresh()->status);
        $this->assertFalse($wpis->fresh()->trashed());
        $this->assertSame(0, ZabezpieczenieDowodu::count());
    }

    public function test_dziennik_audytu_dostaje_wpis_z_aktorem_i_szczegolami(): void
    {
        $moderator = $this->moderator();
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);
        $zdjecie = $this->zdjecie($wpis->author);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->wykonaj($moderator, 'post', $wpis);

        $wpisAudytu = AuditLogEntry::where('action', 'moderation.csam_secured')->sole();
        $this->assertSame($moderator->getKey(), $wpisAudytu->actor_id);
        $this->assertSame($wpis->getKey(), $wpisAudytu->metadata['target_id']);
        $this->assertSame(1, $wpisAudytu->metadata['media_secured']);
        $this->assertTrue($wpisAudytu->metadata['account_banned']);
        $this->assertNotNull($wpisAudytu->ip_hash);
    }

    public function test_bez_potwierdzenia_nic_sie_nie_dzieje(): void
    {
        $moderator = $this->moderator();
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $this->actingAs($moderator)
            ->post(route('admin.csam.store', ['typ' => 'post', 'id' => $wpis->getKey()]), ['note' => 'Moja notatka'])
            ->assertSessionHasErrors('potwierdzam');

        $this->assertFalse($wpis->fresh()->trashed());
        $this->assertSame(0, ZabezpieczenieDowodu::count());
        $this->assertSame(0, AuditLogEntry::where('action', 'moderation.csam_secured')->count());
    }

    public function test_drugie_zabezpieczenie_tej_samej_tresci_jest_odrzucone(): void
    {
        $moderator = $this->moderator();
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $this->wykonaj($moderator, 'post', $wpis);
        $this->wykonaj($moderator, 'post', $wpis)->assertSessionHasErrors('potwierdzam');

        $this->assertSame(1, ZabezpieczenieDowodu::where('target_type', 'post')->count());
        $this->assertSame(1, AuditLogEntry::where('action', 'moderation.csam_secured')->count());

        // Ekran potwierdzenia przekierowuje na wynik, zamiast proponować to samo drugi raz.
        $this->actingAs($moderator)
            ->get(route('admin.csam.create', ['typ' => 'post', 'id' => $wpis->getKey()]))
            ->assertRedirect();
    }

    // ───────────────────────── przywracanie ─────────────────────────

    public function test_zabezpieczonej_tresci_nie_przywroci_ani_moderator_ani_admin(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);
        $this->wykonaj($this->moderator(), 'post', $wpis);

        $this->expectException(BladDlaCzlowieka::class);
        $this->expectExceptionMessage('zabezpieczona jako dowód');

        app(RestoreContent::class)->handle($this->admin(), $wpis->fresh() ?? Post::withTrashed()->find($wpis->getKey()), 'autor_poprawil');
    }

    // ───────────────────────── raport przejrzystości ─────────────────────────

    public function test_raport_przejrzystosci_liczy_zabezpieczone_dowody_osobno(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);
        $this->wykonaj($this->moderator(), 'post', $wpis);

        $this->artisan('kuking:raport-przejrzystosci')
            ->expectsOutputToContain('Materiały zabezpieczone jako dowód')
            ->assertSuccessful();
    }

    // ───────────────────────── pomocnicze ─────────────────────────

    private function wykonaj(User $moderator, string $typ, Post|Recipe|Comment|Media $tresc, array $dodatkowe = []): TestResponse
    {
        return $this->actingAs($moderator)->post(
            route('admin.csam.store', ['typ' => $typ, 'id' => $tresc->getKey()]),
            ['potwierdzam' => '1', 'note' => 'Zgłoszone do Dyżurnet.', ...$dodatkowe],
        );
    }

    private function zdjecie(User $wlasciciel): Media
    {
        $zdjecie = Media::factory()->create(['owner_id' => $wlasciciel->getKey()]);

        foreach ($this->pliki($zdjecie) as $klucz) {
            Storage::disk('public')->put($klucz, 'x');
        }

        return $zdjecie;
    }

    /** @return list<string> */
    private function pliki(Media $zdjecie): array
    {
        return [$zdjecie->object_key, ...array_column($zdjecie->metadata['variants'], 'key')];
    }

    private function wersja(Recipe $przepis, int $numer): void
    {
        $wersja = RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $przepis->author_id,
            'version_number' => $numer,
            'change_note' => "Wersja {$numer}",
            'snapshot' => ['title' => $przepis->title, 'summary' => "Opis {$numer}", 'ingredients' => [], 'steps' => []],
        ]);

        DB::table('recipe_versions')->where('id', $wersja->getKey())->update(['created_at' => now()->subYears(3)]);
    }

    private function tresc(string $typ, User $autor): Post|Recipe|Comment
    {
        return match ($typ) {
            'post' => Post::factory()->create(['author_id' => $autor->getKey()]),
            'recipe' => Recipe::factory()->create(['author_id' => $autor->getKey()]),
            'comment' => Comment::factory()->create([
                'author_id' => $autor->getKey(),
                'post_id' => Post::factory()->create()->getKey(),
            ]),
            default => throw new \LogicException('Nieobsłużony wariant w match.'),
        };
    }
}
