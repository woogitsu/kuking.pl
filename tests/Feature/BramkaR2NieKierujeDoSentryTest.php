<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Procedura odbioru R2 (`docs/infra/BRAMKA_R2.md`, punkt 12) wskazuje
 * rzeczywisty kanał alertów, nie Sentry, którego nie wdrożono (D-041, #2382).
 */
class BramkaR2NieKierujeDoSentryTest extends TestCase
{
    #[Test]
    public function punkt_12_wskazuje_kanal_webhook_i_nie_wymaga_sentry(): void
    {
        $wiersz = null;
        foreach (file(base_path('docs/infra/BRAMKA_R2.md'), FILE_IGNORE_NEW_LINES) ?: [] as $linia) {
            if (str_starts_with($linia, '| 12 |')) {
                $wiersz = $linia;
            }
        }

        $this->assertNotNull($wiersz, 'Brak punktu 12 w tabeli kontroli R2.');
        $this->assertStringContainsString('blad_webhook', $wiersz);
        $this->assertStringContainsString('LOG_BLAD_WEBHOOK_URL', $wiersz);
        $this->assertStringContainsString('D-041', $wiersz);
        $this->assertDoesNotMatchRegularExpression('/sprawdź Sentry/iu', $wiersz);
        $this->assertStringContainsString('nie jest wdrożony', $wiersz, 'Sentry może wystąpić tylko jako wyraźnie oznaczony plan.');
    }
}
