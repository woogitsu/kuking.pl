<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\JpegZeWspolrzednymiGps;
use Tests\TestCase;

/**
 * Publikacja przez API (D-273): „Co dziś ugotowałeś?", „Ugotowałem",
 * komentarz, obserwuj/przestań — przez te same Akcje co WWW.
 */
class PublikacjaApiTest extends TestCase
{
    use JpegZeWspolrzednymiGps;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.api.wlaczone' => true]);
        Storage::fake('public');
    }

    // --------------------------------------------------------------
    //  „Co dziś ugotowałeś?"
    // --------------------------------------------------------------

    public function test_zdjecie_i_kilka_slow_publikuja_wpis(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->jako($basia)->post('/api/v1/wpisy', [
            'photos' => [UploadedFile::fake()->image('obiad.jpg', 800, 600)],
            'body' => 'Dziś pierogi ruskie.',
            'visibility' => 'public',
        ], ['Accept' => 'application/json']);

        $odpowiedz->assertCreated()
            ->assertJsonPath('data.body', 'Dziś pierogi ruskie.')
            ->assertJsonPath('data.author.username', 'basia');

        $wpis = Post::query()->sole();
        $this->assertSame($basia->getKey(), $wpis->author_id);
        $this->assertSame(1, $wpis->media()->count());
        $this->assertSame($basia->getKey(), $wpis->media()->first()->owner_id);
    }

    public function test_zdjecie_z_gps_trafia_do_magazynu_bez_wspolrzednych(): void
    {
        $basia = $this->user('basia');
        $bajty = $this->jpegZGps();

        // KONTROLA DODATNIA: plik wejściowy naprawdę ma współrzędne.
        $this->assertArrayHasKey('GPSLatitude', $this->exif($bajty));

        $this->jako($basia)->post('/api/v1/wpisy', [
            'photos' => [UploadedFile::fake()->createWithContent('kuchnia.jpg', $bajty)],
            'body' => 'Z mojej kuchni',
            'visibility' => 'public',
        ], ['Accept' => 'application/json'])->assertCreated();

        $zdjecie = Media::query()->sole();
        $dysk = Storage::disk($zdjecie->disk);
        $sprawdzone = 0;

        foreach ($dysk->allFiles() as $klucz) {
            $sprawdzone++;
            $this->assertArrayNotHasKey('GPSLatitude', $this->exif((string) $dysk->get($klucz)),
                "Plik {$klucz} po wysłaniu przez API dalej niesie współrzędne kuchni.");
        }

        $this->assertGreaterThan(0, $sprawdzone, 'Na dysku nie ma żadnego pliku — test niczego nie sprawdził.');
    }

    /**
     * @return array<string, mixed>
     */
    private function exif(string $bajty): array
    {
        $plik = tempnam(sys_get_temp_dir(), 'exif');
        file_put_contents($plik, $bajty);
        $dane = @exif_read_data($plik);
        unlink($plik);

        return is_array($dane) ? $dane : [];
    }

    public function test_plik_ktory_nie_jest_zdjeciem_to_422_po_polsku_i_nic_nie_powstaje(): void
    {
        $basia = $this->user('basia');

        $this->jako($basia)->post('/api/v1/wpisy', [
            'photos' => [UploadedFile::fake()->createWithContent('obiad.jpg', '<?php echo "nie zdjęcie";')],
            'visibility' => 'public',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'bledne_dane')
            ->assertJsonStructure(['errors' => ['photos.0']]);

        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, Media::query()->count());
    }

    public function test_brak_widocznosci_to_422_z_komunikatem_z_www(): void
    {
        $this->jako($this->user('basia'))->postJson('/api/v1/wpisy', ['body' => 'Coś'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.visibility.0', 'Zaznacz, kto ma widzieć ten wpis.');
    }

    public function test_ten_sam_klucz_idempotencji_nie_tworzy_drugiego_wpisu(): void
    {
        config(['kuking.formularze.klucz_wyslania_wlaczony' => true]);
        $basia = $this->user('basia');
        $klucz = (string) Str::uuid7();

        $tresc = ['body' => 'Ponawiam przy słabym zasięgu', 'visibility' => 'public'];

        $this->jako($basia)->postJson('/api/v1/wpisy', $tresc, ['Idempotency-Key' => $klucz])->assertCreated();
        $this->jako($basia)->postJson('/api/v1/wpisy', $tresc, ['Idempotency-Key' => $klucz])->assertOk();

        $this->assertSame(1, Post::query()->count());
    }

    public function test_zawieszone_konto_nie_publikuje(): void
    {
        $basia = $this->user('basia');
        $token = $basia->createToken('Telefon')->plainTextToken;
        $basia->forceFill(['status' => User::STATUS_SUSPENDED, 'status_expires_at' => now()->addDay()])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/wpisy', ['body' => 'Próba', 'visibility' => 'public'])
            ->assertForbidden()
            ->assertJsonPath('code', 'konto_zawieszone');

        $this->assertSame(0, Post::query()->count());
    }

    public function test_bez_tokenu_to_401(): void
    {
        $this->postJson('/api/v1/wpisy', ['body' => 'x', 'visibility' => 'public'])->assertUnauthorized();
    }

    // --------------------------------------------------------------
    //  „Ugotowałem"
    // --------------------------------------------------------------

    public function test_ugotowalem_zapisuje_wykonanie_i_powiadamia_autora(): void
    {
        $autorka = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey()]);

        $this->jako($kucharz)->postJson('/api/v1/przepisy/'.$przepis->getKey().'/ugotowalem', [
            'note' => 'Wyszło świetnie, dałam więcej koperku.',
            'would_make_again' => true,
        ])->assertCreated()->assertJsonPath('data.recipe_id', $przepis->getKey());

        $this->assertSame(1, CookedEvent::query()->where('user_id', $kucharz->getKey())->count());
        $this->assertSame(1, $this->powiadomieniaOUgotowaniu($autorka));
    }

    public function test_ugotowanie_wlasnego_przepisu_nie_powiadamia(): void
    {
        $autorka = $this->user('autorka');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey()]);

        $this->jako($autorka)->postJson('/api/v1/przepisy/'.$przepis->getKey().'/ugotowalem', [])->assertCreated();

        $this->assertSame(0, $this->powiadomieniaOUgotowaniu($autorka));
    }

    public function test_blokada_w_obie_strony_nie_dopuszcza_wykonania(): void
    {
        $autorka = $this->user('autorka');
        $zablokowana = $this->user('zablokowana');
        $blokujaca = $this->user('blokujaca');
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey()]);

        app(BlockUser::class)->handle($autorka, $zablokowana);
        app(BlockUser::class)->handle($blokujaca, $autorka);

        foreach ([$zablokowana, $blokujaca] as $kto) {
            $status = $this->jako($kto)->postJson('/api/v1/przepisy/'.$przepis->getKey().'/ugotowalem', [])->getStatusCode();
            $this->assertContains($status, [403, 404]);
        }

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, $this->powiadomieniaOUgotowaniu($autorka));
    }

    public function test_wymazane_konto_autora_nie_dostaje_powiadomienia_a_wykonanie_zostaje(): void
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $autor->markDataErased();

        $this->jako($kucharz)->postJson('/api/v1/przepisy/'.$przepis->getKey().'/ugotowalem', [])->assertCreated();

        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(0, $this->powiadomieniaOUgotowaniu($autor));
    }

    public function test_ugotowalem_prywatnego_cudzego_przepisu_to_odmowa(): void
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->user('autorka')->getKey(), 'visibility' => 'private']);

        $status = $this->jako($this->user('obca'))
            ->postJson('/api/v1/przepisy/'.$przepis->getKey().'/ugotowalem', [])
            ->getStatusCode();

        $this->assertContains($status, [403, 404]);
        $this->assertSame(0, CookedEvent::query()->count());
    }

    // --------------------------------------------------------------
    //  Komentarz
    // --------------------------------------------------------------

    public function test_komentarz_pod_wpisem_i_przepisem(): void
    {
        $autorka = $this->user('autorka');
        $obca = $this->user('obca');
        $wpis = Post::factory()->create(['author_id' => $autorka->getKey()]);
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey()]);

        $this->jako($obca)->postJson('/api/v1/wpisy/'.$wpis->getKey().'/komentarze', ['body' => 'Smacznie wygląda!'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Smacznie wygląda!');

        $this->jako($obca)->postJson('/api/v1/przepisy/'.$przepis->getKey().'/komentarze', ['body' => 'Zrobię w niedzielę.'])
            ->assertCreated();

        $this->assertSame(2, Comment::query()->where('author_id', $obca->getKey())->count());
    }

    public function test_komentarz_pod_cudzym_prywatnym_wpisem_to_odmowa(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user('autorka')->getKey(), 'visibility' => Post::VISIBILITY_PRIVATE]);

        $this->jako($this->user('obca'))->postJson('/api/v1/wpisy/'.$wpis->getKey().'/komentarze', ['body' => 'Hej'])
            ->assertForbidden();

        $this->assertSame(0, Comment::query()->count());
    }

    public function test_pusty_komentarz_to_422_po_polsku(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user('autorka')->getKey()]);

        $this->jako($this->user('obca'))->postJson('/api/v1/wpisy/'.$wpis->getKey().'/komentarze', ['body' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('errors.body.0', 'Napisz coś, zanim wyślesz komentarz.');
    }

    // --------------------------------------------------------------
    //  Obserwowanie
    // --------------------------------------------------------------

    public function test_obserwuj_i_przestan(): void
    {
        $basia = $this->user('basia');
        $adam = $this->user('adam');

        $this->jako($basia)->postJson('/api/v1/osoby/'.$adam->getKey().'/obserwuj')
            ->assertCreated()
            ->assertJsonPath('data.following', true);

        $this->assertTrue($basia->fresh()->isFollowing($adam));
        $this->assertSame(1, Notification::query()->where('user_id', $adam->getKey())->where('type', Notification::TYPE_FOLLOW)->count());

        $this->jako($basia)->postJson('/api/v1/osoby/'.$adam->getKey().'/obserwuj')->assertOk();

        $this->jako($basia)->deleteJson('/api/v1/osoby/'.$adam->getKey().'/obserwuj')
            ->assertOk()
            ->assertJsonPath('data.following', false);

        $this->assertFalse($basia->fresh()->isFollowing($adam));
    }

    public function test_nie_da_sie_obserwowac_osoby_z_blokada_ani_siebie(): void
    {
        $basia = $this->user('basia');
        $adam = $this->user('adam');
        app(BlockUser::class)->handle($adam, $basia);

        $this->jako($basia)->postJson('/api/v1/osoby/'.$adam->getKey().'/obserwuj')->assertForbidden();
        $this->jako($basia)->postJson('/api/v1/osoby/'.$basia->getKey().'/obserwuj')->assertForbidden();
        $this->jako($basia)->postJson('/api/v1/osoby/'.Str::uuid7().'/obserwuj')->assertNotFound();

        $this->assertFalse($basia->fresh()->isFollowing($adam));
    }

    // --------------------------------------------------------------
    //  Pomocnicze
    // --------------------------------------------------------------

    private function jako(User $kto): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$kto->createToken('Telefon')->plainTextToken);
    }

    private function powiadomieniaOUgotowaniu(User $autor): int
    {
        return Notification::query()
            ->where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->count();
    }
}
