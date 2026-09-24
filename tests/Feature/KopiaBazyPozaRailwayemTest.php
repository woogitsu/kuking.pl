<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Warstwa kopii bazy poza Railwayem — niezmienniki, których nie sprawdzi
 * ani test skryptu w powłoce, ani test zachowania aplikacji.
 *
 * DLACZEGO TEN PLIK ISTNIEJE
 * Bo najgroźniejsze usterki tej warstwy nie są usterkami kodu — są
 * rozjazdami między trzema miejscami, które MUSZĄ mówić to samo:
 * skryptem w `docker/kopia/`, deklaracją serwisu w `.railway/railway.ts`
 * i konfiguracją czujki w aplikacji. Rozjazd prefiksu daje czujkę, która
 * zawsze widzi pusty katalog i zawsze krzyczy — czyli alarm, który uczy
 * się ignorować. A dokładnie o alarm chodzi w issue #193.
 *
 * Drugi powód: `railway.ts` i `.env.example` to jedyne miejsca, w których
 * ktoś mógłby PRZEZ PRZYPADEK wpisać klucz. Zrzut bazy jest zaszyfrowany
 * kluczem publicznym; wartość tej ochrony jest równa zeru w chwili, w której
 * klucz prywatny trafia do repozytorium.
 *
 * CZEGO TEN TEST NIE DOWODZI: że kopia powstaje. Serwisu `kopia-bazy` nie ma
 * jeszcze w Railway, bucketu R2 też nie (§7.3). Test czyta pliki, bo tylko
 * tam ta wiedza żyje — panelu Railway nie da się odpytać z PHPUnita.
 */
class KopiaBazyPozaRailwayemTest extends TestCase
{
    private function railway(): string
    {
        $sciezka = base_path('.railway/railway.ts');

        $this->assertFileExists($sciezka, 'Nie ma .railway/railway.ts — popraw ścieżkę w tym teście.');

        return (string) file_get_contents($sciezka);
    }

    /**
     * Ten sam blok, ale BEZ KOMENTARZY.
     *
     * Komentarze mają prawo (i obowiązek) tłumaczyć, czego w tym serwisie
     * świadomie NIE MA — a wtedy padają w nich dokładnie te napisy, których
     * szukamy. Pierwsza wersja tego testu oblewała na własnym komentarzu
     * „ŚWIADOMIE BEZ ...appEnv", czyli na zdaniu opisującym naprawę.
     * Ta sama pułapka i to samo rozwiązanie, co w
     * `tests/skrypty/entrypoint-nadzor.sh` i `OperacjeWdrozeniaCeluja...Test`.
     */
    private function kodSerwisuKopii(): string
    {
        $linie = preg_split('/\r\n|\n|\r/', $this->blokSerwisuKopii()) ?: [];

        $bezKomentarzy = array_filter(
            $linie,
            static fn (string $linia): bool => ! str_starts_with(ltrim($linia), '//'),
        );

        return implode("\n", $bezKomentarzy);
    }

    /** Blok deklaracji serwisu `kopia-bazy`, od `service("kopia-bazy"` do końca jego `env`. */
    private function blokSerwisuKopii(): string
    {
        $railway = $this->railway();

        $poczatek = strpos($railway, 'service("kopia-bazy"');
        $this->assertNotFalse(
            $poczatek,
            'W .railway/railway.ts nie ma deklaracji serwisu „kopia-bazy". '
            .'To JEDYNA planowana kopia bazy (D-043) — bez niej `railway config apply` '
            .'usunąłby ją z projektu w dniu pierwszego uruchomienia.',
        );

        // Serwis kończy się tam, gdzie zaczyna się sekcja kompozycji.
        $koniec = strpos($railway, '//  KOMPOZYCJA', $poczatek);
        $this->assertNotFalse($koniec, 'Nie umiem znaleźć końca deklaracji serwisu kopii.');

        return substr($railway, $poczatek, $koniec - $poczatek);
    }

