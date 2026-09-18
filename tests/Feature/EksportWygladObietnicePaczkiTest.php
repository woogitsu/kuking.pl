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
        $this->assertStringContainsString('jako tytuł i autor', $indeks);
        $this->assertStringContainsString('bez składników i kroków', $indeks);
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
