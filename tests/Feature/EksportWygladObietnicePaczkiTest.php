<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Models\Media;
use App\Models\Recipe;

/**
 * Co paczka o sobie OBIECUJE, a co w niej naprawdę jest (#492).
 *
 * Trzy zdania zmierzone na prawdziwie zbudowanych archiwach:
 *
 *  1. `CZYTAJ-TO-NAJPIERW.txt` opisywał katalogi „przepisy" i „zdjecia" jako
 *     zawartość paczki także wtedy, gdy ich w niej nie ma. `unzip -l` na
 *     archiwum świeżego konta pokazuje CZTERY pliki — `ZipArchive` nie tworzy
 *     pustych katalogów. `index.html` tego samego archiwum mówił już wtedy
 *     prawdę (pilnuje tego `DataExportTest::test_paczka_bez_zdjec_nie_obiecuje_katalogu_ktorego_nie_ma`),
 *     a plik „CZYTAJ TO NAJPIERW" obok twierdził coś innego.
 *  2. `index.html` witał zdaniem „To jest kopia WSZYSTKIEGO, co masz w Kuking".
 *     Zmierzone w `dane.json`: cudzy przepis zapisany w zeszycie wychodzi jako
 *     tytuł, autor, moja notatka i data zapisania — bez składników, kroków
 *     i zdjęć.
 *  3. Oba pliki mówiły „WSZYSTKIE Twoje zdjęcia", a do paczki wchodzą
 *     wyłącznie zdjęcia ze statusem `ready`; odrzucone zostają poza nią
 *     na dobre.
 *
 * Ten plik NIE dubluje `EksportMowiOZdjeciachWDrodzeTest` — tamten pilnuje,
 * żeby przy zdjęciu odrzuconym NIE obiecywać, że dojdzie później. Tu chodzi
 * o co innego: o słowo „wszystkie" w opisie zawartości katalogu.
 */
class EksportWygladObietnicePaczkiTest extends EksportWygladStylPaczki
{
    public function test_czytaj_to_najpierw_nie_opisuje_katalogow_ktorych_w_paczce_nie_ma(): void
    {
        $nowa = $this->user('nowa', ['display_name' => 'Nowa Osoba']);

        $export = $this->zbudujPaczke($nowa);
        $pliki = $this->plikiPaczki($export);

        // Kontrola dodatnia dla całej reszty: dowód, że tych katalogów
        // naprawdę nie ma. Bez niej test mówiłby tylko o treści napisu.
        $this->assertSame([], array_values(array_filter(
            $pliki,
            static fn (string $plik): bool => str_contains($plik, '/'),
        )), 'Paczka świeżego konta miała nie mieć żadnego katalogu.');

        $readme = $this->zPaczki($export, 'CZYTAJ-TO-NAJPIERW.txt');

        $this->assertStringContainsString('przepisy/', $readme, 'Spis zawartości ma wymienić nazwę katalogu, żeby go wyjaśnić.');
        $this->assertStringContainsString('zdjecia/', $readme, 'Spis zawartości ma wymienić nazwę katalogu, żeby go wyjaśnić.');

        /*
         * KAŻDA GAŁĄŹ MA WŁASNĄ ASERCJĘ — pułapka 3b z docs/PULAPKI_TESTOW.md.
         *
         * Zanim to rozdzielono, stało tu jedno `assertStringContainsString`
         * na cały plik. Zmierzone: skasowanie CAŁEJ gałęzi o zdjęciach
         * przechodziło na zielono, bo napis znajdował się przy przepisach.
         * Połowa naprawy mogła zniknąć bez jednego czerwonego przebiegu.
         */
        foreach (['przepisy', 'zdjecia'] as $katalog) {
            $wycinek = $this->sekcjaKatalogu($readme, $katalog);

            $this->assertStringContainsString(
                'Tego katalogu w tej paczce NIE MA',
                $wycinek,
                'Opis katalogu „'.$katalog.'" nie mówi, że katalogu w paczce nie ma.',
            );
        }

        // Zdania z liczbą opisują katalog, który istnieje — przy jego braku
        // nie mają prawa się pojawić.
        $this->assertStringNotContainsString('Przepisów w paczce:', $readme);
        $this->assertStringNotContainsString('Zdjęć w paczce:', $readme);
    }