    #[Test]
    public function serwis_kopii_buduje_sie_z_wlasnego_dockerfilea_bez_php(): void
    {
        $blok = $this->blokSerwisuKopii();

        $this->assertStringContainsString(
            'dockerfilePath: "docker/kopia/Dockerfile"',
            $blok,
            'Serwis kopii musi budować się z WŁASNEGO Dockerfile\'a. Obraz aplikacji '
            .'ma wyłączone `proc_open` (docker/php.ini), bez którego pg_dump z PHP '
            .'nie wystartuje — o tym jest cała decyzja D-043.',
        );

        $this->assertFileExists(base_path('docker/kopia/Dockerfile'));
        $this->assertFileExists(base_path('docker/kopia/kopia-bazy.sh'));
        $this->assertFileExists(base_path('docker/kopia/s3.sh'));
    }

    #[Test]
    public function nieudany_zrzut_nie_wstaje_w_petli(): void
    {
        $blok = $this->blokSerwisuKopii();

        $this->assertMatchesRegularExpression(
            '/cronSchedule:\s*"[^"]+"/',
            $blok,
            'Serwis kopii bez harmonogramu nie zrobi kopii. Ręczne uruchamianie '
            .'raz w tygodniu zostało świadomie odrzucone (D-043): zależy od tego, '
            .'że człowiek pamięta.',
        );

        $this->assertStringContainsString(
            'restartPolicyType: "NEVER"',
            $blok,
            'To jest zadanie jednorazowe. Przy ON_FAILURE nieudany przebieg wstawałby '
            .'w pętli i zrzucał całą bazę co kilkadziesiąt sekund, obciążając produkcję '
            .'w środku awarii.',
        );
    }

    #[Test]
    public function serwis_kopii_nie_dostaje_sekretow_aplikacji(): void
    {
        $blok = $this->kodSerwisuKopii();

        $this->assertStringNotContainsString(
            '...appEnv',
            $blok,
            'Serwis kopii NIE MOŻE dostać `...appEnv`. Trzyma w rękach zrzut całej bazy, '
            .'więc każdy dołożony tam sekret (APP_KEY, klucze bucketów ze zdjęciami, '
            .'poświadczenia poczty) dostaje prawo odczytu do tego procesu. '
            .'Lista zmiennych ma być zamknięta i krótka.',
        );

        // Nie tylko `...appEnv`: od #1013 zestawy są per rola (`webEnv`,
        // `workerEnv`, `schedulerEnv`...), a każdy z nich niesie sekrety
        // aplikacji. Lista kopii ma być zamknięta, więc żadnego rozwinięcia.
        $this->assertStringNotContainsString(
            '...',
            $blok,
            'Serwis kopii nie może dostać ŻADNEGO rozwinięcia zestawu zmiennych (`...xxxEnv`). '
            .'Każdy zestaw aplikacji niesie sekrety, których kontener ze zrzutem bazy nie potrzebuje.',
        );

        foreach (['APP_KEY', 'EMAILLABS_SECRET_KEY', 'SENTRY_LARAVEL_DSN', 'R2_SECRET_ACCESS_KEY', 'OPENAI_MODERATION_KEY'] as $sekret) {
            $this->assertStringNotContainsString(
                $sekret,
                $blok,
                "Serwis kopii nie potrzebuje {$sekret} do niczego — a jego obecność tam "
                .'to poszerzenie powierzchni ataku na kontener ze zrzutem bazy.',
            );
        }
    }

    #[Test]
    public function serwis_kopii_laczy_sie_z_baza_po_sieci_wewnetrznej(): void
    {
        $blok = $this->kodSerwisuKopii();

        $this->assertStringContainsString(
            'DB_URL: db.env.DATABASE_URL',
            $blok,
            'Referencja do serwisu Postgres (host *.railway.internal). Publiczny adres '
            .'(DATABASE_PUBLIC_URL, *.proxy.rlwy.net) wypuszczałby komplet danych osobowych '
            .'przez publiczny internet przy KAŻDYM przebiegu — i nic by o tym nie powiedziało, '
            .'bo kopia nadal by powstawała (#193).',
        );

        $this->assertStringNotContainsString('DATABASE_PUBLIC_URL', $blok);
    }

