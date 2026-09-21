<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\PodstawaDecyzji;
use App\Models\Media;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POMIAR, NIE OPIS: kiedy plik zdjęcia przestaje być dostępny.
 *
 * Kartka `_wspolne/CSAM_JEDNA_KARTKA.md` przeszła sześć wersji bez ani jednego
 * uruchomienia aplikacji. Ten test jest tym uruchomieniem.
 *
 * MIERZY NA DYSKU ZE STEROWNIKIEM `r2` WSKAZANYM NA LOKALNE MinIO. To nie jest
 * ozdobnik: dysk lokalny nie ma podpisów (`providesTemporaryUrls() === false`),
 * więc `MediaController` idzie na nim gałęzią „oddaj plik przez PHP" — czyli
 * NIE tą, którą idzie produkcja. Pytanie „czy wcześniej wydany podpis działa
 * dalej" na dysku lokalnym nie daje się nawet zadać.
 *
 * MATERIAŁ JEST WYGENEROWANY: jednolity prostokąt z `imagecreatetruecolor`,
 * konta testowe, własna baza. Żadnych prawdziwych danych, żadnej produkcji.
 *
 * ZDJĘCIE O DWÓCH RODZICACH ZBUDOWANE WPROST W BAZIE (`attach()` na pivocie
 * `post_media`), bo zwykłą ścieżką użytkownika nie da się go zbudować:
 * `PostController::zebranZdjecia()` filtruje `->whereDoesntHave('posts')`.
 * To jest osobny wynik pomiaru, nie szczegół techniczny.
 *
 * Wymaga MinIO pod adresem z `POMIAR_S3_ENDPOINT`. Bez tej zmiennej test się
 * POMIJA — cicha zamiana pomiaru w „zielony bez pomiaru" jest tu gorsza niż
 * brak testu.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * CO DOPISAŁA RECENZJA ZEWNĘTRZNA (21.09.2026)
 *
 * Pierwsza wersja tego pliku miała DWIE asercje (`200` w stanie 4, `403`
 * w stanie 5), a cała tabela stan × pytający × HTTP z raportu leżała wyłącznie
 * w CSV — czyli zielony wynik nie potwierdzał ani jednego jej wiersza.
 * Recenzent wykazał cztery luki i wszystkie cztery są domknięte tutaj:
 *
 *  1. KAŻDA KRATKA TABELI MA ASERCJĘ (`oczekuj()`), razem z CELEM
 *     przekierowania, plus `assertSoftDeleted` na obu wpisach — bo
 *     `Report::STATUS_RESOLVED` mówi o ZGŁOSZENIU, nie o treści.
 *  2. SESJA WŁAŚCICIELA MIERZONA JEST TĄ SAMĄ SESJĄ, nie nowym `actingAs`.
 *     Właściciel loguje się RAZ, prawdziwym formularzem, PRZED jakąkolwiek
 *     decyzją; dalej wraca wyłącznie z ciasteczkiem sesji (`zmierzStaraSesje()`).
 *     Dopiero to rozstrzyga, czy `User::ban()` unieważnia sesję, czy tylko
 *     strażnik statusu odbija żądanie ZBANOWANEGO konta.
 *     GRANICA: mierzona jest SESJA, nie ciasteczko „zapamiętaj mnie" —
 *     tamto ma własny pomiar w `ZapamietaneLogowanieUniewaznienieTest`.
 *  3. `403` MA UDOWODNIONĄ PRZYCZYNĘ: z samego podpisu czytamy `X-Amz-Date`
 *     i `X-Amz-Expires` (kiedy wystawiony, kiedy gaśnie), zapisujemy TREŚĆ
 *     błędu magazynu, a w tej samej chwili świeży podpis do TEGO SAMEGO pliku
 *     musi dać `200` — inaczej `403` znaczyłoby „pliku nie ma".
 *  4. DOSTĘP MODERATORA MIERZONY JEST BAJTAMI: adres z jego `Location` jest
 *     pobierany i porównywany `sha256` z wygenerowanym obrazem. `302` samo
 *     w sobie potwierdzało przekierowanie, nie dostęp do zawartości.
 *
 * SESJE IDĄ PRZEZ BAZĘ (`session.driver = database`), jak na produkcji
 * (`.env.example`), a nie przez `array` z `phpunit.xml`. Przy `array`
 * `User::invalidateSessions()` wychodzi wcześniej (`config('session.driver')
 * !== 'database'`) i punktu 2 nie da się nawet zadać.
 *
 * DO CSV NIE TRAFIA ANI JEDEN PODPISANY ADRES ANI KLUCZ OBIEKTU — czasy, kody,
 * nagłówki, skróty i treść błędu magazynu tak; `<Key>` z odpowiedzi MinIO nie.
 */
