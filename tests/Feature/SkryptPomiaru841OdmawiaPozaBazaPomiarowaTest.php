<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Skrypt pomiarowy #841 CZYŚCI własne tabele przed założeniem danych
 *
 * syntetycznych (`contact_messages`, konta `*@pomiar841.example.test`), więc jedyną
 * rzeczą, która stoi między nimi a prawdziwą bazą, jest bezpiecznik na początku
 * pliku: praca wyłącznie na lokalnej bazie `kuking_pomiar_841*`.
 *
 * Ten test uruchamia skrypt naprawdę, ze wskazaniem bazy testowej (nie
 * pomiarowej), i wymaga trzech rzeczy: kod wyjścia różny od zera, komunikat
 * z nazwą wymaganej bazy i brak linii „Dane:” — skrypt wypisuje ją dopiero PO
 * założeniu danych, więc jej brak dowodzi, że nic nie zostało skasowane ani wstawione.
 *
 * Baza wskazana skryptowi to baza TEGO przebiegu testów (osobna dla każdego
 * worktree), nigdy `kuking` z `.env`: gdyby bezpiecznik został usunięty, ten
 * test zepsułby tylko własną, jednorazową bazę testową.
 *
 * Kontrola ujemna (wykonana przy pisaniu): zmiana przedrostka w bezpieczniku na
 * `kuking_test` zapala test na czerwono (skrypt kończy się kodem 0 i zakłada dane).
 *
 * @bez-kontroli-dodatniej test uruchamia skrypt jako osobny proces, nie czyta jego kodu.
 */
class SkryptPomiaru841OdmawiaPozaBazaPomiarowaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function skrypty(): array
    {
        return [
            '#841 wiadomości do obsługi' => ['scripts/pomiar-841.php', 'kuking_pomiar_841'],
        ];
    }

    #[DataProvider('skrypty')]
    public function test_skrypt_odmawia_pracy_na_bazie_innej_niz_pomiarowa(string $skrypt, string $przedrostek): void
    {
        $baza = (string) config('database.connections.pgsql.database');
        $this->assertStringStartsNotWith($przedrostek, $baza, 'Test wymaga bazy innej niż pomiarowa.');

        $env = ['DB_DATABASE' => $baza];
        if (getenv('APP_BASE_PATH') !== false) {
            $env['APP_BASE_PATH'] = (string) getenv('APP_BASE_PATH');
        }

        $proces = new Process([PHP_BINARY, base_path($skrypt)], base_path(), $env);
        $proces->setTimeout(60);
        $proces->run();

        $wyjscie = $proces->getOutput().$proces->getErrorOutput();

        $this->assertNotSame(0, $proces->getExitCode(), 'Skrypt pomiarowy nie może się udać na bazie spoza kuking_pomiar_*.');
        $this->assertStringContainsString($przedrostek, $wyjscie);
        $this->assertStringNotContainsString('Dane:', $wyjscie, 'Skrypt zdążył założyć dane mimo złej bazy.');
    }
}