    #[Test]
    public function aplikacja_dostaje_do_bucketu_kopii_tylko_prawo_odczytu(): void
    {
        $railway = $this->railway();

        // Dwa RÓŻNE tokeny do jednego bucketu. Gdyby aplikacja miała prawo
        // zapisu, udany atak na nią mógłby SKASOWAĆ kopie — czyli dokładnie
        // to, przed czym ta warstwa ma chronić.
        // Aplikacja dostaje token OZNACZONY jako odczytowy — to jest jedyna
        // nazwa, którą wolno tu przypiąć na sztywno, bo od niej zależy, o który
        // z dwóch tokenów z §7.3 chodzi.
        $this->assertStringContainsString('AWS_KOPIE_ACCESS_KEY_ID: ctx.shared.R2_KOPIE_ODCZYT_ACCESS_KEY_ID', $railway);

        // Serwisu kopii NIE przypinamy do nazwy, tylko do RÓŻNICY — i to jest
        // tu sedno. Poprzednia wersja tej asercji porównywała dwa LITERAŁY
        // („R2_KOPIE_ACCESS_KEY_ID" wobec „R2_KOPIE_ODCZYT_ACCESS_KEY_ID"),
        // czyli była prawdziwa niezależnie od zawartości `railway.ts`:
        // przeszłaby również po wpisaniu w oba miejsca JEDNEGO tokenu, czyli
        // dokładnie w stanie, przed którym miała chronić. Czytamy więc plik.
        preg_match('/AWS_KOPIE_ACCESS_KEY_ID:\s*ctx\.shared\.(\w+)/', $railway, $aplikacja);
        preg_match('/KOPIA_S3_KLUCZ:\s*ctx\.shared\.(\w+)/', $railway, $serwisKopii);

        $this->assertNotEmpty($aplikacja, 'Nie umiem odczytać tokenu aplikacji z railway.ts.');
        $this->assertNotEmpty($serwisKopii, 'Nie umiem odczytać tokenu serwisu kopii z railway.ts.');

        $this->assertNotSame(
            $serwisKopii[1],
            $aplikacja[1],
            'Aplikacja i serwis kopii dostają TĘ SAMĄ zmienną sharedową, czyli ten sam token. '
            .'Gdyby aplikacja miała prawo zapisu, udany atak na nią mógłby SKASOWAĆ kopie — '
            .'czyli dokładnie to, przed czym ta warstwa ma chronić.',
        );
    }

    #[Test]
    public function klucz_prywatny_nie_ma_prawa_byc_w_repozytorium(): void
    {
        // Zrzut jest szyfrowany kluczem publicznym. Wartość tej ochrony spada
        // do zera w chwili, w której klucz prywatny trafia do gita — a jest to
        // pomyłka o jedną literę w nazwie pliku (`...-publiczny.pem` kontra
        // `...-PRYWATNY.pem`, KOPIE_I_ODTWORZENIE.md §7.1).
        $pliki = [
            '.railway/railway.ts',
            '.env.example',
            'docker/kopia/kopia-bazy.sh',
            'docker/kopia/s3.sh',
            'docker/kopia/Dockerfile',
            'docs/infra/KOPIE_I_ODTWORZENIE.md',
        ];

        // Szukamy PRAWDZIWEGO bloku PEM (nagłówek + ciało base64), a nie
        // wzmianki o nim. Dokumentacja MUSI móc napisać, że część prywatna
        // zaczyna się od `-----BEGIN PRIVATE KEY-----` — bez tego zdania nie
        // da się ostrzec przed pomyleniem plików, a to jest tu najbardziej
        // prawdopodobna pomyłka.
        $prawdziwyBlok = '/-----BEGIN (?:RSA |ENCRYPTED )?(?:PRIVATE KEY|CERTIFICATE)-----\s*(?:\r\n|\n|\r)[A-Za-z0-9+\/=\s]{40,}-----END/';

        foreach ($pliki as $plik) {
            $tresc = (string) file_get_contents(base_path($plik));

            // Certyfikat (część publiczna) jest tu razem z kluczem prywatnym
            // nie dlatego, że jest sekretem — ale wpisany na stałe przestaje
            // dać się wymienić bez wdrożenia, a rotacja klucza szyfrującego
            // to operacja na zmiennej sharedowej, nie na kodzie.
            $this->assertDoesNotMatchRegularExpression(
                $prawdziwyBlok,
                $tresc,
                "W {$plik} jest wklejony klucz albo certyfikat. Klucz prywatny w repozytorium "
                .'unieważnia CAŁE szyfrowanie kopii bazy, a certyfikat na stałe blokuje jego rotację. '
                .'Oba mieszkają w zmiennych i w menedżerze haseł (§7.1).',
            );
        }

        // Żaden plik `.pem` nie może być w repozytorium w ogóle.
        $this->assertSame(
            [],
            glob(base_path('*.pem')) ?: [],
            'W katalogu głównym leży plik .pem. Klucze kopii mieszkają w menedżerze '
            .'haseł właściciela i na nośniku offline, nigdy w repozytorium (§7.1).',
        );
    }

