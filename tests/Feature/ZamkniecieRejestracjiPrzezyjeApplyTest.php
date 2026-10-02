<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zamknięcie rejestracji w panelu nie może wrócić do „otwarta” po `apply`
 * i nie może się zamknąć samo (droga do bety, B9).
 *
 * Dwie przeciwne pomyłki, jeden przełącznik `KUKING_REGISTRATION_OPEN`:
 *
 *  1. Zmiennej nie było w `.railway/railway.ts`. Właściciel zamyka rejestrację
 *     na czas przeglądu prawnika wartością `false` w panelu; pierwsze
 *     `railway config apply` usuwa zmienną spoza pliku i `/register` otwiera się
 *     dla wszystkich, bez żadnego komunikatu.
 *  2. Po dopisaniu referencji `ctx.shared` niezałożona Shared Variable przychodzi
 *     jako PUSTY napis, a `(bool) ''` to `false` — rejestracja zamknęłaby się
 *     sama. Pusty napis musi znaczyć „otwarta”, jak brak zmiennej.
 */
class ZamkniecieRejestracjiPrzezyjeApplyTest extends TestCase
{
    /** @return array<string, array{0: string|null, 1: bool}> */
    public static function wartosci(): array
    {
        return [
            'brak zmiennej: otwarta (wartość domyślna)' => [null, true],
            'pusty napis z niezałożonej Shared Variable: otwarta' => ['', true],
            'true: otwarta' => ['true', true],
            'false: zamknięta' => ['false', false],
            'zero: zamknięta' => ['0', false],
            'wartość niezrozumiała: zamknięta (kierunek bezpieczny)' => ['nie wiem', false],
        ];
    }

    #[Test]
    #[DataProvider('wartosci')]
    public function przelacznik_czyta_sie_tak_jak_obiecuje_komentarz(?string $wartosc, bool $oczekiwana): void
    {
        $repozytorium = Env::getRepository();
        $poprzednia = $repozytorium->get('KUKING_REGISTRATION_OPEN');

        try {
            if ($wartosc === null) {
                $repozytorium->clear('KUKING_REGISTRATION_OPEN');
            } else {
                $this->assertTrue($repozytorium->set('KUKING_REGISTRATION_OPEN', $wartosc));
            }

            $swiezy = require base_path('config/kuking.php');

            $this->assertSame($oczekiwana, $swiezy['account']['registration_open']);
        } finally {
            if ($poprzednia === null) {
                $repozytorium->clear('KUKING_REGISTRATION_OPEN');
            } else {
                $repozytorium->set('KUKING_REGISTRATION_OPEN', $poprzednia);
            }
        }
    }

    #[Test]
    public function railway_ts_przekazuje_przelacznik_rejestracji_serwisowi_www_przez_shared_variables(): void
    {
        $zrodlo = (string) file_get_contents(base_path('.railway/railway.ts'));

        $this->assertMatchesRegularExpression(
            '/const rejestracjaWebEnv = \{\s*KUKING_REGISTRATION_OPEN: ctx\.shared\.KUKING_REGISTRATION_OPEN,\s*\};/',
            $zrodlo,
            'Bez referencji `ctx.shared` zamknięcie rejestracji z panelu zniknie przy pierwszym `apply`.',
        );
        $this->assertMatchesRegularExpression(
            '/const webEnv = \{[^}]*\.\.\.rejestracjaWebEnv/',
            $zrodlo,
            'Serwis WWW ma dostać `rejestracjaWebEnv` — to on obsługuje `/register`.',
        );
    }
}
