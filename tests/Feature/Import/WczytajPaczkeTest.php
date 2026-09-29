<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Users\Exports\WersjaFormatuPaczki;
use App\Domain\Users\Import\PodgladPaczki;
use App\Domain\Users\Import\PodgladPaczkiEksportu;
use App\Domain\Users\Import\PozycjaPodgladu;
use App\Domain\Users\Import\WczytajPaczke;
use App\Models\AuditLogEntry;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WczytanaZPaczki;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Zapis paczki eksportu (issue #1985, etap 2): prywatność domyślna, własność,
 * idempotencja po odcisku, partie, autoryzacja i rollback migracji.
 *
 * @bez-kontroli-dodatniej Test wykonuje akcję i migrację na prawdziwej bazie; nie asertuje na treści źródła.
 */
class WczytajPaczkeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $pliki = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_SLADY_IMPORTU');

        foreach ($this->pliki as $plik) {
            if (is_file($plik)) {
                unlink($plik);
            }
        }

        parent::tearDown();
    }

    public function test_wczytuje_przepis_wpis_i_zeszyt_jako_prywatne_na_konto_zalogowanej_osoby(): void
    {
        $zenek = $this->user('zenek');
        $podglad = $this->podglad($zenek, $this->paczka());

        $wynik = app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        $this->assertSame(['przepis' => 1, 'wpis' => 2, 'zeszyt' => 1], $wynik->utworzone);
        $this->assertSame(0, $wynik->zostalo);

        $przepis = Recipe::query()->where('author_id', $zenek->getKey())->sole();
        $this->assertSame('Rosół z kury', $przepis->title);
        $this->assertSame('Krótki opis.', $przepis->summary);
        $this->assertSame(Recipe::STATUS_DRAFT, $przepis->status);
        $this->assertSame('private', $przepis->visibility);
        $this->assertSame(['2 jajka', 'Sól'], $przepis->ingredients()->orderBy('position')->pluck('ingredient_text')->all());
        $this->assertSame(['Wymieszaj.', 'Gotuj.'], $przepis->steps()->orderBy('position')->pluck('instruction')->all());

        $dish = Post::query()->where('kind', Post::KIND_DISH)->sole();
        $this->assertSame($zenek->getKey(), $dish->author_id);
        $this->assertSame(Post::VISIBILITY_PRIVATE, $dish->visibility);
        $this->assertSame('Obiad u Basi.', $dish->body);

        $pytanie = Post::query()->where('kind', Post::KIND_QUESTION)->sole();
        $this->assertSame('Czym zastąpić śmietanę w zupie?', $pytanie->title);
        $this->assertSame(Post::VISIBILITY_PRIVATE, $pytanie->visibility);

        $zeszyt = Collection::query()->where('owner_id', $zenek->getKey())->where('name', 'Na święta')->sole();
        $this->assertSame('private', $zeszyt->visibility);
        $this->assertFalse($zeszyt->is_default);

        $this->assertSame(4, WczytanaZPaczki::query()->where('user_id', $zenek->getKey())->count());
    }

    public function test_publiczny_status_i_widocznosc_z_paczki_nie_przechodza_dalej(): void
    {
        $zenek = $this->user('zenek');
        $dane = $this->szkielet();
        $dane['przepisy'][] = $this->przepis('Sernik', ['status' => Recipe::STATUS_PUBLISHED, 'widocznosc' => 'public']);
        $dane['wpisy'][] = $this->wpis(['status' => Post::STATUS_PUBLISHED, 'widocznosc' => Post::VISIBILITY_PUBLIC]);
        $dane['kolekcje'][] = ['nazwa' => 'Publiczny zeszyt', 'opis' => null, 'widocznosc' => 'public', 'domyslna' => false];
        $podglad = $this->podglad($zenek, $dane);

        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        $this->assertSame(Recipe::STATUS_DRAFT, Recipe::query()->sole()->status);
        $this->assertSame('private', Recipe::query()->sole()->visibility);
        $this->assertSame(Post::VISIBILITY_PRIVATE, Post::query()->sole()->visibility);
        $this->assertSame('private', Collection::query()->where('name', 'Publiczny zeszyt')->sole()->visibility);
    }

    public function test_prywatnego_wpisu_i_szkicu_nie_widzi_nikt_poza_autorem(): void
    {
        $zenek = $this->user('zenek');
        $obca = $this->user('obca');
        $podglad = $this->podglad($zenek, $this->paczka());

        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        $wpis = Post::query()->where('kind', Post::KIND_DISH)->sole();
        $przepis = Recipe::query()->sole();

        $this->assertTrue(Gate::forUser($zenek)->allows('view', $wpis));
        $this->assertFalse(Gate::forUser($obca)->allows('view', $wpis));
        $this->assertFalse(Gate::forUser($obca)->allows('view', $przepis));
        $this->assertFalse(Post::query()->publiclyVisible()->exists());
    }

    public function test_to_samo_wczytanie_drugi_raz_niczego_nie_dubluje(): void
    {
        $zenek = $this->user('zenek');
        $sciezka = $this->zip($this->paczka());

        $pierwszy = $this->podglad($zenek, $sciezka);
        app(WczytajPaczke::class)->handle($zenek, $pierwszy, $this->odciski($pierwszy));

        // Ta sama paczka, świeży podgląd: wszystko jest już na koncie.
        $drugi = $this->podglad($zenek, $sciezka);
        $this->assertSame(0, $drugi->liczbyStanow()[PozycjaPodgladu::NOWA]);

        // Nawet stary podgląd (drugie okno, ponowione żądanie) nie utworzy drugiej kopii.
        $wynik = app(WczytajPaczke::class)->handle($zenek, $pierwszy, $this->odciski($pierwszy));

        $this->assertSame(0, $wynik->razem());
        $this->assertSame(4, $wynik->juzByly);
        $this->assertSame(1, Recipe::query()->count());
        $this->assertSame(2, Post::query()->count());
        $this->assertSame(1, Collection::query()->where('name', 'Na święta')->count());
        $this->assertSame(4, WczytanaZPaczki::query()->count());
    }

    public function test_baza_nie_przyjmie_dwa_razy_tego_samego_odcisku_tej_samej_osoby(): void
    {
        $zenek = $this->user('zenek');
        $podglad = $this->podglad($zenek, $this->paczka());
        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        $slad = WczytanaZPaczki::query()->where('rodzaj', 'przepis')->sole();

        $this->expectException(QueryException::class);

        DB::table('wczytane_z_paczki')->insert([
            'user_id' => $zenek->getKey(),
            'rodzaj' => 'przepis',
            'odcisk' => $slad->odcisk,
            'recipe_id' => $slad->recipe_id,
        ]);
    }

    public function test_baza_odrzuca_slad_ktorego_rodzaj_nie_zgadza_sie_ze_wskaznikiem(): void
    {
        $zenek = $this->user('zenek');
        $podglad = $this->podglad($zenek, $this->paczka());
        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        $this->expectException(QueryException::class);

        DB::table('wczytane_z_paczki')->insert([
            'user_id' => $zenek->getKey(),
            'rodzaj' => 'wpis',
            'odcisk' => str_repeat('a', 64),
            'recipe_id' => Recipe::query()->sole()->getKey(),
        ]);
    }

    public function test_pozycji_juz_istniejacych_powtorzonych_i_odrzuconych_nie_tworzy_nawet_z_podsunietym_odciskiem(): void
    {
        $zenek = $this->user('zenek');
        $this->przepisWlasny($zenek, 'Rosół z kury');

        $dane = $this->szkielet();
        $dane['przepisy'][] = $this->przepis('Rosół z kury');
        $dane['przepisy'][] = $this->przepis('Barszcz');
        $dane['przepisy'][] = $this->przepis('Barszcz');
        $dane['przepisy'][] = $this->przepis('Ukryty', ['status' => Recipe::STATUS_HIDDEN]);
        $podglad = $this->podglad($zenek, $dane);

        $wszystkie = array_map(fn (PozycjaPodgladu $p): string => $p->odcisk, $podglad->wszystkie());
        $wynik = app(WczytajPaczke::class)->handle($zenek, $podglad, [...$wszystkie, str_repeat('0', 64)]);

        $this->assertSame(['przepis' => 1, 'wpis' => 0, 'zeszyt' => 0], $wynik->utworzone);
        $this->assertEqualsCanonicalizing(
            ['Rosół z kury', 'Barszcz'],
            Recipe::query()->where('author_id', $zenek->getKey())->pluck('title')->all(),
        );
    }

    public function test_wczytuje_tylko_to_co_zaznaczono(): void
    {
        $zenek = $this->user('zenek');
        $podglad = $this->podglad($zenek, $this->paczka());

        $wynik = app(WczytajPaczke::class)->handle($zenek, $podglad, [$podglad->zeszyty[0]->odcisk]);

        $this->assertSame(['przepis' => 0, 'wpis' => 0, 'zeszyt' => 1], $wynik->utworzone);
        $this->assertSame(0, Recipe::query()->count());
        $this->assertSame(0, Post::query()->count());
    }

    public function test_wlasnosc_ustala_zalogowana_osoba_a_nie_plik(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $dane = $this->paczka();
        // Ktoś ręcznie dopisał do paczki cudze identyfikatory i dane konta — importer ich nie czyta.
        $dane['konto'] = ['id' => $basia->getKey(), 'email' => $basia->email];
        $dane['przepisy'][0]['id'] = 'cudzy-id';
        $dane['przepisy'][0]['autor_id'] = $basia->getKey();
        $dane['przepisy'][0]['author_id'] = $basia->getKey();
        $dane['wpisy'][0]['author_id'] = $basia->getKey();
        $dane['kolekcje'][0]['owner_id'] = $basia->getKey();
        $podglad = $this->podglad($zenek, $dane);

        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        $this->assertSame(0, Recipe::query()->where('author_id', $basia->getKey())->count());
        $this->assertSame(0, Post::query()->where('author_id', $basia->getKey())->count());
        $this->assertSame(0, Collection::query()->where('owner_id', $basia->getKey())->where('name', 'Na święta')->count());
        $this->assertSame(0, WczytanaZPaczki::query()->where('user_id', $basia->getKey())->count());
        $this->assertSame(1, Recipe::query()->where('author_id', $zenek->getKey())->count());
    }

    public function test_jedno_wczytanie_tworzy_najwyzej_limit_partii_a_reszta_czeka(): void
    {
        config(['kuking.import_paczki.max_naraz' => 2]);
        $zenek = $this->user('zenek');
        $dane = $this->szkielet();

        foreach (['Sernik', 'Makowiec', 'Pierniki'] as $tytul) {
            $dane['przepisy'][] = $this->przepis($tytul);
        }

        $podglad = $this->podglad($zenek, $dane);

        $pierwszy = app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));
        $this->assertSame(2, $pierwszy->razem());
        $this->assertSame(1, $pierwszy->zostalo);

        $drugi = app(WczytajPaczke::class)->handle($zenek, $this->podglad($zenek, $dane), $this->odciski($podglad));
        $this->assertSame(1, $drugi->razem());
        $this->assertSame(0, $drugi->zostalo);
        $this->assertSame(3, Recipe::query()->count());
    }

    public function test_skasowany_miekko_przepis_mozna_wczytac_jeszcze_raz(): void
    {
        $zenek = $this->user('zenek');
        $dane = $this->szkielet();
        $dane['przepisy'][] = $this->przepis('Sernik');

        $podglad = $this->podglad($zenek, $dane);
        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));
        Recipe::query()->sole()->delete();

        $ponownie = $this->podglad($zenek, $dane);
        $this->assertSame(PozycjaPodgladu::NOWA, $ponownie->przepisy[0]->stan);

        $wynik = app(WczytajPaczke::class)->handle($zenek, $ponownie, $this->odciski($ponownie));

        $this->assertSame(1, $wynik->razem());
        $this->assertSame(1, Recipe::query()->count());
        $this->assertSame(1, WczytanaZPaczki::query()->count());
    }

    public function test_konto_zawieszone_nie_wczytuje_niczego(): void
    {
        $zenek = $this->user('zenek');
        $podglad = $this->podglad($zenek, $this->paczka());
        $zenek->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        try {
            app(WczytajPaczke::class)->handle($zenek->refresh(), $podglad, $this->odciski($podglad));
            $this->fail('Zawieszone konto wczytało treści.');
        } catch (AuthorizationException) {
            // oczekiwane
        }

        $this->assertSame(0, Recipe::query()->count());
        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, WczytanaZPaczki::query()->count());
    }

    public function test_pozycja_ktorej_nie_da_sie_utworzyc_nie_zatrzymuje_reszty_i_nie_zostawia_sladu(): void
    {
        $zenek = $this->user('zenek');
        $dane = $this->szkielet();
        $dane['przepisy'][] = $this->przepis('Sernik');
        $dane['kolekcje'][] = ['nazwa' => 'Na święta', 'opis' => null, 'widocznosc' => 'private', 'domyslna' => false];
        $podglad = $this->podglad($zenek, $dane);

        // W międzyczasie człowiek sam założył zeszyt o tej nazwie (indeks nazwy odbija drugi).
        Collection::query()->create(['owner_id' => $zenek->getKey(), 'name' => 'na święta', 'visibility' => 'private', 'is_default' => false]);

        $wynik = app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        $this->assertSame(1, $wynik->utworzone['przepis']);
        $this->assertSame(0, $wynik->utworzone['zeszyt']);
        $this->assertSame(1, $wynik->juzByly);
        $this->assertSame(0, WczytanaZPaczki::query()->where('rodzaj', 'zeszyt')->count());
        $this->assertSame(1, Collection::query()->where('owner_id', $zenek->getKey())->whereRaw('lower(name) = ?', ['na święta'])->count());
    }

    public function test_dziennik_niesie_liczby_a_nie_tresc_paczki(): void
    {
        $zenek = $this->user('zenek');
        $podglad = $this->podglad($zenek, $this->paczka());

        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad), '203.0.113.9');

        $wpis = AuditLogEntry::query()->where('action', 'data.import_completed')->sole();
        $this->assertSame(1, $wpis->metadata['przepisy']);
        $this->assertSame(2, $wpis->metadata['wpisy']);
        $this->assertSame(1, $wpis->metadata['zeszyty']);

        $this->assertStringNotContainsString('Rosół', (string) json_encode($wpis->getAttributes()));
        $this->assertStringNotContainsString('Obiad u Basi', (string) json_encode($wpis->getAttributes()));
        $this->assertStringNotContainsString('203.0.113.9', (string) json_encode($wpis->getAttributes()));
    }

    // -----------------------------------------------------------------
    // Rollback migracji (D-088)
    // -----------------------------------------------------------------

    public function test_cofniecie_migracji_odmawia_gdy_sa_slady_i_przechodzi_na_pustej_tabeli(): void
    {
        $zenek = $this->user('zenek');
        $podglad = $this->podglad($zenek, $this->paczka());
        app(WczytajPaczke::class)->handle($zenek, $podglad, $this->odciski($podglad));

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie przeszło, choć skasowałoby ślady wczytania.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba śladów, które znikną: 4.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_SLADY_IMPORTU=1', $e->getMessage());
        }

        $this->assertSame(4, DB::table('wczytane_z_paczki')->count());

        putenv('KUKING_ROLLBACK_KASUJE_SLADY_IMPORTU=1');
        $this->migracja()->down();
        $this->assertFalse(Schema::hasTable('wczytane_z_paczki'));
        // Same treści zostają.
        $this->assertSame(1, Recipe::query()->count());
    }

    public function test_cofniecie_migracji_przechodzi_bez_pytania_na_pustej_tabeli(): void
    {
        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('wczytane_z_paczki'));
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function migracja(): object
    {
        return require base_path('database/migrations/2026_09_29_140000_create_wczytane_z_paczki_table.php');
    }

    /** @param  string|array<string, mixed>  $paczka */
    private function podglad(User $user, string|array $paczka): PodgladPaczki
    {
        return (new PodgladPaczkiEksportu)->czytaj($user, is_string($paczka) ? $paczka : $this->zip($paczka));
    }

    /** @return list<string> */
    private function odciski(PodgladPaczki $podglad): array
    {
        return array_map(fn (PozycjaPodgladu $p): string => $p->odcisk, $podglad->wszystkie());
    }

    /** @return array<string, mixed> */
    private function szkielet(): array
    {
        return [
            'o_tym_pliku' => ['serwis' => 'Kuking.pl', 'wersja_formatu' => WersjaFormatuPaczki::AKTUALNA, 'wygenerowano' => '2027-03-14T10:00:00+00:00'],
            'przepisy' => [],
            'wpisy' => [],
            'kolekcje' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function paczka(): array
    {
        $dane = $this->szkielet();
        $dane['przepisy'][] = $this->przepis('Rosół z kury', [
            'skladniki' => [
                ['grupa' => null, 'zapis' => '2 jajka', 'uwaga' => null, 'zamienniki' => null],
                ['grupa' => null, 'zapis' => 'Sól', 'uwaga' => null, 'zamienniki' => null],
            ],
            'kroki' => [['numer' => 1, 'opis' => 'Wymieszaj.'], ['numer' => 2, 'opis' => 'Gotuj.']],
        ]);
        $dane['wpisy'][] = $this->wpis(['tresc' => 'Obiad u Basi.']);
        $dane['wpisy'][] = $this->wpis(['rodzaj' => Post::KIND_QUESTION, 'tytul' => 'Czym zastąpić śmietanę w zupie?', 'tresc' => null]);
        $dane['kolekcje'][] = ['nazwa' => 'Na święta', 'opis' => 'Pierniki i sernik.', 'widocznosc' => 'private', 'domyslna' => false];

        return $dane;
    }

    /**
     * @param  array<string, mixed>  $nadpisz
     * @return array<string, mixed>
     */
    private function przepis(string $tytul, array $nadpisz = []): array
    {
        return array_merge([
            'tytul' => $tytul,
            'krotki_opis' => 'Krótki opis.',
            'status' => Recipe::STATUS_DRAFT,
            'widocznosc' => 'private',
            'skladniki' => [['grupa' => null, 'zapis' => '2 jajka', 'uwaga' => null, 'zamienniki' => null]],
            'kroki' => [['numer' => 1, 'opis' => 'Wymieszaj.']],
        ], $nadpisz);
    }

    /**
     * @param  array<string, mixed>  $nadpisz
     * @return array<string, mixed>
     */
    private function wpis(array $nadpisz = []): array
    {
        return array_merge([
            'rodzaj' => Post::KIND_DISH,
            'tytul' => null,
            'tresc' => 'Obiad.',
            'widocznosc' => 'private',
            'status' => Post::STATUS_DRAFT,
        ], $nadpisz);
    }

    private function przepisWlasny(User $autor, string $tytul): Recipe
    {
        return app(PublishRecipe::class)->handle(
            $autor,
            ['title' => $tytul, 'visibility' => 'private', 'source_type' => Recipe::SOURCE_OWN],
            [['text' => '1 cebula']],
            [['instruction' => 'Ugotuj.']],
        );
    }

    /** @param  array<string, mixed>  $dane */
    private function zip(array $dane): string
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-wczytaj-');
        $this->assertIsString($sciezka);
        $this->pliki[] = $sciezka;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true);
        $zip->addFromString('dane.json', json_encode($dane, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($zip->close());

        return $sciezka;
    }
}
