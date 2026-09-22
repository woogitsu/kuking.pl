<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `kuking:bramka-r2` MA MÓWIĆ PRAWDĘ — także tę niewygodną.
 *
 * Komenda istnieje dlatego, że bramki z issue #120 nie da się sprawdzić
 * z PHP. Jeśli sama zacznie meldować przejście tam, gdzie nic nie
 * sprawdziła, będzie GORSZA od jej braku: właściciel wystawi
 * `cdn.kuking.pl` przed bucketem, mając na ekranie zielony napis.
 *
 * Dlatego każdy test niżej pyta o to samo z innej strony: czy komenda
 * oblewa, gdy powinna. Z dwudziestu przypadków przejście bramki sprawdza
 * pięć, a nieprzejście piętnaście — bo fałszywa zieleń jest tu groźna,
 * a fałszywa czerwień tylko kosztuje wieczór.
 *
 * JEDEN Z TYCH TESTÓW NIE PATRZY NA WYJŚCIE KOMENDY, a na to, co poszło
 * w sieć (`test_bramka_pyta_kazdy_zadeklarowany_adres_o_prawdziwy_klucz`).
 * Wszystkie pozostałe przeszłyby także wtedy, gdyby ktoś zamienił żądania
 * HTTP na `return true` — to pułapka 5 z `docs/PULAPKI_TESTOW.md`:
 * narzędzie potrafi zameldować sukces, nie robiąc nic.
 *
 * PUŁAPKA, KTÓRĄ TE TESTY PILNUJĄ NA WEJŚCIU: na dysku lokalnym każde
 * sprawdzenie tej komendy wychodzi ładnie. Nie ma bucketu, nie ma
 * publicznego adresu, więc „oryginał nie jest publiczny" jest prawdą —
 * tylko o R2 nie mówi nic.
 */
