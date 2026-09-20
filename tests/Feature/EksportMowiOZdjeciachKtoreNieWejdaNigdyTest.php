<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Paczka RODO mówi o zdjęciach, które NIGDY do niej nie wejdą (issue #692).
 *
 * CO TO JEST ZA WIADOMOŚĆ I CZYM SIĘ RÓŻNI OD TEJ Z #113
 * `ExportPhotoPlan` bierze do paczki wyłącznie `ready`, a jako „w drodze"
 * liczy `pending` i `processing`. Jego własny komentarz od #113 mówił wprost,
 * że `rejected` też do paczki nie wchodzi, ale że „to jest INNA wiadomość",
 * bo takie zdjęcie nie pojawi się w niej NIGDY. Tamtej wiadomości nikt nie
 * napisał: paczka konta ze zdjęciami odrzuconymi albo skasowanymi milczała
 * o nich całkowicie. #678 przestało obiecywać „wszystkie" zdjęcia — to jest
 * krok drugi: paczka zaczyna mówić, ILU brakuje i że nie dojdą.
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * Ten plik człowiek czyta PRZED skasowaniem konta. Paczka wyglądająca na
 * kompletną, a niekompletna, jest gorsza od paczki mówiącej o swoich brakach.
 *
 * DLACZEGO OSOBNY PLIK TESTU, A NIE DOPISEK DO `EksportMowiOZdjeciachWDrodzeTest`
 * Tamten test pilnuje gałęzi „zdjęcia w drodze" i gałęzi „pusta paczka".
 * Ten pilnuje gałęzi „nigdy". Konto może mieć wszystkie naraz i właśnie to,
 * że się nie mieszają, jest tu osobno zmierzone
 * (`test_trzy_rodzaje_brakow_nie_mieszaja_sie_ze_soba`).
 *
 * PACZKA POWSTAJE NAPRAWDĘ, przez `GenerateUserExport`, a nie renderem samego
 * Blade — inaczej test nie mówiłby nic o archiwum, które człowiek pobierze.
 */
class EksportMowiOZdjeciachKtoreNieWejdaNigdyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rdzeń obu nowych zdań: ile ich nie ma i że nie będzie ich NIGDY.
     *
     * Kotwicą jest ZDANIE, nie słowo „UWAGA" ani „odrzucone": to drugie pada
     * też w innych miejscach serwisu, a takie asercje przechodzą, niczego nie
     * sprawdzając (`docs/PULAPKI_TESTOW.md`, pułapka 1). Fragment zawiera
     * odmienioną formę czasownika, więc zależy od liczby — dlatego jest
     * funkcją, a nie stałą.
     */
    private function zdanieONigdy(string $weszlo, string $wejdzie): string
    {
        return "nie {$weszlo} do tej paczki i nie {$wejdzie} do żadnej następnej";
    }

    /** Zdanie, które rozróżnia PRZYCZYNĘ — odrzucenie przy przygotowaniu. */
    private const PRZYCZYNA_ODRZUCENIA = 'przygotować do pokazania w serwisie';

    /** Zdanie, które rozróżnia PRZYCZYNĘ — skasowanie przez człowieka. */
    private const PRZYCZYNA_SKASOWANIA = 'które skasowano z Kuking';

    /**
     * Rada należna WYŁĄCZNIE zdjęciom w drodze (issue #113).
     *
     * Przy zdjęciu odrzuconym albo skasowanym „poproś o nową paczkę" jest
     * zwykłą nieprawdą — następna paczka też go nie przyniesie. Ta stała
     * pilnuje, żeby obie gałęzie się nie zlały.
     */
    private const RADA_DLA_W_DRODZE = 'Poproś o nową paczkę';

    /** Zdanie gałęzi „w drodze", po którym poznaje się jej ostrzeżenie. */
    private const ZDANIE_O_W_DRODZE = 'nie zmieściło się w tej paczce';

    /** Oba pliki paczki, które czyta człowiek — obowiązek z kryteriów odbioru. */
    private const PLIKI_DLA_CZLOWIEKA = ['index.html', 'CZYTAJ-TO-NAJPIERW.txt'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config(['kuking.exports.disk' => 'local', 'kuking.exports.ttl_days' => 7]);
    }

    public function test_paczka_mowi_o_zdjeciach_odrzuconych_i_o_tym_ze_nie_dojda(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        // Obok odrzuconych stoi zdjęcie gotowe: to jest scena, w której
        // `photoCount` > 0, czyli gałąź INNA niż „pusta paczka". Do #692
        // właśnie tu paczka milczała najgłośniej — wyglądała na kompletną.
        $this->zdjecie($basia, 'sernik', Media::STATUS_READY);
        $spalony = $this->zdjecie($basia, 'spalony', Media::STATUS_REJECTED);
        $this->zdjecie($basia, 'rozmyty', Media::STATUS_REJECTED);

        $paczka = $this->zbudujPaczke($basia);

        // POMIAR PRZED NAPISEM: pliki odrzuconych SĄ w storage, a mimo to
        // w archiwum leży dokładnie jedno zdjęcie — to gotowe.
        $this->assertTrue(Storage::disk('public')->exists((string) $spalony->object_key));
        $this->assertCount(1, $this->zdjeciaWArchiwum($paczka));

        foreach (self::PLIKI_DLA_CZLOWIEKA as $plik) {
            $tresc = $this->jednymWierszem($this->zArchiwum($paczka, $plik));

            $this->assertStringContainsString(
                '2 zdjęcia '.$this->zdanieONigdy('weszły', 'wejdą'),
                $tresc,
                "Plik {$plik} milczy o zdjęciach, które do paczki nie wejdą nigdy.",
            );

            $this->assertStringContainsString(
                self::PRZYCZYNA_ODRZUCENIA,
                $tresc,
                "Plik {$plik} mówi o braku, ale nie mówi, skąd się wziął.",
            );

            // To NIE jest gałąź „w drodze": obietnica, że nowa paczka pomoże,
            // byłaby tu nieprawdą.
            $this->assertStringNotContainsString(
                self::RADA_DLA_W_DRODZE,
                $tresc,
                "Plik {$plik} obiecuje, że nowa paczka przyniesie zdjęcie, które nie wejdzie nigdy.",
            );
            $this->assertStringNotContainsString(self::ZDANIE_O_W_DRODZE, $tresc);
        }

        $dane = $this->dane($paczka);
        $this->assertSame(2, $dane['o_tym_pliku']['zdjec_odrzuconych_przy_przygotowaniu']);
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_skasowanych']);
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_jeszcze_w_przygotowaniu']);
    }

    public function test_paczka_mowi_o_zdjeciach_skasowanych_osobnym_zdaniem(): void
    {
        // „Odrzucone przy wysyłaniu" i „skasowane przez Ciebie" to dwie różne
        // historie: pierwszą człowiek może naprawić, wgrywając oryginał
        // jeszcze raz, druga jest jego własną decyzją i nie ma tu nic do
        // zrobienia. Dlatego są to dwa zdania, a nie jedno — i dlatego
        // zdanie o skasowanych NIE jest ostrzeżeniem.
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $this->zdjecie($basia, 'sernik', Media::STATUS_READY);
        $this->zdjecie($basia, 'nieudany', Media::STATUS_DELETED);

        $paczka = $this->zbudujPaczke($basia);

        foreach (self::PLIKI_DLA_CZLOWIEKA as $plik) {
            $tresc = $this->jednymWierszem($this->zArchiwum($paczka, $plik));

            $this->assertStringContainsString(
                '1 zdjęcie, '.self::PRZYCZYNA_SKASOWANIA.', '.$this->zdanieONigdy('weszło', 'wejdzie'),
                $tresc,
                "Plik {$plik} milczy o zdjęciu skasowanym.",
            );

            // Kontrola rozdziału gałęzi: nie ma tu ani rady dla odrzuconych
            // („wgraj oryginał jeszcze raz" ma sens tylko przy odrzuconym),
            // ani niczego o zdjęciach w drodze.
            $this->assertStringNotContainsString(
                self::PRZYCZYNA_ODRZUCENIA,
                $tresc,
                "Plik {$plik} przypisuje skasowaniu przyczynę odrzucenia.",
            );
            $this->assertStringNotContainsString(self::RADA_DLA_W_DRODZE, $tresc);
        }

        // Skasowane NIE dostaje ramki ostrzeżenia: to była decyzja człowieka,
        // a nie strata, o której nie wiedział.
        $this->assertStringNotContainsString(
            'class="uwaga"',
            $this->zArchiwum($paczka, 'index.html'),
            'Paczka krzyczy „UWAGA" na człowieka za to, że sam skasował zdjęcie.',
        );

        $dane = $this->dane($paczka);
        $this->assertSame(1, $dane['o_tym_pliku']['zdjec_skasowanych']);
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_odrzuconych_przy_przygotowaniu']);
    }

    public function test_paczka_z_kompletem_zdjec_nie_dostaje_ostrzezenia_o_brakach(): void
    {
        // KONTROLA DODATNIA z kryteriów odbioru. Bez niej wszystkie asercje
        // wyżej przeszłyby też na widoku, który wypisuje oba zdania ZAWSZE
        // (pułapka 4 z `docs/PULAPKI_TESTOW.md`).
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $this->zdjecie($basia, 'sernik', Media::STATUS_READY);
        $this->zdjecie($basia, 'rosol', Media::STATUS_READY);

        $paczka = $this->zbudujPaczke($basia);

        $this->assertCount(2, $this->zdjeciaWArchiwum($paczka));

        foreach (self::PLIKI_DLA_CZLOWIEKA as $plik) {
            $tresc = $this->jednymWierszem($this->zArchiwum($paczka, $plik));

            // Każda z trzech odmienionych form osobno: sprawdzenie jednej
            // przepuściłoby widok, który dla dwóch zdjęć milczy, a dla
            // pięciu straszy bez powodu.
            foreach ([['weszło', 'wejdzie'], ['weszły', 'wejdą']] as [$weszlo, $wejdzie]) {
                $this->assertStringNotContainsString(
                    $this->zdanieONigdy($weszlo, $wejdzie),
                    $tresc,
                    "Plik {$plik} ostrzega o brakach, których w paczce nie ma — fałszywy alarm.",
                );
            }

            $this->assertStringNotContainsString(self::PRZYCZYNA_ODRZUCENIA, $tresc);
            $this->assertStringNotContainsString(self::PRZYCZYNA_SKASOWANIA, $tresc);
        }

        // Liczniki są mimo to OBECNE i wynoszą zero — patrz test niżej.
        $dane = $this->dane($paczka);
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_odrzuconych_przy_przygotowaniu']);
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_skasowanych']);
    }

    public function test_trzy_rodzaje_brakow_nie_mieszaja_sie_ze_soba(): void
    {
        // Konto może mieć jedno i drugie naraz — to jest jawny warunek
        // z kryteriów odbioru #692. Każda z trzech wiadomości ma paść raz
        // i żadna nie ma przejąć uzasadnienia sąsiedniej.
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $this->zdjecie($basia, 'sernik', Media::STATUS_READY);
        $this->zdjecie($basia, 'wdrodze', Media::STATUS_PROCESSING);
        $this->zdjecie($basia, 'spalony', Media::STATUS_REJECTED);
        $this->zdjecie($basia, 'nieudany', Media::STATUS_DELETED);

        $paczka = $this->zbudujPaczke($basia);

        foreach (self::PLIKI_DLA_CZLOWIEKA as $plik) {
            $tresc = $this->jednymWierszem($this->zArchiwum($paczka, $plik));

            $this->assertStringContainsString(
                '1 zdjęcie nie zmieściło się w tej paczce',
                $tresc,
                "Plik {$plik} przestał mówić o zdjęciu w drodze, gdy obok stanęło odrzucone.",
            );
            $this->assertStringContainsString(
                '1 zdjęcie '.$this->zdanieONigdy('weszło', 'wejdzie'),
                $tresc,
                "Plik {$plik} przestał mówić o zdjęciu odrzuconym, gdy obok stanęło to w drodze.",
            );
            $this->assertStringContainsString(
                '1 zdjęcie, '.self::PRZYCZYNA_SKASOWANIA,
                $tresc,
                "Plik {$plik} przestał mówić o zdjęciu skasowanym.",
            );

            // Rada „poproś o nową paczkę" należy WYŁĄCZNIE do gałęzi w drodze
            // i ma paść dokładnie raz, mimo trzech braków obok siebie.
            $this->assertSame(
                1,
                substr_count($tresc, self::RADA_DLA_W_DRODZE),
                "Plik {$plik} powtarza radę o nowej paczce przy brakach, których ona nie dotyczy.",
            );
        }

        $dane = $this->dane($paczka)['o_tym_pliku'];
        $this->assertSame(1, $dane['zdjec_jeszcze_w_przygotowaniu']);
        $this->assertSame(1, $dane['zdjec_odrzuconych_przy_przygotowaniu']);
        $this->assertSame(1, $dane['zdjec_skasowanych']);
    }

    public function test_liczebnik_w_zdaniu_o_brakach_jest_odmieniony_po_polsku(): void
    {
        // „5 zdjęć nie weszły" to zdanie, po którym człowiek widzi, że pisał
        // je automat. W pliku czytanym przed skasowaniem konta to jest
        // ostatnia rzecz, jakiej potrzeba. Nastki (12-14) biorą formę „wiele"
        // mimo końcówki 2-4 — dlatego 12, a nie tylko 5.
        $przypadki = [
            1 => '1 zdjęcie nie weszło do tej paczki i nie wejdzie do żadnej następnej',
            3 => '3 zdjęcia nie weszły do tej paczki i nie wejdą do żadnej następnej',
            5 => '5 zdjęć nie weszło do tej paczki i nie wejdzie do żadnej następnej',
            12 => '12 zdjęć nie weszło do tej paczki i nie wejdzie do żadnej następnej',
        ];

        foreach ($przypadki as $ile => $oczekiwane) {
            $basia = $this->user('odrzucone'.$ile);

            for ($i = 0; $i < $ile; $i++) {
                $this->zdjecie($basia, 'spalony-'.$i, Media::STATUS_REJECTED);
            }

            $index = $this->jednymWierszem(
                $this->zArchiwum($this->zbudujPaczke($basia), 'index.html'),
            );

            $this->assertStringContainsString(
                $oczekiwane,
                $index,
                "Przy {$ile} zdjęciach odrzuconych liczebnik jest odmieniony źle.",
            );
        }
    }

    public function test_dane_json_opisuje_regule_bezwarunkowo_takze_przy_zerze(): void
    {
        /*
         * GRANICA NAZWANA W #678, ROZSTRZYGNIĘTA TU ŚWIADOMIE.
         *
         * `dane.json` czyta PROGRAM i jest opisem REGUŁY eksportu, nie
         * zawartości tego jednego archiwum — dlatego zdanie o zdjęciach,
         * które nie wchodzą nigdy, stoi tam BEZWARUNKOWO, a oba liczniki
         * są obecne także przy zerze. Klucz pojawiający się tylko przy
         * brakach zmuszałby czytający program do zgadywania, czy zera nie
         * ma, bo braków nie było, czy dlatego, że paczkę zbudowała starsza
         * wersja serwisu.
         *
         * `index.html` i README czyta CZŁOWIEK i opisują mu TĘ paczkę —
         * tam to samo zdanie stoi pod warunkiem `> 0` (pilnuje tego
         * `test_paczka_z_kompletem_zdjec_nie_dostaje_ostrzezenia_o_brakach`).
         * Ta asymetria jest dokładnie tą samą, którą #678 nazwało przy
         * cudzych przepisach w zeszycie, i z tego samego powodu.
         */
        $basia = $this->user('basia');
        $this->zdjecie($basia, 'sernik', Media::STATUS_READY);

        $dane = $this->dane($this->zbudujPaczke($basia));

        $this->assertArrayHasKey('zdjec_odrzuconych_przy_przygotowaniu', $dane['o_tym_pliku']);
        $this->assertArrayHasKey('zdjec_skasowanych', $dane['o_tym_pliku']);
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_odrzuconych_przy_przygotowaniu']);
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_skasowanych']);

        $this->assertStringContainsString(
            'nie wejdą do żadnej paczki',
            $dane['o_tym_pliku']['czego_nie_zawiera'],
            '`czego_nie_zawiera` przestało opisywać regułę, mimo że opisuje regułę, nie to archiwum.',
        );

        // Granica z #678 zostaje nietknięta — dokładanie zdania nie ma
        // prawa zjeść poprzedniego.
        $this->assertStringContainsString('cudzych przepisów', $dane['o_tym_pliku']['czego_nie_zawiera']);
    }

    public function test_zakres_danych_paczki_nie_rosnie_o_zdjecia_ktore_do_niej_nie_weszly(): void
    {
        // Kryterium odbioru mówi wprost: dokładamy WYŁĄCZNIE informację
        // o brakach. Ani jednego pliku, ani identyfikatora, ani powodu
        // odrzucenia z `metadata.failure_reason`.
        $basia = $this->user('basia');
        $spalony = $this->zdjecie($basia, 'spalony', Media::STATUS_REJECTED);
        $skasowany = $this->zdjecie($basia, 'nieudany', Media::STATUS_DELETED);

        $paczka = $this->zbudujPaczke($basia);
        $json = $this->zArchiwum($paczka, 'dane.json');

        $this->assertSame([], $this->zdjeciaWArchiwum($paczka));
        $this->assertSame([], $this->dane($paczka)['zdjecia']);

        foreach ([$spalony, $skasowany] as $zdjecie) {
            $this->assertStringNotContainsString(
                (string) $zdjecie->getKey(),
                $json,
                'Do paczki weszło coś o zdjęciu, którego w niej nie ma — to rozszerzenie zakresu eksportu.',
            );
            $this->assertStringNotContainsString((string) $zdjecie->object_key, $json);
        }

        $this->assertStringNotContainsString('failure_reason', $json);
        $this->assertStringNotContainsString('processing_failed', $json);
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    /**
     * Treść pliku z paczki sprowadzona do jednego wiersza.
     *
     * Oba pliki łamią wiersze — README twardo, `index.html` w Blade — więc
     * asercja na zdanie dłuższe niż kilka słów trafiałaby w `\n` i oblewała
     * mimo poprawnego tekstu. Normalizujemy białe znaki, nie treść.
     */
    private function jednymWierszem(string $tresc): string
    {
        return (string) preg_replace('/\s+/u', ' ', $tresc);
    }

    private function zdjecie(User $wlasciciel, string $nazwa, string $status): Media
    {
        $klucz = "media/{$wlasciciel->getKey()}/{$nazwa}.webp";

        // Plik JEST na dysku także dla odrzuconego i skasowanego — inaczej
        // test dowodziłby tylko tego, że paczka nie potrafi spakować czegoś,
        // czego nie ma. Pilnujemy decyzji o statusie, nie braku bajtów.
        Storage::disk('public')->put($klucz, 'udawana-zawartosc-zdjecia');

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'object_key' => $klucz,
            'status' => $status,
        ]);
    }

    private function zbudujPaczke(User $user): DataExport
    {
        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return $export->refresh();
    }

    /** @return array<string, mixed> */
    private function dane(DataExport $export): array
    {
        return json_decode(
            $this->zArchiwum($export, 'dane.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function zArchiwum(DataExport $export, string $plik): string
    {
        $zip = $this->otworz($export);
        $tresc = $zip->getFromName($plik);
        $zip->close();

        $this->assertIsString($tresc, "W archiwum nie ma pliku {$plik}.");

        return $tresc;
    }

    /** @return list<string> nazwy plików w katalogu `zdjecia/` */
    private function zdjeciaWArchiwum(DataExport $export): array
    {
        $zip = $this->otworz($export);
        $pliki = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nazwa = $zip->getNameIndex($i);

            if (is_string($nazwa) && str_starts_with($nazwa, 'zdjecia/')) {
                $pliki[] = $nazwa;
            }
        }

        $zip->close();

        return $pliki;
    }

    private function otworz(DataExport $export): ZipArchive
    {
        $zip = new ZipArchive;

        $this->assertTrue(
            $zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true,
            'Nie udało się otworzyć paczki ZIP.',
        );

        return $zip;
    }
}
