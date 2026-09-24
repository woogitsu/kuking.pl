<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\Odmiana;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Porównuje wiersze `media` z jedną migawką kopii zdjęć (#617, D-256).
 *
 * PO CO TO ISTNIEJE
 * Kopia, której nikt nie porównał z bazą, jest zamiarem, a nie kopią. Ta
 * komenda odpowiada na trzy pytania, każde osobno:
 *
 *  - BRAK W KOPII — wiersz `ready` wskazuje plik, którego w migawce nie ma.
 *    Tego zdjęcia z tej migawki nie da się odtworzyć.
 *  - INNY ROZMIAR / INNA SUMA — plik jest, ale nie ten. Rozmiar wariantu
 *    porównuje się z `metadata.variants.*.bytes`. Sam rozmiar nie wystarcza
 *    (próba z #617: podmieniony bajt ma ten sam rozmiar), więc `--sumy`
 *    liczy SHA-256 oryginału i porównuje z `media.checksum_sha256`. Bez
 *    `--sumy` oryginał jest sprawdzany wyłącznie na obecność.
 *  - NADMIAROWE — obiekt w migawce, na który nie wskazuje już ŻADEN wiersz
 *    `media`. Najczęściej to zdjęcie konta usuniętego po zrobieniu migawki.
 *    NIE ODTWARZAĆ: kopia ma przeżyć żądanie usunięcia wyłącznie jako
 *    retencja techniczna, nigdy nie wracać do serwisu. Lista odtworzenia
 *    bierze się z bazy, nie z bucketu kopii.
 *
 * CZEGO TO NIE ROBI
 * Nie zapisuje, nie kasuje, nie kopiuje — ani w kopii, ani w żywych
 * bucketach, ani w bazie. Wyłącznie `exists()`, `size()`, `readStream()`
 * (przy `--sumy`) i `allFiles()` (przy `--nadmiarowe`). Test
 * `KopiaZdjecSprawdzanaTylkoOdczytemTest` podstawia dysk, który wybucha przy
 * każdej próbie zapisu. Odtworzenie jest czynnością właściciela, opisaną
 * w `docs/infra/DR_ZDJEC_R2.md`.
 *
 * UKŁAD MIGAWKI
 * `<prefiks>oryginaly/<object_key>` i `<prefiks>warianty/<klucz wariantu>` —
 * ten sam układ, który tworzą polecenia z runbooka. Rozjazd układu daje raport
 * „brakuje wszystkiego", a nie ciche zero: kod wyjścia jest wtedy niezerowy.
 */
class SprawdzKopieZdjec extends Command
{
    protected $signature = 'kuking:sprawdz-kopie-zdjec
                            {--dysk=r2_kopia_zdjec : Dysk z migawkami (tylko do odczytu)}
                            {--prefiks= : Katalog migawki, np. migawka-2026-09-24/}
                            {--sumy : Policz SHA-256 oryginałów (pobiera bajty; wolniej, żądania klasy B)}
                            {--nadmiarowe : Wypisz obiekty migawki, na które nie wskazuje żaden wiersz media}
                            {--limit=0 : Sprawdź najwyżej tyle wierszy (0 = wszystkie)}';

    protected $description = 'Raport (tylko odczyt): czy migawka kopii zdjęć zgadza się z wierszami media';

    public function handle(): int
    {
        $nazwaDysku = (string) $this->option('dysk');
        $prefiks = (string) $this->option('prefiks');

        if ($prefiks !== '' && ! str_ends_with($prefiks, '/')) {
            $prefiks .= '/';
        }

        if (! is_array(config("filesystems.disks.{$nazwaDysku}"))) {
            $this->error("Nie ma dysku `{$nazwaDysku}` w config/filesystems.php. Podaj istniejący w --dysk.");

            return self::FAILURE;
        }

        if ($nazwaDysku === 'r2_kopia_zdjec' && (string) config('filesystems.disks.r2_kopia_zdjec.bucket') === '') {
            $this->error(
                'Kopia zdjęć nie jest skonfigurowana: AWS_ZDJECIA_KOPIA_BUCKET jest puste. '
                .'Nie ma czego sprawdzić — to znaczy, że KOPII NIE MA. Kroki: docs/infra/DR_ZDJEC_R2.md.',
            );

            return self::FAILURE;
        }

        // Pusty klucz NIE znaczy „bez uprawnień": AWS SDK sięga wtedy po
        // `AWS_ACCESS_KEY_ID` ze środowiska, czyli po token aplikacji, który
        // kasuje w oryginałach. Czytać kopię takim tokenem to udawać, że
        // aplikacja nie ma do niej dostępu.
        if ($nazwaDysku === 'r2_kopia_zdjec'
            && (blank(config('filesystems.disks.r2_kopia_zdjec.key')) || blank(config('filesystems.disks.r2_kopia_zdjec.secret')))) {
            $this->error(
                'Ustaw AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID i AWS_ZDJECIA_KOPIA_SECRET_ACCESS_KEY: token TYLKO DO ODCZYTU '
                .'bucketu kopii. Bez nich biblioteka wzięłaby token aplikacji ze środowiska.',
            );

            return self::FAILURE;
        }

        if ($prefiks === '') {
            $this->warn('Bez --prefiks sprawdzam korzeń bucketu. Migawki z runbooka leżą w katalogach migawka-RRRR-MM-DD/.');
        }

        $dysk = Storage::disk($nazwaDysku);
        $sumy = (bool) $this->option('sumy');

        $zapytanie = Media::query()
            ->where('status', Media::STATUS_READY)
            ->orderBy('created_at')
            ->orderBy('id');

        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $zapytanie->limit($limit);
        }

        $sprawdzone = 0;
        $zRozbieznoscia = 0;
        $bledy = 0;

        foreach ($zapytanie->cursor() as $zdjecie) {
            $sprawdzone++;

            try {
                $rozbieznosci = $this->rozbieznosci($dysk, $prefiks, $zdjecie, $sumy);
            } catch (Throwable $e) {
                $bledy++;
                $this->error('BŁĄD ODCZYTU: media '.$zdjecie->getKey().' — '.$e->getMessage());

                continue;
            }

            if ($rozbieznosci === []) {
                continue;
            }

            $zRozbieznoscia++;
            $this->warn('media '.$zdjecie->getKey().':');

            foreach ($rozbieznosci as $linia) {
                $this->warn('  - '.$linia);
            }
        }

        $this->newLine();
        $this->info('Sprawdzone wiersze: '.$sprawdzone.'.');
        $this->info('Z rozbieżnością: '.$zRozbieznoscia.' '.Odmiana::rzeczownik($zRozbieznoscia, 'wiersz', 'wiersze', 'wierszy').'.');

        if (! $sumy) {
            $this->line('Sumy SHA-256 NIE były liczone (brak --sumy): zgodny rozmiar nie wyklucza podmienionych bajtów.');
        }

        if ((bool) $this->option('nadmiarowe')) {
            try {
                $this->wypiszNadmiarowe($dysk, $prefiks);
            } catch (Throwable $e) {
                $bledy++;
                $this->error('BŁĄD LISTOWANIA migawki: '.$e->getMessage());
            }
        }

        if ($bledy > 0) {
            $this->error('Odczytów, które się nie udały: '.$bledy.'. Wynik jest NIEPEŁNY.');
        }

        $this->line('Ten raport niczego nie zmienił — ani w kopii, ani w serwisie, ani w bazie.');

        if ($zRozbieznoscia === 0 && $bledy === 0) {
            $this->info('Każdy sprawdzony wiersz ma swoje pliki w tej migawce.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * Klucze, które migawka powinna mieć dla jednego zdjęcia.
     *
     * @return list<array{co: string, klucz: string, bajty: int|null, sha256: string|null}>
     */
    private function oczekiwane(string $prefiks, Media $zdjecie): array
    {
        $pozycje = [];

        if ((string) $zdjecie->object_key !== '') {
            $pozycje[] = [
                'co' => 'oryginał',
                'klucz' => $prefiks.'oryginaly/'.$zdjecie->object_key,
                // NIE `media.bytes`: to rozmiar pliku PRZED zdjęciem GPS-u,
                // a w buckecie leży plik po nim. Oryginał sprawdza suma —
                // `checksum_sha256` liczono z bajtów, które naprawdę zapisano.
                'bajty' => null,
                'sha256' => $zdjecie->checksum_sha256 !== null ? (string) $zdjecie->checksum_sha256 : null,
            ];
        }

        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $nazwa => $wariant) {
            if (! is_array($wariant) || ! isset($wariant['key'])) {
                continue;
            }

            $pozycje[] = [
                'co' => 'wariant '.(string) $nazwa,
                'klucz' => $prefiks.'warianty/'.(string) $wariant['key'],
                'bajty' => isset($wariant['bytes']) ? (int) $wariant['bytes'] : null,
                'sha256' => null,
            ];
        }

        return $pozycje;
    }

    /**
     * @return list<string>
     */
    private function rozbieznosci(Filesystem $dysk, string $prefiks, Media $zdjecie, bool $sumy): array
    {
        $linie = [];

        foreach ($this->oczekiwane($prefiks, $zdjecie) as $pozycja) {
            if (! $dysk->exists($pozycja['klucz'])) {
                $linie[] = 'BRAK W KOPII '.$pozycja['co'].': '.$pozycja['klucz'];

                continue;
            }

            if ($pozycja['bajty'] !== null) {
                $rozmiar = $dysk->size($pozycja['klucz']);

                if ($rozmiar !== $pozycja['bajty']) {
                    $linie[] = 'INNY ROZMIAR '.$pozycja['co'].': '.$pozycja['klucz']
                        .' (baza '.$pozycja['bajty'].' B, kopia '.$rozmiar.' B)';

                    continue;
                }
            }

            if ($sumy && $pozycja['sha256'] !== null && $this->sha256($dysk, $pozycja['klucz']) !== $pozycja['sha256']) {
                $linie[] = 'INNA SUMA '.$pozycja['co'].': '.$pozycja['klucz'];
            }
        }

        return $linie;
    }

    private function sha256(Filesystem $dysk, string $klucz): string
    {
        $strumien = $dysk->readStream($klucz);

        if (! is_resource($strumien)) {
            throw new \RuntimeException("Nie da się odczytać {$klucz} z kopii.");
        }

        try {
            $hasz = hash_init('sha256');
            hash_update_stream($hasz, $strumien);

            return hash_final($hasz);
        } finally {
            fclose($strumien);
        }
    }

    /**
     * Obiekty migawki bez żadnego wiersza `media` — w DOWOLNYM statusie.
     *
     * Wiersz `deleted` jeszcze istnieje (kasowanie plików trwa), więc jego
     * klucze też liczą się jako „znane". Nadmiarowe są tylko te, których baza
     * nie zna wcale — i tych nie wolno odtwarzać.
     */
    private function wypiszNadmiarowe(Filesystem $dysk, string $prefiks): void
    {
        $znane = [];

        foreach (Media::query()->cursor() as $zdjecie) {
            foreach ($this->oczekiwane($prefiks, $zdjecie) as $pozycja) {
                $znane[$pozycja['klucz']] = true;
            }
        }

        $nadmiarowe = 0;

        foreach (['oryginaly/', 'warianty/'] as $katalog) {
            foreach ($dysk->allFiles($prefiks.$katalog) as $klucz) {
                if (isset($znane[$klucz])) {
                    continue;
                }

                $nadmiarowe++;
                $this->line('  NADMIAROWE (nie odtwarzać): '.$klucz);
            }
        }

        $this->info('NADMIAROWE w migawce: '.$nadmiarowe.' '.Odmiana::rzeczownik($nadmiarowe, 'obiekt', 'obiekty', 'obiektów').'.');

        if ($nadmiarowe > 0) {
            $this->line(
                'To zwykle zdjęcia usunięte po zrobieniu migawki. Wygasną razem z nią. '
                .'Odtwarza się wyłącznie to, na co wskazuje baza.',
            );
        }
    }
}
