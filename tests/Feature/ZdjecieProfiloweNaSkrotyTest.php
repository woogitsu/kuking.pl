<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\JpegZeWspolrzednymiGps;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * ZDJĘCIE PROFILOWE NA SKRÓTY — prośba właściciela.
 *
 * CO BYŁO NIE TAK (funkcja istniała, droga do niej nie)
 * Pole `name="avatar"` stało jako SZÓSTE pole formularza `/ustawienia/profil`,
 * pod imieniem, nazwą użytkownika, opisem, regionem i specjalnością. Żeby
 * wstawić swoje zdjęcie, trzeba było: otworzyć menu → „Ustawienia" (które
 * otwierają się na „Czytelności") → „Profil" → przewinąć pod pięć pól,
 * których nikt nie zamierzał ruszać → dopiero tam trafić w obszar wyboru
 * pliku. Na telefonie dochodziło do tego, że „Ustawienia" w ogóle nie ma
 * w pasku dolnym — jedyną drogą był własny profil i przycisk „Zmień swój
 * profil".
 *
 * Awatar na własnym profilu — czyli miejsce, w które człowiek klika
 * instynktownie — nie robił NIC.
 *
 * CO ROBI TA ZMIANA
 *  1. własny awatar na `/@ja` jest odnośnikiem wprost do ustawienia zdjęcia,
 *     z widocznym podpisem („Dodaj zdjęcie profilowe" / „Zmień…"), a nie
 *     samą klikalną ikoną;
 *  2. odnośnik prowadzi na osobny, krótki ekran `/ustawienia/zdjecie`,
 *     na którym nie ma nic poza zdjęciem — kotwica `#f-avatar` w starym
 *     formularzu wyrzucałaby na telefonie w środek ekranu pełnego innych pól;
 *  3. z tego samego ekranu da się zdjęcie USUNĄĆ, czego wcześniej nie dało
 *     się zrobić w ogóle — dało się tylko podmienić.
 *
 * Pole zostało PRZENIESIONE, nie skopiowane: `/ustawienia/profil` nie
 * przyjmuje już pliku. Drugi mechanizm wgrywania byłby drugą okazją do
 * rozjazdu, a zapis, przetwarzanie i zdejmowanie EXIF-u miały zostać takie,
 * jakie są.
 */
class ZdjecieProfiloweNaSkrotyTest extends TestCase
{
    use JpegZeWspolrzednymiGps;
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    /** Prawdziwy JPEG, nie `UploadedFile::fake()->image()` — potok czyta magic bytes. */
    private function zdjecie(string $nazwa = 'ja.jpg'): UploadedFile
    {
        $obraz = imagecreatetruecolor(120, 90);
        imagefilledrectangle($obraz, 0, 0, 119, 89, (int) imagecolorallocate($obraz, 200, 120, 60));

        $sciezka = tempnam(sys_get_temp_dir(), 'awatar').'.jpg';
        imagejpeg($obraz, $sciezka, 85);
        imagedestroy($obraz);

        return new UploadedFile($sciezka, $nazwa, null, null, true);
    }

