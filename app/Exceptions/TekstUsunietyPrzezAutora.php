<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Komentarza nie da się przywrócić, bo jego tekst skasował człowiek, nie
 * moderacja — autor komentarza albo autor treści, pod którą stał
 * (`RestoreContent`, D-251 pkt 10).
 *
 * Osobna klasa, bo `ResolveAppeal::cofnij()` MUSI odróżnić ten przypadek od
 * „treść już widoczna”. Przy „już widoczna” obietnica z odpowiedzi na
 * odwołanie jest prawdziwa. Tu nie jest — i autor odwołania dostaje zdanie,
 * że komentarz nie wrócił.
 */
final class TekstUsunietyPrzezAutora extends BladDlaCzlowieka {}