    /**
     * Akapit otwierający `index.html` — ten, który mówi, co w paczce JEST.
     *
     * Bez tego wycinka asercje o polach cudzego przepisu chodzą po całym
     * pliku i łapią te same słowa postawione gdziekolwiek indziej
     * (pułapka 1).
     */
    private function akapitOtwierajacy(string $indeks): string
    {
        $od = mb_strpos($indeks, '<div class="karta">');
        $this->assertNotFalse($od, 'W spisie treści nie ma akapitu otwierającego.');

        $do = mb_strpos($indeks, '</div>', $od);
        $this->assertNotFalse($do);

        return mb_substr($indeks, $od, $do - $od);
    }

    /**
     * Opis JEDNEGO katalogu ze spisu „CO JEST W ŚRODKU".
     *
     * Bez tego wycinka asercja „ten katalog jest opisany jako nieobecny"
     * łapie napis postawiony przy sąsiednim katalogu (pułapka 1: to samo
     * zdanie pada w pliku dwa razy, przy dwóch różnych rzeczach).
     */
    private function sekcjaKatalogu(string $readme, string $katalog): string
    {
        $od = mb_strpos($readme, $katalog.'/');

        $this->assertNotFalse($od, 'W spisie nie ma w ogóle wpisu o katalogu „'.$katalog.'".');

        // Wpis kończy się pustą linią przed następną pozycją spisu.
        $do = mb_strpos($readme, "\n\n", $od);

        return $do === false ? mb_substr($readme, $od) : mb_substr($readme, $od, $do - $od);
    }

    public function test_czytaj_to_najpierw_opisuje_katalogi_ktore_w_paczce_sa(): void
    {
        // Kontrola dodatnia do testu wyżej (pułapka 4): asercja „czegoś nie ma"
        // przeszłaby także wtedy, gdyby opis katalogów zniknął na zawsze.
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);
        $this->zdjecieDla($basia, 'rosol');

        $readme = $this->zPaczki($this->zbudujPaczke($basia), 'CZYTAJ-TO-NAJPIERW.txt');

