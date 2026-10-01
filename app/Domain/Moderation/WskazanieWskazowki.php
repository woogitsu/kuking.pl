<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\ModerationAction;
use App\Models\Recipe;

/**
 * Zdanie, które mówi kucharzowi, KTÓREJ wskazówki przy którym przepisie
 * dotyczy decyzja moderacji (#2352).
 *
 * Powiadomienie o decyzji nie ma osobnego miejsca na wskazanie celu, więc
 * zdanie idzie na początku uzasadnienia i razem z nim ląduje w
 * `moderation_actions.user_message` — tak samo jak `WskazanieWersji`.
 * Przy ukryciu dodajemy, co się stało i czego NIE: uwaga zostaje pod
 * wykonaniem, bo kucharz mógłby uznać, że straciło je całe.
 */
final class WskazanieWskazowki
{
    public const TYP = 'recipe_hint';

    public static function tekst(Recipe $recipe, string $akcja): string
    {
        $zdanie = 'Dotyczy wskazówki od gotujących, którą pokazywaliśmy przy przepisie „'.$recipe->title.'”.';

        if ($akcja === ModerationAction::ACTION_HIDE) {
            $zdanie .= ' Wskazówka zniknęła ze strony przepisu, a Twoja uwaga zostaje pod Twoim wykonaniem.';
        }

        return $zdanie;
    }

    /** Długość zdania w najdłuższej postaci (ukrycie) — do limitu wiadomości moderatora. */
    public static function dlugoscMax(Recipe $recipe): int
    {
        return mb_strlen(self::tekst($recipe, ModerationAction::ACTION_HIDE));
    }

    /**
     * Wiadomość dla kucharza ze zdaniem wskazującym wskazówkę na początku.
     * Decyzja „Bez działania” nic kucharzowi nie zmienia, więc jej nie dotyczy.
     */
    public static function wiadomosc(Recipe $recipe, string $akcja, ?string $wiadomoscModeratora): ?string
    {
        if ($akcja === ModerationAction::ACTION_NONE) {
            return $wiadomoscModeratora;
        }

        return trim(self::tekst($recipe, $akcja).' '.trim((string) $wiadomoscModeratora));
    }
}
