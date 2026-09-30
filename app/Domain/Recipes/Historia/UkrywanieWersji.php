<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Historia;

use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 *
 * UKRYCIE PRZEZ MODERACJĘ TO DECYZJA MODERACYJNA W ROZUMIENIU DSA (decyzja
 * właściciela z 30.09.2026, D-333, wiersz #2270). Po stronie moderacji
 * `ukryj()` i `przywroc()` wymagają `$decyzja` — domknięcia, które pod tą
 * samą blokadą zapisuje wiersz `moderation_actions` (uzasadnienie art. 17,
 * powiadomienie autora, droga odwołania art. 20). Podaje je wyłącznie
 * `App\Domain\Moderation\Actions\DecyzjaOWersjiPrzepisu`. Bez niego
 * zapis po stronie moderacji kończy się `LogicException`, zanim cokolwiek
 * trafi do bazy — nie ma drogi do ukrycia przez moderację bez decyzji.
 * Ukrycie przez AUTORA zostaje jego decyzją o własnej treści, bez DSA.
 *
 * Ten moduł nie importuje `Moderation` (graf modułów bez cykli, #971) —
 * domknięcie przychodzi z góry, a tu jest tylko miejsce, w którym się wykona.
 */
final class UkrywanieWersji
{
    public const UKRYTO = 'ukryto';

    public const JUZ_UKRYTA = 'juz_ukryta';

    public const NAJNOWSZA = 'najnowsza';

    public const PRZYWROCONO = 'przywrocono';

    public const NIE_BYLA_UKRYTA = 'nie_byla_ukryta';

    public const BRAK_WERSJI = 'brak_wersji';

    /** Przywrócenie odmówione: tę wersję ukryła druga strona (stan pod blokadą). */
    public const UKRYTA_PRZEZ_DRUGA_STRONE = 'ukryta_przez_druga_strone';

    public const BEZ_DECYZJI = 'Ukrycie wersji przez moderację i jego cofnięcie to decyzje moderacyjne (DSA art. 17) '
        .'— idą przez DecyzjaOWersjiPrzepisu, z podstawą i uzasadnieniem.';

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
     * @param  ?Closure(RecipeVersion): mixed  $decyzja  po stronie moderacji
     *                                                   OBOWIĄZKOWE: zapis decyzji moderacyjnej pod blokadą, przed
     *                                                   ukryciem. Wyjątek z niego cofa całość. Po stronie autora
     *                                                   pomijane.
     * @return self::UKRYTO|self::JUZ_UKRYTA|self::NAJNOWSZA|self::BRAK_WERSJI
     */
    public function ukryj(User $kto, Recipe $recipe, int $numer, ?string $ip = null, ?Closure $decyzja = null): string
    {
        $strona = self::strona($kto, $recipe);
        if ($strona === RecipeVersion::UKRYLA_MODERACJA && $decyzja === null) {
            throw new LogicException(self::BEZ_DECYZJI);
        }

        return DB::transaction(function () use ($kto, $recipe, $numer, $ip, $strona, $decyzja): string {
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

            $idDecyzji = null;
            if ($strona === RecipeVersion::UKRYLA_MODERACJA) {
                $idDecyzji = self::idDecyzji($decyzja($wersja));
            }

            $wersja->ukryj($strona);

            AuditLogEntry::record('recipe_version.hidden', $kto, $wersja, [
                'recipe_id' => $recipe->getKey(),
                'version_number' => $numer,
                'strona' => $strona,
                // Tylko po stronie moderacji: wskazanie decyzji w rejestrze.
                ...($idDecyzji === null ? [] : ['moderation_action_id' => $idDecyzji]),
            ], $ip);

            return self::UKRYTO;
        });
    }

    /**
     * @param  ?Closure(RecipeVersion): mixed  $decyzja  OBOWIĄZKOWE, gdy wersję
     *                                                   ukryła moderacja: zapis decyzji `unhide` pod blokadą (także
     *                                                   po uznanym odwołaniu). Może odmówić wyjątkiem — wtedy nic się
     *                                                   nie zmienia.
     * @return self::PRZYWROCONO|self::NIE_BYLA_UKRYTA|self::UKRYTA_PRZEZ_DRUGA_STRONE|self::BRAK_WERSJI
     */
    public function przywroc(User $kto, Recipe $recipe, int $numer, ?string $ip = null, ?Closure $decyzja = null): string
    {
        return DB::transaction(function () use ($kto, $recipe, $numer, $ip, $decyzja): string {
            Recipe::query()->whereKey($recipe->getKey())->lockForUpdate()->first();

            $wersja = $this->wersjaPodBlokada($recipe, $numer);
            if ($wersja === null) {
                return self::BRAK_WERSJI;
            }
            if (! $wersja->czyUkryta()) {
                return self::NIE_BYLA_UKRYTA;
            }

            $ktoUkryl = $wersja->hidden_by_role;
            $strona = self::strona($kto, $recipe);

            // Stan POD BLOKADĄ, nie ten, który widziała Policy: między
            // ekranem a zapisem druga strona mogła wersję przywrócić i ukryć
            // po swojemu. Autor nie cofa ukrycia moderacji, moderacja nie
            // odsłania ukrycia autora — tak samo jak w `RecipeVersionPolicy`.
            if ($ktoUkryl !== $strona) {
                return self::UKRYTA_PRZEZ_DRUGA_STRONE;
            }

            // Cofnięcie ukrycia moderacji jest decyzją moderacyjną.
            $idDecyzji = null;
            if ($ktoUkryl === RecipeVersion::UKRYLA_MODERACJA) {
                if ($decyzja === null) {
                    throw new LogicException(self::BEZ_DECYZJI);
                }
                $idDecyzji = self::idDecyzji($decyzja($wersja));
            }

            $wersja->odkryj();

            AuditLogEntry::record('recipe_version.restored', $kto, $wersja, [
                'recipe_id' => $recipe->getKey(),
                'version_number' => $numer,
                'strona' => $strona,
                'ukryl' => $ktoUkryl,
                ...($idDecyzji === null ? [] : ['moderation_action_id' => $idDecyzji]),
            ], $ip);

            return self::PRZYWROCONO;
        });
    }

    private static function idDecyzji(mixed $zapis): ?string
    {
        return is_object($zapis) && method_exists($zapis, 'getKey') ? (string) $zapis->getKey() : null;
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