    #[Test]
    public function czujka_jest_w_harmonogramie(): void
    {
        $nazwy = array_map(
            static fn ($zadanie): string => (string) $zadanie->description,
            app(Schedule::class)->events(),
        );

        $this->assertContains(
            'kuking:sprawdz-kopie',
            $nazwy,
            'Bez tego zadania nikt nie zauważy, że kopie przestały powstawać. '
            .'Serwis kopii alarmuje, gdy jego przebieg się nie udał — ale nie zaalarmuje, '
            .'gdy przebiegu NIE BYŁO. Kod, który wtedy nie chodzi, nie może o sobie donieść.',
        );
    }

    #[Test]
    public function prefiks_w_aplikacji_i_w_serwisie_kopii_to_ta_sama_wartosc(): void
    {
        $blok = $this->blokSerwisuKopii();

        $this->assertMatchesRegularExpression('/KOPIA_PREFIKS:\s*"([^"]+)"/', $blok);
        preg_match('/KOPIA_PREFIKS:\s*"([^"]+)"/', $blok, $trafienia);

        $this->assertSame(
            $trafienia[1],
            (string) config('kuking.kopie.prefiks'),
            'Prefiks, pod który serwis kopii ZAPISUJE, i prefiks, pod którym aplikacja '
            .'SZUKA, muszą być tą samą wartością. Rozjazd daje czujkę, która zawsze widzi '
            .'pusty katalog i codziennie alarmuje bez powodu — a alarm, który zawsze dzwoni, '
            .'przestaje być alarmem.',
        );
    }

    #[Test]
    public function prog_wieku_kopii_jest_ten_sam_po_obu_stronach(): void
    {
        $blok = $this->blokSerwisuKopii();

        preg_match('/KOPIA_ALARM_PO_GODZINACH:\s*"(\d+)"/', $blok, $trafienia);

        $this->assertNotEmpty($trafienia, 'Serwis kopii nie ma progu wieku poprzedniej kopii.');
        $this->assertSame(
            (int) $trafienia[1],
            (int) config('kuking.kopie.maks_wiek_godzin'),
            'Skrypt i czujka mają ten sam próg, żeby nie zdarzyło się, że jedna strona '
            .'uznaje kopię za świeżą, a druga za przestarzałą — wtedy nie wiadomo, '
            .'której wierzyć.',
        );
    }

    #[Test]
    public function retencja_ma_dolna_granice(): void
    {
        $blok = $this->blokSerwisuKopii();

        preg_match('/KOPIA_MINIMUM_KOPII:\s*"(\d+)"/', $blok, $trafienia);

        $this->assertNotEmpty($trafienia, 'Brak KOPIA_MINIMUM_KOPII — retencja bez dolnej granicy.');
        $this->assertGreaterThan(
            1,
            (int) $trafienia[1],
            'Bez dolnej granicy jedna przerwa w działaniu serwisu dłuższa niż okno retencji '
            .'wystarczy, żeby przebieg wznowiony po niej skasował WSZYSTKIE kopie, jakie '
            .'jeszcze były — bo każda byłaby „za stara". Kasowanie ostatniej kopii to nie '
            .'porządki, to utrata danych.',
        );
    }

