<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ukrycie i przywrócenie jednej wersji przepisu (issue #2270, D-333).
 *
 * KTO — rozstrzyga `RecipeVersionPolicy` (`hide`, `restore`) PRZED wołaniem
 * tej klasy. Tu jest tylko STAN: czy wersję w ogóle da się teraz ukryć albo
 * przywrócić, i zapis razem z wpisem w `audit_log`.
 *
 * NAJNOWSZEJ WERSJI NIE UKRYWAMY. To treść przepisu, którą i tak widać na
 * jego stronie, więc ukrycie niczego by nie schowało, a historia udawałaby,
 * że przepis nie ma bieżącej wersji. Żeby usunąć tekst z najnowszej wersji,
 * autor poprawia przepis: „Zapisz” na opublikowanym przepisie tworzy nową
 * wersję (`SnapshotRecipeVersion::poprawka()`), a poprzednią da się wtedy
 * ukryć. Sprawdzamy to pod blokadą wiersza `recipes` — tą samą, pod którą
 * `SnapshotRecipeVersion` nadaje numer nowej wersji — więc „najnowsza"
 * nie zmieni się między sprawdzeniem a zapisem.
 *
 * DZIENNIK: `recipe_version.hidden` / `recipe_version.restored` idą przez
 * `record()` WEWNĄTRZ transakcji (D-249, klasa 1). `recipe_versions` mówi
 * tylko, ŻE wersję ukryto i po której stronie; KTÓRE konto to zrobiło, jest
 * wyłącznie tutaj — awaria dziennika ma więc cofnąć ukrycie. Bez treści
 * wersji w metadanych: dziennik nie może być drugim miejscem, w którym
 * ukryty tekst przeżywa.
 */
final class UkrywanieWersji
{
    public const UKRYTO = 'ukryto';

    public const JUZ_UKRYTA = 'juz_ukryta';

    public const NAJNOWSZA = 'najnowsza';

    public const PRZYWROCONO = 'przywrocono';

    public const NIE_BYLA_UKRYTA = 'nie_byla_ukryta';

    public const BRAK_WERSJI = 'brak_wersji';

    /**
     * Po której stronie stoi ta osoba przy tym przepisie. Autor własnego
     * przepisu jest zawsze autorem, także gdy ma rolę moderatora.
     */
    public static function strona(User $kto, Recipe $recipe): string
    {
        return $kto->getKey() === $recipe->author_id
            ? RecipeVersion::UKRYL_AUTOR
            : RecipeVersion::UKRYLA_MODERACJA;
    }

    /**
     * @return self::UKRYTO|self::JUZ_UKRYTA|self::NAJNOWSZA|self::BRAK_WERSJI
     */
    public function ukryj(User $kto, Recipe $recipe, int $numer, ?string $ip = null): string
    {
        return DB::transaction(function () use ($kto, $recipe, $numer, $ip): string {
            Recipe::query()->whereKey($recipe->getKey())->lockForUpdate()->first();

            $wersja = $this->wersjaPodBlokada($recipe, $numer);
            if ($wersja === null) {
                return self::BRAK_WERSJI;
            }
            if ($wersja->czyUkryta()) {
                return self::JUZ_UKRYTA;
            }
            if (HistoriaWersji::numerNajnowszej($recipe) === $numer) {
                return self::NAJNOWSZA;
            }

            $strona = self::strona($kto, $recipe);
            $wersja->ukryj($strona);

            AuditLogEntry::record('recipe_version.hidden', $kto, $wersja, [
                'recipe_id' => $recipe->getKey(),
                'version_number' => $numer,
                'strona' => $strona,
            ], $ip);

            return self::UKRYTO;
        });
    }

    /**
     * @return self::PRZYWROCONO|self::NIE_BYLA_UKRYTA|self::BRAK_WERSJI
     */
    public function przywroc(User $kto, Recipe $recipe, int $numer, ?string $ip = null): string
    {
        return DB::transaction(function () use ($kto, $recipe, $numer, $ip): string {
            Recipe::query()->whereKey($recipe->getKey())->lockForUpdate()->first();

            $wersja = $this->wersjaPodBlokada($recipe, $numer);
            if ($wersja === null) {
                return self::BRAK_WERSJI;
            }
            if (! $wersja->czyUkryta()) {
                return self::NIE_BYLA_UKRYTA;
            }

            $ktoUkryl = $wersja->hidden_by_role;
            $wersja->odkryj();

            AuditLogEntry::record('recipe_version.restored', $kto, $wersja, [
                'recipe_id' => $recipe->getKey(),
                'version_number' => $numer,
                'strona' => self::strona($kto, $recipe),
                'ukryl' => $ktoUkryl,
            ], $ip);

            return self::PRZYWROCONO;
        });
    }

    private function wersjaPodBlokada(Recipe $recipe, int $numer): ?RecipeVersion
    {
        return RecipeVersion::query()
            ->where('recipe_id', $recipe->getKey())
            ->where('version_number', $numer)
            ->lockForUpdate()
            ->first(['id', 'recipe_id', 'version_number', 'hidden_at', 'hidden_by_role']);
    }
}
