<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SavePostToCollection;
use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * Issue #1017 — zapisany CUDZY przepis wychodzi z paczki tylko wtedy, gdy
 * właściciel zeszytu nadal ma prawo go przeczytać.
 *
 * Do 24 września `CollectUserExportData::collections()` ładowało `recipes`
 * bez filtra: przepis zmieniony na „Tylko ja", ukryty przez moderację,
 * objęty blokadą albo należący do zbanowanego lub zamykanego konta znikał
 * z ekranu zeszytu, a w `dane.json` dalej stał jego tytuł i podpis autora.
 * Paczka zostaje na dysku na zawsze, więc to było trwałe wyjęcie cudzej
 * treści poza ustawienie widoczności jej autora.
 *
 * Każdy przypadek sprawdza CAŁE archiwum — nazwy i rozpakowaną treść
 * wszystkich plików — a nie tylko `dane.json` (pułapka 4 z
 * `docs/PULAPKI_TESTOW.md`: „czegoś nie ma" w jednym pliku nie dowodzi,
 * że nie ma tego w drugim).
 */
class EksportZeszytuWidocznoscPrzepisowTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Bigos Zenka z suszonymi śliwkami';

    private const NOTATKA = 'Mniej kminku niż u mamy';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config(['kuking.exports.disk' => 'local', 'kuking.exports.ttl_days' => 7]);
    }

    /**
     * Kontrola DODATNIA: publiczny cudzy przepis wychodzi z tytułem,
     * autorem i notatką, a licznik braków istnieje i wynosi zero.
     */
    public function test_publiczny_zapisany_przepis_trafia_do_paczki(): void
    {
        [$basia, $zenek] = $this->basiaZapisujePrzepisZenka();

        $export = $this->eksport($basia);
        $zeszyt = $this->dane($export)['kolekcje'][0];

        $this->assertSame([self::TYTUL], array_column($zeszyt['przepisy'], 'tytul'));
        $this->assertSame('Zenek Kowal', $zeszyt['przepisy'][0]['autor']);
        $this->assertSame(self::NOTATKA, $zeszyt['przepisy'][0]['moja_notatka']);
        $this->assertArrayHasKey('przepisow_juz_niewidocznych', $zeszyt);
        $this->assertSame(0, $zeszyt['przepisow_juz_niewidocznych']);
        $this->assertStringContainsString(self::TYTUL, $this->caleArchiwum($export));

        // Zdanie o „tytule i autorze" stoi, bo jest czego dotyczyć; zdania
        // o brakach nie ma, bo braków nie ma.
        $index = $this->plik($export, 'index.html');
        $this->assertStringContainsString('Cudze przepisy zapisane w Twoim zeszycie', $index);
        $this->assertStringNotContainsString('już Ci nie pokazują', $index);
    }

    public function test_wlasny_prywatny_przepis_zostaje_w_paczce_wlasciciela(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $wlasny = Recipe::factory()->for($basia, 'author')->create([
            'title' => 'Moja tajna nalewka',
            'visibility' => 'private',
        ]);
        app(SaveRecipeToCollection::class)->handle($basia, $wlasny, $basia->defaultCollection());

        $zeszyt = $this->dane($this->eksport($basia))['kolekcje'][0];

        $this->assertSame(['Moja tajna nalewka'], array_column($zeszyt['przepisy'], 'tytul'));
        $this->assertSame(0, $zeszyt['przepisow_juz_niewidocznych']);
    }

    /**
     * Regresja z przeglądu #1017: `dostepnyJakoAutor()` obejmował też
     * właścicielkę zeszytu. W karencji (`pending_delete`) paczkę wolno
     * zamówić i pobrać (`GenerateUserExport::revoked()`), a wtedy jej
     * WŁASNY przepis i wpis z notatkami wypadały z zeszytu, a index.html
     * twierdził, że „autorzy już Ci nie pokazują: 2".
     */
    public function test_wlascicielka_w_karencji_zachowuje_wlasne_pozycje_zeszytu(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Moja nalewka z pigwy']);
        $wpis = Post::factory()->create(['author_id' => $basia->getKey(), 'body' => 'Mój rosół na niedzielę']);
        $zeszyt = $basia->defaultCollection();
        app(SaveRecipeToCollection::class)->handle($basia, $przepis, $zeszyt, self::NOTATKA);
        app(SavePostToCollection::class)->handle($basia, $wpis, $zeszyt, 'Dla wnuków');

        $basia->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        $export = $this->eksport($basia);
        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $dane = $this->dane($export)['kolekcje'][0];

        $this->assertSame(['Moja nalewka z pigwy'], array_column($dane['przepisy'], 'tytul'));
        $this->assertSame(self::NOTATKA, $dane['przepisy'][0]['moja_notatka']);
        $this->assertSame(0, $dane['przepisow_juz_niewidocznych']);

        $this->assertSame(['Mój rosół na niedzielę'], array_column($dane['wpisy'], 'tresc'));
        $this->assertSame('Dla wnuków', $dane['wpisy'][0]['moja_notatka']);
        $this->assertSame(0, $dane['wpisow_juz_niewidocznych']);

        $this->assertStringNotContainsString('już Ci nie pokazują', $this->plik($export, 'index.html'));
    }

    /**
     * Wpis skasowany przez autora liczy się jako brak — tak jak na ekranie
     * zeszytu (`posts_total_count` z `withTrashed()`), a nie znika bez śladu.
     */
    public function test_skasowany_cudzy_wpis_wychodzi_jako_brak(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek Kowal']);
        $wpis = Post::factory()->create(['author_id' => $zenek->getKey()]);
        app(SavePostToCollection::class)->handle($basia, $wpis, $basia->defaultCollection());

        $wpis->delete();

        $dane = $this->dane($this->eksport($basia))['kolekcje'][0];

        $this->assertSame([], $dane['wpisy']);
        $this->assertSame(1, $dane['wpisow_juz_niewidocznych']);
    }

    /**
     * @return array<string, array{Closure(User, User, Recipe): void}>
     */
    public static function granice(): array
    {
        return [
            'przepis prywatny' => [fn (User $b, User $z, Recipe $r) => $r->forceFill(['visibility' => 'private'])->save()],
            'przepis dla obserwujących, Basia nie obserwuje' => [fn (User $b, User $z, Recipe $r) => $r->forceFill(['visibility' => 'followers'])->save()],
            'ukryty przez moderację' => [fn (User $b, User $z, Recipe $r) => $r->forceFill(['status' => Recipe::STATUS_HIDDEN])->save()],
            'usunięty przez moderację' => [fn (User $b, User $z, Recipe $r) => $r->forceFill(['status' => Recipe::STATUS_REMOVED])->save()],
            'skasowany przez autora' => [fn (User $b, User $z, Recipe $r) => $r->delete()],
            'autor zbanowany' => [fn (User $b, User $z, Recipe $r) => $z->forceFill(['status' => User::STATUS_BANNED])->save()],
            'autor zamyka konto' => [fn (User $b, User $z, Recipe $r) => $z->forceFill(['status' => User::STATUS_PENDING_DELETE])->save()],
            'Basia zablokowała autora' => [fn (User $b, User $z, Recipe $r) => $b->blocking()->attach($z->getKey(), ['created_at' => now()])],
            'autor zablokował Basię' => [fn (User $b, User $z, Recipe $r) => $z->blocking()->attach($b->getKey(), ['created_at' => now()])],
        ];
    }

    /**
     * @param  Closure(User, User, Recipe): void  $granica
     */
    #[DataProvider('granice')]
    public function test_niewidoczny_cudzy_przepis_wychodzi_tylko_jako_liczba(Closure $granica): void
    {
        [$basia, $zenek, $przepis] = $this->basiaZapisujePrzepisZenka();
        $slug = $przepis->slug;

        $granica($basia, $zenek, $przepis);

        $export = $this->eksport($basia);
        $zeszyt = $this->dane($export)['kolekcje'][0];

        $this->assertSame([], $zeszyt['przepisy']);
        $this->assertSame(1, $zeszyt['przepisow_juz_niewidocznych']);

        // Podpis autora sprawdzamy w zeszytach, nie w całym archiwum: przy
        // blokadzie nazwa Zenka stoi legalnie w `zablokowane_osoby` — to
        // lista Basi, jej własne dane.
        $this->assertStringNotContainsString('Zenek Kowal', json_encode($zeszyt, JSON_UNESCAPED_UNICODE));

        $cale = $this->caleArchiwum($export);
        foreach ([self::TYTUL, $slug, self::NOTATKA] as $zakazane) {
            $this->assertStringNotContainsString(
                $zakazane,
                $cale,
                "Paczka wyjęła „{$zakazane}\" poza granicę widoczności, którą ekran zeszytu trzyma.",
            );
        }

        // Czytelny spis treści mówi o braku liczbą i nie obiecuje „tytułu
        // i autora" cudzych przepisów, których w paczce nie ma.
        $index = $this->plik($export, 'index.html');
        $this->assertStringContainsString('już Ci nie pokazują: 1.', $index);
        $this->assertStringNotContainsString('Cudze przepisy zapisane w Twoim zeszycie', $index);

        // Pozycja NIE znika z zeszytu — wraca, gdy granica ustąpi.
        $this->assertDatabaseHas('collection_items', ['recipe_id' => $przepis->getKey()]);
    }

    public function test_ponowne_udostepnienie_przywraca_przepis_w_nastepnej_paczce(): void
    {
        [$basia, , $przepis] = $this->basiaZapisujePrzepisZenka();

        $przepis->forceFill(['visibility' => 'private'])->save();
        $this->assertSame([], $this->dane($this->eksport($basia))['kolekcje'][0]['przepisy']);

        $przepis->forceFill(['visibility' => 'public'])->save();
        $zeszyt = $this->dane($this->eksport($basia))['kolekcje'][0];

        $this->assertSame([self::TYTUL], array_column($zeszyt['przepisy'], 'tytul'));
        $this->assertSame(self::NOTATKA, $zeszyt['przepisy'][0]['moja_notatka']);
        $this->assertSame(0, $zeszyt['przepisow_juz_niewidocznych']);
    }

    /**
     * Ekran zeszytu i paczka odpowiadają na to samo pytanie tak samo:
     * widoczny przepis jest w obu, ukryty w żadnym.
     */
    public function test_paczka_i_ekran_zeszytu_pokazuja_te_same_przepisy(): void
    {
        [$basia, $zenek, $ukryty] = $this->basiaZapisujePrzepisZenka();
        $widoczny = Recipe::factory()->for($zenek, 'author')->create(['title' => 'Pierogi ruskie Zenka']);
        $zeszyt = $basia->defaultCollection();
        app(SaveRecipeToCollection::class)->handle($basia, $widoczny, $zeszyt);

        $ukryty->forceFill(['visibility' => 'private'])->save();

        $ekran = $this->actingAs($basia)->get(route('collections.show', $zeszyt));
        $ekran->assertOk();
        $ekran->assertSee('Pierogi ruskie Zenka');
        $ekran->assertDontSee(self::TYTUL);

        $wPaczce = array_column($this->dane($this->eksport($basia))['kolekcje'][0]['przepisy'], 'tytul');
        $this->assertSame(['Pierogi ruskie Zenka'], $wPaczce);
    }

    /**
     * @return array{User, User, Recipe}
     */
    private function basiaZapisujePrzepisZenka(): array
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek Kowal']);
        $przepis = Recipe::factory()->for($zenek, 'author')->create(['title' => self::TYTUL]);

        app(SaveRecipeToCollection::class)->handle($basia, $przepis, $basia->defaultCollection(), self::NOTATKA);

        return [$basia, $zenek, $przepis];
    }

    private function eksport(User $user): DataExport
    {
        $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return $export->refresh();
    }

    /** @return array<string, mixed> */
    private function dane(DataExport $export): array
    {
        return json_decode($this->plik($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function plik(DataExport $export, string $nazwa): string
    {
        $zip = $this->otworz($export);
        $tresc = $zip->getFromName($nazwa);
        $zip->close();

        $this->assertIsString($tresc, "W archiwum nie ma pliku {$nazwa}.");

        return $tresc;
    }

    /** Nazwy i ROZPAKOWANA treść wszystkich plików — ZIP trzyma je skompresowane. */
    private function caleArchiwum(DataExport $export): string
    {
        $zip = $this->otworz($export);
        $czesci = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $czesci[] = (string) $zip->getNameIndex($i);
            $czesci[] = (string) $zip->getFromIndex($i);
        }

        $zip->close();

        $this->assertGreaterThan(2, count($czesci), 'Archiwum jest puste — test nie mierzy niczego.');

        return implode("\n", $czesci);
    }

    private function otworz(DataExport $export): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue(
            $zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true,
            'Nie udało się otworzyć paczki ZIP.',
        );

        return $zip;
    }
}
