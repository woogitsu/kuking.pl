<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Pytania do rejestru dowodów zabezpieczonych przed usunięciem.
 *
 * JEDNO MIEJSCE, które odpowiada na „czy wolno to skasować”, żeby kasujące
 * ścieżki (retencja treści, wersji i spraw, kasowanie zdjęć, wymazanie konta,
 * przywracanie) nie miały własnych kopii tego warunku. Rejestr zapełnia
 * wyłącznie `Actions\ZabezpieczDowodCsam`; zdejmowania nie ma (patrz migracja).
 *
 * Zapytania są świadomie surowe (`DB::table`), bez modelu: wołają je
 * sprzątacze w pętlach i kontrola ma być tania i niezależna od zakresów
 * modeli (soft delete).
 */
final class ZabezpieczoneDowody
{
    /** Typy obiektów, które rejestr zna — te same, których dotyczy CHECK w bazie. */
    public const TYPY = ['post', 'recipe', 'comment', 'media'];

    /**
     * Czy którykolwiek z podanych obiektów jest zabezpieczony.
     *
     * @param  string|list<string>  $id
     */
    public static function dotyczy(string $typ, string|array $id): bool
    {
        $id = array_values(array_filter((array) $id));

        if ($id === []) {
            return false;
        }

        return DB::table('zabezpieczenia_dowodow')
            ->where('target_type', $typ)
            ->whereIn('target_id', $id)
            ->exists();
    }

    /** Czy to zdjęcie jest zabezpieczone (dla zdjęcia z `status = secured` zawsze tak). */
    public static function zdjecie(string $id): bool
    {
        return self::dotyczy('media', $id);
    }

    /**
     * Czy konto ma zabezpieczone dowody — wtedy nie wolno go wymazać.
     *
     * Wymazanie anonimizuje adres e-mail i dane profilu, a to są dane, o które
     * zapyta organ przy zgłoszeniu (playbook §7.1 pkt 3, 6).
     */
    public static function konto(string $userId): bool
    {
        return DB::table('zabezpieczenia_dowodow')->where('subject_user_id', $userId)->exists();
    }

    /**
     * Czy wersje tego przepisu są objęte zabezpieczeniem (przepis zabezpieczony
     * razem z całą historią).
     */
    public static function przepis(string $recipeId): bool
    {
        return self::dotyczy('recipe', $recipeId);
    }
}