        $this->assertStringContainsString('Przepisów w paczce: 1', $readme);
        $this->assertStringContainsString('Zdjęć w paczce: 1', $readme);
        $this->assertStringNotContainsString('Tego katalogu w tej paczce NIE MA', $readme);
    }

    public function test_spis_tresci_nie_obiecuje_pelnej_kopii_cudzych_przepisow(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $marek = $this->user('marek', ['display_name' => 'Marek']);

        $cudzy = Recipe::factory()->for($marek, 'author')->create(['title' => 'Żurek na zakwasie']);
        app(SaveRecipeToCollection::class)->handle($basia, $cudzy, null, 'Zrobić na Wielkanoc.');

        $export = $this->zbudujPaczke($basia);

        // Najpierw POMIAR, potem zdanie: sprawdzamy, że ograniczenie jest
        // prawdziwe, a dopiero potem, że paczka je nazywa.
        $dane = json_decode($this->zPaczki($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $zapisany = $dane['kolekcje'][0]['przepisy'][0] ?? null;

        $this->assertIsArray($zapisany, 'Zapisany cudzy przepis miał wejść do paczki.');
        $this->assertSame('Żurek na zakwasie', $zapisany['tytul']);
        $this->assertSame(['tytul', 'autor', 'moja_notatka', 'zapisano'], array_keys($zapisany),
            'Zakres zapisanego cudzego przepisu się zmienił — sprawdź, czy zdanie w index.html nadal jest prawdziwe.');

        $indeks = $this->zPaczki($export, 'index.html');

        $this->assertStringNotContainsString('kopia wszystkiego', $indeks,
            'Paczka nie jest kopią wszystkiego — cudzych przepisów z zeszytu nie ma tu w całości.');

        /*
         * ZDANIE MA WYMIENIĆ WSZYSTKIE CZTERY POLA, NIE DWA.
         *
         * Asercja wyżej na `array_keys` jest tu kontrolą dodatnią: paczka
         * daje `tytul`, `autor`, `moja_notatka` i `zapisano`. Zdanie mówiło
         * o „tytule i autorze", czyli zaniżało to, co człowiek naprawdę
         * dostaje — i przy rezygnacji z konta to jest zaniżenie w złą
         * stronę. Każde pole ma własną asercję, żeby wypadnięcie jednego
         * nie schowało się za pozostałymi (pułapka 3b).
         */
        // Asercje idą po WYCINKU z akapitem otwierającym, nie po całym pliku.
        // Dziś te słowa padają w `index.html` tylko tam, ale to unikalność
        // przypadkowa: dopisanie autora przy pozycji spisu treści wyłączyłoby
        // asercję bez jednego czerwonego przebiegu (pułapka 1).
        $akapit = $this->akapitOtwierajacy($indeks);

        foreach (['tytuł', 'autor', 'notatka', 'data zapisania'] as $pole) {
            $this->assertStringContainsString($pole, $akapit,
                'Zdanie o cudzych przepisach w zeszycie nie wymienia pola „'.$pole.'”, które paczka naprawdę niesie.');
        }

        $this->assertStringContainsString('bez składników, kroków i zdjęć', $akapit);
    }

    public function test_spis_tresci_pustego_zeszytu_nie_mowi_o_cudzych_przepisach(): void
    {
        /*
         * Zdanie o ograniczeniu cudzych przepisów opisuje coś, czego na tym
         * koncie w paczce NIE MA — zeszyt jest pusty. To ta sama klasa
         * usterki co katalogi opisywane mimo nieobecności: świeże konto
         * czytało o „cudzych przepisach zapisanych w Twoim zeszycie",
         * nie mając w zeszycie ani jednego.
         */
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);

        $export = $this->zbudujPaczke($basia);

        // Pomiar, na którym stoi asercja: zeszyt naprawdę jest pusty.
        $dane = json_decode($this->zPaczki($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $zapisane = array_merge(...array_map(
            static fn (array $kolekcja): array => $kolekcja['przepisy'],
            $dane['kolekcje'],
        ) ?: [[]]);
        $this->assertSame([], $zapisane, 'Scena miała mieć pusty zeszyt.');

        $indeks = $this->zPaczki($export, 'index.html');

        $this->assertStringNotContainsString('zapisane w Twoim zeszycie', $indeks,
            'Paczka opisuje ograniczenie cudzych przepisów, choć w zeszycie nie ma ani jednego.');

        // Kontrola dodatnia (pułapka 4): zdanie otwierające ma zostać —
        // znika jedno zastrzeżenie, nie cały akapit.
        $this->assertStringContainsString('To jest kopia Twoich przepisów', $indeks);
    }

    public function test_wlasny_przepis_w_wlasnym_zeszycie_nie_wywoluje_zdania_o_cudzych(): void
    {
        // Odłożenie WŁASNEGO przepisu do własnego zeszytu nie jest powodem
        // do zastrzeżenia: pełną treść tego przepisu paczka niesie
        // w katalogu „przepisy". Bez tego rozróżnienia warunek byłby
        // spełniony przez rzecz, której ograniczenie nie dotyczy.
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $wlasny = Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);
        app(SaveRecipeToCollection::class)->handle($basia, $wlasny, null, 'Moja wersja.');

        $export = $this->zbudujPaczke($basia);

        $dane = json_decode($this->zPaczki($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($dane['kolekcje'][0]['przepisy'] ?? [],
            'Scena miała mieć własny przepis w zeszycie.');

        $this->assertStringNotContainsString(
            'zapisane w Twoim zeszycie',
            $this->zPaczki($export, 'index.html'),
            'Paczka mówi o cudzych przepisach, choć w zeszycie leży wyłącznie własny.',
        );
    }

    public function test_skasowany_cudzy_przepis_w_zeszycie_nie_wywoluje_zdania_o_ograniczeniu(): void
    {
        /*
         * `Recipe` ma `SoftDeletes`, a licznik zeszytu idzie złączeniem —
         * czyli z pominięciem globalnego zakresu modelu. Bez jawnego warunku
         * o `deleted_at` przepis skasowany liczyłby się do warunku, choć
         * `CollectUserExportData::collections()` już go do paczki nie wkłada.
         * Zdanie o ograniczeniu wychodziłoby wtedy na koncie, w którego
         * paczce nie ma ani jednego cudzego przepisu.
         */
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $marek = $this->user('marek', ['display_name' => 'Marek']);

        $cudzy = Recipe::factory()->for($marek, 'author')->create(['title' => 'Żurek na zakwasie']);
        app(SaveRecipeToCollection::class)->handle($basia, $cudzy, null, 'Zrobić na Wielkanoc.');
        $cudzy->delete();

        $export = $this->zbudujPaczke($basia);

        // POMIAR PRZED NAPISEM: zapis w zeszycie został, ale do paczki
        // nie wchodzi już żaden cudzy przepis.
        $this->assertSoftDeleted($cudzy);

        // Wiersz w zeszycie ma PRZEŻYĆ miękkie skasowanie przepisu. Gdyby
        // znikał, licznik byłby zerem z niewłaściwego powodu, a test
        // przechodziłby pusto.
        $this->assertDatabaseHas('collection_items', ['recipe_id' => $cudzy->getKey()]);

        $dane = json_decode($this->zPaczki($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $zapisane = array_merge([], ...array_map(
            static fn (array $kolekcja): array => $kolekcja['przepisy'],
            $dane['kolekcje'],
        ));
        $this->assertSame([], $zapisane, 'Skasowany przepis miał do paczki NIE wejść.');

        $this->assertStringNotContainsString(
            'zapisane w Twoim zeszycie',
            $this->zPaczki($export, 'index.html'),
            'Paczka opisuje ograniczenie cudzych przepisów, choć żaden do niej nie wszedł.',
        );
    }

    public function test_paczka_nie_mowi_wszystkie_zdjecia_gdy_czesc_zostaje_poza_nia(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Rosół z kury']);

        $this->zdjecieDla($basia, 'rosol');

        // Odrzucone zdjęcie NIE wejdzie do paczki nigdy — i ma plik na dysku,
        // więc to nie jest „zdjęcie, którego nie ma".
        Media::factory()->create([
            'owner_id' => $basia->getKey(),
            'disk' => 'public',
            'object_key' => 'media/'.$basia->getKey().'/odrzucone.webp',
            'status' => Media::STATUS_REJECTED,
        ]);

        $export = $this->zbudujPaczke($basia);

        $wPaczce = array_values(array_filter(
            $this->plikiPaczki($export),
            static fn (string $plik): bool => str_starts_with($plik, 'zdjecia/'),
        ));

        // Pomiar, na którym stoi cała reszta: w bazie są dwa zdjęcia z plikiem,
        // w paczce jedno. Bez tej asercji test mówiłby tylko o napisie.
        $this->assertCount(1, $wPaczce);
        $this->assertSame(2, $basia->media()->count());

        $indeks = $this->zPaczki($export, 'index.html');
        $readme = $this->zPaczki($export, 'CZYTAJ-TO-NAJPIERW.txt');

        $this->assertStringNotContainsString('Wszystkie Twoje zdjęcia', $indeks);
        $this->assertStringNotContainsString('Wszystkie Twoje zdjęcia', $readme);

        // Kontrola dodatnia: opis katalogu ma dalej istnieć i dalej mówić,
        // ile zdjęć w paczce jest — poprawka dotyczy jednego słowa, nie sekcji.
        $this->assertStringContainsString('Zdjęcia z tej paczki leżą w katalogu', $indeks);
        $this->assertStringContainsString('Zdjęć w paczce: 1', $readme);
    }
}
