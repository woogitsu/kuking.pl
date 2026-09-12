<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Runbook wdrożeniowy nie uczy konfiguracji, która na zalecanym przez niego
 * planie NIE DZIAŁA i nie zgłasza tego błędem (D-116).
 *
 * CO BYŁO NIE TAK
 * `DEPLOYMENT_RUNBOOK.md` zalecał w KROKU 0.2 plan **Hobby**, a w §3.2 podawał
 * konfigurację **SMTP jako drogę domyślną** — „sterownik `smtp` jest już
 * skonfigurowany, zero zmian w kodzie". Railway blokuje ruch SMTP na planach
 * Free, Trial i Hobby. Wariantu, który DZIAŁA (`MAIL_MAILER=emaillabs` plus
 * klucze `EMAILLABS_*`), runbook nie wymieniał **ani razu** — sprawdzone
 * `grep`-em, zero trafień.
 *
 * DLACZEGO TO NIE JEST DROBIAZG W DOKUMENTACJI
 * Awaria jest CICHA: zadanie wisi w `RUNNING` bez końca, w logach zero błędu,
 * rejestracja się udaje, a list nie dochodzi nigdzie. Człowiek zakłada konto
 * i nie dostaje ani linku aktywacyjnego, ani linku do zmiany hasła — i nic
 * o tym nie mówi. To nie jest hipoteza: to opis 9 września 2026.
 *
 * D-116 kończy się zdaniem, które ten test wykonuje dosłownie:
 * „**Każde miejsce, które je wymienia, musi mówić, że na Hobby nie działają.**"
 *
 * CZEGO TEN TEST ŚWIADOMIE NIE ROBI
 * Nie zakazuje słowa „SMTP" w runbooku. Wariant SMTP zostaje opisany jako
 * droga na plan Pro — usunięcie go zostawiłoby regułę bez powodu, a reguła bez
 * powodu jest następnym kandydatem do „uproszczenia" (D-157). Test pyta
 * o jedno: czy tam, gdzie runbook podaje zmienne SMTP, stoi też ostrzeżenie
 * o planie Hobby.
 */
class RunbookNieUczySmtpNaHobbyTest extends TestCase
{
    private function runbook(): string
    {
        return (string) file_get_contents(base_path('docs/infra/DEPLOYMENT_RUNBOOK.md'));
    }

    public function test_runbook_wymienia_wariant_ktory_dziala_na_hobby(): void
    {
        $runbook = $this->runbook();

        // Kontrola dodatnia: plik w ogóle jest i jest runbookiem wdrożeniowym.
        $this->assertStringContainsString('runbook wdrożenia od zera', $runbook);

        $this->assertStringContainsString(
            'MAIL_MAILER',
            $runbook,
            'Runbook nie mówi, jaki sterownik poczty ustawić.',
        );

        // NIE sam napis „emaillabs". W wersji sprzed tej poprawki padał on
        // w runbooku CZTERY razy — w nazwie hosta `smtp.emaillabs.net.pl`,
        // czyli dokładnie w tym wariancie, który na Hobby nie działa. Asercja
        // na sam wyraz byłaby więc zielona na zepsutym dokumencie. Sprawdzamy
        // PRZYPISANIE sterownika, bo to ono decyduje, którą drogą idzie list.
        $this->assertMatchesRegularExpression(
            '/MAIL_MAILER\s*=\s*emaillabs/',
            $runbook,
            'Runbook nie wymienia wariantu `MAIL_MAILER=emaillabs` — jedynego, '
            .'który działa na planie Railway Hobby, zalecanym przez ten sam '
            .'runbook w KROKU 0.2 (D-116). Do 12 września 2026 nie było go tu '
            .'ani razu, a jedyną opisaną drogą był SMTP, który na tym planie '
            .'milczy: zadanie wisi w RUNNING, rejestracja się udaje, list nie '
            .'dochodzi.',
        );

        foreach (['EMAILLABS_APP_KEY', 'EMAILLABS_SECRET_KEY', 'EMAILLABS_SMTP_ACCOUNT'] as $zmienna) {
            $this->assertStringContainsString(
                $zmienna,
                $runbook,
                "Runbook nie podaje zmiennej `{$zmienna}`, bez której wariant "
                .'działający na Hobby nie da się skonfigurować.',
            );
        }
    }

    public function test_kazde_miejsce_ze_zmiennymi_smtp_ostrzega_o_planie_hobby(): void
    {
        $runbook = $this->runbook();
        $linie = explode("\n", $runbook);

        // Zmienne, których obecność oznacza „tu ktoś konfiguruje SMTP".
        $zmienneSmtp = ['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'];

        $bezOstrzezenia = [];

        foreach ($linie as $nr => $linia) {
            $dotyczySmtp = false;
            foreach ($zmienneSmtp as $zmienna) {
                if (str_contains($linia, $zmienna)) {
                    $dotyczySmtp = true;
                    break;
                }
            }

            if (! $dotyczySmtp) {
                continue;
            }

            // OKNO, NIE SAMA LINIA. Ostrzeżenie stoi naturalnie w akapicie nad
            // blokiem kodu albo w tej samej komórce tabeli — szukanie wyłącznie
            // w linii z nazwą zmiennej kazałoby powtarzać zdanie przy każdej
            // z czterech i nie opisywałoby tego, co człowiek naprawdę czyta.
            $od = max(0, $nr - 25);
            $okno = implode("\n", array_slice($linie, $od, ($nr - $od) + 6));

            if (! preg_match('/Hobby/ui', $okno)) {
                $bezOstrzezenia[] = ($nr + 1).': '.trim($linia);
            }
        }

        $this->assertSame(
            [],
            $bezOstrzezenia,
            'D-116: każde miejsce wymieniające zmienne SMTP musi mówić, że na planie '
            .'Railway Hobby nie działają — awaria jest cicha i wygląda jak sukces. '
            ."Miejsca bez ostrzeżenia:\n".implode("\n", $bezOstrzezenia),
        );
    }

    public function test_wykrywacz_naprawde_lapie_brak_ostrzezenia(): void
    {
        // KONTROLA SAMEGO WYKRYWACZA. Bez niej test wyżej mógłby być zielony
        // dlatego, że nie znajduje zmiennych SMTP w ogóle — a nie dlatego, że
        // wszystkie mają ostrzeżenie.
        $runbook = $this->runbook();

        $ile = 0;
        foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD'] as $zmienna) {
            $ile += substr_count($runbook, $zmienna);
        }

        $this->assertGreaterThanOrEqual(
            4,
            $ile,
            'W runbooku nie ma już zmiennych SMTP, więc test wyżej nie mierzy niczego. '
            .'Jeśli zostały usunięte świadomie, ten test trzeba zmienić razem z nimi — '
            .'a nie zostawić zielonym na pustym zbiorze.',
        );
    }
}
