<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reguła indeksowania profilu (#2235, #2236 — to samo zgłoszenie dwa razy)
 * i obraz osoby w JSON-LD (#2231).
 *
 * Profil jest `index` i ma `ProfilePage`, gdy autor jest dostępny
 * (`jestDostepnyJakoAutor()` — konto `erased` z zachowanymi treściami TAK,
 * decyzja właściciela z 30.09, D-333) i ma co najmniej jedną treść
 * widoczną dla gościa: wpis, przepis ALBO wykonanie z zakładki „Ugotowane".
 * Wykonanie liczy się tym samym zakresem co ta zakładka — z przepisu
 * prywatnego albo autora zbanowanego nie odblokowuje niczego.
 */
final class ProfilZSamymiWykonaniamiJestIndeksowanyTest extends TestCase
{
    use RefreshDatabase;

    private const NOINDEX = '<meta name="robots" content="noindex';

    public function test_profil_z_samym_publicznym_wykonaniem_jest_indeksowany_i_w_mapie_strony(): void
    {
        $kucharz = $this->kucharzZWykonaniem(Recipe::factory()->create());

        $html = $this->profilGoscia($kucharz);

        $this->assertStringNotContainsString(self::NOINDEX, $html);
        $this->assertNotNull($this->osoba($html), 'Profil z publicznym wykonaniem ma dostać ProfilePage.');
        $this->assertStringContainsString($this->adresProfilu($kucharz), $this->mapaStrony());
    }

    public function test_pierwsze_wykonanie_odswieza_zapamietana_mape_strony(): void
    {
        $kucharz = $this->user('swiezy_kucharz');
        $przepis = Recipe::factory()->create();
        $this->assertStringNotContainsString($this->adresProfilu($kucharz).'<', $this->mapaStrony());

        CookedEvent::factory()->create(['user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey()]);

        $this->assertStringContainsString($this->adresProfilu($kucharz).'<', $this->mapaStrony());
    }

    public function test_wykonanie_z_przepisu_prywatnego_albo_autora_zbanowanego_nie_odblokowuje_indeksu(): void
    {
        $prywatny = $this->kucharzZWykonaniem(Recipe::factory()->create(['visibility' => 'private']));
        $autorZbanowany = $this->user('zbanowany_autor');
        $autorZbanowany->forceFill(['status' => User::STATUS_BANNED])->save();
        $zbanowanego = $this->kucharzZWykonaniem(Recipe::factory()->create(['author_id' => $autorZbanowany->getKey()]));
        // Kontrola dodatnia w tym samym przebiegu (PULAPKI_TESTOW §4).
        $widoczny = $this->kucharzZWykonaniem(Recipe::factory()->create());

        $mapa = $this->mapaStrony();

        foreach ([$prywatny, $zbanowanego] as $kucharz) {
            $html = $this->profilGoscia($kucharz);
            $this->assertStringContainsString(self::NOINDEX, $html);
            $this->assertNull($this->osoba($html));
            $this->assertStringNotContainsString($this->adresProfilu($kucharz).'<', $mapa);
        }

        $this->assertStringNotContainsString(self::NOINDEX, $this->profilGoscia($widoczny));
        $this->assertStringContainsString($this->adresProfilu($widoczny).'<', $mapa);
    }

    public function test_profil_naprawde_pusty_zostaje_noindex(): void
    {
        $pusty = $this->user('pusta_ola');

        $html = $this->profilGoscia($pusty);

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertNull($this->osoba($html));
    }

    /**
     * Regresja (decyzja właściciela z 30.09, D-333): profil konta usuniętego
     * (`erased`), którego treści zostały (D-022: domyślnie tekst zostaje),
     * ma być DALEJ indeksowany — meta robots, JSON-LD i mapa strony zgodnie.
     * Gałąź #2235/#2236 wycinała go warunkiem `jestWidocznyJakoOsoba()`.
     */
    public function test_konto_usuniete_z_zachowanymi_tresciami_dalej_jest_indeksowane(): void
    {
        $usuniete = $this->kucharzZWykonaniem(Recipe::factory()->create());
        Post::factory()->create(['author_id' => $usuniete->getKey(), 'visibility' => 'public', 'body' => 'Rosół jak u mamy']);
        $this->assertStringNotContainsString(self::NOINDEX, $this->profilGoscia($usuniete), 'Przed usunięciem profil ma treść.');

        $usuniete->markDataErased();
        $this->assertTrue($usuniete->refresh()->isErased());

        $html = $this->profilGoscia($usuniete);
        $this->assertStringNotContainsString(self::NOINDEX, $html, 'Profil konta usuniętego z treściami dostał noindex.');
        $this->assertNotNull($this->osoba($html), 'Profil konta usuniętego z treściami ma dostać ProfilePage.');
        $this->assertStringContainsString($this->adresProfilu($usuniete).'<', $this->mapaStrony(), 'Mapa strony pominęła profil konta usuniętego z treściami.');
    }

    /**
     * Druga strona granicy: konto `erased` BEZ publicznej treści (np. usunięte
     * razem z treściami) zostaje `noindex` i poza mapą, tak jak każdy pusty
     * profil. Konto zbanowane i kasowane dalej nie wchodzi nigdzie.
     */
    public function test_konto_usuniete_bez_tresci_zostaje_poza_indeksem(): void
    {
        $pusteUsuniete = $this->user('usuniete_bez_tresci');
        $pusteUsuniete->markDataErased();
        // Kontrola dodatnia w tym samym przebiegu (PULAPKI_TESTOW §4).
        $zTrescia = $this->kucharzZWykonaniem(Recipe::factory()->create());
        $zTrescia->markDataErased();

        $html = $this->profilGoscia($pusteUsuniete);
        $this->assertStringContainsString(self::NOINDEX, $html);
        $this->assertNull($this->osoba($html));

        $mapa = $this->mapaStrony();
        $this->assertStringNotContainsString($this->adresProfilu($pusteUsuniete).'<', $mapa);
        $this->assertStringContainsString($this->adresProfilu($zTrescia).'<', $mapa);
    }

    public function test_osoba_ma_obraz_tylko_z_gotowym_awatarem(): void
    {
        $zAwatarem = $this->kucharzZWykonaniem(Recipe::factory()->create());
        $gotowy = Media::factory()->create(['owner_id' => $zAwatarem->getKey()]);
        Profile::query()->where('user_id', $zAwatarem->getKey())->update(['avatar_media_id' => $gotowy->getKey()]);

        // Awatar „z samym podglądem" (pending, #430): na stronie się pokazuje
        // i gość go otworzy, ale do danych dla robota — jak do og:image — nie idzie.
        $zNiegotowym = $this->kucharzZWykonaniem(Recipe::factory()->create());
        $niegotowy = Media::factory()->zSamymPodgladem()->create(['owner_id' => $zNiegotowym->getKey()]);
        Profile::query()->where('user_id', $zNiegotowym->getKey())->update(['avatar_media_id' => $niegotowy->getKey()]);

        $bezAwatara = $this->kucharzZWykonaniem(Recipe::factory()->create());

        $html = $this->profilGoscia($zAwatarem);
        $osoba = $this->osoba($html);
        $this->assertIsArray($osoba);
        $this->assertArrayHasKey('image', $osoba);
        $this->assertStringStartsWith('http', $osoba['image']);
        $this->assertStringContainsString((string) $gotowy->getKey(), $osoba['image']);
        // Ten sam plik co karta do udostępniania.
        $this->assertStringContainsString('<meta property="og:image" content="'.e($osoba['image']).'">', $html);

        foreach ([$zNiegotowym, $bezAwatara] as $kucharz) {
            $osoba = $this->osoba($this->profilGoscia($kucharz));
            $this->assertIsArray($osoba, 'Profil ma treść, więc Person jest.');
            $this->assertArrayNotHasKey('image', $osoba);
        }
    }

    private function kucharzZWykonaniem(Recipe $przepis): User
    {
        static $numer = 0;
        $numer++;
        $kucharz = $this->user('kucharz_'.$numer);
        CookedEvent::factory()->create(['user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey()]);

        return $kucharz;
    }

    private function adresProfilu(User $user): string
    {
        return route('profile.show', $user->profile()->value('username'));
    }

    private function profilGoscia(User $user): string
    {
        auth()->logout();

        return (string) $this->get($this->adresProfilu($user))->assertOk()->getContent();
    }

    private function mapaStrony(): string
    {
        auth()->logout();

        return (string) $this->get(route('sitemap'))->assertOk()->getContent();
    }

    /** @return ?array<string, mixed> `mainEntity` z `ProfilePage` albo null */
    private function osoba(string $html): ?array
    {
        preg_match_all('#<script type="application/ld\+json"[^>]*>(.*?)</script>#s', $html, $bloki);

        foreach ($bloki[1] as $blok) {
            $dane = json_decode($blok, true);
            if (is_array($dane) && ($dane['@type'] ?? null) === 'ProfilePage') {
                return $dane['mainEntity'];
            }
        }

        return null;
    }
}
