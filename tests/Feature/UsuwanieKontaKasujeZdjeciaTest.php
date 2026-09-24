<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Jobs\PurgePublicMediaCache;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Usunięcie konta kasuje WSZYSTKIE zdjęcia, a nie tylko profilowe
 * (audyt W4-01, decyzja D-018).
 *
 * CO BYŁO NIE TAK
 * Ekran usuwania konta kazał potwierdzić: „Rozumiem, że po 30 dniach moje
 * wpisy, przepisy i zdjęcia zostaną usunięte na stałe". `EraseAccountData`
 * kasował jednak wyłącznie zdjęcie profilowe, a resztę zostawiał przy
 * zanonimizowanym koncie.
 *
 * To nie jest spór o interpretację RODO — to obietnica złożona konkretnym
 * zdaniem, pod którym człowiek musiał postawić haczyk, i niedotrzymana.
 *
 * DLACZEGO ZDJĘCIA INACZEJ NIŻ TEKST
 * Tekst przepisu po anonimizacji podpisu przestaje być danymi osobowymi.
 * Zdjęcie nie: dane są w pikselach — twarz, wnętrze mieszkania, dokument na
 * stole — a w oryginale jeszcze EXIF z datą, modelem telefonu i miejscem.
 * Podmiana podpisu nie zmienia tam niczego.
 *
 * KONTROLA DODATNIA (issue #1386, zmierzona 24.09.2026)
 * Obie mutacje z `tests/mutacje/kasowanie.txt` — wymazanie kasujące sam
 * oryginał zamiast `KasujZdjecie::skasujPliki()` oraz pominięcie pętli po
 * `metadata.variants` — wywracają `test_kasuje_zdjecia_z_wpisow_i_przepisow_nie_tylko_awatar`.
 * Przy starej fixture (wariant pod kluczem oryginału) ten test obie przeżywał.
 */
class UsuwanieKontaKasujeZdjeciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        config(['kuking.media.disk' => 'local', 'kuking.media.public_disk' => 'public']);
    }

    /**
     * Oryginał i wariant to DWA RÓŻNE pliki na DWÓCH dyskach — tak jak na
     * produkcji (`r2` dla oryginałów, `r2_publiczne` dla wariantów).
     *
     * Do issue #1386 wariant wskazywał ten sam klucz na tym samym dysku co
     * oryginał, więc skasowanie samego oryginału „kasowało" też wariant
     * i test nie widział ścieżki wymazania, która pomija warianty. Nie wracaj
     * do wspólnego klucza.
     */
    private function zdjecie(User $wlasciciel, string $nazwa): Media
    {
        $oryginal = "originals/{$wlasciciel->getKey()}/{$nazwa}.jpg";
        $wariant = "variants/{$wlasciciel->getKey()}/{$nazwa}-feed.webp";

        Storage::disk('local')->put($oryginal, 'oryginal z EXIF-em');
        Storage::disk('public')->put($wariant, 'wariant do feedu');

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'local',
            'variants_disk' => 'public',
            'object_key' => $oryginal,
            'metadata' => ['variants' => ['feed' => ['key' => $wariant]]],
        ]);
    }

    private function kluczWariantu(Media $zdjecie): string
    {
        return (string) $zdjecie->metadata['variants']['feed']['key'];
    }

    public function test_kasuje_zdjecia_z_wpisow_i_przepisow_nie_tylko_awatar(): void
    {
        $basia = $this->user('basia');

        $awatar = $this->zdjecie($basia, 'awatar');
        $basia->profile->forceFill(['avatar_media_id' => $awatar->getKey()])->save();

        $doWpisu = $this->zdjecie($basia, 'sernik');
        $doPrzepisu = $this->zdjecie($basia, 'rosol');

        $wpis = Post::factory()->for($basia, 'author')->create();
        $wpis->media()->attach($doWpisu->getKey(), ['position' => 0]);

        Recipe::factory()->for($basia, 'author')->create(['hero_media_id' => $doPrzepisu->getKey()]);

        $zdjecia = [$awatar, $doWpisu, $doPrzepisu];

        // Kontrola dodatnia fixture: oba pliki naprawdę leżą, osobno.
        foreach ($zdjecia as $zdjecie) {
            $this->assertNotSame($zdjecie->object_key, $this->kluczWariantu($zdjecie));
            Storage::disk('local')->assertExists($zdjecie->object_key);
            Storage::disk('public')->assertExists($this->kluczWariantu($zdjecie));
        }

        $basia->markForDeletion();

        $this->assertTrue((new EraseAccountData)->handle($basia->refresh()));

        foreach ($zdjecia as $zdjecie) {
            $this->assertDatabaseMissing('media', ['id' => $zdjecie->getKey()]);
            Storage::disk('local')->assertMissing($zdjecie->object_key);
            // Publiczny wariant to te same piksele co oryginał (#1386).
            Storage::disk('public')->assertMissing($this->kluczWariantu($zdjecie));
        }
    }

    public function test_tekst_zostaje_ale_bez_nazwiska(): void
    {
        // Druga połowa decyzji D-018. Skasowanie tekstu zabrałoby coś ludziom,
        // którzy o nic nie prosili: ktoś odpowiedział w komentarzu, ktoś
        // ugotował z tego przepisu i ma go w zeszycie.
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół babci Zofii']);

        $basia->markForDeletion();
        (new EraseAccountData)->handle($basia->refresh());

        $this->assertDatabaseHas('recipes', [
            'id' => $przepis->getKey(),
            'title' => 'Rosół babci Zofii',
        ]);

        $this->assertSame('Użytkownik usunięty', $basia->refresh()->profile->display_name);
        $this->assertStringNotContainsString('basia', (string) $basia->profile->username);
    }

    public function test_kasowanie_zleca_wyczyszczenie_cache_cdn(): void
    {
        // Skasowanie pliku w buckecie to nie to samo co zniknięcie
        // z internetu — a przy usuwaniu konta to jest cała stawka.
        Queue::fake();

        $basia = $this->user('basia');
        $this->zdjecie($basia, 'sernik');

        $basia->markForDeletion();
        (new EraseAccountData)->handle($basia->refresh());

        Queue::assertPushed(PurgePublicMediaCache::class);
    }

    public function test_cudze_zdjecia_zostaja_nietkniete(): void
    {
        // Kasujemy komplet zdjęć JEDNEJ osoby na jej własne żądanie —
        // i tylko dlatego wolno tu pominąć sprawdzenie „czy ktoś tego jeszcze
        // używa". Gdyby ta pętla sięgnęła po cudze zdjęcie, byłoby to
        // kasowanie treści osoby, która o nic nie prosiła.
        $basia = $this->user('basia');
        $halina = $this->user('halina');

        $mojeZdjecie = $this->zdjecie($basia, 'sernik');
        $cudzeZdjecie = $this->zdjecie($halina, 'placki');

        $basia->markForDeletion();
        (new EraseAccountData)->handle($basia->refresh());

        $this->assertDatabaseMissing('media', ['id' => $mojeZdjecie->getKey()]);
        $this->assertDatabaseHas('media', ['id' => $cudzeZdjecie->getKey()]);
        Storage::disk('local')->assertExists($cudzeZdjecie->object_key);
        Storage::disk('public')->assertExists($this->kluczWariantu($cudzeZdjecie));
    }

    public function test_ekran_obiecuje_dokladnie_to_co_kod_robi(): void
    {
        // Ta para — zdanie na ekranie i zachowanie kodu — rozjechała się raz
        // i nikt tego nie zauważył przez kilkanaście commitów. Test wiąże je
        // ze sobą.
        $widok = (string) file_get_contents(
            resource_path('views/pages/settings/data.blade.php'),
        );

        // Nie obiecujemy usunięcia wpisów i przepisów, bo ich nie usuwamy.
        $this->assertStringNotContainsString(
            'moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe',
            $widok,
            'Ekran nadal obiecuje usunięcie wpisów i przepisów, których kod nie kasuje.',
        );

        // Mówimy wprost o obu stronach.
        $this->assertStringContainsString('Wszystkie Twoje zdjęcia', $widok);
        $this->assertStringContainsString('Użytkownik usunięty', $widok);
    }
}
