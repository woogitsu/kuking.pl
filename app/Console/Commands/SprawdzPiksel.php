<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Poczta\SladySledzeniaOtwarc;
use Illuminate\Console\Command;

/**
 * `kuking:sprawdz-piksel {plik}` — czy w DORĘCZONYM liście stoi obcy obrazek
 * śledzący otwarcie (issue #204).
 *
 * PO CO KOMENDA, A NIE ZWYKŁY TEST
 * Bo piksel dokłada DOSTAWCA, po naszej stronie żądania. `TransportEmailLabs`
 * wysyła dokładnie tę treść, którą złożyły nasze szablony — a w nich obrazków
 * nie ma w ogóle (D-057). Test na treść, którą składamy sami, przechodziłby
 * więc zawsze i nie mierzyłby niczego, co ma zmierzyć: pilnowałby naszych
 * szablonów, a piksel doklejany jest DALEJ. Jedyne miejsce, w którym piksel
 * naprawdę jest, to list, który przyszedł do skrzynki — i tylko człowiek
 * z dostępem do tej skrzynki może go tu podać.
 *
 * Kryterium przyjęcia z issue #204 brzmi: „dostarczony list transakcyjny nie
 * zawiera ani jednego odwołania do `click.kuking.pl` — sprawdzone na SUROWYM
 * ŹRÓDLE wiadomości, nie na widoku w kliencie pocztowym". Ta komenda zamienia
 * to zdanie w powtarzalny pomiar: oblewa się, kiedy ślad jest, i mówi, który.
 *
 * JAK TEGO UŻYĆ
 *   1. `php artisan kuking:sprawdz-poczte ty@o2.pl` — wyślij jeden list.
 *   2. W skrzynce: Gmail „Pokaż oryginał", o2 i WP „Więcej" → „Pokaż
 *      szczegóły" → zapisz źródło do pliku, np. `list.eml`.
 *   3. `php artisan kuking:sprawdz-piksel list.eml`
 *      (albo `... -` i wklej źródło na standardowe wejście).
 *
 * CZEGO TA KOMENDA NIE SPRAWDZA — i to jest tu najważniejsze zdanie
 * Nie sprawdza USTAWIENIA konta wysyłkowego u dostawcy. Śledzenie otwarć
 * włącza się i wyłącza w panelu EmailLabs; API, którym wysyłamy, nie ma pola,
 * którym dałoby się to ustawienie odczytać ani zmienić (nagłówek
 * `X-TRACKING-OFF` ze specyfikacji dotyczy wyłącznie śledzenia ODNOŚNIKÓW).
 * Zielony wynik mówi więc: „TEN list wyszedł czysty", a nie „przełącznik jest
 * wyłączony". Czerwony jest mocniejszy: jeden ślad wystarcza, żeby wiedzieć,
 * że śledzenie otwarć wciąż działa.
 */
class SprawdzPiksel extends Command
{
    protected $signature = 'kuking:sprawdz-piksel
                            {plik : Plik z SUROWYM źródłem doręczonej wiadomości („Pokaż oryginał” w kliencie pocztowym); „-” czyta ze standardowego wejścia}';

    protected $description = 'Sprawdza, czy w doręczonym liście stoi obcy obrazek śledzący otwarcie (issue #204)';