class BramkaR2MowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    /** Klucz oryginału użyty w testach — jeden, żeby dało się go szukać w wyjściu. */
    private const KLUCZ_ORYGINALU = 'incoming/2026/09/rosol.jpg';

    /**
     * Endpoint z identyfikatorem konta — NIE MA prawa wyjść na ekran.
     *
     * KSZTAŁT JURYSDYKCYJNY (`.eu.`), bo tak wygląda środowisko skonfigurowane
     * zgodnie z tym, co polityka prywatności mówi użytkownikom: zdjęcia leżą
     * w Unii Europejskiej. Testy mierzące brak tej gwarancji podstawiają
     * `ENDPOINT_BEZ_JURYSDYKCJI` same.
     */
    private const ENDPOINT = 'https://1a2b3c4d5e6f.eu.r2.cloudflarestorage.com';

    /** Zwykły endpoint konta — bez segmentu jurysdykcji (issue #619). */
    private const ENDPOINT_BEZ_JURYSDYKCJI = 'https://1a2b3c4d5e6f.r2.cloudflarestorage.com';

    /**
     * Zadeklarowane publiczne adresy — droga, którą naprawdę chodzi
     * przeglądarka. Własna domena i `r2.dev` z panelu Cloudflare.
     */
    private const PUBLICZNA_DOMENA = 'https://cdn.test.kuking.pl';

    private const PUBLICZNY_R2_DEV = 'https://pub-test123.r2.dev';

    #[Test]
    public function test_dysk_lokalny_nie_udaje_przejscia_bramki(): void
    {
        Http::fake();

        // Domyślna konfiguracja testów: dysk `public`, czyli lokalny.
        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('Serwis NIE zapisuje zdjęć do R2')
            ->assertExitCode(1);

        // Bramka na dysku lokalnym nie ma prawa nawet zapytać R2 —
        // każde żądanie oznaczałoby, że komenda sprawdza coś innego,
        // niż serwis naprawdę używa.
        Http::assertNothingSent();
    }

    #[Test]
    public function test_jeden_bucket_dla_oryginalow_i_wariantow_oblewa(): void
    {
        $this->ustawR2();
        config(['filesystems.disks.r2_publiczne.bucket' => 'kuking-oryginaly']);

        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('TEN SAM bucket')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_brak_zdjecia_w_bazie_nie_jest_sukcesem(): void
    {
        $this->ustawR2();

        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('nie ma ani jednego gotowego zdjęcia')
            ->expectsOutputToContain('NIEPRZEJŚCIONA')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_zamkniety_bucket_i_pelny_zestaw_wariantow_daje_przejscie(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('Część serwerowa bramki PRZESZŁA')
            // Dowód, a nie zapewnienie: kod odmowy spod obu zadeklarowanych
            // publicznych adresów, wypisany dosłownie.
            ->expectsOutputToContain('Każdy zadeklarowany adres odmówił.')
            ->expectsOutputToContain('cdn.test.kuking.pl: HTTP 403')
            ->expectsOutputToContain('pub-test123.r2.dev: HTTP 403')
            // Nawet po przejściu bramka mówi, czego nie sprawdziła.
            ->expectsOutputToContain('KOMPLETNĄ listę publicznych adresów')
            ->assertExitCode(0);
    }

    #[Test]
    public function test_bez_proby_zapisu_bramka_nie_jest_domknieta(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        // Wszystko inne w porządku, a mimo to nie ma przejścia: dowodu na
        // `PutObject` bez ACL nikt nie przedstawił.
        $this->artisan('kuking:bramka-r2')
            ->expectsOutputToContain('dołóż `--zapis`')
            ->expectsOutputToContain('NIEPRZEJŚCIONA')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_wariant_dostepny_bez_podpisu_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 200, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: bucket wariantów oddaje pliki BEZ podpisu')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_oryginal_do_pobrania_z_endpointu_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 200);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: oryginał z pełnym EXIF-em')
            ->assertExitCode(1);
    }

    /**
     * KONTROLA UJEMNA CAŁEGO ZADANIA: podstawione „publiczne" wiadro.
     *
     * Bucket oddaje oryginał pod własną domeną — tą, którą naprawdę chodzi
     * przeglądarka. To jest dokładnie wypadek, przed którym stoi issue #120:
     * w oryginale siedzi pełny EXIF ze współrzędnymi GPS kuchni, a adres
     * wyprowadza się z publicznego adresu wariantu podmianą `media/`
     * na `incoming/`.
     *
     * Bramka MA oblać i MA podać kod odpowiedzi dosłownie.
     */
    #[Test]
    public function test_oryginal_oddawany_pod_wlasna_domena_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403, publicznyOryginal: 200);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: oryginał z pełnym EXIF-em (GPS kuchni) wychodzi publiczną drogą.')
            ->expectsOutputToContain('cdn.test.kuking.pl: HTTP 200')
            ->assertExitCode(1);
    }

    /**
     * `r2.dev` włączone na buckecie oryginałów — drugi adres z listy.
     *
     * Osobny test, nie parametr poprzedniego: pułapka 3b z
     * `docs/PULAPKI_TESTOW.md` mówi, że dwa sprawdzenia trafiające w tę samą
     * gałąź warunku dają jedno sprawdzenie i jedną atrapę. Tu każdy adres
     * odpowiada INACZEJ — własna domena odmawia, `r2.dev` oddaje plik —
     * więc test dowodzi, że bramka pyta KAŻDY adres z listy, a nie pierwszy
     * i tyle.
     */
    #[Test]
    public function test_jeden_otwarty_adres_z_dwoch_wystarcza_zeby_oblac(): void
    {
        $this->ustawR2();
        $this->zdjecie();

        Http::fake(function (Request $zadanie) {
            $adres = $zadanie->url();

            if (Str::startsWith($adres, self::PUBLICZNY_R2_DEV)) {
                return Http::response('bajty oryginału', 200);
            }

            if (Str::startsWith($adres, self::PUBLICZNA_DOMENA)) {
                return Http::response('', 403);
            }

            return Str::contains($adres, 'expiration=')
                ? Http::response('bajty wariantu', 200)
                : Http::response('', 403);
        });

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: oryginał z pełnym EXIF-em (GPS kuchni) wychodzi publiczną drogą.')
            ->expectsOutputToContain('cdn.test.kuking.pl: HTTP 403')
            ->expectsOutputToContain('pub-test123.r2.dev: HTTP 200')
            ->assertExitCode(1);
    }

    /**
     * PRZEKIEROWANIE NIE JEST ODMOWĄ.
     *
     * Issue #120 żąda dosłownie `403/404`. Gdyby bramka uznawała „cokolwiek
     * poza 200" za odmowę, `301` na inny host przechodziłby ją na zielono —
     * a `301` nie mówi „nie wolno", tylko „plik jest tam". Bramka chodzi
     * z `withoutRedirecting()`, więc sama za tym wskazaniem nie idzie
     * i bez tej gałęzi nie dowiedziałaby się, co leży na jego końcu.
     */
    #[Test]
    public function test_przekierowanie_z_publicznego_adresu_nie_jest_odmowa(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403, publicznyOryginal: 301);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: oryginał z pełnym EXIF-em (GPS kuchni) wychodzi publiczną drogą.')
            ->expectsOutputToContain('cdn.test.kuking.pl: HTTP 301')
            ->assertExitCode(1);
    }

    /**
     * Wariant pod publiczną domeną to też alarm — po audycie W7-02.
     *
     * Issue #120 pisane było wtedy, gdy bucket wariantów miał mieć własną
     * domenę i miał spod niej oddawać 200. W7-02 tę decyzję odwrócił:
     * wariant przepisu prywatnego jest tak samo prywatny jak sam przepis,
     * a `recipes.source_scan_media_id` to skan kartki z nazwiskami. Zdjęcie
     * klucza `url` z konfiguracji NIE zdejmuje domeny z bucketu — dopóki
     * `cdn.kuking.pl` tam wskazuje, stare adresy działają wiecznie i dla
     * każdego. To sprawdzenie jest jedyną rzeczą w repozytorium, która
     * potrafi to zmierzyć.
     */
    #[Test]
    public function test_wariant_oddawany_pod_wlasna_domena_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403, publicznyWariant: 200);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: wariant wychodzi publiczną drogą')
            ->assertExitCode(1);
    }

    /**
     * CISZA NIE JEST ZALICZENIEM: bez zadeklarowanych adresów nie ma dowodu.
     *
     * Tak właśnie zachowywała się bramka przed tą zmianą — nie pytała
     * o własną domenę ani o `r2.dev`, bo nie miała ich skąd wziąć, i mimo
     * to kończyła się zielono. Teraz brak deklaracji jest `NIE WIEMY`,
     * a `NIE WIEMY` oblewa.
     */
    #[Test]
    public function test_brak_zadeklarowanych_publicznych_adresow_oblewa(): void
    {
        $this->ustawR2();
        config(['kuking.media.publiczne_adresy' => []]);
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('Nie zadeklarowano ANI JEDNEGO publicznego adresu')
            ->expectsOutputToContain('Adres niezapytany nie jest dowodem na nic')
            ->expectsOutputToContain('NIEPRZEJŚCIONA')
            ->assertExitCode(1);
    }

    /**
     * Milczący host przy niedziałającym wyjściu na świat to `NIE WIEMY`.
     *
     * Gdyby bramka uznawała każdy brak odpowiedzi za dowód zamknięcia,
     * kontener bez wyjścia na świat przechodziłby ją na zielono ZA KAŻDYM
     * razem — odmawiałaby sieć, nie Cloudflare, a raport mówiłby to samo
     * zdanie co przy prawdziwie zamkniętym buckecie.
     */
    #[Test]
    public function test_milczacy_publiczny_adres_bez_dowodu_wyjscia_na_swiat_oblewa(): void
    {
        $this->ustawR2();
        $this->zdjecie();

        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('NIE UDAŁO SIĘ dojść do R2 nawet z podpisem')
            ->assertExitCode(1);
    }

    /**
     * Milczący host przy DZIAŁAJĄCYM wyjściu na świat wolno uznać za zamknięty.
     *
     * Kontrola dodatnia do testu wyżej — bez niej „NIE WIEMY" mogłoby
     * wypadać z powodu, którego nikt nie zmierzył, i cała para „cisza vs
     * cisza z dowodem sieci" nie dowodziłaby, że bramka je rozróżnia.
     * Domena, której nie ma w DNS-ie, nie wyda pliku nikomu — ale wolno tak
     * powiedzieć tylko wtedy, gdy w tym samym przebiegu coś innego na świat
     * wyszło.
     */
    #[Test]
    public function test_milczacy_publiczny_adres_przy_dzialajacej_sieci_przechodzi(): void
    {
        $this->ustawR2();
        $this->zdjecie();

        Http::fake(function (Request $zadanie) {
            if ($this->publicznyHost($zadanie->url())) {
                throw new ConnectionException('Could not resolve host');
            }

            return Str::contains($zadanie->url(), 'expiration=')
                ? Http::response('bajty wariantu', 200)
                : Http::response('', 403);
        });

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('część nie odpowiedziała wcale')
            ->expectsOutputToContain('Część serwerowa bramki PRZESZŁA')
            ->assertExitCode(0);
    }

    /**
     * BRAMKA NAPRAWDĘ WYSŁAŁA TE ŻĄDANIA, a nie tylko o nich napisała.
     *
     * Pułapka 5 z `docs/PULAPKI_TESTOW.md`: narzędzie potrafi zameldować
     * sukces, nie robiąc nic. Wszystkie pozostałe testy patrzą na WYJŚCIE
     * komendy, więc przeszłyby także wtedy, gdyby ktoś zamienił żądania na
     * `return true`. Ten jeden patrzy na to, co poszło w sieć: pod każdym
     * zadeklarowanym adresem musi być zapytanie o klucz PRAWDZIWEGO
     * oryginału z bazy — nie o klucz wymyślony, bo 404 na nieistniejącym
     * kluczu nie mówi nic o tym, czy bucket jest publiczny.
     */
    #[Test]
    public function test_bramka_pyta_kazdy_zadeklarowany_adres_o_prawdziwy_klucz(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')->assertExitCode(0);

        foreach ([self::PUBLICZNA_DOMENA, self::PUBLICZNY_R2_DEV] as $adres) {
            Http::assertSent(
                fn (Request $zadanie): bool => $zadanie->url() === $adres.'/'.self::KLUCZ_ORYGINALU
                    && $zadanie->method() === 'GET'
                    && $zadanie->header('Authorization') === [],
            );
        }
    }

    /**
     * #619 — ENDPOINT BEZ JURYSDYKCJI DOWODZI, ŻE BUCKETY NIE SĄ „EU JURISDICTION".
     *
     * Polityka prywatności mówi użytkownikom, że zdjęcia leżą w Unii
     * Europejskiej (`resources/legal/polityka-prywatnosci.md`, tabela
     * podwykonawców). Do 17.09.2026 w repozytorium nie było ANI JEDNEGO
     * miejsca, w którym cokolwiek to sprawdzało — a sprawdzić się da,
     * z serwera, bez panelu Cloudflare.
     *
     * Dokumentacja Cloudflare (odczyt 17.09.2026,
     * https://developers.cloudflare.com/r2/reference/data-location/) mówi
     * dwie rzeczy, które razem dają dowód:
     *
     *   1. bucket z ograniczeniem jurysdykcyjnym jest dostępny WYŁĄCZNIE
     *      przez endpoint `https://<KONTO>.<JURYSDYKCJA>.r2.cloudflarestorage.com`,
     *   2. jurysdykcji istniejącego bucketu nie da się później zmienić.
     *
     * Czyli: jeśli aplikacja NAPRAWDĘ odczytała obiekt przez endpoint bez
     * segmentu jurysdykcji, to ten bucket żadnej jurysdykcji nie ma. To nie
     * jest „nie wiemy" — to jest „wiemy, że nie".
     */
    #[Test]
    public function test_endpoint_bez_jurysdykcji_przy_dzialajacym_api_oblewa(): void
    {
        $this->ustawR2();
        config(['filesystems.disks.r2.endpoint' => self::ENDPOINT_BEZ_JURYSDYKCJI]);
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: te buckety NIE mają ograniczenia jurysdykcyjnego')
            ->expectsOutputToContain('NIEPRZEJŚCIONA')
            ->assertExitCode(1);
    }

    /**
     * KONTROLA DODATNIA do testu wyżej: endpoint jurysdykcji `eu` przechodzi.
     *
     * Bez tej pary „endpoint bez jurysdykcji oblewa" nie dowodziłoby niczego
     * — oblewałoby też wtedy, gdyby sprawdzenie zawsze mówiło NIE
     * (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
     */
    #[Test]
    public function test_endpoint_jurysdykcji_eu_przechodzi(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('Endpoint należy do jurysdykcji `eu`')
            ->assertExitCode(0);
    }

    /**
     * Cudza jurysdykcja to alarm, a nie „coś tam jest, więc dobrze".
     *
     * Osobna gałąź warunku, więc osobny test — pułapka 3b: dwa sprawdzenia
     * trafiające w tę samą gałąź dają jedno sprawdzenie i jedną atrapę.
     */
    #[Test]
    public function test_endpoint_cudzej_jurysdykcji_to_alarm(): void
    {
        $this->ustawR2();
        config(['filesystems.disks.r2.endpoint' => 'https://1a2b3c4d5e6f.us.r2.cloudflarestorage.com']);
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: endpoint należy do jurysdykcji `us`')
            ->assertExitCode(1);
    }

    /**
     * Bez odczytu przez API endpoint niczego nie dowodzi — to `NIE WIEMY`.
     *
     * Cały dowód z tego sprawdzenia opiera się na tym, że aplikacja NAPRAWDĘ
     * sięgnęła po obiekt TYM endpointem. Sama nazwa hosta w konfiguracji
     * mówi tylko, co ktoś wpisał — nie, gdzie leżą pliki. Gdyby sprawdzenie
     * obywało się bez tej kontroli dodatniej, meldowałoby „wiemy, że nie"
     * o środowisku, w którym nie udało się odczytać ani jednego bajtu.
     */
    #[Test]
    public function test_endpoint_bez_jurysdykcji_bez_odczytu_przez_api_to_nie_wiemy(): void
    {
        $this->ustawR2();
        config(['filesystems.disks.r2.endpoint' => self::ENDPOINT_BEZ_JURYSDYKCJI]);
        $media = $this->zdjecie();
        Storage::disk('r2')->delete(self::KLUCZ_ORYGINALU);
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('nie udało się przez niego odczytać ani jednego obiektu')
            ->doesntExpectOutputToContain('ALARM: te buckety NIE mają ograniczenia jurysdykcyjnego')
            ->assertExitCode(1);

        $this->assertSame(self::KLUCZ_ORYGINALU, $media->object_key);
    }

    /**
     * IDENTYFIKATOR KONTA NIE MA PRAWA WYJŚĆ RAZEM Z JURYSDYKCJĄ.
     *
     * Sprawdzenie #619 czyta host endpointu, czyli jedyne miejsce
     * w konfiguracji, w którym stoi identyfikator konta Cloudflare.
     * Wyjście komendy wkleja się do zgłoszeń na GitHubie.
     */
    #[Test]
    public function test_sprawdzenie_jurysdykcji_nie_wypisuje_identyfikatora_konta(): void
    {
        $this->ustawR2();
        config(['filesystems.disks.r2.endpoint' => self::ENDPOINT_BEZ_JURYSDYKCJI]);
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: te buckety NIE mają ograniczenia jurysdykcyjnego')
            ->doesntExpectOutputToContain('1a2b3c4d5e6f')
            ->doesntExpectOutputToContain(self::ENDPOINT_BEZ_JURYSDYKCJI)
            ->assertExitCode(1);
    }

    /**
     * #120 — ADRES, POD KTÓRY NIE DA SIĘ WYSŁAĆ ŻĄDANIA, NIE JEST DOWODEM.
     *
     * To jest usterka odtworzona, nie hipoteza. Panel Cloudflare pokazuje
     * publiczne adresy bucketu BEZ protokołu — `pub-abc123.r2.dev`
     * i `cdn.kuking.pl`. Przepisane do `KUKING_R2_PUBLICZNE_ADRESY`
     * dokładnie tak, jak je widać, nie dają się zamienić w żądanie HTTP:
     * adres bez hosta wywala klienta wyjątkiem, który bramka łapie jako
     * „brak odpowiedzi".
     *
     * A „brak odpowiedzi" przy działającym wyjściu na świat bramka liczy
     * jako ODMOWĘ — świadomie, bo domena, której nie ma w DNS-ie, naprawdę
     * nikomu pliku nie wyda. Tyle że tutaj nie odmówił nikt: żądanie nigdy
     * nie powstało. Bramka meldowała więc „Każdy zadeklarowany adres
     * odmówił" i kod wyjścia 0 o środowisku, w którym `r2.dev` mogło być
     * włączone na oścież.
     *
     * To jest ta sama klasa usterki, przed którą ostrzega sama bramka
     * („adres niezapytany nie jest dowodem na nic") i pułapka 5
     * z `docs/PULAPKI_TESTOW.md`.
     */
    #[Test]
    public function test_publiczny_adres_bez_protokolu_nie_jest_dowodem_odmowy(): void
    {
        $this->ustawR2();
        config(['kuking.media.publiczne_adresy' => ['cdn.test.kuking.pl', self::PUBLICZNY_R2_DEV]]);
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('Zadeklarowano adres, pod który NIE DA SIĘ wysłać żądania')
            ->expectsOutputToContain('cdn.test.kuking.pl')
            ->expectsOutputToContain('NIEPRZEJŚCIONA')
            ->assertExitCode(1);
    }

    /**
     * Kontrola dodatnia do testu wyżej — i zarazem kontrola „nie za szeroko".
     *
     * Odrzucenie MUSI dotyczyć tylko wpisów, z których nie da się zbudować
     * żądania. Gdyby odrzucało też poprawne adresy, bramka nie miałaby jak
     * przejść nigdy, a każda inna asercja tego pliku oblewałaby się z tego
     * samego, niewłaściwego powodu (pułapka 8 z `docs/PULAPKI_TESTOW.md`).
     */
    #[Test]
    public function test_poprawne_adresy_z_protokolem_przechodza(): void
    {
        $this->ustawR2();
        config(['kuking.media.publiczne_adresy' => [
            self::PUBLICZNA_DOMENA,
            self::PUBLICZNY_R2_DEV.'/',
            'http://cdn2.test.kuking.pl',
        ]]);
        $this->zdjecie();

        Http::fake(fn (Request $zadanie) => Str::contains($zadanie->url(), 'expiration=')
            ? Http::response('bajty wariantu', 200)
            : Http::response('', 403));

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('Każdy zadeklarowany adres odmówił.')
            ->expectsOutputToContain('cdn2.test.kuking.pl: HTTP 403')
            ->assertExitCode(0);
    }

    /**
     * Dysk wariantów też musi być sterownikiem `r2`.
     *
     * Bez tego sprawdzenia `KUKING_MEDIA_PUBLIC_DISK` wskazujący dysk
     * lokalny przepuszczał bramkę do sprawdzeń, które CZYTAJĄ Z TEGO DYSKU:
     * sprawdzenie 9 („w publicznym buckecie nie ma kluczy `incoming/`")
     * listowało wtedy pusty katalog na dysku lokalnym i odpowiadało TAK —
     * odpowiedź prawdziwa, tylko że nie o R2. To jest ta sama choroba, przed
     * którą broni pierwsze sprawdzenie komendy, wpuszczona bocznymi drzwiami.
     */
    #[Test]
    public function test_dysk_lokalny_w_roli_bucketu_wariantow_nie_przechodzi_dalej(): void
    {
        $this->ustawR2();
        config(['kuking.media.public_disk' => 'public']);
        $this->zdjecie();
        Http::fake();

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('Dysk wariantów nie jest sterownikiem `r2`')
            ->assertExitCode(1);

        // Skoro komenda odmówiła na wejściu, nie ma prawa zapytać R2 o nic —
        // inaczej sprawdzałaby coś innego, niż serwis naprawdę używa.
        Http::assertNothingSent();
    }

    #[Test]
    public function test_exif_w_wariancie_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie(trescWariantu: "RIFF\x00\x00\x00\x00WEBPEXIF\x00\x00GPS 52.2297 21.0122");
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: w wariancie siedzi blok EXIF')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_oryginaly_w_publicznym_buckecie_to_alarm(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        Storage::disk('r2_publiczne')->put('incoming/2026/09/obce.jpg', 'oryginał, który tu nie ma prawa leżeć');
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('ALARM: leży tam 1 plik')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_brak_odpowiedzi_nie_jest_dowodem_zamkniecia(): void
    {
        $this->ustawR2();
        $this->zdjecie();

        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->artisan('kuking:bramka-r2 --zapis')
            ->expectsOutputToContain('NIE WIEMY')
            ->expectsOutputToContain('bez niej nie wolno uznać, że bucket jest zamknięty')
            ->assertExitCode(1);
    }

    #[Test]
    public function test_plik_probny_nie_zostaje_w_buckecie(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')->assertExitCode(0);

        $this->assertSame(
            [],
            Storage::disk('r2')->files('bramka'),
            'Plik próbny został w buckecie — bramka ma po sobie sprzątać.',
        );
    }

    #[Test]
    public function test_wyjscie_nie_niesie_endpointu_ani_sygnatury(): void
    {
        $this->ustawR2();
        $this->zdjecie();
        $this->odpowiedziR2(bezPodpisu: 403, oryginal: 403);

        $this->artisan('kuking:bramka-r2 --zapis')
            ->doesntExpectOutputToContain(self::ENDPOINT)
            ->doesntExpectOutputToContain('1a2b3c4d5e6f')
            ->doesntExpectOutputToContain('expiration=')
            // Pełna ścieżka do czyjegoś oryginału też nie ma prawa wyjść —
            // wyjście tej komendy wkleja się do zgłoszeń i do
            // `docs/infra/BRAMKA_R2.md`. Bramka wypisuje sam host i kod.
            ->doesntExpectOutputToContain(self::KLUCZ_ORYGINALU)
            ->assertExitCode(0);
    }

    /**
     * Konfiguracja udająca prawdziwe R2: dwa dyski, dwa buckety, endpoint.
     *
     * `Storage::fake()` podstawia dysk lokalny, ale NIE rusza wpisów
     * w `config`, więc komenda widzi sterownik `r2` — i to jest tu
     * potrzebne, bo inaczej oblałaby na pierwszym sprawdzeniu. Dyski
     * podstawione przez `fake()` umieją podpisywać adresy
     * (`buildTemporaryUrlsUsing` w `Storage::fake`), czyli to samo, co
     * na R2 robi `temporaryUrl()`.
     */
    private function ustawR2(): void
    {
        config([
            'filesystems.disks.r2.driver' => 'r2',
            'filesystems.disks.r2.bucket' => 'kuking-oryginaly',
            'filesystems.disks.r2.endpoint' => self::ENDPOINT,
            'filesystems.disks.r2_publiczne.driver' => 'r2',
            'filesystems.disks.r2_publiczne.bucket' => 'kuking-media',
            'filesystems.disks.r2_publiczne.endpoint' => self::ENDPOINT,
            'kuking.media.disk' => 'r2',
            'kuking.media.public_disk' => 'r2_publiczne',
            // Bez tej listy bramka nie wie, o jakie adresy pytać, i mówi
            // o tym `NIE WIEMY`. Domyślnie deklarujemy oba adresy, bo tak
            // ma wyglądać poprawnie skonfigurowane środowisko; testy, które
            // mierzą właśnie brak deklaracji, czyszczą ją same.
            'kuking.media.publiczne_adresy' => [self::PUBLICZNA_DOMENA, self::PUBLICZNY_R2_DEV],
        ]);

        Storage::fake('r2');
        Storage::fake('r2_publiczne');
    }

    /** Gotowe zdjęcie: oryginał w prywatnym buckecie, trzy warianty w publicznym. */
    private function zdjecie(string $trescWariantu = "RIFF\x00\x00\x00\x00WEBPVP8 czysty wariant"): Media
    {
        $wlasciciel = $this->user('gotujaca');

        Storage::disk('r2')->put(self::KLUCZ_ORYGINALU, "\xFF\xD8\xFF\xE1Exif\x00\x00GPS 52.2297 21.0122");

        $warianty = [];

        foreach (['thumb', 'feed', 'large'] as $nazwa) {
            $klucz = 'media/2026/09/rosol_'.$nazwa.'.webp';
            Storage::disk('r2_publiczne')->put($klucz, $trescWariantu);
            $warianty[$nazwa] = ['key' => $klucz, 'width' => 800, 'height' => 600];
        }

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'r2',
            'variants_disk' => 'r2_publiczne',
            'object_key' => self::KLUCZ_ORYGINALU,
            'mime_type' => 'image/jpeg',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => $warianty],
        ]);
    }

    /**
     * Odpowiedzi udawanego R2, rozdzielone po tym, CZEGO dotyczy żądanie.
     *
     * Adres podpisany przez `Storage::fake()` niesie `?expiration=` —
     * po tym poznajemy podpis. Adres oryginału poznajemy po tym, że stoi
     * na endpoincie konta, a nie na adresie aplikacji.
     *
     * ROZDZIAŁ PO HOŚCIE JEST TU ISTOTNY I MUSI BYĆ PIERWSZY. Ostrzał
     * publicznych adresów idzie po TYM SAMYM kluczu oryginału co
     * sprawdzenie 6, więc atrapa dopasowująca najpierw klucz odpowiadałaby
     * tak samo na żądanie do endpointu konta i na żądanie do
     * `cdn.test.kuking.pl`. Dwa sprawdzenia zlałyby się w jedno i żadne
     * z nich nie mierzyłoby tego, co obiecuje — to ta sama choroba, przed
     * którą ostrzega sama bramka.
     */
    private function odpowiedziR2(
        int $bezPodpisu,
        int $oryginal,
        int $publicznyOryginal = 403,
        int $publicznyWariant = 403,
    ): void {
        Http::fake(function (Request $zadanie) use ($bezPodpisu, $oryginal, $publicznyOryginal, $publicznyWariant) {
            $adres = $zadanie->url();

            if ($this->publicznyHost($adres)) {
                return Str::contains($adres, self::KLUCZ_ORYGINALU)
                    ? Http::response('bajty oryginału', $publicznyOryginal)
                    : Http::response('bajty wariantu', $publicznyWariant);
            }

            if (Str::contains($adres, self::KLUCZ_ORYGINALU)) {
                return Http::response('bajty oryginału', $oryginal);
            }

            if (Str::contains($adres, 'expiration=')) {
                return Http::response('bajty wariantu', 200);
            }

            return Http::response('', $bezPodpisu);
        });
    }

    /** Czy to żądanie idzie pod jeden z zadeklarowanych publicznych adresów. */
    private function publicznyHost(string $adres): bool
    {
        return Str::startsWith($adres, [self::PUBLICZNA_DOMENA, self::PUBLICZNY_R2_DEV]);
    }
}
