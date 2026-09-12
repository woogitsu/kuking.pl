<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `public/manifest.webmanifest` nie wymusza jednej orientacji ekranu.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Audyt z 10 września (`docs/research/audyt-2026-09-10/08_SEO_PWA_UDOSTEPNIANIE.md`,
 * znalezisko SEO/PWA-01, priorytet P1) zgłosił w manifeście wiersz:
 *
 *     "orientation": "portrait-primary"
 *
 * WCAG 2.2, kryterium **1.3.4 Orientation (poziom AA)**, wymaga, żeby treść
 * i obsługa NIE były ograniczone do jednej orientacji wyświetlania, chyba że
 * konkretna orientacja jest **niezbędna**. W Kuking nie jest — przeciwnie:
 * tryb gotowania (`/przepisy/{slug}/gotuj`) to telefon albo tablet oparty
 * o coś przy blacie, czyli typowo POZIOMO. Blokada uderzała więc dokładnie
 * w to użycie, dla którego ten ekran powstał.
 *
 * Blokada jest niewidoczna w zwykłej przeglądarce: `orientation` działa
 * dopiero w ZAINSTALOWANEJ aplikacji (`display: standalone`), więc ani jeden
 * test strony, ani `scripts/dostepnosc.mjs` nie miały jak jej zauważyć.
 * Dlatego strażnikiem jest test czytający PLIK, a nie przeglądarka.
 *
 * DLACZEGO KLUCZ ZOSTAŁ USUNIĘTY, A NIE USTAWIONY NA `"any"`
 * Audyt dopuszczał oba warianty. Różnica nie jest kosmetyczna i wynika
 * z W3C Web Application Manifest (https://www.w3.org/TR/appmanifest/):
 *
 *  - **klucza nie ma** — przetwarzanie manifestu kończy krok na
 *    „If json["orientation"] doesn't exist […] return", czyli aplikacja
 *    NIE DEKLARUJE domyślnej orientacji w ogóle. Zostaje zachowanie
 *    systemu, łącznie z **blokadą obrotu, którą włączył sam człowiek**.
 *  - **`"any"`** — wartość obecna i poprawna staje się „default screen
 *    orientation for the life of the web application", a przeglądarka
 *    „MUST return the orientation to the default screen orientation any
 *    time the orientation is unlocked". To jest deklaracja CZYNNA:
 *    aplikacja mówi, co ma się dziać z orientacją, zamiast milczeć.
 *
 * Dla grupy 50+ to nie jest drobiazg. Blokada obrotu w telefonie bywa
 * włączona świadomie (czytanie w łóżku, drżenie ręki) i ma być nadrzędna
 * wobec życzeń strony. Wariant bez klucza nie ma jak jej naruszyć, bo nic
 * nie deklaruje; wariant `"any"` deklaruje „każda orientacja jest dobra"
 * i zależy od tego, jak dana przeglądarka pogodzi to z ustawieniem systemu.
 * Kryterium 1.3.4 spełniają OBA — wybraliśmy ten, który oddaje decyzję
 * człowiekowi, i który dodatkowo nie zostawia w pliku konfiguracji
 * do utrzymywania.
 *
 * DLACZEGO TEST MIMO TO PRZEPUSZCZA `"any"`
 * Strażnik pilnuje KRYTERIUM (brak wymuszenia), nie naszego wyboru stylu.
 * Gdyby ktoś kiedyś dopisał `"any"` — na przykład po to, żeby zamiar był
 * widoczny wprost w pliku — WCAG nadal jest spełnione i test nie ma powodu
 * zapalać się na czerwono. Czerwień jest zarezerwowana dla rzeczy, które
 * realnie ograniczają człowieka.
 *
 * DLACZEGO LISTA DOZWOLONYCH, A NIE LISTA ZAKAZANYCH
 * Naturalny odruch to `assertStringNotContainsString('portrait-primary')`.
 * Taki strażnik łapie JEDEN NAPIS i przepuszcza `"portrait"`, `"landscape"`,
 * `"portrait-secondary"`, `"landscape-primary"`, `"landscape-secondary"`
 * oraz `"natural"` — a każda z tych wartości ogranicza treść do jednej
 * orientacji dokładnie tak samo (`"natural"` najpodstępniej: na telefonie
 * znaczy pion, na tablecie poziom, więc „działa u mnie" nic nie dowodzi).
 * Dlatego przechodzi wyłącznie brak klucza albo `"any"`, a WSZYSTKO inne
 * oblewa — także wartość, której dziś w specyfikacji nie ma.
 *
 * PO CO DRUGI TEST (KONTROLA DODATNIA)
 * Sam warunek „w manifeście nie ma wymuszenia orientacji" jest prawdziwy
 * także dla pliku PUSTEGO, dla pliku z jedną klamrą i dla pliku, którego
 * ktoś przypadkiem nadpisał śmieciem (`docs/PULAPKI_TESTOW.md`, pułapka 2:
 * test skanujący przechodzi, gdy nie znajdzie NICZEGO). Zielony strażnik
 * orientacji nad rozwalonym manifestem byłby gorszy niż brak strażnika,
 * bo zapewniałby, że PWA jest w porządku. Dlatego drugi test wymaga, żeby
 * plik był poprawnym JSON-em i miał komplet pól, na których stoi instalacja
 * aplikacji — nazwę, `start_url`, `scope`, `display`, kolory oraz ikony
 * 192 i 512 px razem z wariantem `maskable`.
 *
 * CZEGO TEN PLIK ŚWIADOMIE NIE PILNUJE
 *  - **Czy pliki ikon istnieją i mają zadeklarowany rozmiar.** To osobna
 *    usterka i osobny strażnik; tu chodzi o orientację.
 *  - **Czy `resources/css/app.css` nie blokuje orientacji drugą drogą**
 *    (`@media (orientation: portrait)` użyte jako wymóg). Sprawdzone ręcznie
 *    12.09.2026 — w całym `resources/` nie ma ani jednego zapytania
 *    o orientację ani o wysokość okna; wszystkie progi układu są wyłącznie
 *    szerokościowe. Nie ma dziś czego pilnować, a strażnik na pustym zbiorze
 *    byłby zielony z definicji.
 */
class ManifestNieWymuszaOrientacjiTest extends TestCase
{
    private const SCIEZKA_MANIFESTU = 'public/manifest.webmanifest';

    /**
     * Jedyne wartości `orientation`, które NIE ograniczają treści do jednej
     * orientacji. Brak klucza jest traktowany osobno — to nie jest wartość.
     */
    private const WARTOSCI_BEZ_WYMUSZENIA = ['any'];

    /**
     * Pola, bez których manifest nie jest manifestem — kontrola dodatnia.
     *
     * To NIE jest cała zawartość pliku (nie ma tu `description`, `lang`,
     * `dir`, `categories` ani `shortcuts`): lista ma zostać prawdziwa przy
     * zwykłej pracy nad treścią manifestu, a oblać, gdy plik przestanie być
     * manifestem.
     */
    private const POLA_WYMAGANE = [
        'name',
        'short_name',
        'start_url',
        'scope',
        'display',
        'background_color',
        'theme_color',
        'icons',
    ];

    /** Rozmiary ikon, na których stoi instalacja aplikacji. */
    private const ROZMIARY_IKON = ['192x192', '512x512'];

    public function test_manifest_nie_wymusza_zadnej_orientacji_ekranu(): void
    {
        $manifest = $this->manifest();

        if (! array_key_exists('orientation', $manifest)) {
            // Brak klucza = brak deklaracji = orientacja zostaje przy
            // ustawieniu systemu. To jest stan docelowy.
            $this->assertTrue(true);

            return;
        }

        $wartosc = $manifest['orientation'];

        $this->assertIsString(
            $wartosc,
            'Klucz „orientation" w '.self::SCIEZKA_MANIFESTU.' nie jest napisem. '
            .'Jeśli ma tam zostać, jedyną dopuszczalną wartością jest „any" — '
            .'najlepiej jednak usunąć klucz w całości (WCAG 2.2, 1.3.4 Orientation).',
        );

        $this->assertContains(
            $wartosc,
            self::WARTOSCI_BEZ_WYMUSZENIA,
            'Manifest PWA wymusza orientację „'.$wartosc.'". '
            .'WCAG 2.2, kryterium 1.3.4 Orientation (AA), zakazuje ograniczania treści '
            .'do jednej orientacji, o ile konkretna nie jest niezbędna — w Kuking nie jest, '
            .'a tryb gotowania przy blacie to typowo telefon położony POZIOMO. '
            .'Usuń wiersz „orientation" z '.self::SCIEZKA_MANIFESTU.' '
            .'(wtedy zainstalowana aplikacja idzie za ustawieniem systemu, łącznie z blokadą '
            .'obrotu włączoną przez człowieka) albo ustaw „any". '
            .'Uzasadnienie wyboru: docblock tego pliku, znalezisko SEO/PWA-01.',
        );
    }

    /**
     * KONTROLA DODATNIA: bez tego pierwszy test byłby zielony na pustym pliku.
     */
    public function test_manifest_jest_poprawnym_jsonem_i_ma_reszte_pol(): void
    {
        $sciezka = base_path(self::SCIEZKA_MANIFESTU);
        $tresc = file_get_contents($sciezka);

        $this->assertIsString($tresc, "Nie da się przeczytać {$sciezka}.");
        $this->assertNotSame('', trim($tresc), "Plik {$sciezka} jest pusty.");

        $manifest = json_decode($tresc, true);

        $this->assertSame(
            JSON_ERROR_NONE,
            json_last_error(),
            "Plik {$sciezka} nie jest poprawnym JSON-em: ".json_last_error_msg().'. '
            .'Przeglądarka odrzuci taki manifest w całości, a aplikacji nie da się zainstalować.',
        );
        $this->assertIsArray($manifest, "Plik {$sciezka} nie zawiera obiektu JSON.");

        foreach (self::POLA_WYMAGANE as $pole) {
            $this->assertArrayHasKey(
                $pole,
                $manifest,
                'W '.$sciezka.' brakuje pola „'.$pole.'". Bez niego manifest przestaje opisywać '
                .'aplikację, a strażnik orientacji wyżej przechodziłby na takim pliku.',
            );
        }

        $this->assertSame('/home', $manifest['start_url'], 'Manifest przestał startować na tablicy.');
        $this->assertSame('/', $manifest['scope'], 'Manifest przestał obejmować cały serwis.');
        $this->assertSame('standalone', $manifest['display'], 'Manifest przestał się instalować jako aplikacja.');

        $this->assertIsArray($manifest['icons']);
        $this->assertGreaterThanOrEqual(
            4,
            count($manifest['icons']),
            'Manifest ma mniej ikon niż komplet 192/512 w wariantach „any" i „maskable".',
        );

        $rozmiary = array_column($manifest['icons'], 'sizes');
        $przeznaczenia = array_column($manifest['icons'], 'purpose');

        foreach (self::ROZMIARY_IKON as $rozmiar) {
            $this->assertContains(
                $rozmiar,
                $rozmiary,
                'W '.$sciezka.' nie ma ikony '.$rozmiar.'. Bez niej system nie ma czego położyć na ekranie.',
            );
        }

        $this->assertContains(
            'maskable',
            $przeznaczenia,
            'W '.$sciezka.' nie ma ikony „maskable" — Android przycina wtedy zwykłą ikonę po swojemu.',
        );

        $this->assertArrayHasKey('shortcuts', $manifest, 'Manifest stracił skróty „Dodaj zdjęcie" i „Mój zeszyt".');
        $this->assertNotEmpty($manifest['shortcuts']);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $sciezka = base_path(self::SCIEZKA_MANIFESTU);

        $this->assertFileExists($sciezka, "Nie ma pliku {$sciezka} — manifestu PWA nie da się sprawdzić.");

        $manifest = json_decode((string) file_get_contents($sciezka), true);

        $this->assertIsArray(
            $manifest,
            "Plik {$sciezka} nie jest poprawnym obiektem JSON — patrz drugi test w tym pliku.",
        );

        return $manifest;
    }
}
