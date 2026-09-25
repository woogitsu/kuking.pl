<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Actions;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Zapis przepisu z formularza bez JavaScriptu — ekran dodawania
 * i formularz szczegółów na jednej stronie.
 *
 * Wyjęte z `RecipeController::store()` / `update()` bez zmiany zachowania
 * (issue #970, krok 1). Kolejność jest ta sama, co w kontrolerze: najpierw
 * zdjęcie główne, potem zdjęcie kartki z zeszytu, potem zdjęcia kroków,
 * na końcu `PublishRecipe`. Reguły samego przepisu nadal żyją wyłącznie
 * w `PublishRecipe` — ta akcja tylko zamienia pliki z formularza na
 * identyfikatory `media` i niczego nie rozstrzyga za nią.
 *
 * `BladDlaCzlowieka` ze zdjęcia głównego, skanu albo z `PublishRecipe`
 * wychodzi na zewnątrz bez zmian (kontroler pokazuje go przy tytule).
 * Błąd zdjęcia KROKU zamienia się w `ValidationException` pod kluczem
 * `steps.{numer wiersza}.photo`, żeby trafił przy polu tego kroku.
 */
final class ZapiszPrzepisZFormularza
{
    public function __construct(
        private readonly PublishRecipe $publishRecipe,
        private readonly StoreUploadedImage $storeImage,
    ) {}

    /**
     * Dane to wynik `ZapisPrzepisuRequest::daneZapisu()`.
     *
     * @param  array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>, steps: array<array-key, array<string, mixed>>}  $dane
     * @param  array<array-key, UploadedFile>  $zdjeciaKrokow  pliki pod TYMI SAMYMI kluczami, co `$dane['steps']`
     *
     * @throws BladDlaCzlowieka
     * @throws ValidationException
     */
    public function handle(
        User $author,
        array $dane,
        ?UploadedFile $zdjecieGlowne,
        ?UploadedFile $skan,
        array $zdjeciaKrokow,
        bool $publish,
        ?string $ip,
        ?Recipe $existing = null,
        ?string $kluczWyslania = null,
    ): Recipe {
        $heroMediaId = $existing?->hero_media_id;

        if ($zdjecieGlowne !== null) {
            $heroMediaId = $this->storeImage->handle($author, $zdjecieGlowne)->getKey();
        }

        // Zdjęcia, których ten formularz nie przesłał, MUSZĄ zostać
        // przepisane ręcznie. PublishRecipe zapisuje dokładnie to, co
        // dostanie — pominięcie source_scan_media_id skasowałoby
        // zdjęcie kartki z zeszytu przy pierwszej edycji tytułu.
        $scanMediaId = $existing?->source_scan_media_id;

        if ($skan !== null) {
            $scanMediaId = $this->storeImage->handle($author, $skan)->getKey();
        }

        $attributes = [
            ...$dane['recipe'],
            'hero_media_id' => $heroMediaId,
            'source_scan_media_id' => $scanMediaId,
        ];

        if ($existing !== null) {
            // Pochodzenie przepisu jest od #364 NIEOBOWIĄZKOWE, więc
            // żądanie bez tego pola nie może po cichu przestawić
            // „rodzinny" na „mój własny". Brak pola = bez zmiany.
            $attributes['source_type'] = $dane['recipe']['source_type'] ?? $existing->source_type;
        }

        $steps = $this->zeZdjeciamiKrokow($author, $dane['steps'], $zdjeciaKrokow);

        // Edycja nie przekazuje klucza wysłania — tak było przed #970
        // i akcja domenowa dostaje wtedy swoje domyślne `null`.
        return $existing === null
            ? $this->publishRecipe->handle(
                author: $author,
                attributes: $attributes,
                ingredients: $dane['ingredients'],
                steps: $steps,
                publish: $publish,
                ip: $ip,
                kluczWyslania: $kluczWyslania,
            )
            : $this->publishRecipe->handle(
                author: $author,
                attributes: $attributes,
                ingredients: $dane['ingredients'],
                steps: $steps,
                publish: $publish,
                existing: $existing,
                ip: $ip,
            );
    }

    /**
     * Wgranie zdjęć kroków — po jednym na wiersz, tą samą drogą co każde inne
     * zdjęcie w serwisie.
     *
     * ZDJĘCIE WGRYWAMY TYLKO DLA WIERSZA, KTÓRY MA TREŚĆ. Wiersz bez opisu
     * kroku jest pomijany przy zapisie (`PublishRecipe::cleanSteps`), więc
     * zdjęcie do niego dołączone byłoby wierszem w `media`, do którego nic
     * nie prowadzi — śmieciem w buckecie i w eksporcie RODO tego człowieka.
     *
     * Wchodzi tablica O KLUCZACH Z ŻĄDANIA, wychodzi zwykła lista — od tego
     * miejsca numery wierszy formularza nie są już do niczego potrzebne.
     *
     * @param  array<array-key, array<string, mixed>>  $steps
     * @param  array<array-key, UploadedFile>  $zdjecia
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException gdy zdjęcie odpadnie — komunikat trafia
     *                             PRZY POLE tego kroku, nie nad formularzem
     */
    private function zeZdjeciamiKrokow(User $author, array $steps, array $zdjecia): array
    {
        foreach ($steps as $index => $row) {
            if (! isset($zdjecia[$index])) {
                continue;
            }

            if (trim((string) ($row['instruction'] ?? '')) === '') {
                continue;
            }

            try {
                $steps[$index]['media_id'] = $this->storeImage
                    ->handle($author, $zdjecia[$index])
                    ->getKey();
            } catch (BladDlaCzlowieka $e) {
                throw ValidationException::withMessages([
                    "steps.{$index}.photo" => $e->getMessage(),
                ]);
            }
        }

        return array_values($steps);
    }
}