    public function handle(): int
    {
        $plik = (string) $this->argument('plik');
        $zrodlo = $this->zrodlo($plik);

        if ($zrodlo === null) {
            return self::FAILURE;
        }

        $slady = SladySledzeniaOtwarc::wSurowymZrodle($zrodlo);

        $this->newLine();
        $this->line('<options=bold>Sprawdzenie listu pod kątem śledzenia otwarć</>');
        $this->newLine();

        $this->table(['Co', 'Wartość'], [
            ['Plik', $plik === '-' ? 'standardowe wejście' : $plik],
            ['Znaków źródła', (string) mb_strlen($zrodlo)],
            ['Znaków po odkodowaniu', (string) $slady->znakowPoDekodowaniu],
            ['Adresów http(s) w treści', (string) $slady->adresow],
            ['Hosty uznawane za nasze', implode(', ', SladySledzeniaOtwarc::naszeHosty())],
        ]);

        // NAJPIERW PYTANIE „CZY W OGÓLE COŚ ZMIERZYLIŚMY".
        // Zero adresów w pliku znaczy, że to nie jest źródło listu z Kuking
        // albo że odkodowanie nic nie dało. Każdy nasz list niesie co najmniej
        // jeden odnośnik, więc zero adresów to „NIE WIEMY" — a „NIE WIEMY"
        // liczy się jako nieprzejście, nie jako sukces
        // (`docs/PULAPKI_TESTOW.md` §5).
        if ($slady->nicNieZmierzono()) {
            $this->error('W tym pliku nie ma ANI JEDNEGO adresu http(s) — czyli nie ma czego sprawdzać.');
            $this->newLine();
            $this->line('To nie znaczy „list jest czysty”. Znaczy „nic nie zmierzyliśmy”. Sprawdź:');
            $this->line('  • czy w pliku jest SUROWE ŹRÓDŁO wiadomości („Pokaż oryginał”), a nie widok z klienta;');
            $this->line('  • czy plik nie urwał się w połowie (źródło listu z Kuking ma kilkadziesiąt kilobajtów);');
            $this->line('  • czy to na pewno list z Kuking — każdy z nich ma w treści przycisk z odnośnikiem.');

            return self::FAILURE;
        }

        if (! $slady->czysto()) {
            $this->error('W doręczonym liście stoi śledzenie treści. Znalezione ślady:');
            $this->newLine();

            foreach ($slady->slady as $slad) {
                $this->line('  • '.$slad);
            }

            $this->newLine();
            $this->line('<options=bold>Co zrobić</>');
            $this->line('Tego NIE naprawi zmiana w repozytorium — nasze szablony listów nie mają obrazków');
            $this->line('w ogóle, a nagłówek `X-TRACKING-OFF` gasi wyłącznie śledzenie ODNOŚNIKÓW.');
            $this->line('Śledzenie otwarć jest ustawieniem konta wysyłkowego w panelu EmailLabs:');
            $this->line('  1. panel EmailLabs → konto wysyłkowe → wyłącz śledzenie otwarć;');
            $this->line('  2. wyślij list jeszcze raz (`kuking:sprawdz-poczte`) i powtórz to sprawdzenie;');
            $this->line('  3. wynik z datą zapisz w `docs/infra/POCZTA_URUCHOMIENIE.md` §5 (issue #204).');
            $this->newLine();
            $this->line('Dopóki ślad tu jest, dostawca zna moment otwarcia listu, adres IP i klienta');
            $this->line('pocztowego odbiorcy — przy liście transakcyjnym są to dane zbierane bez celu.');

            return self::FAILURE;
        }

        $this->info('W tym liście nie ma obcego obrazka ani przepisanego odnośnika.');
        $this->newLine();
        $this->line('Uczciwie o granicy tego wyniku: mówi on o TYM JEDNYM liście, a nie o ustawieniu');
        $this->line('konta u dostawcy. Ustawienia śledzenia otwarć nie da się odczytać przez API, którym');
        $this->line('wysyłamy — potwierdzeniem, że przełącznik jest wyłączony, jest panel EmailLabs');
        $this->line('i ten sam wynik na kilku listach z różnych powiadomień.');

        return self::SUCCESS;
    }

    /** Źródło wiadomości z pliku albo ze standardowego wejścia; `null` = nie da się przeczytać. */
    private function zrodlo(string $plik): ?string
    {
        if ($plik === '-') {
            $zrodlo = stream_get_contents(STDIN);

            if (! is_string($zrodlo) || trim($zrodlo) === '') {
                $this->error('Na standardowym wejściu nie było nic. Wklej surowe źródło listu i zakończ Ctrl+D.');

                return null;
            }

            return $zrodlo;
        }

        if (! is_file($plik) || ! is_readable($plik)) {
            $this->error('Nie da się przeczytać pliku „'.$plik.'”.');
            $this->newLine();
            $this->line('Podaj plik z surowym źródłem doręczonej wiadomości, na przykład:');
            $this->line('  php artisan kuking:sprawdz-piksel ~/list-z-kuking.eml');
            $this->newLine();
            $this->line('Źródło bierze się z klienta pocztowego: Gmail „Pokaż oryginał”,');
            $this->line('o2 i WP „Więcej” → „Pokaż szczegóły”. Zapisz je do pliku tekstowego.');

            return null;
        }

        $zrodlo = file_get_contents($plik);

        if (! is_string($zrodlo) || trim($zrodlo) === '') {
            $this->error('Plik „'.$plik.'” jest pusty — nie ma czego sprawdzać.');

            return null;
        }

        return $zrodlo;
    }
}
