<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\Odmiana;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Znajduje wiersze `media`, które WSKAZUJĄ na plik, którego tam nie ma (#1031).
 *
 * PO CO TO ISTNIEJE
 * `kuking:przenies-zdjecia` w wersji sprzed 22 września 2026 uznawało brak
 * pliku w starym buckecie za poprawne przeniesienie: przestawiało `disk`
 * i `variants_disk` na nowe buckety, mimo że nie skopiowało ani jednego bajtu.
 * Taki wiersz wypadał z zapytania `where('disk', 'r2_legacy')`, więc kolejne
 * przebiegi już go nie widziały i nikt nie miał jak się o stracie dowiedzieć.
 * Poprawka w komendzie zamyka drogę na przyszłość, ale NIE cofa wierszy, które
 * zdążyły się tak przestawić. Do nich jest to narzędzie.
 *
 * CZEGO TO NIE ROBI
 * Nie kasuje, nie przestawia, nie kopiuje — ani jednego zapisu. Wyłącznie
 * `exists()` po dyskach i wypisanie wyniku. Decyzja, co zrobić z wierszem,
 * którego pliku nie ma nigdzie, jest decyzją właściciela danych, nie skryptu:
 * przy zerowej liczbie kopii zapasowych bazy produkcyjnej automatyczne
 * „sprzątanie" byłoby drugą, tym razem świadomą, bezpowrotną utratą.
 *
 * JAK CZYTAĆ WYNIK
 *  - DO ODZYSKANIA — pliku nie ma tam, gdzie wskazuje wiersz, ale LEŻY jeszcze
 *    w starym buckecie (`r2_legacy`). To jest do naprawienia: wystarczy
 *    przestawić wiersz z powrotem na `r2_legacy` i puścić `kuking:przenies-zdjecia`,
 *    które teraz kopiuje albo odmawia.
 *  - UTRACONE — pliku nie ma ani tam, gdzie wskazuje wiersz, ani w starym
 *    buckecie. Bajtów nie odzyska już żadna komenda.
 *
 * Kod wyjścia jest niezerowy, gdy cokolwiek brakuje — żeby dało się to wpiąć
 * w bramkę przed migracją bucketów (#619) i nie przeoczyć.
 */
class SprawdzZdjeciaPoPrzenosinach extends Command
{
    protected $signature = 'kuking:sprawdz-zdjecia-po-przenosinach
                            {--wszystkie-statusy : Sprawdź też zdjęcia inne niż `ready` (pending i deleted nie muszą mieć plików)}
                            {--tylko-utracone : Pokaż wyłącznie te, których nie ma także w starym buckecie}
                            {--limit=0 : Sprawdź najwyżej tyle wierszy (0 = wszystkie)}';

    protected $description = 'Raport (tylko odczyt): wiersze media wskazujące na pliki, których w buckecie nie ma';

    public function handle(): int
    {
        $stary = 'r2_legacy';
        $staryDziala = (string) config("filesystems.disks.{$stary}.bucket") !== '';

        if (! $staryDziala) {
            $this->warn(
                'Dysk `r2_legacy` nie ma ustawionego bucketu (AWS_LEGACY_BUCKET). '
                .'Raport powstanie, ale KAŻDY brak pokaże jako UTRACONE — bo nie ma gdzie '
                .'sprawdzić, czy plik leży jeszcze w starym buckecie.',
            );
        }

        $zapytanie = Media::query()->orderBy('created_at');

        if (! (bool) $this->option('wszystkie-statusy')) {
            // `pending` i `processing` jeszcze nie mają kompletu plików,
            // a `deleted` już go nie ma z założenia. Brak pliku znaczy tam
            // coś innego niż utratę i zalałby raport szumem.
            $zapytanie->where('status', Media::STATUS_READY);
        }

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $zapytanie->limit($limit);
        }

        $sprawdzone = 0;
        $doOdzyskania = 0;
        $utracone = 0;
        $bledy = 0;

        foreach ($zapytanie->cursor() as $zdjecie) {
            $sprawdzone++;

            try {
                $braki = $this->brakujaceKlucze($zdjecie, $stary, $staryDziala);
            } catch (Throwable $e) {
                $bledy++;
                $this->error('BŁĄD ODCZYTU: '.$zdjecie->getKey().' — '.$e->getMessage());

                continue;
            }

            if ($braki === []) {
                continue;
            }

            $czyUtracone = false;

            foreach ($braki as $brak) {
                if ($brak['werdykt'] === 'UTRACONE') {
                    $czyUtracone = true;
                }
            }

            if ($czyUtracone) {
                $utracone++;
            } else {
                $doOdzyskania++;
            }

            if ((bool) $this->option('tylko-utracone') && ! $czyUtracone) {
                continue;
            }

            $this->warn('media '.$zdjecie->getKey().' (disk='.$zdjecie->disk
                .', variants_disk='.($zdjecie->variants_disk ?? 'NULL').'):');

            foreach ($braki as $brak) {
                $this->warn('  - '.$brak['werdykt'].' '.$brak['co'].': '.$brak['klucz']);
            }
        }

        $this->newLine();
        $this->info('Sprawdzone wiersze: '.$sprawdzone.'.');

        $slowoDoOdzyskania = Odmiana::rzeczownik($doOdzyskania, 'wiersz', 'wiersze', 'wierszy');
        $slowoUtracone = Odmiana::rzeczownik($utracone, 'wiersz', 'wiersze', 'wierszy');

        $this->info("DO ODZYSKANIA (plik jest jeszcze w `{$stary}`): {$doOdzyskania} {$slowoDoOdzyskania}.");
        $this->info("UTRACONE (pliku nie ma nigdzie): {$utracone} {$slowoUtracone}.");

        if ($bledy > 0) {
            $this->error('Wierszy, których nie dało się sprawdzić: '.$bledy.'. Wynik jest NIEPEŁNY.');
        }

        if ($doOdzyskania === 0 && $utracone === 0 && $bledy === 0) {
            $this->info('Każdy sprawdzony wiersz ma swoje pliki tam, gdzie wskazuje.');

            return self::SUCCESS;
        }

        $this->warn('Ten raport niczego nie zmienił. Co dalej z tymi wierszami — decyzja właściciela danych.');

        return self::FAILURE;
    }

    /**
     * @return list<array{co: string, klucz: string, werdykt: string}>
     */
    private function brakujaceKlucze(Media $zdjecie, string $stary, bool $staryDziala): array
    {
        $braki = [];

        $doSprawdzenia = [];

        if ($zdjecie->object_key !== null && $zdjecie->object_key !== '') {
            $doSprawdzenia[] = ['co' => 'oryginał', 'klucz' => $zdjecie->object_key, 'dysk' => $zdjecie->disk];
        }

        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $nazwa => $wariant) {
            if (! is_array($wariant) || ! isset($wariant['key'])) {
                continue;
            }

            $doSprawdzenia[] = [
                'co' => 'wariant '.(string) $nazwa,
                'klucz' => (string) $wariant['key'],
                'dysk' => $zdjecie->variantsDisk(),
            ];
        }

        foreach ($doSprawdzenia as $pozycja) {
            if (Storage::disk($pozycja['dysk'])->exists($pozycja['klucz'])) {
                continue;
            }

            $wStarym = $staryDziala
                && $pozycja['dysk'] !== $stary
                && Storage::disk($stary)->exists($pozycja['klucz']);

            $braki[] = [
                'co' => $pozycja['co'],
                'klucz' => $pozycja['klucz'],
                'werdykt' => $wStarym ? 'DO ODZYSKANIA' : 'UTRACONE',
            ];
        }

        return $braki;
    }
}
