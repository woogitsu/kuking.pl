<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\ModerationAction;
use App\Models\RecipeHint;

/**
 * Zdanie, które mówi kucharzowi, KTÓREJ wskazówki przy którym przepisie
 * dotyczy decyzja moderacji (#2352).
 *
 * Powiadomienie o decyzji nie ma osobnego miejsca na wskazanie celu, więc
 * zdanie idzie na początku uzasadnienia i razem z nim ląduje w
 * `moderation_actions.user_message` — tak samo jak `WskazanieWersji`.
 * Przy ukryciu dodajemy, co się stało i czego NIE: uwaga zostaje pod
 * wykonaniem, bo kucharz mógłby uznać, że straciło je całe.
 *
 * TYTUŁ PRZEPISU TYLKO BEZ BLOKADY. Wiadomość trafia do kucharza i zostaje w
 * rejestrze decyzji, więc tytuł przepisu osoby, która zablokowała kucharza (albo
 * którą on zablokował), byłby wyciekiem w poprzek blokady — jak przy każdej innej
 * treści autora. Przy blokadzie, albo gdy nie da się ustalić autora przepisu,
 * zdanie wskazuje wskazówkę bez tytułu.
 */
final class WskazanieWskazowki
{
    public const TYP = 'recipe_hint';

    private const ZDANIE_BEZ_TYTULU = 'Dotyczy wskazówki od gotujących, którą pokazywaliśmy przy jednym z przepisów.';

    private const KONIEC_UKRYCIA = ' Wskazówka zniknęła ze strony przepisu, a Twoja uwaga zostaje pod Twoim wykonaniem.';

    /**
     * Tytuł przepisu do wymienienia w wiadomości dla kucharza — albo `null`, gdy
     * wymienić go nie wolno (blokada między kucharzem a autorem przepisu) albo
     * nie ma czego (przepisu już nie ma).
     */
    private static function tytulDoWiadomosci(RecipeHint $wskazowka): ?string
    {
        $wskazowka->loadMissing(['cook', 'author', 'recipe']);

        $przepis = $wskazowka->recipe;

        if ($przepis === null || $wskazowka->cook->hasBlockRelationWith($wskazowka->author)) {
            return null;
        }

        return $przepis->title;
    }

    public static function tekst(RecipeHint $wskazowka, string $akcja): string
    {
        $tytul = self::tytulDoWiadomosci($wskazowka);
        $zdanie = $tytul !== null
            ? 'Dotyczy wskazówki od gotujących, którą pokazywaliśmy przy przepisie „'.$tytul.'”.'
            : self::ZDANIE_BEZ_TYTULU;

        if ($akcja === ModerationAction::ACTION_HIDE) {
            $zdanie .= self::KONIEC_UKRYCIA;
        }

        return $zdanie;
    }

    /**
     * Zdanie dla kucharza przy ręcznym przywróceniu wskazówki przez moderację:
     * wskazówka znów stoi przy przepisie. Dodawane do wiadomości tylko wtedy,
     * gdy zgoda kucharza nadal obowiązuje — po wycofaniu zgody nic nie wraca
     * i nikt nie dostaje wiadomości o widoczności.
     */
    public static function przywrocenie(RecipeHint $wskazowka): string
    {
        $tytul = self::tytulDoWiadomosci($wskazowka);

        return $tytul !== null
            ? 'Wskazówka od gotujących przy przepisie „'.$tytul.'” jest znowu widoczna.'
            : 'Wskazówka od gotujących, którą pokazywaliśmy przy jednym z przepisów, jest znowu widoczna.';
    }

    /**
     * Długość zdania w najdłuższej postaci (ukrycie, z tytułem albo bez) — do
     * limitu wiadomości moderatora, żeby całość mieściła się w kolumnie.
     */
    public static function dlugoscMax(RecipeHint $wskazowka): int
    {
        $wskazowka->loadMissing('recipe');

        $przepis = $wskazowka->recipe;
        $zTytulem = 'Dotyczy wskazówki od gotujących, którą pokazywaliśmy przy przepisie „'.($przepis === null ? '' : $przepis->title).'”.';

        return max(mb_strlen($zTytulem), mb_strlen(self::ZDANIE_BEZ_TYTULU)) + mb_strlen(self::KONIEC_UKRYCIA);
    }

    /**
     * Wiadomość dla kucharza ze zdaniem wskazującym wskazówkę na początku.
     * Decyzja „Bez działania” nic kucharzowi nie zmienia, więc jej nie dotyczy.
     */
    public static function wiadomosc(RecipeHint $wskazowka, string $akcja, ?string $wiadomoscModeratora): ?string
    {
        if ($akcja === ModerationAction::ACTION_NONE) {
            return $wiadomoscModeratora;
        }

        return trim(self::tekst($wskazowka, $akcja).' '.trim((string) $wiadomoscModeratora));
    }
}
