<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Każda rola aplikacji dostaje tylko te sekrety, których jej kod używa (#1013).
 *
 * DLACZEGO TEN PLIK ISTNIEJE
 * Do 24 IX 2026 `.railway/railway.ts` rozwijał jeden wspólny `appEnv`
 * w usługach `web`, `worker` i `scheduler`. Każda z trzech ról dostawała
 * 27 referencji do Shared Variables: worker dekodujący nieufne zdjęcia
 * trzymał sekret OAuth Google i Facebooka oraz Turnstile, scheduler to samo
 * plus pocztę, a web token odczytu bucketa kopii bazy. Dopisanie kolejnego
 * sekretu do `appEnv` rozszerzało dostęp wszystkich trzech ról naraz i nie
 * oblewało żadnego testu. Jedyna kontrola najmniejszych uprawnień
 * (`KopiaBazyPozaRailwayemTest`) kończyła się na czwartej usłudze.
 *
 * CO TEN TEST SPRAWDZA
 * Czyta PRAWDZIWE bloki `const …Env = { … }` i PRAWDZIWE `env:` usług,
 * rozwija spready i porównuje zbiór referencji `ctx.shared` każdej roli
 * z ZAMKNIĘTĄ macierzą niżej — dokładnie, w obie strony: brak wymaganej
 * zmiennej oblewa tak samo jak nadmiarowa. Macierz nie jest przepisana
 * z pliku, tylko z kodu: przy każdej zmiennej stoi jej konsument, a test
 * sprawdza, że `config/` naprawdę ją czyta.
 *
 * CZEGO NIE DOWODZI: że panel Railwaya wygląda tak samo. `railway config apply`
 * nie zostało jeszcze uruchomione, więc na produkcji obowiązują zmienne
 * ustawione ręcznie. Instrukcja dla właściciela:
 * `docs/security/SEKRETY_PER_USLUGA_1013.md` §4.
 */
class SekretyPerUslugaTest extends TestCase
{
    private const RAILWAY = '.railway/railway.ts';

    private const DOKUMENT = 'docs/security/SEKRETY_PER_USLUGA_1013.md';

    /**
     * Zmienna w kontenerze → role, które ją dostają, i powód z kodu.
     *
     * Kluczem jest nazwa, którą czyta `config/` (lewa strona w `railway.ts`),
     * nie nazwa Shared Variable w panelu (prawa strona).
     *
     * @var array<string, array{role: list<string>, powod: string}>
     */
    private const MACIERZ = [
        'APP_KEY' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'szyfrowanie sesji, 2FA i zadań kolejki — każda rola'],
        'LOG_BLAD_WEBHOOK_URL' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'kanał `blad_webhook` w config/logging.php — błąd może paść w każdej roli'],

        'AWS_ACCESS_KEY_ID' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'dyski r2/r2_publiczne/r2_eksporty; scheduler kasuje pliki w KasujZdjecie i CleanUpDataExports'],
        'AWS_SECRET_ACCESS_KEY' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'jak AWS_ACCESS_KEY_ID'],
        'AWS_BUCKET' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'oryginały: upload Livewire (web), ProcessUploadedImage (worker), sprzątanie (scheduler)'],
        'AWS_PUBLIC_BUCKET' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'warianty: podpisane adresy (web), zapis wariantów (worker), kasowanie (scheduler)'],
        'AWS_EXPORTS_BUCKET' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'paczki RODO: pobranie (web), budowa (worker), kuking:sprzataj-eksporty (scheduler)'],
        'AWS_ENDPOINT' => ['role' => ['web', 'worker', 'scheduler'], 'powod' => 'endpoint wszystkich dysków R2, także r2_kopie'],

        'AWS_KOPIE_BUCKET' => ['role' => ['scheduler'], 'powod' => 'kuking:sprawdz-kopie (StanKopiiBazy) — tylko harmonogram'],
        'AWS_KOPIE_ACCESS_KEY_ID' => ['role' => ['scheduler'], 'powod' => 'jak AWS_KOPIE_BUCKET'],
        'AWS_KOPIE_SECRET_ACCESS_KEY' => ['role' => ['scheduler'], 'powod' => 'jak AWS_KOPIE_BUCKET'],

        'EMAILLABS_APP_KEY' => ['role' => ['web', 'worker'], 'powod' => 'OdpowiedzNaWiadomosc idzie synchronicznie z web, Poczta::dziala() w kontrolerach; reszta listów w workerze'],
        'EMAILLABS_SECRET_KEY' => ['role' => ['web', 'worker'], 'powod' => 'jak EMAILLABS_APP_KEY'],
        'EMAILLABS_SMTP_ACCOUNT' => ['role' => ['web', 'worker'], 'powod' => 'jak EMAILLABS_APP_KEY'],

        'TURNSTILE_SITE_KEY' => ['role' => ['web'], 'powod' => 'widget na formularzach publicznych'],
        'TURNSTILE_SECRET_KEY' => ['role' => ['web'], 'powod' => 'KlientTurnstile w regule TurnstileJestPotwierdzony — tylko żądania HTTP'],
        'GOOGLE_CLIENT_ID' => ['role' => ['web'], 'powod' => 'GoogleLoginController'],
        'GOOGLE_CLIENT_SECRET' => ['role' => ['web'], 'powod' => 'KlientGoogle wołany z GoogleLoginController'],
        'FACEBOOK_CLIENT_ID' => ['role' => ['web'], 'powod' => 'wejście kontem Facebooka'],
        'FACEBOOK_CLIENT_SECRET' => ['role' => ['web'], 'powod' => 'wejście kontem Facebooka i podpis żądania usunięcia danych — trasy web'],
        'CLOUDFLARE_ANALYTICS_TOKEN' => ['role' => ['web'], 'powod' => 'beacon w HTML-u stron (AnalitykaCloudflare)'],
    ];

    /**
     * Sekrety bez wdrożonego konsumenta. Nie wolno ich rozsyłać „na zapas".
     */
    private const USUNIETE_Z_RUNTIMEU = [
        'SENTRY_LARAVEL_DSN' => 'Sentry nie jest zainstalowany (TabelaStackuMowiPrawdeTest)',
        'POSTHOG_KEY' => 'w kodzie nie ma klienta PostHoga',
        'MAIL_HOST' => 'MAIL_MAILER to emaillabs; SMTP jest na planie Hobby zablokowane',
        'MAIL_PORT' => 'jak MAIL_HOST',
        'MAIL_USERNAME' => 'jak MAIL_HOST',
        'MAIL_PASSWORD' => 'jak MAIL_HOST',
    ];

    #[Test]
    public function kazda_rola_dostaje_dokladnie_swoje_sekrety(): void
    {
        $role = $this->roleZPliku($this->railway());

        foreach (['web', 'worker', 'scheduler'] as $rola) {
            $this->assertEqualsCanonicalizing(
                $this->oczekiwane($rola),
                $role[$rola],
                "Rola `{$rola}` w ".self::RAILWAY.' dostaje inny zestaw referencji `ctx.shared` '
                .'niż macierz w tym teście. Nowy sekret dopisz do zestawu roli, której kod go '
                .'czyta (np. `workerEnv`), a nie do wspólnego `appEnv`, i dopisz go do MACIERZ '
                .'razem z konsumentem (#1013).',
            );
        }
    }

    #[Test]
    public function worker_i_scheduler_nie_maja_sekretow_wejscia(): void
    {
        $role = $this->roleZPliku($this->railway());

        foreach (['worker', 'scheduler'] as $rola) {
            foreach (['GOOGLE_CLIENT_SECRET', 'FACEBOOK_CLIENT_SECRET', 'TURNSTILE_SECRET_KEY'] as $sekret) {
                $this->assertNotContains(
                    $sekret,
                    $role[$rola],
                    "Rola `{$rola}` nie obsługuje logowania ani formularzy, a dostaje {$sekret}. "
                    .'Przejęcie workera (nieufne pliki) dawałoby wtedy możliwość podszycia się '
                    .'pod logowanie społecznościowe (#1013).',
                );
            }
        }

        $this->assertNotContains('EMAILLABS_SECRET_KEY', $role['scheduler'], 'Scheduler tylko kolejkuje listy — klucz API poczty ma worker.');
    }

    #[Test]
    public function web_nie_czyta_bucketa_kopii(): void
    {
        $role = $this->roleZPliku($this->railway());

        foreach (['AWS_KOPIE_BUCKET', 'AWS_KOPIE_ACCESS_KEY_ID', 'AWS_KOPIE_SECRET_ACCESS_KEY'] as $zmienna) {
            $this->assertNotContains(
                $zmienna,
                $role['web'],
                'Po podziale usług `kuking:sprawdz-kopie` uruchamia wyłącznie scheduler. '
                ."Web nie potrzebuje {$zmienna}.",
            );
            $this->assertContains($zmienna, $role['scheduler'], "Scheduler bez {$zmienna} nie sprawdzi świeżości kopii.");
        }
    }

    #[Test]
    public function rola_all_dostaje_sume_trzech_rol(): void
    {
        $role = $this->roleZPliku($this->railway());

        $suma = array_values(array_unique(array_merge($role['web'], $role['worker'], $role['scheduler'])));

        $this->assertEqualsCanonicalizing(
            $suma,
            $role['all'],
            'Staging i preview chodzą jako JEDEN kontener w roli `all` — robią pracę web, workera '
            .'i schedulera naraz. Brak którejś zmiennej psuje tam jedną z tych prac, nadmiar '
            .'daje sekret, którego nie potrzebuje żadna rola.',
        );
    }

    #[Test]
    public function kazdy_sekret_ma_konsumenta_w_konfiguracji(): void
    {
        $konfiguracja = '';
        foreach (glob(base_path('config/*.php')) ?: [] as $plik) {
            $konfiguracja .= (string) file_get_contents($plik);
        }

        $this->assertNotSame('', $konfiguracja, 'Nie znalazłem plików config/*.php.');

        foreach (array_keys(self::MACIERZ) as $zmienna) {
            $this->assertStringContainsString(
                "env('{$zmienna}'",
                $konfiguracja,
                "{$zmienna} jest rozsyłana do ról, ale `config/` jej nie czyta. Sekret bez "
                .'konsumenta to tylko powierzchnia ataku — usuń referencję z railway.ts.',
            );
        }
    }

    #[Test]
    public function uspione_sekrety_nie_trafiaja_do_zadnej_roli(): void
    {
        $kod = $this->bezKomentarzy($this->railway());

        foreach (self::USUNIETE_Z_RUNTIMEU as $zmienna => $powod) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.$zmienna.':\s*ctx\.shared\./',
                $kod,
                "{$zmienna} wróciła do railway.ts, a nie ma wdrożonego konsumenta ({$powod}). "
                .'Uśpiony sekret to nadal sekret w każdym kontenerze (#1013).',
            );
        }
    }

    #[Test]
    public function serwis_kopii_nie_rozwija_zadnego_zestawu_aplikacji(): void
    {
        $env = $this->envUslugi($this->bezKomentarzy($this->railway()), 'kopia-bazy');

        $this->assertStringNotContainsString(
            '...',
            $env,
            'Serwis `kopia-bazy` ma zamkniętą listę zmiennych (#193). Żaden zestaw aplikacji '
            .'(`appEnv`, `schedulerEnv`, …) nie może tam trafić spreadem.',
        );
        $this->assertStringContainsString('KOPIA_S3_SEKRET: ctx.shared.R2_KOPIE_SECRET_ACCESS_KEY', $env);
    }

    #[Test]
    public function dokumentacja_opisuje_macierz_i_nie_obiecuje_wspolnego_zestawu(): void
    {
        $dokument = (string) file_get_contents(base_path(self::DOKUMENT));

        foreach (array_keys(self::MACIERZ) as $zmienna) {
            $this->assertStringContainsString("`{$zmienna}`", $dokument, self::DOKUMENT." nie wymienia {$zmienna}.");
        }

        $runbook = (string) file_get_contents(base_path('docs/infra/DEPLOYMENT_RUNBOOK.md'));

        $this->assertStringNotContainsString(
            'rozdziela je do wszystkich serwisów',
            $runbook,
            'Runbook nadal mówi, że railway.ts rozsyła Shared Variables do wszystkich usług. '
            .'Od #1013 zakres zależy od roli.',
        );
        $this->assertStringContainsString('SEKRETY_PER_USLUGA_1013.md', $runbook);
    }

    /**
     * KONTROLA WYKRYWACZA. Bez niej test wyżej mógłby być zielony dlatego, że
     * parser nie widzi zmian w zestawach — a nie dlatego, że zestawy są dobre.
     * Ten sam plik, jedna dopisana linia: sekret OAuth w workerze.
     */
    #[Test]
    public function wykrywacz_widzi_sekret_oauth_dopisany_do_workera(): void
    {
        $zrodlo = $this->railway();
        $przed = $this->roleZPliku($zrodlo);

        $this->assertNotContains('GOOGLE_CLIENT_SECRET', $przed['worker']);
        $this->assertContains('APP_KEY', $przed['worker'], 'Parser nie widzi nawet rdzenia — test nic nie mierzy.');

        $zepsute = preg_replace(
            '/^(  const workerEnv = \{)/m',
            '$1 GOOGLE_CLIENT_SECRET: ctx.shared.GOOGLE_CLIENT_SECRET,',
            $zrodlo,
            1,
            $ile,
        );
        $this->assertSame(1, $ile, 'Nie znalazłem `const workerEnv = {` — popraw kontrolę.');

        $this->assertContains('GOOGLE_CLIENT_SECRET', $this->roleZPliku((string) $zepsute)['worker']);
        $this->assertNotContains('GOOGLE_CLIENT_SECRET', $this->roleZPliku((string) $zepsute)['scheduler']);
    }

    /**
     * @return list<string>
     */
    private function oczekiwane(string $rola): array
    {
        return array_keys(array_filter(
            self::MACIERZ,
            static fn (array $wpis): bool => in_array($rola, $wpis['role'], true),
        ));
    }

    private function railway(): string
    {
        $sciezka = base_path(self::RAILWAY);
        $this->assertFileExists($sciezka);

        return (string) file_get_contents($sciezka);
    }

    /**
     * Komentarze tłumaczą, czego gdzie NIE MA, i padają w nich te same nazwy.
     */
    private function bezKomentarzy(string $zrodlo): string
    {
        $linie = preg_split('/\r\n|\n|\r/', $zrodlo) ?: [];

        return implode("\n", array_map(
            static fn (string $linia): string => str_starts_with(ltrim($linia), '//')
                ? ''
                : (string) preg_replace('#\s//\s.*$#', '', $linia),
            $linie,
        ));
    }

    /**
     * Rola → posortowana lista zmiennych z referencją `ctx.shared`.
     *
     * @return array{web: list<string>, worker: list<string>, scheduler: list<string>, all: list<string>}
     */
    private function roleZPliku(string $zrodlo): array
    {
        $kod = $this->bezKomentarzy($zrodlo);

        preg_match_all('/^  const (\w+Env) = \{(.*?)\};$/ms', $kod, $bloki, PREG_SET_ORDER);
        $zestawy = [];
        foreach ($bloki as [, $nazwa, $tresc]) {
            $zestawy[$nazwa] = $tresc;
        }

        $this->assertArrayHasKey('appEnv', $zestawy, 'Parser nie znalazł `const appEnv = {` w '.self::RAILWAY.'.');

        $webEnv = $this->envUslugi($kod, 'web');
        $this->assertSame(
            1,
            preg_match('/\.\.\.\(splitServices \? (\w+) : (\w+)\)/', $webEnv, $trojka),
            'Serwis `web` ma wybierać zestaw przez `...(splitServices ? webEnv : allEnv)` — '
            .'po podziale rola `web`, bez podziału rola `all`.',
        );

        // Referencje wpisane wprost w `env:` serwisu, poza zestawami, też się liczą.
        $wprost = $this->rozwinTresc($zestawy, $webEnv, []);
        $wynik = [];
        foreach (['web' => $trojka[1], 'all' => $trojka[2]] as $rola => $zestaw) {
            $zmienne = array_values(array_unique([...$this->rozwin($zestawy, $zestaw), ...$wprost]));
            sort($zmienne);
            $wynik[$rola] = $zmienne;
        }

        foreach (['worker', 'scheduler'] as $rola) {
            $wynik[$rola] = $this->rozwinTresc($zestawy, $this->envUslugi($kod, $rola), []);
        }

        return $wynik;
    }

    /**
     * @param  array<string, string>  $zestawy
     * @return list<string>
     */
    private function rozwin(array $zestawy, string $nazwa): array
    {
        $this->assertArrayHasKey($nazwa, $zestawy, "Nie znalazłem `const {$nazwa} = {` w ".self::RAILWAY.'.');

        return $this->rozwinTresc($zestawy, $zestawy[$nazwa], [$nazwa]);
    }

    /**
     * @param  array<string, string>  $zestawy
     * @param  list<string>  $stos
     * @return list<string>
     */
    private function rozwinTresc(array $zestawy, string $tresc, array $stos): array
    {
        preg_match_all('/\b([A-Z][A-Z0-9_]*):\s*ctx\.shared\.\w+/', $tresc, $wlasne);
        $zmienne = $wlasne[1];

        preg_match_all('/\.\.\.(\w+Env)\b/', $tresc, $spready);
        foreach ($spready[1] as $spread) {
            $this->assertNotContains($spread, $stos, "Cykl spreadów: {$spread}.");
            $this->assertArrayHasKey($spread, $zestawy, "Spread `...{$spread}` wskazuje zestaw, którego parser nie znalazł.");
            $zmienne = array_merge($zmienne, $this->rozwinTresc($zestawy, $zestawy[$spread], [...$stos, $spread]));
        }

        $zmienne = array_values(array_unique($zmienne));
        sort($zmienne);

        return $zmienne;
    }

    private function envUslugi(string $kod, string $nazwa): string
    {
        $poczatek = strpos($kod, 'service("'.$nazwa.'", {');
        $this->assertNotFalse($poczatek, "Nie ma deklaracji serwisu `{$nazwa}` w ".self::RAILWAY.'.');

        $koniec = strpos($kod, "\n  });", $poczatek);
        $this->assertNotFalse($koniec, "Nie znalazłem końca serwisu `{$nazwa}`.");

        $blok = substr($kod, $poczatek, $koniec - $poczatek);
        $env = strrpos($blok, "\n    env: {");
        $this->assertNotFalse($env, "Serwis `{$nazwa}` nie ma bloku `env:`.");

        return substr($blok, $env);
    }
}
