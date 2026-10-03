<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Alergeny\OznaczAlergenyPrzepisu;
use App\Domain\Recipes\MojaWersja;
use App\Domain\Recipes\RecipeStatusTransitions;
use App\Domain\Recipes\TrescPrzepisu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\RecipeVersion;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * „Zastosuj jako nową poprawkę” (#2525, V2, D-333 — paczka E): autor przenosi
 * wybrane części treści z DOSTĘPNEJ wcześniejszej wersji własnego, opublikowanego
 * przepisu do bieżącego przepisu — jako NOWĄ wersję.
 *
 * To nie jest cofnięcie czasu. Stara migawka zostaje niezmieniona, dotychczasowe
 * wersje nie są przenumerowywane, a wykonania zachowują swoje odniesienia do wersji.
 * Nie powstaje „Ugotowałem”. Zakres i to, czego nie przywracamy: `PodgladPoprawkiZWersji`.
 *
 * WSZYSTKO W JEDNEJ TRANSAKCJI, POD BLOKADĄ PRZEPISU, NA ŚWIEŻYM STANIE:
 *  - autor (konto aktywne) i przepis odczytane pod blokadą (`users` → `recipes`,
 *    kolejność z `PublishRecipe`), status wciąż dopuszcza edycję i przepis jest
 *    opublikowany;
 *  - `content_revision` z ekranu podglądu musi być równa bieżącej — zmiana
 *    przepisu w drugiej karcie/urządzeniu odrzuca zapis (nic nie jest nadpisane);
 *  - wersja źródłowa musi wciąż istnieć i nie być ukryta;
 *  - sekcje oceniane są od nowa (np. zdjęcia kroków dodane w międzyczasie
 *    blokują przywrócenie kroków);
 *  - awaria na dowolnym kroku cofa całość — treść i nowa migawka są atomowe.
 *
 * Gdy po zastosowaniu treść jest identyczna z ostatnią wersją, nic się nie
 * zapisuje (wynik: zero zmian) — nie mnożymy pustych wersji.
 */
final class ZastosujWersjeJakoPoprawke
{
    public function __construct(
        private readonly SnapshotRecipeVersion $migawki,
        private readonly OznaczAlergenyPrzepisu $alergeny,
    ) {}

    /**
     * @param  list<string>  $sekcje  podzbiór `PodgladPoprawkiZWersji::SEKCJE`
     * @return array{wersja: ?RecipeVersion, sekcje: list<string>}
     *
     * @throws BladDlaCzlowieka
     */
    public function handle(User $autor, Recipe $przepis, int $numer, array $sekcje, int $widzianaRewizja, ?string $ip = null): array
    {
        $sekcje = array_values(array_intersect(PodgladPoprawkiZWersji::SEKCJE, $sekcje));

        if ($sekcje === []) {
            throw new BladDlaCzlowieka('Zaznacz, co z tej wersji chcesz zastosować. Nic nie zostało zmienione.');
        }

        return DB::transaction(function () use ($autor, $przepis, $numer, $sekcje, $widzianaRewizja, $ip): array {
            $swiezyAutor = User::query()->whereKey($autor->getKey())->sharedLock()->first();
            $swiezy = Recipe::query()->whereKey($przepis->getKey())->lockForUpdate()->first();

            if ($swiezyAutor === null || $swiezy === null) {
                throw new BladDlaCzlowieka('Tego przepisu już nie ma. Nic nie zostało zmienione.');
            }

            if ($swiezy->author_id !== $swiezyAutor->getKey() || ! $swiezyAutor->isActive() || ! $swiezy->isPublished()
                || ! RecipeStatusTransitions::authorMayEdit($swiezy->status)) {
                throw new BladDlaCzlowieka('Tego przepisu nie można teraz poprawiać z historii (konto albo przepis jest zamrożony). Nic nie zostało zmienione.');
            }

            if ($swiezy->content_revision !== $widzianaRewizja) {
                throw new BladDlaCzlowieka('Ten przepis zmienił się od otwarcia podglądu (w drugiej karcie albo na innym urządzeniu). Nic nie zostało zmienione — otwórz podgląd jeszcze raz i porównaj.');
            }

            $zrodlo = HistoriaWersji::zapytanie($swiezy, false)->where('version_number', $numer)->first();

            if ($zrodlo === null) {
                throw new BladDlaCzlowieka('Tej wersji już nie ma albo jest ukryta. Nic nie zostało zmienione.');
            }

            // Ocena od nowa, na stanie pod blokadą.
            $podglad = PodgladPoprawkiZWersji::dla($swiezy, $zrodlo);
            foreach ($sekcje as $sekcja) {
                if ($podglad[$sekcja]['dostepna'] !== true) {
                    throw new BladDlaCzlowieka($podglad[$sekcja]['powod'] ?? 'Tej części nie ma czego przywracać — jest taka sama jak dziś. Nic nie zostało zmienione.');
                }
            }

            $migawka = new MigawkaWersji($zrodlo->snapshot ?? []);
            $przedOdcisk = TrescPrzepisu::odcisk((string) $swiezy->getKey());

            if (in_array(PodgladPoprawkiZWersji::SEKCJA_DANE, $sekcje, true)) {
                $this->zastosujDane($swiezy, $migawka);
            }
            if (in_array(PodgladPoprawkiZWersji::SEKCJA_SKLADNIKI, $sekcje, true)) {
                $this->zastosujSkladniki($swiezy, $migawka);
            }
            if (in_array(PodgladPoprawkiZWersji::SEKCJA_KROKI, $sekcje, true)) {
                $this->zastosujKroki($swiezy, $migawka);
            }

            if ($przedOdcisk == TrescPrzepisu::odcisk((string) $swiezy->getKey())) {
                return ['wersja' => null, 'sekcje' => []];
            }

            if (in_array(PodgladPoprawkiZWersji::SEKCJA_SKLADNIKI, $sekcje, true)) {
                $this->alergeny->uniewaznPoZmianieSkladnikow($swiezy);
            }

            $swiezy->forceFill([
                'content_revision' => $swiezy->content_revision + 1,
                'tresc_zmieniona_at' => now(),
            ])->save();
            // Relacje załadowane przez podgląd (stan sprzed zmian) odświeżamy przed porównaniem i migawką.
            $swiezy->refresh();

            // Reguła „Mojej wersji”: wersja nie może być identyczna z oryginałem (rzuca BladDlaCzlowieka).
            MojaWersja::pilnujRoznicy($swiezy);

            $wersja = $this->migawki->poprawka(
                $swiezy,
                $swiezyAutor,
                'Przywrócono treść wersji '.$numer.' jako nową poprawkę',
            );

            AuditLogEntry::record(
                action: 'recipe.version_applied',
                actor: $swiezyAutor,
                subject: $swiezy,
                metadata: ['zrodlo_wersja' => $numer, 'sekcje' => $sekcje, 'nowa_wersja' => $wersja?->version_number],
                ip: $ip,
            );

            return ['wersja' => $wersja, 'sekcje' => $sekcje];
        });
    }

    private function zastosujDane(Recipe $przepis, MigawkaWersji $migawka): void
    {
        $dane = [];

        foreach (PodgladPoprawkiZWersji::KLUCZE_DANYCH as $klucz) {
            if (! $migawka->maKlucz($klucz)) {
                continue; // brak danych = zostaje dzisiejsza wartość
            }

            $wartosc = $migawka->surowa($klucz);

            if ($wartosc === null && in_array($klucz, ['title', 'servings'], true)) {
                continue;
            }

            $dane[$klucz] = $wartosc;
        }

        // Liczba i opis gotowych sztuk idą razem albo wcale.
        if (array_key_exists('yield_count', $dane) !== array_key_exists('yield_unit', $dane)) {
            unset($dane['yield_count'], $dane['yield_unit']);
        }

        if ($dane !== []) {
            $przepis->forceFill($dane)->save();
        }
    }

    private function zastosujSkladniki(Recipe $przepis, MigawkaWersji $migawka): void
    {
        $przepis->ingredients()->delete();

        foreach ($migawka->wierszeSkladnikow() as $i => $wiersz) {
            $tekst = trim((string) ($wiersz['text'] ?? ''));
            if ($tekst === '') {
                continue;
            }

            $kod = $wiersz['unit'] ?? null;

            RecipeIngredient::create([
                'recipe_id' => $przepis->getKey(),
                'group_name' => $wiersz['group_name'] ?? null,
                'ingredient_id' => null,
                'ingredient_text' => $tekst,
                'quantity' => $wiersz['quantity'] ?? null,
                'unit_id' => is_string($kod) && $kod !== '' ? Unit::query()->where('code', $kod)->value('id') : null,
                'note' => $wiersz['note'] ?? null,
                'substitutes' => $wiersz['substitutes'] ?? null,
                // Starsze migawki nie miały `no_amount`: brak klucza = domyślne „ma ilość”.
                'no_amount' => (bool) ($wiersz['no_amount'] ?? false),
                'position' => $i,
            ]);
        }
    }

    private function zastosujKroki(Recipe $przepis, MigawkaWersji $migawka): void
    {
        // Pod blokadą jeszcze raz: żaden dzisiejszy krok nie może mieć zdjęcia.
        if ($przepis->steps()->whereNotNull('media_id')->exists()) {
            throw new BladDlaCzlowieka('Kroki mają teraz zdjęcia, więc ich nie przywracamy. Nic nie zostało zmienione.');
        }

        $przepis->steps()->delete();

        foreach ($migawka->kroki() as $i => $krok) {
            RecipeStep::create([
                'recipe_id' => $przepis->getKey(),
                'position' => $i,
                'instruction' => $krok['instruction'],
                'media_id' => null,
                'timer_seconds' => $krok['timer_seconds'],
                'section_name' => $krok['section_name'],
            ]);
        }
    }
}
