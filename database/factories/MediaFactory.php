<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * ZDJĘCIE `ready` MA WARIANTY — I TO NIE JEST OZDOBNIK (issue #430).
     *
     * Do 12 września 2026 stało tu `'variants' => []`, czyli stan, którego
     * w produkcji NIE MA I BYĆ NIE MOŻE: `ProcessUploadedImage` ustawia
     * `ready` w jednym `update()` razem z wariantami, więc gotowe zdjęcie
     * bez wariantów nie powstaje żadną drogą.
     *
     * Fabryka opisywała więc stan nieistniejący, a testy na niej stojące
     * sprawdzały widok, który na produkcji nie wystąpi. Nie było tego widać,
     * dopóki bramką było `isReady()` — status się zgadzał, więc zdjęcie
     * „się pokazywało", tyle że `Media::url()` po cichu oddawał wtedy
     * placeholder `kuking-mark.svg` zamiast czegokolwiek z bucketu.
     *
     * Wymiary wariantów są policzone tak, jak liczy je naprawdę `scaleDown()`
     * dla zdjęcia 1600×1200 z `definition()`, a klucze tak, jak liczy je
     * `Media::kluczPublicznegoWariantu()`. Fabryka, która zmyśla te liczby,
     * jest gorsza niż jej brak: `srcset` w `components/photo.blade.php`
     * buduje się właśnie z nich.
     */
    public function definition(): array
    {
        $objectKey = 'media/test/'.Str::uuid().'.webp';

        return [
            'owner_id' => User::factory(),
            'disk' => 'public',
            'object_key' => $objectKey,
            'mime_type' => 'image/webp',
            'bytes' => 120_000,
            'width' => 1600,
            'height' => 1200,
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => self::warianty($objectKey, [
                'thumb' => [320, 240],
                'podglad' => [640, 480],
                'feed' => [960, 720],
                'large' => [1600, 1200],
            ])],
        ];
    }

    /**
     * Zdjęcie tuż po wgraniu, ZANIM cokolwiek się policzyło.
     *
     * Warianty czyścimy jawnie — `definition()` daje je dziś w komplecie,
     * a `pending` z kompletem wariantów byłby drugim stanem nie z tego
     * świata. To jest stan, w którym widok NIE MA co pokazać i musi
     * powiedzieć to słowami (`components/photo.blade.php`, issue #432).
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => Media::STATUS_PENDING,
            'metadata' => ['variants' => []],
        ]);
    }

    /**
     * Stan z produkcji od #430: zadanie w tle jeszcze nie ruszyło, ale
     * `PodgladOdRazu` zdążył zrobić `podglad` w żądaniu wgrywającym.
     *
     * To jest stan, w którym autorka MA JUŻ ZOBACZYĆ swoje zdjęcie, mimo
     * że status wiersza to dalej `pending`.
     */
    public function zSamymPodgladem(): static
    {
        return $this->state(fn (array $atrybuty) => [
            'status' => Media::STATUS_PENDING,
            'metadata' => ['variants' => self::warianty(
                (string) $atrybuty['object_key'],
                ['podglad' => [640, 480]],
            )],
        ]);
    }

    /**
     * @param  array<string, array{0: int, 1: int}>  $rozmiary
     * @return array<string, array{key: string, width: int, height: int, bytes: int}>
     */
    private static function warianty(string $objectKey, array $rozmiary): array
    {
        $warianty = [];

        foreach ($rozmiary as $nazwa => [$szerokosc, $wysokosc]) {
            $warianty[$nazwa] = [
                'key' => Media::kluczPublicznegoWariantu($objectKey, $nazwa),
                'width' => $szerokosc,
                'height' => $wysokosc,
                // Z grubsza tyle, ile waży naprawdę WebP q82 w tym rozmiarze
                // — patrz pomiary w `App\Domain\Media\PodgladOdRazu`.
                'bytes' => intdiv($szerokosc * $wysokosc, 5),
            ];
        }

        return $warianty;
    }
}
