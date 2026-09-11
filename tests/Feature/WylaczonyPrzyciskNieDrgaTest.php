<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Wyłączony przycisk nie udaje, że coś robi.
 *
 * USTERKA: `.btn:active:not(:disabled)` przesuwa przycisk o 1 px w dół przy
 * naciśnięciu. Wyklucza przy tym wyłącznie `:disabled`, czyli atrybut, który
 * ma tylko `<button>`. Wyłączony element zapisany jako
 * `<span class="btn" aria-disabled="true">` — a takie stoją w karuzeli zdjęć
 * przy „Poprzednie zdjęcie" i „Następne zdjęcie" — przechodził tym selektorem
 * i drgał tak samo jak przycisk działający.
 *
 * Człowiek naciska, widzi reakcję, nie dostaje nic. To jest martwy przycisk
 * udający żywy, czyli dokładnie to, przed czym ostrzega AGENTS.md §5 punkt 3.
 */
class WylaczonyPrzyciskNieDrgaTest extends TestCase
{
    public function test_przycisk_wylaczony_atrybutem_aria_nie_przesuwa_sie_przy_nacisnieciu(): void
    {
        $tokeny = (string) file_get_contents(resource_path('css/tokens.css'));

        $this->assertSame(
            1,
            preg_match('/\.btn\[aria-disabled="true"\]\s*\{(.+?)\}/s', $tokeny, $trafienie),
            'W `tokens.css` nie ma reguły `.btn[aria-disabled="true"]`.',
        );

        $this->assertMatchesRegularExpression(
            '/transform:\s*none/',
            $trafienie[1],
            'Reguła `.btn[aria-disabled="true"]` nie zeruje `transform`. `.btn:active` '
            .'wyklucza tylko `:disabled`, więc wyłączony `<span>` nadal drgnie przy '
            .'naciśnięciu i nic nie zrobi.',
        );
    }
}