    /** Gotowy awatar z wariantami leżącymi na dysku — jak po przetworzeniu. */
    private function gotoweZdjecieNaProfilu(User $user): Media
    {
        $media = Media::factory()->create([
            'owner_id' => $user->getKey(),
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'incoming/'.$user->getKey().'/oryginal.jpg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/'.$user->getKey().'/thumb.webp'],
                'feed' => ['key' => 'media/'.$user->getKey().'/feed.webp'],
            ]],
        ]);

        Storage::disk('public')->put($media->object_key, 'oryginal');

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('public')->put($wariant['key'], 'wariant');
        }

        $user->profile->update(['avatar_media_id' => $media->getKey()]);

        return $media;
    }

    // -----------------------------------------------------------------
    //  1. Krótka droga naprawdę ustawia zdjęcie
    // -----------------------------------------------------------------

    /**
     * ASERCJA JEST NA STANIE, NIE NA KODZIE ODPOWIEDZI.
     *
     * „302 i brak błędów" przechodziłoby także wtedy, gdyby kontroler
     * przyjął plik i nie przypiął go do niczego.
     */
    public function test_zdjecie_ustawia_sie_krotka_droga_i_laduje_na_profilu(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('settings.avatar'))
            ->post(route('settings.avatar.update'), ['avatar' => $this->zdjecie()])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.avatar'));

        $profil = $basia->fresh()->profile;

        $this->assertNotNull($profil->avatar_media_id, 'Zdjęcie nie trafiło na profil.');
        $this->assertSame($basia->getKey(), $profil->avatar->owner_id);
        $this->assertDatabaseCount('media', 1);
    }

    public function test_ze_swojego_profilu_do_ekranu_zdjecia_jest_jedno_klikniecie(): void
    {
        $basia = $this->user('basia');

        $html = (string) $this->actingAs($basia)
            ->get(route('profile.show', 'basia'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('settings.avatar').'"',
            $html,
            'Z własnego profilu nie da się przejść wprost do ustawienia zdjęcia.',
        );
    }

    // -----------------------------------------------------------------
    //  2. Zachęta: widać ją dokładnie wtedy, kiedy trzeba
    // -----------------------------------------------------------------

    public function test_osoba_bez_zdjecia_widzi_zachete_a_osoba_ze_zdjeciem_jej_nie_widzi(): void
    {
        $basia = $this->user('basia');

        $bezZdjecia = (string) $this->actingAs($basia)
            ->get(route('profile.show', 'basia'))->getContent();

        // ZACHĘTA PRZY AWATARZE, czyli w treści ekranu — a nie gdziekolwiek
        // w dokumencie (pułapka 1). Ten sam napis niesie skrót w prawej
        // szynie, więc asercja na całej odpowiedzi przechodziła też po
        // skasowaniu podpisu pod awatarem, o który chodzi w tym pliku.
        $this->assertStringContainsString(
            'Dodaj zdjęcie profilowe',
            $this->trescEkranu($bezZdjecia),
        );

        // Asercje „czegoś nie ma" zostają na CAŁYM dokumencie: szerzej znaczy
        // tu ostrożniej, bo zachęta nie ma prawa wyjść RÓWNIEŻ w szynie.
        $this->assertStringNotContainsString('Zmień zdjęcie profilowe', $bezZdjecia);

        $this->gotoweZdjecieNaProfilu($basia);

        $zeZdjeciem = (string) $this->actingAs($basia)
            ->get(route('profile.show', 'basia'))->getContent();

        $this->assertStringNotContainsString(
            'Dodaj zdjęcie profilowe',
            $zeZdjeciem,
            'Zachęta „dodaj zdjęcie" stoi przy człowieku, który zdjęcie już ma.',
        );
        $this->assertStringContainsString('Zmień zdjęcie profilowe', $zeZdjeciem);
    }

    public function test_na_cudzym_profilu_nie_ma_ani_zachety_ani_odnosnika(): void
    {
        $this->user('basia');
        $adam = $this->user('adam');

        $html = (string) $this->actingAs($adam)
            ->get(route('profile.show', 'basia'))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Dodaj zdjęcie profilowe', $html);
        $this->assertStringNotContainsString(route('settings.avatar'), $html);
    }

    // -----------------------------------------------------------------
    //  3. Usunięcie — czego wcześniej nie dało się zrobić wcale
    // -----------------------------------------------------------------

    public function test_zdjecie_da_sie_usunac_razem_z_plikami(): void
    {
        $basia = $this->user('basia');
        $media = $this->gotoweZdjecieNaProfilu($basia);

        $this->actingAs($basia)
            ->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.avatar'));

        $this->assertNull(
            $basia->fresh()->profile->avatar_media_id,
            'Zdjęcie dalej wisi na profilu.',
        );

        // „Usunięte" ma znaczyć usunięte także na dysku — inaczej plik
        // z czyjąś twarzą otwiera się dalej pod tym samym adresem (issue #93).
        $this->assertNull($media->fresh(), 'Wiersz `media` został.');
        Storage::disk('public')->assertMissing($media->object_key);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('public')->assertMissing($wariant['key']);
        }
    }

    public function test_usuniecie_bez_zdjecia_nie_wywala_sie(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->delete(route('settings.avatar.destroy'))
            ->assertRedirect(route('settings.avatar'));

        $this->assertNull($basia->fresh()->profile->avatar_media_id);
    }

    // -----------------------------------------------------------------
    //  4. Bez JavaScriptu
    // -----------------------------------------------------------------

    /**
     * Zwykły `<form method="POST" enctype="multipart/form-data">` z tokenem
     * CSRF i polem pliku — bez Livewire, bez `wire:model`, bez `onsubmit`.
     *
     * Sam POST wyżej też jest dowodem (żadne żądanie w tym pliku nie udaje
     * XHR-a Livewire'a), ale to jest dowód na SERWER. Ten sprawdza stronę:
     * człowiek z niedociągniętym skryptem ma zobaczyć formularz, który da
     * się wysłać, a nie przycisk, który nic nie robi.
     */
    public function test_ekran_zdjecia_dziala_bez_javascriptu(): void
    {
        $html = (string) $this->actingAs($this->user('basia'))
            ->get(route('settings.avatar'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<form[^>]*method="POST"[^>]*enctype="multipart\/form-data"/',
            $html,
            'Formularz zdjęcia nie jest zwykłym formularzem wysyłającym plik.',
        );
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('type="file" name="avatar"', $html);
        $this->assertStringNotContainsString('wire:model', $html);
        $this->assertStringNotContainsString('onsubmit', $html);
    }

    // -----------------------------------------------------------------
    //  5. Cudzego zdjęcia nie da się zmienić
    // -----------------------------------------------------------------

    /**
     * Trasa nie przyjmuje identyfikatora, więc nie ma czego w adresie
     * podmienić — ale reguła „to jest moje zdjęcie" ma mieszkać w Policy,
     * a nie w kształcie dzisiejszej trasy (AGENTS.md §7).
     */
    public function test_policy_nie_wpuszcza_obcej_osoby_do_cudzego_profilu(): void
    {
        $basia = $this->user('basia');
        $adam = $this->user('adam');

        $profilBasi = Profile::query()->where('user_id', $basia->getKey())->firstOrFail();

        $this->assertTrue(Gate::forUser($basia)->allows('update', $profilBasi));
        $this->assertFalse(
            Gate::forUser($adam)->allows('update', $profilBasi),
            'Obca osoba przechodzi przez Policy zdjęcia profilowego.',
        );
    }

    public function test_cudzego_zdjecia_nie_da_sie_zmienic_ani_usunac(): void
    {
        $basia = $this->user('basia');
        $adam = $this->user('adam');

        $mediaBasi = $this->gotoweZdjecieNaProfilu($basia);

        // Adam wgrywa i kasuje — obie akcje dotyczą WYŁĄCZNIE jego konta.
        $this->actingAs($adam)
            ->post(route('settings.avatar.update'), ['avatar' => $this->zdjecie()])
            ->assertSessionHasNoErrors();

        $this->actingAs($adam)->delete(route('settings.avatar.destroy'));

        $this->assertSame(
            $mediaBasi->getKey(),
            $basia->fresh()->profile->avatar_media_id,
            'Cudze zdjęcie zmieniło się po akcji innej osoby.',
        );
        $this->assertNotNull($mediaBasi->fresh(), 'Cudze zdjęcie zostało skasowane z dysku.');
        Storage::disk('public')->assertExists($mediaBasi->object_key);
    }

    public function test_niezalogowany_nie_wchodzi_i_nie_zapisuje(): void
    {
        $this->get(route('settings.avatar'))->assertRedirect(route('login'));
        $this->post(route('settings.avatar.update'), ['avatar' => $this->zdjecie()])
            ->assertRedirect(route('login'));
        $this->delete(route('settings.avatar.destroy'))->assertRedirect(route('login'));

        $this->assertDatabaseCount('media', 0);
    }

    // -----------------------------------------------------------------
    //  6. Zły plik: komunikat po polsku i NIETKNIĘTA reszta profilu
    // -----------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function zlePliki(): array
    {
        return ['zły typ' => ['typ'], 'za duży' => ['rozmiar'], 'brak pliku' => ['brak']];
    }

    #[DataProvider('zlePliki')]
    public function test_zly_plik_daje_polski_komunikat_i_nie_rusza_reszty_profilu(string $rodzaj): void
    {
        $basia = $this->user('basia');
        $basia->profile->update([
            'display_name' => 'Basia z Podkarpacia',
            'bio' => 'Gotuję od czterdziestu lat.',
            'region' => 'Podkarpacie',
        ]);
        $stareZdjecie = $this->gotoweZdjecieNaProfilu($basia);

        $dane = match ($rodzaj) {
            'typ' => ['avatar' => UploadedFile::fake()->create('lista.pdf', 20, 'application/pdf')],
            'rozmiar' => ['avatar' => UploadedFile::fake()->create(
                'ogromne.jpg',
                (int) (LimityZdjec::maksKilobajtowDoWalidacji() + 1024),
                'image/jpeg',
            )],
            default => [],
        };

        $odpowiedz = $this->actingAs($basia)
            ->from(route('settings.avatar'))
            ->post(route('settings.avatar.update'), $dane);

        $odpowiedz->assertRedirect(route('settings.avatar'))->assertSessionHasErrors('avatar');

        $komunikat = (string) session('errors')->first('avatar');

        $this->assertNotSame('', trim($komunikat));
        $this->assertStringNotContainsString('validation.', $komunikat, 'Komunikat to surowy klucz tłumaczenia.');
        $this->assertDoesNotMatchRegularExpression(
            '/\b(The|field|must|file|image|invalid|greater)\b/i',
            $komunikat,
            "Komunikat po angielsku: {$komunikat}",
        );

        // NAJWAŻNIEJSZA CZĘŚĆ: nieudane zdjęcie nie kasuje niczego innego.
        $profil = $basia->fresh()->profile;

        $this->assertSame('Basia z Podkarpacia', $profil->display_name);
        $this->assertSame('Gotuję od czterdziestu lat.', $profil->bio);
        $this->assertSame('Podkarpacie', $profil->region);
        $this->assertSame(
            $stareZdjecie->getKey(),
            $profil->avatar_media_id,
            'Nieudane wgranie zabrało zdjęcie, które już było.',
        );
    }

    public function test_komunikat_o_za_duzym_pliku_mowi_co_zrobic(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('settings.avatar'))
            ->post(route('settings.avatar.update'), [
                'avatar' => UploadedFile::fake()->create(
                    'ogromne.jpg',
                    (int) (LimityZdjec::maksKilobajtowDoWalidacji() + 1024),
                    'image/jpeg',
                ),
            ])
            ->assertSessionHasErrors('avatar');

        $komunikat = (string) session('errors')->first('avatar');

        $this->assertStringContainsString(
            (string) LimityZdjec::maksMegabajtowDoKomunikatu(),
            $komunikat,
            'Komunikat nie mówi, jaki jest limit.',
        );
        $this->assertStringContainsString(
            'wybierz mniejsze',
            $komunikat,
            'Komunikat mówi, co się stało, ale nie mówi, co zrobić (docs/UX_50_PLUS.md).',
        );
    }

    // -----------------------------------------------------------------
    //  7. Ta sama droga co reszta zdjęć — z EXIF-em włącznie
    // -----------------------------------------------------------------

    /**
     * Że `UsunGps` działa, sprawdza `OryginalTraciWspolrzedneGpsTest`
     * (i `OryginalTraciGpsTakzeWPngIWebpTest` dla pozostałych formatów).
     * Ten test pyta o coś innego i węższego: czy TRASA ZDJĘCIA PROFILOWEGO
     * naprawdę idzie przez ten sam potok, czy tylko tak wygląda.
     *
     * Sprawdzamy trzy ślady, których nie da się podrobić bez wywołania
     * `StoreUploadedImage`:
     *  - oryginał leży pod prefiksem `incoming/`, pod kluczem wymyślonym
     *    przez serwer (nazwa pliku od człowieka nie trafia do ścieżki),
     *  - w zapisanych bajtach NIE MA współrzędnych GPS,
     *  - status to `pending` i poleciało zadanie `ProcessUploadedImage`,
     *    które dopiero przekoduje obraz i zdejmie resztę EXIF-u.
     */
    public function test_zdjecie_profilowe_idzie_tym_samym_potokiem_i_traci_gps(): void
    {
        Queue::fake();

        $basia = $this->user('basia');

        $jpeg = $this->jpegZGps();
        $this->assertPlikTestowyMaGps($jpeg);

        $sciezka = tempnam(sys_get_temp_dir(), 'zgps').'.jpg';
        file_put_contents($sciezka, $jpeg);

        $this->actingAs($basia)
            ->post(route('settings.avatar.update'), [
                'avatar' => new UploadedFile($sciezka, 'kuchnia.jpg', 'image/jpeg', null, true),
            ])
            ->assertSessionHasNoErrors();

        $media = $basia->fresh()->profile->avatar;

        $this->assertNotNull($media);
        $this->assertStringStartsWith('incoming/', (string) $media->object_key);
        $this->assertStringNotContainsString('kuchnia', (string) $media->object_key);
        $this->assertSame(Media::STATUS_PENDING, $media->status);

        $wBuckecie = (string) Storage::disk('public')->get($media->object_key);

        $this->assertArrayNotHasKey(
            'GPSLatitude',
            $this->exif($wBuckecie),
            'Zdjęcie profilowe leży w buckecie ze współrzędnymi kuchni.',
        );

        Queue::assertPushed(ProcessUploadedImage::class);

        @unlink($sciezka);
    }
}
