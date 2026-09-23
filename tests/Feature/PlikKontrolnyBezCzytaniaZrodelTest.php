<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * PLIK KONTROLNY — KONTROLA Z DRUGIEJ STRONY dla klasyfikatora
 * `StraznikTekstuMaKontroleDodatniaTest`.
 *
 * Ten plik celowo NIE czyta żadnego źródła aplikacji. Ma nie zostać uznany
 * za strażnika tekstu — gdyby został, klasyfikator łapałby wszystko jak leci,
 * strażnik byłby zawsze czerwony i skończyłby wyłączony w tydzień.
 *
 * NIE DOPISUJ tu odczytu plików ani asercji na ich treści. Jeżeli potrzebujesz
 * takiego testu, napisz osobny — ten jest przyrządem, nie miejscem na treść.
 */
class PlikKontrolnyBezCzytaniaZrodelTest extends TestCase
{
    public function test_przyrzad_kontrolny_nie_czyta_zrodel(): void
    {
        $this->assertTrue(true, 'Przyrząd kontrolny: sam fakt istnienia tego pliku jest treścią kontroli.');
    }
}