class PomiarOdcieciaDostepuDoPlikuTest extends TestCase
{
    use RefreshDatabase;

    private const DYSK = 'pomiar_r2';

    /** Hasło z `UserFactory` — potrzebne, bo właściciel loguje się NAPRAWDĘ. */
    private const HASLO = 'haslo-testowe-123';

    private const CEL_MAGAZYN = 'magazyn (podpisany adres)';

    /** @var list<array<string, string|int>> */
    private array $pomiary = [];

    /** `sha256` bajtów, które naprawdę wgrano do magazynu. */
    private string $skrotZrodla = '';

    /**
     * Czasy z podpisu wydanego gościowi w stanie 1 i ważność z konfiguracji.
     *
     * Trzymane w polach, a nie przekazywane do `zapisz()`, bo CSV powstaje
     * w `tearDown()` — surowy wynik ma przeżyć TAKŻE porażkę asercji.
     * Przebieg, który obala tabelę raportu, jest najcenniejszym wynikiem,
     * jaki ten test może dać, i akurat on nie może kończyć się bez danych.
     *
     * @var array{wystawiony: string, wygasa: string, waznosc: int}
     */
    private array $czasyGoscia = ['wystawiony' => '(nie zmierzono)', 'wygasa' => '(nie zmierzono)', 'waznosc' => 0];

    private int $minutyPodpisu = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = env('POMIAR_S3_ENDPOINT');

        if (! is_string($endpoint) || $endpoint === '') {
            $this->markTestSkipped('Brak POMIAR_S3_ENDPOINT — pomiar wymaga żywego magazynu S3.');
        }

        config()->set('filesystems.disks.'.self::DYSK, [
            // Ten sam sterownik co produkcja (`App\Support\Storage\DyskR2`).
            'driver' => 'r2',
            'key' => env('POMIAR_S3_KEY'),
            'secret' => env('POMIAR_S3_SECRET'),
            'region' => 'auto',
            'bucket' => env('POMIAR_S3_BUCKET', 'pomiar'),
            'endpoint' => $endpoint,
            // JEDYNA RÓŻNICA WOBEC PRODUKCJI: MinIO adresuje bucket ścieżką,
            // R2 hostem. Nie dotyka to ani podpisywania, ani `GetObject`.
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);

        // SESJE W BAZIE, JAK NA PRODUKCJI. `phpunit.xml` stawia `array`, przy
        // którym `User::invalidateSessions()` nie ma czego kasować i wychodzi
        // pierwszym `return` — a wtedy pytanie „czy ban unieważnia sesję"
        // nie ma w tym teście żadnego mierzalnego znaczenia.
        config()->set('session.driver', 'database');
    }

    protected function tearDown(): void
    {
        // CSV POWSTAJE ZAWSZE, RÓWNIEŻ PO CZERWIENI. Poprzednia wersja
        // zapisywała go dopiero po ostatniej asercji, więc przebieg, który
        // OBALA tabelę raportu, nie zostawiał po sobie żadnej tabeli.
        if ($this->pomiary !== []) {
            $this->zapisz();
        }

        parent::tearDown();
    }

