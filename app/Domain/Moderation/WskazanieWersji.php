<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Recipe;

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
}
