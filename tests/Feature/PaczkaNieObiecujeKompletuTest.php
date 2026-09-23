<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * OPIS PACZKI NIE OBIECUJE KOMPLETU, KTÓREGO PACZKA NIE NIESIE (#492).
 *
 * `dane.json` mówił o sobie „Wszystkie treści tego konta". To jest obietnica
 * kompletu — a paczka kompletem nie jest: poza nią zostają m.in. wcześniejsze
 * wersje własnych przepisów, zapisywane przy każdej publikacji przez
 * `SnapshotRecipeVersion`. Człowiek, który przed skasowaniem konta czyta
 * „wszystkie", ma prawo sądzić, że niczego mu nie brakuje.
 *
 * TEN PLIK MIERZY DWIE RZECZY NARAZ I TO JEST CAŁY JEGO SENS.
 *
 * Pierwszy test mierzy RZECZYWISTOŚĆ: wersje istnieją i w paczce ich nie ma.
 * Drugi mierzy OBIETNICĘ: opis nie twierdzi, że niesie komplet. Osobno każdy
 * z nich byłby słaby — pierwszy opisywałby brak jako stan pożądany, drugi
 * pilnowałby słowa bez związku z zawartością. Razem trzymają zdanie i paczkę
 * w zgodzie: gdy ktoś kiedyś DOŁOŻY wersje do eksportu, oblewa się test
 * rzeczywistości i każe wrócić do zdania; gdy ktoś wróci do „wszystkich",
 * oblewa się test obietnicy.
 *
 * CZEGO TEN PLIK NIE ROBI: nie poszerza zakresu eksportu i nie jest żądaniem,
 * żeby wersje przepisów do paczki weszły. To jest decyzja o zakresie danych,
 * osobna od tego, co paczka o sobie mówi.
 */
final class PaczkaNieObiecujeKompletuTest extends TestCase
{
    use RefreshDatabase;

    /** Stoi wyłącznie w `recipe_versions`, nigdy w aktualnym przepisie. */
    private const ZDANIE_HISTORYCZNE = 'Zdanie tylko z pierwszej wersji.';

    /** Stoi w aktualnym przepisie — i ma być w paczce. */
    private const ZDANIE_AKTUALNE = 'Zdanie z wersji aktualnej.';

    public function test_wczesniejsze_wersje_wlasnych_przepisow_nie_wchodza_do_paczki(): void
    {
        [$autor, $przepis] = $this->przepisZDwiemaWersjami();

        // Kontrola dodatnia: wersje NAPRAWDĘ powstały. Bez niej asercja
        // „nie ma ich w paczce" przeszłaby także wtedy, gdyby mechanizm wersji
        // w ogóle nie działał (pułapka 4 z docs/PULAPKI_TESTOW.md).
        $this->assertGreaterThanOrEqual(
            2,
            RecipeVersion::where('recipe_id', $przepis->getKey())->count(),
            'Scena nie zbudowała wersji przepisu — pomiar nie dotyczy tego, co miał mierzyć.',
        );

        $json = json_encode($this->paczka($autor), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($json);

        // Kontrola dodatnia drugiego rodzaju: przepis W WERSJI AKTUALNEJ jest
        // w paczce. Bez niej „historii nie ma" przechodziłoby także wtedy,
        // gdyby w paczce nie było w ogóle żadnego przepisu.
        $this->assertStringContainsString(self::ZDANIE_AKTUALNE, $json, 'Paczka nie niesie nawet aktualnej wersji przepisu — pomiar nie dotyczy tego, co miał mierzyć.');

        $this->assertStringNotContainsString(self::ZDANIE_HISTORYCZNE, $json, 'Zdanie z wcześniejszej wersji przepisu wyszło w paczce — zakres eksportu się zmienił, więc zdanie „co_zawiera" wymaga ponownego sprawdzenia.');
        $this->assertStringNotContainsString('version_number', $json, 'Paczka zaczęła nieść numery wersji przepisów — patrz wyżej.');
        $this->assertStringNotContainsString('change_note', $json, 'Paczka zaczęła nieść notatki zmian wersji — patrz wyżej.');
    }

    public function test_opis_paczki_nie_obiecuje_kompletu_danych_konta(): void
    {
        [$autor] = $this->przepisZDwiemaWersjami();

        $opis = $this->paczka($autor)['o_tym_pliku'];

        // Sprawdzamy OBIETNICĘ, nie brzmienie: opis ma dalej mówić, co jest
        // w środku, i nie ma prawa twierdzić, że to komplet.
        $this->assertStringContainsString('wpisy prywatne', $opis['co_zawiera'], 'Opis przestał mówić, że paczka niesie treści prywatne — to jest prawdziwa i ważna informacja.');
        $this->assertStringContainsString('szkice przepisów', $opis['co_zawiera']);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(wszystk\w+|komplet\w*|pełn\w+ kopi\w+)\b/ui',
            $opis['co_zawiera'],
            'Opis paczki znów obiecuje komplet: „'.$opis['co_zawiera'].'". Poza paczką zostają m.in. wcześniejsze wersje własnych przepisów.',
        );

        // Granica dotycząca cudzych treści ma zostać tam, gdzie jest —
        // ten test jej nie dubluje, tylko pilnuje, żeby nie zniknęła przy
        // przeredagowaniu sąsiedniego zdania.
        $this->assertStringContainsString('przepis', mb_strtolower($opis['czego_nie_zawiera']));
    }

    /** @return array{0: User, 1: Recipe} */
    private function przepisZDwiemaWersjami(): array
    {
        $autor = User::factory()->create();
        $publikacja = app(PublishRecipe::class);

        $przepis = $publikacja->handle(
            $autor,
            ['title' => 'Rosół na dwie wersje', 'summary' => self::ZDANIE_HISTORYCZNE],
            [['text' => '1 kura']],
            [['instruction' => 'Zagotuj wodę.']],
            true,
        );

        $publikacja->handle(
            $autor,
            ['title' => 'Rosół na dwie wersje', 'summary' => self::ZDANIE_AKTUALNE],
            [['text' => '1 kura']],
            [['instruction' => 'Zagotuj wodę i posól.']],
            true,
            $przepis,
        );

        return [$autor->fresh(), $przepis->fresh()];
    }

    /** @return array<string, mixed> */
    private function paczka(User $autor): array
    {
        return app(CollectUserExportData::class)->handle(
            $autor,
            new ExportPhotoPlan($autor),
            Carbon::parse('2026-09-18 12:00:00', 'UTC'),
        );
    }
}