    public function test_kiedy_plik_przestaje_byc_dostepny(): void
    {
        $minuty = max(1, (int) config('kuking.media.signed_url_minutes'));
        $this->minutyPodpisu = $minuty;

        // ---------- materiał: wygenerowany jednolity prostokąt ----------
        $obraz = imagecreatetruecolor(960, 720);
        imagefill($obraz, 0, 0, (int) imagecolorallocate($obraz, 200, 200, 200));
        ob_start();
        imagewebp($obraz, null, 82);
        $bajty = (string) ob_get_clean();
        imagedestroy($obraz);

        $this->skrotZrodla = hash('sha256', $bajty);

        $autor = $this->user('autorpomiaru');
        $moderator = $this->moderator();

        $klucz = 'media/pomiar/'.Str::uuid().'_feed.webp';
        Storage::disk(self::DYSK)->put($klucz, $bajty);

        $zdjecie = Media::factory()->create([
            'owner_id' => $autor->getKey(),
            'disk' => self::DYSK,
            'variants_disk' => self::DYSK,
            'object_key' => $klucz,
            'metadata' => ['variants' => ['feed' => [
                'key' => $klucz, 'width' => 960, 'height' => 720, 'bytes' => strlen($bajty),
            ]]],
        ]);

        // ---------- dwie treści, jedno zdjęcie ----------
        $wpisA = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Treść A pomiaru',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $wpisB = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Treść B pomiaru',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        // WPROST W BAZIE — patrz komentarz klasy.
        $wpisA->media()->attach($zdjecie->getKey(), ['position' => 0]);
        $wpisB->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $adres = route('media.show', ['media' => $zdjecie->getKey(), 'wariant' => 'feed']);

        // ---------- SESJA WŁAŚCICIELA SPRZED JAKIEJKOLWIEK DECYZJI ----------
        // Logowanie jest PRAWDZIWE i JEDNORAZOWE. Od tej chwili właściciel
        // wraca wyłącznie z tym identyfikatorem sesji — bez `actingAs`, czyli
        // bez odtwarzania uwierzytelnienia, które ban miałby właśnie zabrać.
        $sesjaWlasciciela = $this->zalogujNaprawde($autor);

        // ---------- STAN 1: kontrola dodatnia ----------
        $podpis = $this->stan('1-przed-czymkolwiek', $adres, $autor, $moderator);
        $this->zmierzStaraSesje('1-przed-czymkolwiek', $adres, $sesjaWlasciciela);

        $this->assertNotNull(
            $podpis,
            'KONTROLA DODATNIA PADŁA: gość nie dostał adresu przed żadną decyzją — pomiar nieważny.',
        );

        $this->zmierzPodpis('1-przed-czymkolwiek', $podpis);
        $this->assertTabelaStanuOtwartego('1-przed-czymkolwiek');

        // Podpis SAM MÓWI, na jak długo został wystawiony. Bez tego „wygasł po
        // pięciu minutach" opierałoby się na `sleep()` i na konfiguracji, a nie
        // na tym, co poszło do magazynu.
        $this->czasyGoscia = $this->czasyPodpisu($podpis);

        $this->assertSame(
            $minuty * 60,
            $this->czasyGoscia['waznosc'],
            'Podpis deklaruje w `X-Amz-Expires` inną ważność niż `kuking.media.signed_url_minutes`.',
        );

        // ---------- STAN 2: `Usuń treść` na wpisie A ----------
        $this->decyzja($moderator, 'post', (string) $wpisA->getKey(), 'remove');

        // `Report::STATUS_RESOLVED` mówi o ZGŁOSZENIU. O treści mówi dopiero to:
        $this->assertSoftDeleted('posts', ['id' => $wpisA->getKey()]);

        $this->stan('2-po-usunieciu-A', $adres, $autor, $moderator);
        $this->zmierzStaraSesje('2-po-usunieciu-A', $adres, $sesjaWlasciciela);
        $this->zmierzPodpis('2-po-usunieciu-A', $podpis);
        $this->assertTabelaStanuOtwartego('2-po-usunieciu-A');

        // ---------- STAN 3: ban konta autora ----------
        $this->decyzja($moderator, 'user', (string) $autor->getKey(), 'ban');
        $this->assertSame(User::STATUS_BANNED, $autor->fresh()?->status);

        // WPROST: czy wiersz sesji sprzed banu jeszcze istnieje. Asercja stoi
        // PRZED pomiarem HTTP, bo żądanie z tym samym ciasteczkiem zapisze
        // pod tym identyfikatorem nową, pustą sesję i zatarłoby ślad.
        $this->assertDatabaseMissing('sessions', ['id' => $sesjaWlasciciela]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $autor->getKey()]);