    #[Test]
    public function dysk_kopii_nie_ma_publicznego_adresu(): void
    {
        $dysk = config('filesystems.disks.r2_kopie');

        $this->assertIsArray($dysk, 'Brak dysku `r2_kopie` — czujka nie ma czego listować.');
        $this->assertArrayNotHasKey(
            'url',
            $dysk,
            'Dysk z kopiami bazy nie może mieć klucza `url`. `Storage::url()` ma tu rzucić '
            .'wyjątek, a nie zwrócić adres, pod którym leży komplet danych osobowych '
            .'wszystkich kont — ta sama zasada, co przy `r2` i `r2_eksporty`.',
        );
    }

    #[Test]
    public function czujka_jest_domyslnie_wylaczona_w_szablonie_srodowiska(): void
    {
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString(
            "AWS_KOPIE_BUCKET=\n",
            $env,
            'W `.env.example` bucket kopii MUSI być pusty. Umowa „brak zmiennej = zero '
            .'efektu" jest tu ważna z dwóch stron: lokalnie i w CI nie ma czego listować, '
            .'a na produkcji pusta wartość znaczy „nikt nie patrzy" i komenda mówi to wprost.',
        );
    }

    // =========================================================================
    //  DOKUMENTACJA — bo procedury odtworzenia nie da się przetestować kodem,
    //  a jej brak jest tu równie groźny jak brak kopii.
    // =========================================================================

    #[Test]
    public function dokument_kopii_opisuje_to_co_naprawde_zbudowano(): void
    {
        $doc = (string) file_get_contents(base_path('docs/infra/KOPIE_I_ODTWORZENIE.md'));

        foreach ([
            'docker/kopia/kopia-bazy.sh',
            '### 7.1',
            '### 7.3',
            '### 7.4',
            'openssl cms -decrypt',
            'kuking:sprawdz-kopie',
        ] as $fragment) {
            $this->assertStringContainsString(
                $fragment,
                $doc,
                "Dokument kopii nie zawiera «{$fragment}». Procedura odtworzenia musi opisywać "
                .'to, co naprawdę stoi w repozytorium — dokument mówiący co innego niż kod '
                .'jest w awarii gorszy niż jego brak (D-038).',
            );
        }
    }

    #[Test]
    public function dokument_mowi_gdzie_mieszka_klucz_i_ze_nie_ma_go_w_railwayu(): void
    {
        $doc = (string) file_get_contents(base_path('docs/infra/KOPIE_I_ODTWORZENIE.md'));

        $this->assertStringContainsString('menedżer haseł', $doc);
        $this->assertStringContainsString('NIGDY w Railwayu', $doc);

        // Utrata klucza prywatnego jest bezpowrotna i MUSI stać w tabeli
        // ryzyk §1.1 obok `APP_KEY`, a nie tylko w opisie procedury.
        $this->assertStringContainsString('Klucz PRYWATNY kopii bazy', $doc);
    }

    #[Test]
    public function dokument_nie_obiecuje_kopii_railwaya_ktorych_nie_ma(): void
    {
        $doc = (string) file_get_contents(base_path('docs/infra/KOPIE_I_ODTWORZENIE.md'));

        // Volume Backups i PITR są funkcjami planu Pro (D-043). Instrukcja
        // „włącz Daily + Weekly + PITR" dawała się ODHACZYĆ, nie będąc prawdą —
        // a odhaczony punkt w checkliście backupów jest gorszy niż jego brak.
        $this->assertStringNotContainsString(
            'włącz Daily+Weekly+PITR teraz',
            $doc,
            'W dokumencie została instrukcja włączenia kopii Railwaya, których na planie '
            .'Free/Hobby nie ma (D-043).',
        );

        $this->assertStringContainsString('planie Pro', $doc);
    }
}
