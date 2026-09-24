<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Klucz modelu moderacji i adres alarmowy dochodzą do ról, które ich używają (#1014).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Do 24 września 2026 `.railway/railway.ts` nie referencował ani
 * `OPENAI_MODERATION_KEY`, ani `KUKING_MODEL_ALARM_EMAIL`. Produkcja chodzi na
 * jednym, ręcznie skonfigurowanym serwisie, więc dziś tego nie widać. Po
 * rozdzieleniu usług (#595) worker i scheduler powstałyby z IaC bez tych
 * zmiennych: model przestałby oceniać treści, a moderator dostawać alarmy —
 * przy zielonym deployu i zielonym `/health`, bo brak klucza to świadomy
 * fail-open (D-055). Railway udostępnia Shared Variable tylko usługom, które
 * ją referencują.
 *
 * CO TEN PLIK WIĄŻE
 *  - konsumenta konfiguracji (plik czytający `kuking.moderation.model.*`)
 *    z rolą procesu, w którym działa;
 *  - rolę z usługą Railway i jej blokiem `env`;
 *  - runbook z pełną listą Shared Variables i odbiorem na właściwych usługach.
 *
 * CZEGO NIE PILNUJE: czy właściciel wpisał wartości w panelu Railway. Tego
 * z repozytorium nie widać — od tego jest `kuking:sprawdz-model` w odbiorze.
 */
class ModeracjaModelemDochodziDoWlasciwychRolTest extends TestCase
{
    private const RAILWAY = '.railway/railway.ts';

    private const RUNBOOK = 'docs/infra/DEPLOYMENT_RUNBOOK.md';

    private const KLUCZ = 'OPENAI_MODERATION_KEY';

    private const ADRES = 'KUKING_MODEL_ALARM_EMAIL';

    /**
     * Jedyni czytelnicy klucza. Nowy czytelnik (np. kontroler) oznacza nową
     * rolę, która potrzebuje referencji — wtedy ten test ma oblać i kazać
     * poprawić `railway.ts`, zanim zrobi to cicho produkcja.
     */
    private const CZYTELNICY_KLUCZA = [
        'app/Console/Commands/SprawdzModel.php',
        'app/Moderacja/KlientOpenAI.php',
    ];

    /** Czytelnicy adresu i role, w których działają. */
    private const CZYTELNICY_ADRESU = [
        'app/Console/Commands/PilnujTerminowOdwolan.php' => 'scheduler',
        'app/Console/Commands/PodsumowanieAutomatu.php' => 'scheduler',
        'app/Domain/Moderation/Actions/AlarmujModeratora.php' => 'worker',
        'app/Domain/Moderation/Actions/AlarmujOPilnymZgloszeniu.php' => 'web',
    ];

    public function test_nazwy_zmiennych_sa_te_same_co_w_konfiguracji(): void
    {
        $config = $this->plik('config/kuking.php');

        $this->assertStringContainsString("'klucz' => env('".self::KLUCZ."')", $config);
        $this->assertStringContainsString("'alarm_email' => env('".self::ADRES."')", $config);
    }

    public function test_lista_czytelnikow_konfiguracji_jest_kompletna(): void
    {
        $this->assertSame(
            self::CZYTELNICY_KLUCZA,
            $this->czytelnicy("config('kuking.moderation.model.klucz')"),
            'Zmienił się zbiór plików czytających klucz modelu. Sprawdź, w której roli '
            .'działa nowy czytelnik, i dopisz referencję '.self::KLUCZ.' do tej usługi w '
            .self::RAILWAY.' — inaczej funkcja wyłączy się tam po cichu.',
        );

        $this->assertSame(
            array_keys(self::CZYTELNICY_ADRESU),
            $this->czytelnicy("config('kuking.moderation.model.alarm_email')"),
            'Zmienił się zbiór plików czytających adres alarmowy. Przypisz nowemu '
            .'czytelnikowi rolę w CZYTELNICY_ADRESU i sprawdź referencję w '.self::RAILWAY.'.',
        );
    }

    /**
     * Klucz czyta tylko kolejka: model wstrzykuje jedynie zadanie
     * `PrzeanalizujTresc`, a zadania wykonuje worker (albo web w roli `all`).
     * Wstrzyknięcie modelu np. do kontrolera oznacza, że rola `web` też
     * potrzebuje klucza — i ma oblać tutaj, a nie po cichu na produkcji.
     */
    public function test_model_wstrzykuje_tylko_zadanie_kolejki(): void
    {
        $wstrzykujacy = [];

        foreach ($this->plikiPhp('app') as $sciezka) {
            if (str_starts_with($sciezka, 'app/Moderacja/')) {
                continue;
            }

            if (preg_match('/\b(?:OcenaModelem|KlientOpenAI)\s+\$/', $this->plik($sciezka)) === 1) {
                $wstrzykujacy[] = $sciezka;
            }
        }

        $this->assertSame(
            ['app/Jobs/PrzeanalizujTresc.php'],
            $wstrzykujacy,
            'Zmienił się zbiór klas używających modelu moderacji. Sprawdź, w której roli '
            .'działa nowa klasa, i daj tej usłudze `kluczModelu` w '.self::RAILWAY.'.',
        );
    }

    public function test_klucz_ma_tylko_worker_i_web_w_roli_all(): void
    {
        $this->assertStringNotContainsString(
            self::KLUCZ,
            $this->appEnv(),
            'Klucz modelu stoi we wspólnym `appEnv`, więc dostałby go też web i scheduler.',
        );

        $this->assertMatchesRegularExpression(
            '/const kluczModelu = isProduction \? ctx\.shared\.'.self::KLUCZ.' : "";/',
            $this->railway(),
            'Klucz modelu ma pochodzić z Shared Variable wyłącznie na produkcji; staging '
            .'i preview mają funkcję jawnie wyłączoną.',
        );

        $this->assertMatchesRegularExpression(
            '/\n\s*'.self::KLUCZ.':\s*kluczModelu,/',
            $this->envUslugi('worker'),
            'Worker wykonuje `PrzeanalizujTresc` i nie dostaje klucza modelu — moderacja '
            .'modelem wyłączy się po cichu po rozdzieleniu usług.',
        );

        $this->assertStringContainsString(
            '...(splitServices ? {} : { '.self::KLUCZ.': kluczModelu })',
            $this->envUslugi('web'),
            'Web w roli `all` obsługuje kolejkę i musi dostać klucz; w roli `web` — nie.',
        );

        $this->assertStringNotContainsString(self::KLUCZ, $this->envUslugi('scheduler'));
    }

    public function test_adres_dostaja_wszystkie_role_ktore_go_czytaja(): void
    {
        $this->assertMatchesRegularExpression(
            '/'.self::ADRES.':\s*isProduction\s*\?\s*ctx\.shared\.'.self::ADRES.'\s*:\s*""/',
            $this->appEnv(),
            'Adres alarmowy ma iść przez wspólne `appEnv` (czytają go web, worker i '
            .'scheduler) i tylko na produkcji.',
        );

        foreach (array_unique(self::CZYTELNICY_ADRESU) as $rola) {
            $this->assertStringContainsString(
                '...appEnv',
                $this->envUslugi($rola),
                "Usługa `{$rola}` czyta adres alarmowy, a nie rozwija `appEnv`.",
            );
        }
    }

    public function test_runbook_wymienia_obie_zmienne_i_odbior(): void
    {
        $runbook = $this->plik(self::RUNBOOK);

        $this->assertMatchesRegularExpression(
            '/^\| `'.self::KLUCZ.'` \|[^\n]*\| \*\*TAK\*\* \|/m',
            $runbook,
            'Pełna lista Shared Variables nie ma '.self::KLUCZ.' jako sekretu (Sealed).',
        );
        $this->assertMatchesRegularExpression(
            '/^\| `'.self::ADRES.'` \|[^\n]*\| nie \|/m',
            $runbook,
            'Pełna lista Shared Variables nie ma '.self::ADRES.'.',
        );

        $krok = $this->sekcja($runbook, '## KROK 8B.');

        foreach (['worker', 'scheduler', 'kuking:sprawdz-model', '/health', self::ADRES] as $kotwica) {
            $this->assertStringContainsString($kotwica, $krok, "KROK 8B nie wspomina „{$kotwica}”.");
        }
    }

    // ------------------------------------------------------------------

    private function railway(): string
    {
        return $this->plik(self::RAILWAY);
    }

    private function appEnv(): string
    {
        return $this->wytnij($this->railway(), 'const appEnv = {', "\n  };");
    }

    /** Blok `env` usługi — od `env:` do końca deklaracji `service(...)`. */
    private function envUslugi(string $rola): string
    {
        $usluga = $this->wytnij($this->railway(), "service(\"{$rola}\", {", "\n  });");

        $poczatek = strrpos($usluga, "\n    env:");
        $this->assertNotFalse($poczatek, "Usługa `{$rola}` w ".self::RAILWAY.' nie ma bloku env.');

        return substr($usluga, $poczatek);
    }

    private function wytnij(string $tekst, string $od, string $do): string
    {
        $poczatek = strpos($tekst, $od);
        $this->assertNotFalse($poczatek, "Nie znaleziono „{$od}” — popraw kotwicę w tym teście.");

        $koniec = strpos($tekst, $do, $poczatek);
        $this->assertNotFalse($koniec, "Nie znaleziono końca bloku po „{$od}”.");

        $blok = substr($tekst, $poczatek, $koniec - $poczatek);
        // Próg: kotwica trafiająca w pusty skrawek nie może dawać zieleni.
        $this->assertGreaterThan(200, strlen($blok), "Blok „{$od}” jest podejrzanie krótki.");

        return $blok;
    }

    private function sekcja(string $tekst, string $naglowek): string
    {
        $poczatek = strpos($tekst, $naglowek);
        $this->assertNotFalse($poczatek, "Runbook nie ma „{$naglowek}”.");

        $koniec = strpos($tekst, "\n## ", $poczatek + 1);

        return substr($tekst, $poczatek, $koniec === false ? null : $koniec - $poczatek);
    }

    /** @return list<string> */
    private function czytelnicy(string $wywolanie): array
    {
        $znalezione = [];

        foreach ($this->plikiPhp('app') as $sciezka) {
            if (str_contains($this->plik($sciezka), $wywolanie)) {
                $znalezione[] = $sciezka;
            }
        }

        sort($znalezione);
        $this->assertNotEmpty($znalezione, "Nikt nie czyta {$wywolanie} — skan nie działa.");

        return $znalezione;
    }

    /** @return list<string> */
    private function plikiPhp(string $katalog): array
    {
        $pliki = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($katalog)));

        foreach ($iterator as $plik) {
            if ($plik->isFile() && $plik->getExtension() === 'php') {
                $pliki[] = ltrim(substr($plik->getPathname(), strlen(base_path())), '/');
            }
        }

        return $pliki;
    }

    private function plik(string $sciezka): string
    {
        $tresc = file_get_contents(base_path($sciezka));
        $this->assertIsString($tresc, "Nie mogę przeczytać {$sciezka}.");

        return $tresc;
    }
}
