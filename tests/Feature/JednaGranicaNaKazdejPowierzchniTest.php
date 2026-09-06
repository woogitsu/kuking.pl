<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ta sama treść nie może być bardziej widoczna przez inną powierzchnię
 * (audyt W5-08, W5-09).
 *
 * WZORZEC, KTÓRY WRACAŁ W KAŻDEJ FALI AUDYTU
 * Reguła istnieje poprawnie w jednej warstwie, a druga warstwa buduje własne
 * zapytanie i o niej nie wie. Tutaj chodzi o granicę „autor dostępny":
 * `banned` i `pending_delete` odpadają, `suspended` zostaje (kara za pisanie
 * nie kasuje tego, co ktoś już napisał).
 *
 * Reguła żyła WYŁĄCZNIE jako powtórzony `in_array(...)` w trzech politykach.
 * Polityka pilnuje jednak WEJŚCIA NA JEDNĄ TREŚĆ. Listy — zeszyt, mapa strony,
 * profil — budują własne zapytania i nie miały skąd jej wziąć. Skutek:
 * przepis zbanowanego autora dawał 403 przy wejściu wprost, a w cudzym
 * zeszycie stał dalej z tytułem, nazwiskiem i miniaturą; mapa strony podawała
 * jego adres wyszukiwarkom.
 *
 * Teraz jedno źródło: `User::jestDostepnyJakoAutor()` dla obiektu
 * i `scopeDostepnyJakoAutor()` dla zapytania.
 */
class JednaGranicaNaKazdejPowierzchniTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: bool}> */
    public static function statusy(): array
    {
        return [
            'aktywny' => [User::STATUS_ACTIVE, true],
            // Zawieszenie to kara za PISANIE. Treści zostają widoczne —
            // tak mówią polityki i tak ma być wszędzie.
            'zawieszony' => [User::STATUS_SUSPENDED, true],
            'zbanowany' => [User::STATUS_BANNED, false],
            'kasuje konto' => [User::STATUS_PENDING_DELETE, false],
        ];
    }

    #[DataProvider('statusy')]
    public function test_zeszyt_pokazuje_przepis_dokladnie_wtedy_co_wejscie_wprost(
        string $status,
        bool $powinnoByc,
    ): void {
        $autor = $this->user('autor');
        $basia = $this->user('basia');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $zeszyt = Collection::create([
            'owner_id' => $basia->getKey(),
            'name' => 'Na kiedyś',
            'visibility' => 'private',
        ]);

        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
            'created_at' => now(),
        ]);

        $autor->forceFill(['status' => $status])->save();

        // Wejście wprost — granica z polityki.
        $wprost = $this->actingAs($basia)->get(route('recipes.show', $przepis->slug));

        // Zeszyt — ta sama treść, inna powierzchnia.
        $wZeszycie = $this->actingAs($basia)->get(route('collections.show', $zeszyt));

        if ($powinnoByc) {
            $wprost->assertOk();
            $wZeszycie->assertOk()->assertSee($przepis->title, false);
        } else {
            $wprost->assertForbidden();
            $wZeszycie->assertOk()->assertDontSee($przepis->title, false);
        }
    }

    #[DataProvider('statusy')]
    public function test_mapa_strony_oglasza_dokladnie_to_co_da_sie_otworzyc(
        string $status,
        bool $powinnoByc,
    ): void {
        Cache::flush();

        $autor = $this->user('autor');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $wpis = Post::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            'body' => 'Rosół wyszedł złoty.',
        ]);

        $autor->forceFill(['status' => $status])->save();
        Cache::flush();

        $mapa = $this->get(route('sitemap'))->assertOk()->getContent();

        if ($powinnoByc) {
            $this->assertStringContainsString($przepis->slug, (string) $mapa);
            $this->assertStringContainsString((string) $wpis->getKey(), (string) $mapa);
        } else {
            // Mapa zapraszałaby wyszukiwarki pod adres, który zwykłemu
            // człowiekowi daje 403.
            $this->assertStringNotContainsString($przepis->slug, (string) $mapa);
            $this->assertStringNotContainsString((string) $wpis->getKey(), (string) $mapa);
        }
    }

    public function test_regula_ma_jedno_zrodlo_a_nie_kopie_w_politykach(): void
    {
        // Gdyby ktoś wpisał ten warunek z powrotem inline, wróciłby dokładnie
        // ten sam rozjazd: polityka poprawiona, listy nie.
        foreach (glob(app_path('Policies').'/*.php') as $plik) {
            $this->assertStringNotContainsString(
                'STATUS_ACTIVE, User::STATUS_SUSPENDED',
                (string) file_get_contents($plik),
                basename($plik).' wpisuje regułę „autor dostępny" od siebie. '
                .'Użyj User::jestDostepnyJakoAutor() — inaczej listy i polityki znowu się rozjadą.',
            );
        }
    }

    public function test_obie_postaci_reguly_daja_ten_sam_wynik(): void
    {
        // Wersja obiektowa i zapytaniowa MUSZĄ się zgadzać — inaczej granica
        // zależy od tego, którędy się do niej podejdzie.
        foreach (self::statusy() as [$status, $powinnoByc]) {
            $osoba = $this->user(null);
            $osoba->forceFill(['status' => $status])->save();

            $this->assertSame(
                $powinnoByc,
                $osoba->jestDostepnyJakoAutor(),
                "jestDostepnyJakoAutor() dla statusu {$status}",
            );

            $this->assertSame(
                $powinnoByc,
                User::query()->whereKey($osoba->getKey())->dostepnyJakoAutor()->exists(),
                "scopeDostepnyJakoAutor() dla statusu {$status}",
            );
        }
    }
}
