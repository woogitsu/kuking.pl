<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `<x-recipe-card>` czyta autora (`attributionLine()`, plakietka „konto
 * przykładowe") — lista kart musi go załadować z góry (#1374).
 *
 * Profil ładował tylko `heroMedia`, więc każda karta dociągała autora
 * i jego profil osobnym zapytaniem. Kontrakt danych karty to
 * `Recipe::RELACJE_KARTY`; ten test pilnuje go na profilu, w wyszukiwarce
 * i w zeszycie.
 */
class KartaPrzepisuLadujeAutoraZGoryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $leniweRelacje = [];

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        Model::handleLazyLoadingViolationUsing(null);

        parent::tearDown();
    }

    private function autorZPrzepisami(string $nazwa, int $ile): User
    {
        $autor = $this->user($nazwa);

        for ($i = 0; $i < $ile; $i++) {
            Recipe::factory()->create([
                'author_id' => $autor->getKey(),
                'title' => "Pierogi karta {$nazwa} {$i}",
                'visibility' => 'public',
                'status' => Recipe::STATUS_PUBLISHED,
                'published_at' => now()->subMinutes($i),
            ]);
        }

        return $autor;
    }

    private function zapytaniaOAutora(User $autor): int
    {
        $zapytania = [];
        DB::listen(function ($query) use (&$zapytania): void {
            $zapytania[] = $query->sql;
        });

        $html = $this->get(route('profile.show', [
            'username' => $autor->profile->username,
            'zakladka' => 'przepisy',
        ]))->assertOk()->getContent();

        // Kontrola dodatnia: karty naprawdę się wyrenderowały z podpisem.
        $this->assertStringContainsString("Pierogi karta {$autor->profile->username} 0", $html);
        $this->assertStringContainsString(e($autor->displayName()), $html);

        return count(array_filter(
            $zapytania,
            fn (string $sql): bool => preg_match('/from "(users|profiles)"/', $sql) === 1,
        ));
    }

    public function test_liczba_odczytow_autora_na_profilu_nie_rosnie_z_liczba_kart(): void
    {
        $dwie = $this->zapytaniaOAutora($this->autorZPrzepisami('dwie', 2));
        $dwanascie = $this->zapytaniaOAutora($this->autorZPrzepisami('dwanascie', 12));

        $this->assertSame(
            $dwie,
            $dwanascie,
            "Odczyty users/profiles wzrosły z {$dwie} (2 karty) do {$dwanascie} (12 kart) — karta dociąga autora osobno.",
        );
    }

    public function test_profil_nie_zmienia_kolejnosci_ani_limitu_strony(): void
    {
        $autor = $this->autorZPrzepisami('limit', 13);

        $przepisy = $this->get(route('profile.show', [
            'username' => $autor->profile->username,
            'zakladka' => 'przepisy',
        ]))->assertOk()->viewData('recipes');

        $this->assertCount(12, $przepisy->items());
        $this->assertSame(13, $przepisy->total());
        $this->assertSame('Pierogi karta limit 0', $przepisy->items()[0]->title);
        $this->assertTrue($przepisy->items()[0]->relationLoaded('author'));
    }

    public static function powierzchnie(): array
    {
        return [
            'profil' => ['profil'],
            'wyszukiwarka' => ['wyszukiwarka'],
            'zeszyt' => ['zeszyt'],
        ];
    }

    #[DataProvider('powierzchnie')]
    public function test_karta_nie_dociaga_autora_leniwie(string $powierzchnia): void
    {
        $autor = $this->autorZPrzepisami('leniwy', 3);
        $adres = match ($powierzchnia) {
            'profil' => route('profile.show', ['username' => $autor->profile->username, 'zakladka' => 'przepisy']),
            'wyszukiwarka' => route('search', ['q' => 'pierogi', 'sekcja' => 'przepisy']),
            'zeszyt' => route('collections.show', tap(
                $autor->collections()->create(['name' => 'Zeszyt kart', 'visibility' => 'public']),
                fn ($zeszyt) => $zeszyt->recipes()->attach($autor->recipes()->pluck('id')->all()),
            )),
        };

        // Liczą się tylko relacje czytane przez kartę przepisu; inne
        // fragmenty strony mają własne testy wydajności.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relacja): void {
            if (($model instanceof Recipe && in_array($relacja, ['author', 'heroMedia'], true))
                || ($model instanceof User && $relacja === 'profile')) {
                $this->leniweRelacje[] = $model::class.'::'.$relacja;
            }
            // Bez wyjątku: po powrocie Laravel ładuje relację jak zwykle,
            // więc reszta strony renderuje się bez zmian.
        });

        // Zeszyt wymaga logowania; ta sama osoba ogląda wszystkie trzy listy.
        $html = $this->actingAs($autor)->get($adres)->assertOk()->getContent();

        $this->assertStringContainsString('Pierogi karta leniwy 0', $html, 'Kontrola dodatnia: karta musi się wyrenderować.');
        $this->assertSame([], array_values(array_unique($this->leniweRelacje)), "Karta przepisu na powierzchni „{$powierzchnia}” dociąga relacje leniwie.");
    }
}