        $this->stan('3-po-banie-autora', $adres, $autor, $moderator);
        $this->zmierzStaraSesje('3-po-banie-autora', $adres, $sesjaWlasciciela);
        $this->zmierzPodpis('3-po-banie-autora', $podpis);
        $this->assertTabelaStanuZamknietego('3-po-banie-autora');

        // ---------- STAN 4: usunięcie wpisu B ----------
        $this->decyzja($moderator, 'post', (string) $wpisB->getKey(), 'remove');
        $this->assertSoftDeleted('posts', ['id' => $wpisB->getKey()]);

        $this->stan('4-po-usunieciu-B', $adres, $autor, $moderator);
        $this->zmierzStaraSesje('4-po-usunieciu-B', $adres, $sesjaWlasciciela);
        $this->zmierzPodpis('4-po-usunieciu-B', $podpis);
        $this->assertTabelaStanuZamknietego('4-po-usunieciu-B');

        // ---------- STAN 5: po wygaśnięciu podpisu ----------
        // Czekamy PRAWDZIWE tyle, ile mówi SAM PODPIS (`X-Amz-Expires`),
        // a nie tyle, ile mówi konfiguracja, plus 20 s zapasu.
        sleep($this->czasyGoscia['waznosc'] + 20);

        $this->zmierzPodpis('5-po-wygasnieciu-podpisu', $podpis);

        // KONTROLA DODATNIA DO `403`: ten sam plik, ten sam magazyn, ta sama
        // chwila — tylko podpis świeży. Bez niej `403` mogłoby znaczyć
        // „obiekt zniknął", a nie „podpis wygasł".
        $swiezy = Storage::disk(self::DYSK)->temporaryUrl($klucz, now()->addMinutes($minuty));
        $this->zmierzPodpis('5-po-wygasnieciu-podpisu', $swiezy, 'kontrola dodatnia (świeży podpis)');

        // ---------- co ma być prawdą po wszystkim ----------
        $this->assertSame(
            200,
            $this->kod('4-po-usunieciu-B', 'gość (stary podpis)'),
            'Stary podpis przestał działać po usunięciu obu treści i banie — ZMIANA wobec pomiaru z 21.09.2026.',
        );

        $this->assertSame(
            403,
            $this->kod('5-po-wygasnieciu-podpisu', 'gość (stary podpis)'),
            'Podpis nie wygasł po '.$minuty.' min — ZMIANA wobec pomiaru z 21.09.2026.',
        );

        $this->assertSame(
            200,
            $this->kod('5-po-wygasnieciu-podpisu', 'kontrola dodatnia (świeży podpis)'),
            'KONTROLA DODATNIA PADŁA: świeży podpis też nie oddaje pliku, więc `403` wyżej '
                .'nie dowodzi wygaśnięcia — dowodzi tylko, że plik jest nieosiągalny.',
        );

