<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\RecipeVersion;

/**
 * Zdanie, które mówi autorowi, KTÓREJ wersji przepisu dotyczy decyzja
 * moderacji (#2270, #2390).
 *
 * Powiadomienie o decyzji nie ma osobnego miejsca na wskazanie celu, więc
 * zdanie idzie na początku uzasadnienia i razem z nim ląduje w
 * `moderation_actions.user_message`. Jedno źródło dla obu dróg: ukrycia
 * z historii zmian (`DecyzjaOWersjiPrzepisu`) i decyzji w sprawie zgłoszenia
 * wersji (`RozstrzygnijZgloszenie`).
 */
final class WskazanieWersji
{
    public static function tekst(Recipe $recipe, int $numer): string
    {
        return 'Dotyczy wersji '.$numer.' przepisu „'.$recipe->title.'” w historii zmian.';
    }

    /**
     * Ile znaków może mieć wiadomość moderatora dla autora, gdy przed nią idzie
     * to zdanie (kolumna `moderation_actions.user_message` ma 2000). Dotyczy
     * też wskazówki od gotujących (`WskazanieWskazowki`, #2352), która ma
     * własne zdanie. Dla celu innego niż te dwa: pełne 2000.
     */
    public static function limitWiadomosci(?string $typCelu, mixed $idCelu): int
    {
        $pelny = 2000;

        if ($typCelu === WskazanieWskazowki::TYP) {
            $wskazowka = ModeratedContent::znajdz($typCelu, is_string($idCelu) ? $idCelu : null);

            if (! $wskazowka instanceof RecipeHint || $wskazowka->recipe === null) {
                return $pelny;
            }

            return $pelny - WskazanieWskazowki::dlugoscMax($wskazowka->recipe) - 1;
        }

        if ($typCelu !== CofniecieUkryciaWersji::TYP) {
            return $pelny;
        }

        $wersja = ModeratedContent::znajdz($typCelu, is_string($idCelu) ? $idCelu : null);

        if (! $wersja instanceof RecipeVersion || $wersja->recipe === null) {
            return $pelny;
        }

        return $pelny - mb_strlen(self::tekst($wersja->recipe, $wersja->version_number)) - 1;
    }
}