        // Magazyn ma POWIEDZIEĆ, że chodzi o czas. „AccessDenied" bez słowa
        // o wygaśnięciu znaczyłby, że odcięło coś innego niż zegar.
        $this->assertMatchesRegularExpression(
            '/expired/i',
            (string) $this->pole('5-po-wygasnieciu-podpisu', 'gość (stary podpis)', 'blad'),
            'Magazyn odmówił, ale jego odpowiedź nie mówi o wygaśnięciu — przyczyną `403` może być co innego.',
        );
    }

    /**
     * Stany, w których zdjęcie widzą jeszcze wszyscy trzej pytający.
     *
     * Cel przekierowania jest częścią asercji, nie ozdobą: `302` na `/login`
     * i `302` na magazyn to dwa przeciwne wyniki pod tym samym kodem.
     */
    private function assertTabelaStanuOtwartego(string $stan): void
    {
        $this->oczekuj($stan, 'gość', 302, self::CEL_MAGAZYN);
        $this->oczekuj($stan, 'właściciel zdjęcia', 302, self::CEL_MAGAZYN);
        $this->oczekuj($stan, 'moderator', 302, self::CEL_MAGAZYN);
        $this->oczekuj($stan, 'właściciel (sesja sprzed banu)', 302, self::CEL_MAGAZYN);
        $this->assertBajtyModeratora($stan);
    }

    /**
     * Stany po banie autora: gościa trasa odcina, moderatora nie.
     *
     * Właściciel jest tu zmierzony DWIEMA drogami, bo to dwa różne pytania:
     *  - `właściciel zdjęcia` to świeże `actingAs` ZBANOWANEGO konta, czyli
     *    reakcja strażnika statusu na nowe żądanie;
     *  - `właściciel (sesja sprzed banu)` to ta sama sesja, co przed banem.
     */
    private function assertTabelaStanuZamknietego(string $stan): void
    {
        $this->oczekuj($stan, 'gość', 404);
        $this->oczekuj($stan, 'właściciel zdjęcia', 302, url('/login'));
        $this->oczekuj($stan, 'moderator', 302, self::CEL_MAGAZYN);

        // WYNIK, NIE ŻYCZENIE: sesja sprzed banu została skasowana, więc to
        // żądanie jest żądaniem GOŚCIA — a gość w tym stanie dostaje 404.
        // Gdyby sesja przeżyła, byłoby tu `302 → /login` (odbicie strażnika
        // statusu) i ta asercja by o tym powiedziała.
        $this->oczekuj($stan, 'właściciel (sesja sprzed banu)', 404);

        $this->assertNotSame(
            self::CEL_MAGAZYN,
            $this->cel($stan, 'właściciel (sesja sprzed banu)'),
            'Sesja wydana przed banem nadal prowadzi do pliku w magazynie.',
        );

        $this->assertBajtyModeratora($stan);
    }

    /**
     * `302` moderatora potwierdza przekierowanie. Dopiero pobranie adresu
     * z jego `Location` i zgodność `sha256` potwierdza DOSTĘP DO BAJTÓW.
     */
    private function assertBajtyModeratora(string $stan): void
    {
        $this->assertSame(
            200,
            $this->kod($stan, 'moderator (bajty z magazynu)'),
            'Moderator dostał przekierowanie, ale pod jego adresem nie ma pliku — stan '.$stan.'.',
        );

        $this->assertSame(
            $this->skrotZrodla,
            (string) $this->pole($stan, 'moderator (bajty z magazynu)', 'skrot'),
            'Moderator pobrał BAJTY INNE niż wgrane — stan '.$stan.'.',
        );
    }

    private function oczekuj(string $stan, string $kto, int $kod, ?string $cel = null): void
    {
        $this->assertSame(
            $kod,
            $this->kod($stan, $kto),
            'Stan '.$stan.', '.$kto.': inny kod HTTP niż w tabeli raportu — ZMIANA wobec pomiaru z 21.09.2026.',
        );

        if ($cel !== null) {
            $this->assertSame(
                $cel,
                $this->cel($stan, $kto),
                'Stan '.$stan.', '.$kto.': przekierowanie prowadzi gdzie indziej niż w tabeli raportu.',
            );
        }
    }

    /**
     * Jedno przejście trasy aplikacji trzema pytającymi. Zwraca podpisany
     * adres z nagłówka `Location` dla gościa, o ile go dostał.
     */
    private function stan(string $stan, string $adres, User $autor, User $moderator): ?string
    {
        $podpis = null;

        foreach ([
            'gość' => null,
            'właściciel zdjęcia' => $autor,
            'moderator' => $moderator,
        ] as $kto => $widz) {
            $this->wyloguj();

            if ($widz !== null) {
                $this->actingAs($widz->fresh());
            }

            $odpowiedz = $this->get($adres);
            $cel = (string) $odpowiedz->headers->get('Location');

            // DOKĄD prowadzi przekierowanie. Bez tego 302 na `/login`
            // (sesja unieważniona banem) policzyłoby się jako „widzi
            // zdjęcie" — dokładnie ten rodzaj fałszywego potwierdzenia,
            // przed którym ostrzega `CZYTAJ-TO-NAJPIERW.md`.
            $this->zapiszPomiar(
                $stan,
                $kto,
                'trasa aplikacji',
                $odpowiedz->getStatusCode(),
                (string) $odpowiedz->headers->get('Cache-Control'),
                $this->nazwijCel($cel),
            );

            if ($kto === 'gość' && $odpowiedz->getStatusCode() === 302) {
                $podpis = $cel;
            }

            // MODERATOR: idziemy jego adresem po BAJTY (luka 4 z recenzji).
            if ($kto === 'moderator' && $odpowiedz->getStatusCode() === 302 && $cel !== '') {
                $this->zmierzBajty($stan, 'moderator (bajty z magazynu)', $cel);
            }
        }

        return $podpis;
    }

    /**
     * Ta sama sesja, którą właściciel dostał PRZED banem — bez `actingAs`.
     *
     * To jest cały sens tego pomiaru: `actingAs($autor->fresh())` odtwarza
     * uwierzytelnienie z modelu i mierzy, jak aplikacja odpowiada ZBANOWANEMU
     * użytkownikowi. Tu wraca wyłącznie ciasteczko sesji, więc mierzone jest
     * to, o co chodziło: czy ta sesja jeszcze działa.
     *
     * `defaultCookies` jest podmieniane i przywracane, żeby ciasteczko nie
     * wyciekło do pozostałych żądań tego testu.
     */
    private function zmierzStaraSesje(string $stan, string $adres, string $sesja): void
    {
        $this->wyloguj();

        $poprzednie = $this->defaultCookies;
        $this->defaultCookies = [];
        $this->withCookie((string) config('session.cookie'), $sesja);

        $odpowiedz = $this->get($adres);

        $this->defaultCookies = $poprzednie;

        $this->zapiszPomiar(
            $stan,
            'właściciel (sesja sprzed banu)',
            'trasa aplikacji (stara sesja)',
            $odpowiedz->getStatusCode(),
            (string) $odpowiedz->headers->get('Cache-Control'),
            $this->nazwijCel((string) $odpowiedz->headers->get('Location')),
        );
    }

    /** Ten sam podpisany adres magazynu, sprawdzany PRAWDZIWYM żądaniem HTTP. */
    private function zmierzPodpis(string $stan, ?string $podpis, string $kto = 'gość (stary podpis)'): void
    {
        if ($podpis === null) {
            $this->zapiszPomiar($stan, $kto, 'podpisany adres magazynu', 0, 'BRAK ADRESU');

            return;
        }

        $odpowiedz = $this->zadanie($podpis);
        $czasy = $this->czasyPodpisu($podpis);

        preg_match('/^cache-control:\s*(.+)$/mi', $odpowiedz['naglowki'], $dopasowanie);

        $this->zapiszPomiar(
            $stan,
            $kto,
            'podpisany adres magazynu',
            $odpowiedz['kod'],
            trim($dopasowanie[1] ?? '(brak)'),
            '-',
            [
                'podpis_od' => $czasy['wystawiony'],
                'podpis_do' => $czasy['wygasa'],
                // TREŚĆ ODMOWY, nie samo `403` (luka 3 z recenzji).
                'blad' => $odpowiedz['kod'] >= 400
                    ? $this->bladMagazynu($odpowiedz['cialo'])
                    : '-',
                'skrot' => $odpowiedz['kod'] === 200 ? hash('sha256', $odpowiedz['cialo']) : '-',
            ],
        );
    }

    /** Pobranie bajtów podanym adresem i porównanie ich `sha256` ze źródłem. */
    private function zmierzBajty(string $stan, string $kto, string $adres): void
    {
        $odpowiedz = $this->zadanie($adres);

        preg_match('/^cache-control:\s*(.+)$/mi', $odpowiedz['naglowki'], $dopasowanie);

        $this->zapiszPomiar(
            $stan,
            $kto,
            'podpisany adres magazynu',
            $odpowiedz['kod'],
            trim($dopasowanie[1] ?? '(brak)'),
            '-',
            [
                'blad' => $odpowiedz['kod'] >= 400 ? $this->bladMagazynu($odpowiedz['cialo']) : '-',
                'skrot' => $odpowiedz['kod'] === 200 ? hash('sha256', $odpowiedz['cialo']) : '-',
            ],
        );
    }

    /** @return array{kod: int, naglowki: string, cialo: string} */
    private function zadanie(string $adres): array
    {
        $uchwyt = curl_init($adres);
        curl_setopt_array($uchwyt, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $wynik = (string) curl_exec($uchwyt);
        $kod = (int) curl_getinfo($uchwyt, CURLINFO_RESPONSE_CODE);
        $dlugosc = (int) curl_getinfo($uchwyt, CURLINFO_HEADER_SIZE);
        curl_close($uchwyt);

        return [
            'kod' => $kod,
            'naglowki' => substr($wynik, 0, $dlugosc),
            'cialo' => substr($wynik, $dlugosc),
        ];
    }

    /**
     * Kiedy podpis został wystawiony i kiedy gaśnie — CZYTANE Z NIEGO SAMEGO.
     *
     * `X-Amz-Date` i `X-Amz-Expires` są jawną częścią adresu SigV4 i nie są
     * sekretem (sekretem jest `X-Amz-Signature`, którego ten test nigdzie nie
     * wypisuje). Dzięki temu „wygasł po pięciu minutach" opiera się na tym, co
     * poszło do magazynu, a nie na naszym `sleep()` i naszej konfiguracji.
     *
     * @return array{wystawiony: string, wygasa: string, waznosc: int}
     */
    private function czasyPodpisu(string $podpis): array
    {
        parse_str((string) parse_url($podpis, PHP_URL_QUERY), $parametry);

        $data = is_string($parametry['X-Amz-Date'] ?? null) ? $parametry['X-Amz-Date'] : '';
        $waznosc = (int) ($parametry['X-Amz-Expires'] ?? 0);

        $wystawiony = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $data, new DateTimeZone('UTC'));

        if ($wystawiony === false || $waznosc <= 0) {
            return ['wystawiony' => '(brak w adresie)', 'wygasa' => '(brak w adresie)', 'waznosc' => 0];
        }

        return [
            'wystawiony' => $wystawiony->format('Y-m-d H:i:s').'Z',
            'wygasa' => $wystawiony->modify('+'.$waznosc.' seconds')->format('Y-m-d H:i:s').'Z',
            'waznosc' => $waznosc,
        ];
    }

    /**
     * Co magazyn mówi przy odmowie.
     *
     * Do CSV idą WYŁĄCZNIE `<Code>` i `<Message>`. `<Key>` z tej samej
     * odpowiedzi zawiera klucz obiektu i tu nie trafia — tak samo jak nie
     * trafia tam żaden podpisany adres.
     */
    private function bladMagazynu(string $cialo): string
    {
        preg_match('#<Code>([^<]*)</Code>#', $cialo, $kod);
        preg_match('#<Message>([^<]*)</Message>#', $cialo, $tresc);

        $zlozone = trim(($kod[1] ?? '').' — '.($tresc[1] ?? ''), " \t\n\r—");

        return $zlozone === '' ? '(bez treści błędu)' : $zlozone;
    }

    /**
     * Prawdziwe logowanie formularzem. Zwraca identyfikator sesji z tabeli
     * `sessions` — czyli dokładnie ten wiersz, który `invalidateSessions()`
     * ma skasować.
     */
    private function zalogujNaprawde(User $kto): string
    {
        $this->wyloguj();

        $this->post(route('login'), [
            'login' => $kto->email,
            'password' => self::HASLO,
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($kto);

        $sesja = DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $kto->getKey())
            ->value('id');

        $this->assertIsString(
            $sesja,
            'Logowanie nie zostawiło sesji w bazie — nie ma czego unieważniać, więc pomiar sesji byłby pusty.',
        );

        $this->wyloguj();

        return $sesja;
    }

    private function decyzja(User $moderator, string $typ, string $id, string $akcja): void
    {
        $zgloszenie = Report::create([
            'target_type' => $typ,
            'target_id' => $id,
            'reason' => 'other',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->wyloguj();

        $this->actingAs($moderator->fresh())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => PodstawaDecyzji::KRZYWDZENIE_DZIECI,
                'note' => 'Pomiar odcięcia dostępu do pliku.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->fresh()?->status);
    }

    /**
     * Powrót do stanu „nikt nie jest zalogowany" między pomiarami.
     *
     * `Auth::forgetGuards()` NIE WYSTARCZA, odkąd ten test wysyła żądania
     * z prawdziwym ciasteczkiem sesji. Obiekt sesji jest w kontenerze JEDEN
     * na cały test, a `Store::loadSession()` DOKLEJA dane z magazynu do tego,
     * co już ma (`array_replace`) — więc żądanie „gościa" wykonane zaraz po
     * żądaniu z cudzym ciasteczkiem szło dalej jako TAMTA OSOBA.
     *
     * Zmierzone, nie przewidziane: pierwszy przebieg tej wersji pokazał
     * w stanie 3 gościa z `302 → /login`, czyli z odpowiedzią przeznaczoną
     * dla zbanowanego autora. To jest dokładnie ten rodzaj fałszywego
     * pomiaru, przed którym ostrzega `CZYTAJ-TO-NAJPIERW.md`, i gdyby nie
     * asercja na kod gościa, wszedłby do raportu jako wynik.
     *
     * Identyfikator zmieniamy RAZEM z wyczyszczeniem: samo `flush()`
     * zostawiłoby następnemu żądaniu cudzy identyfikator, pod którym
     * zapisałoby ono pustą sesję — czyli zatarłoby wiersz, o który pytamy.
     */
    private function wyloguj(): void
    {
        Auth::forgetGuards();

        $sesja = $this->app['session.store'];
        $sesja->flush();
        $sesja->setId(null);
    }

    /**
     * Nazwa celu przekierowania. PODPISANY ADRES NIE WYCHODZI POZA PAMIĘĆ
     * TESTU — do pomiaru trafia sama informacja „to był magazyn".
     */
    private function nazwijCel(string $cel): string
    {
        if ($cel === '') {
            return '(bez przekierowania)';
        }

        return str_starts_with($cel, (string) env('POMIAR_S3_ENDPOINT'))
            ? self::CEL_MAGAZYN
            : $cel;
    }

    /** @param  array<string, string>  $dodatkowe */
    private function zapiszPomiar(
        string $stan,
        string $kto,
        string $droga,
        int $kod,
        string $cache,
        string $cel = '-',
        array $dodatkowe = [],
    ): void {
        $this->pomiary[] = array_merge([
            'czas' => (new DateTimeImmutable)->format('H:i:s.v'),
            'stan' => $stan,
            'kto' => $kto,
            'droga' => $droga,
            'kod' => $kod,
            'cache' => $cache === '' ? '(brak)' : $cache,
            'cel' => $cel,
            'podpis_od' => '-',
            'podpis_do' => '-',
            'blad' => '-',
            'skrot' => '-',
        ], $dodatkowe);
    }

    private function kod(string $stan, string $kto): ?int
    {
        $pole = $this->pole($stan, $kto, 'kod');

        return $pole === null ? null : (int) $pole;
    }

    private function cel(string $stan, string $kto): ?string
    {
        $pole = $this->pole($stan, $kto, 'cel');

        return $pole === null ? null : (string) $pole;
    }

    private function pole(string $stan, string $kto, string $kolumna): string|int|null
    {
        foreach ($this->pomiary as $pomiar) {
            if ($pomiar['stan'] === $stan && $pomiar['kto'] === $kto) {
                return $pomiar[$kolumna] ?? null;
            }
        }

        return null;
    }

    private function zapisz(): void
    {
        $kolumny = ['czas', 'stan', 'kto', 'droga', 'kod', 'cache', 'cel', 'podpis_od', 'podpis_do', 'blad', 'skrot'];

        $wiersze = ['czas;stan;kto;droga;kod_http;cache_control;cel;podpis_wystawiony;podpis_wygasa;blad_magazynu;sha256'];

        foreach ($this->pomiary as $pomiar) {
            $wiersz = [];

            foreach ($kolumny as $kolumna) {
                // Średnik w treści błędu rozjechałby kolumny.
                $wiersz[] = str_replace(';', ',', (string) $pomiar[$kolumna]);
            }

            $wiersze[] = implode(';', $wiersz);
        }

        $naglowek = '# signed_url_minutes='.$this->minutyPodpisu.'; dysk='.self::DYSK
            .'; sterownik=r2 (MinIO, path-style)'
            .'; sesje=database'
            .'; podpis_gosc_wystawiony='.$this->czasyGoscia['wystawiony']
            .'; podpis_gosc_wygasa='.$this->czasyGoscia['wygasa']
            .'; X-Amz-Expires='.$this->czasyGoscia['waznosc'].'s'
            .'; sha256_zrodla='.$this->skrotZrodla;

        file_put_contents(
            (string) env('POMIAR_WYNIK', storage_path('pomiar-odciecia.csv')),
            $naglowek."\n".implode("\n", $wiersze)."\n",
        );
    }
}
