<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use App\Support\Odmiana;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Próba odtworzenia zdjęć z migawki kopii NA WSKAZANY DYSK TESTOWY (#617).
 *
 * PO CO
 * Kopia, z której nikt nie odtworzył ani jednego zdjęcia, jest zamiarem.
 * Runbook (`docs/infra/DR_ZDJEC_R2.md` §7.1) każe właścicielowi zmierzyć
 * odtworzenie i wpisać RPO oraz RTO. Ta komenda robi z migawki to, co zrobiłby
 * prawdziwy restore — ale ZAWSZE na dysk, który nie jest żadnym żywym
 * magazynem zdjęć, więc próbę można powtarzać bez ryzyka dla produkcji.
 *
 * CO ROBI (dla kilku wskazanych zdjęć ze stanem `ready`)
 *  1. lista pochodzi Z BAZY (wiersze `media`), nigdy z zawartości kopii —
 *     obiekty kont usuniętych po migawce nie mają wiersza i nie wracają;
 *  2. czyta z migawki (`<prefiks>oryginaly/<object_key>`,
 *     `<prefiks>warianty/<klucz wariantu>`) — dysk kopii jest tylko czytany;
 *  3. oryginał sprawdza SHA-256 z `media.checksum_sha256` (sam rozmiar nie
 *     wystarcza), warianty rozmiarem z `metadata.variants.*.bytes`;
 *  4. dopiero zgodne bajty zapisuje na dysk docelowy (te same podkatalogi
 *     `oryginaly/` i `warianty/`) i czyta je z powrotem, żeby potwierdzić sumę;
 *  5. wypisuje liczby do tabeli §8 runbooka: obiekty, bajty, czas (RTO próby)
 *     i wiek migawki (RPO w chwili próby).
 *
 * BEZPIECZNIKI
 *  - bez `--wykonaj` niczego nie zapisuje (czyta i sprawdza sumy);
 *  - dysk docelowy musi istnieć w konfiguracji, nie może być dyskiem kopii ani
 *    żadnym dyskiem z zakazanej listy (żywe oryginały, warianty, stary bucket,
 *    eksporty, kopie bazy, `local`, `public`) ani dyskiem wskazywanym przez
 *    wiersze `media` z zakresu, ani wskazywać na to samo miejsce (bucket,
 *    katalog) co któryś z nich;
 *  - NIGDY nie kasuje i nie nadpisuje: obiekt, który na dysku docelowym już
 *    jest, ale ma inną zawartość, kończy próbę błędem;
 *  - domyślnie 3 zdjęcia (`--limit`), żeby próba nie zamieniła się w
 *    odtwarzanie całości; konkretne zdjęcia podaje się przez `--media`;
 *  - nie wypisuje kluczy obiektów ani komunikatów storage (#973, #1860).
 *
 * CZEGO NIE ROBI: nie odtwarza do serwisu. Odtworzenie na żywe buckety to
 * czynność właściciela z listą z bazy (runbook §7.1 krok 5–6, §9).
 */
class ProbaOdtworzeniaZdjec extends Command
{
    /** Dyski, na które próba nigdy nie zapisuje. */
    private const ZAKAZANE_DYSKI = [
        'r2', 'r2_publiczne', 'r2_legacy', 'r2_eksporty', 'r2_kopie', 'r2_kopia_zdjec',
        'local', 'public', 's3',
    ];

    protected $signature = 'kuking:proba-odtworzenia-zdjec
                            {--prefiks= : Katalog migawki, np. migawka-2026-09-28/ (wymagany)}
                            {--cel= : Dysk TESTOWY, na który zapisać odtworzone pliki, np. proba_odtworzenia (wymagany)}
                            {--kopia=r2_kopia_zdjec : Dysk z migawkami (tylko do odczytu)}
                            {--media=* : UUID zdjęcia z tabeli media; można podać kilka razy}
                            {--limit=3 : Bez --media: ile najnowszych gotowych zdjęć odtworzyć}
                            {--wykonaj : Naprawdę zapisz na dysk testowy; bez tego komenda tylko czyta i sprawdza sumy}';

    protected $description = 'Próba odtworzenia kilku zdjęć z migawki kopii na dysk testowy, z sumami i pomiarem czasu (#617)';

    public function handle(): int
    {
        $prefiks = (string) $this->option('prefiks');
        $celNazwa = (string) $this->option('cel');
        $kopiaNazwa = (string) $this->option('kopia');

        if ($prefiks === '' || $celNazwa === '') {
            $this->error('Podaj --prefiks (np. migawka-2026-09-28/) i --cel (dysk testowy, np. proba_odtworzenia).');

            return self::FAILURE;
        }

        if (! str_ends_with($prefiks, '/')) {
            $prefiks .= '/';
        }

        $ids = array_values(array_filter(array_map('strval', (array) $this->option('media')), fn (string $id) => $id !== ''));

        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                $this->error('To nie jest identyfikator zdjęcia. Podaj UUID z kolumny `id` tabeli media w --media.');

                return self::FAILURE;
            }
        }

        $limit = (int) $this->option('limit');

        if ($ids === [] && $limit < 1) {
            $this->error('Podaj --media=<uuid> albo --limit większy od zera.');

            return self::FAILURE;
        }

        $zdjecia = $this->zakres($ids, $limit);

        if ($zdjecia === []) {
            $this->error('Nie ma gotowego zdjęcia w tym zakresie. Sprawdź UUID: próba dotyczy tylko zdjęć w stanie `ready`.');

            return self::FAILURE;
        }

        $blad = $this->odmowaDysku($kopiaNazwa, $celNazwa, $zdjecia);

        if ($blad !== null) {
            $this->error($blad);

            return self::FAILURE;
        }

        $kopia = Storage::disk($kopiaNazwa);
        $cel = Storage::disk($celNazwa);
        $wykonaj = (bool) $this->option('wykonaj');

        if (! $wykonaj) {
            $this->line('Tryb bez zapisu: czytam migawkę i sprawdzam sumy. Żeby zapisać na dysk testowy, dodaj --wykonaj.');
        }

        $start = hrtime(true);
        $obiektow = 0;
        $bajtow = 0;
        $bledow = 0;

        foreach ($zdjecia as $zdjecie) {
            foreach ($this->pozycje($prefiks, $zdjecie) as $pozycja) {
                try {
                    $bajtow += $this->odtworz($kopia, $cel, $pozycja, $wykonaj);
                    $obiektow++;
                } catch (Throwable $e) {
                    $bledow++;
                    $this->error('BŁĄD media '.$zdjecie->getKey().' ('.$pozycja['co'].'): '.$this->opis($e));
                }
            }
        }

        $sekundy = (hrtime(true) - $start) / 1e9;

        $this->newLine();
        $this->info('Zdjęć w próbie: '.count($zdjecia).'.');
        $this->info('Obiekty '.($wykonaj ? 'odtworzone i sprawdzone' : 'sprawdzone w kopii').': '.$obiektow.' '
            .Odmiana::rzeczownik($obiektow, 'obiekt', 'obiekty', 'obiektów').', '.$bajtow.' B.');
        $this->info('Czas próby (RTO na tym zbiorze, bez wykrycia awarii i decyzji): '.number_format($sekundy, 2, ',', '').' s.');
        $this->line($this->wiekMigawki($prefiks));

        if ($bledow > 0) {
            $this->error('Błędów: '.$bledow.'. Próba NIEUDANA — nie wpisuj tego wyniku do tabeli w runbooku jako sukcesu.');
        }

        $this->line('Komenda niczego nie skasowała i nie dotknęła żywych bucketów.');

        return $bledow === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $ids
     * @return list<Media>
     */
    private function zakres(array $ids, int $limit): array
    {
        $zapytanie = Media::query()->where('status', Media::STATUS_READY);

        if ($ids !== []) {
            return $zapytanie->whereKey($ids)->orderBy('id')->get()->all();
        }

        return $zapytanie->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get()->all();
    }

    /**
     * @param  list<Media>  $zdjecia
     */
    private function odmowaDysku(string $kopiaNazwa, string $celNazwa, array $zdjecia): ?string
    {
        foreach (['kopia' => $kopiaNazwa, 'cel' => $celNazwa] as $rola => $nazwa) {
            if (! is_array(config("filesystems.disks.{$nazwa}"))) {
                return "Nie ma dysku `{$nazwa}` w config/filesystems.php. Podaj istniejący w --{$rola}.";
            }
        }

        if ($celNazwa === $kopiaNazwa) {
            return 'Dysk testowy nie może być dyskiem kopii: próba czyta z kopii i nigdy do niej nie zapisuje.';
        }

        if ($kopiaNazwa === 'r2_kopia_zdjec') {
            if ((string) config('filesystems.disks.r2_kopia_zdjec.bucket') === '') {
                return 'Kopia zdjęć nie jest skonfigurowana: AWS_ZDJECIA_KOPIA_BUCKET jest puste. Nie ma z czego odtwarzać — KOPII NIE MA. Kroki: docs/infra/DR_ZDJEC_R2.md.';
            }

            if (blank(config('filesystems.disks.r2_kopia_zdjec.key')) || blank(config('filesystems.disks.r2_kopia_zdjec.secret'))) {
                return 'Ustaw AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID i AWS_ZDJECIA_KOPIA_SECRET_ACCESS_KEY: token TYLKO DO ODCZYTU bucketu kopii.';
            }
        }

        $zywe = array_merge(
            self::ZAKAZANE_DYSKI,
            [(string) config('kuking.media.disk'), (string) config('kuking.media.public_disk')],
        );

        foreach ($zdjecia as $zdjecie) {
            $zywe[] = (string) $zdjecie->disk;
            $zywe[] = $zdjecie->variantsDisk();
        }

        $zywe = array_values(array_unique(array_filter($zywe)));

        if (in_array($celNazwa, $zywe, true)) {
            return "Dysk `{$celNazwa}` jest żywym magazynem albo dyskiem kopii. Próba zapisuje wyłącznie na osobny dysk testowy (np. proba_odtworzenia).";
        }

        $miejscaCelu = $this->miejsca($celNazwa);

        foreach ($zywe as $nazwa) {
            if (array_intersect($miejscaCelu, $this->miejsca($nazwa)) !== []) {
                return "Dysk `{$celNazwa}` wskazuje to samo miejsce co `{$nazwa}`. Próba zapisuje wyłącznie na osobny dysk testowy.";
            }
        }

        return null;
    }

    /**
     * Fizyczne miejsca dysku: bucket (s3) i/lub katalog (local). Dwa dyski
     * wskazują to samo, gdy mają choć jedno wspólne miejsce.
     *
     * @return list<string>
     */
    private function miejsca(string $nazwa): array
    {
        $konfig = config("filesystems.disks.{$nazwa}");
        $miejsca = [];

        if (! is_array($konfig)) {
            return $miejsca;
        }

        if (isset($konfig['bucket']) && (string) $konfig['bucket'] !== '') {
            $miejsca[] = 'bucket:'.$konfig['bucket'];
        }

        if (isset($konfig['root']) && (string) $konfig['root'] !== '') {
            $miejsca[] = 'katalog:'.rtrim((string) $konfig['root'], '/');
        }

        return $miejsca;
    }

    /**
     * @return list<array{co: string, zrodlo: string, cel: string, bajty: int|null, sha256: string|null}>
     */
    private function pozycje(string $prefiks, Media $zdjecie): array
    {
        $pozycje = [];

        if ((string) $zdjecie->object_key !== '') {
            $pozycje[] = [
                'co' => 'oryginał',
                'zrodlo' => $prefiks.'oryginaly/'.$zdjecie->object_key,
                'cel' => 'oryginaly/'.$zdjecie->object_key,
                // NIE `media.bytes`: to rozmiar przed zdjęciem GPS-u; oryginał sprawdza suma.
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
                'zrodlo' => $prefiks.'warianty/'.(string) $wariant['key'],
                'cel' => 'warianty/'.(string) $wariant['key'],
                'bajty' => isset($wariant['bytes']) ? (int) $wariant['bytes'] : null,
                'sha256' => null,
            ];
        }

        return $pozycje;
    }

    /**
     * Jeden obiekt: odczyt z kopii, weryfikacja, opcjonalny zapis i odczyt zwrotny.
     *
     * @param  array{co: string, zrodlo: string, cel: string, bajty: int|null, sha256: string|null}  $pozycja
     * @return int liczba bajtów obiektu
     */
    private function odtworz(Filesystem $kopia, Filesystem $cel, array $pozycja, bool $wykonaj): int
    {
        if (! $kopia->exists($pozycja['zrodlo'])) {
            throw new BladProbyOdtworzenia('BRAK W KOPII');
        }

        if ($pozycja['co'] === 'oryginał' && $pozycja['sha256'] === null) {
            throw new BladProbyOdtworzenia('BAZA NIE MA SUMY oryginału — nie da się stwierdzić, czy kopia jest ta sama');
        }

        $bufor = fopen('php://temp/maxmemory:8388608', 'w+b');

        if ($bufor === false) {
            throw new BladProbyOdtworzenia('Nie da się przygotować bufora.');
        }

        try {
            [$sha, $rozmiar] = $this->przepisz($kopia, $pozycja['zrodlo'], $bufor);

            if ($pozycja['bajty'] !== null && $rozmiar !== $pozycja['bajty']) {
                throw new BladProbyOdtworzenia('INNY ROZMIAR: baza '.$pozycja['bajty'].' B, kopia '.$rozmiar.' B');
            }

            if ($pozycja['sha256'] !== null && $sha !== $pozycja['sha256']) {
                throw new BladProbyOdtworzenia('INNA SUMA');
            }

            if (! $wykonaj) {
                return $rozmiar;
            }

            if ($cel->exists($pozycja['cel'])) {
                if ($this->sumaZDysku($cel, $pozycja['cel']) === $sha) {
                    return $rozmiar; // już odtworzone tą samą treścią — nic nie nadpisujemy
                }

                throw new BladProbyOdtworzenia('NA DYSKU TESTOWYM JEST INNY PLIK pod tym kluczem — nie nadpisuję; wskaż pusty dysk');
            }

            rewind($bufor);
            $cel->writeStream($pozycja['cel'], $bufor);

            if (! $cel->exists($pozycja['cel']) || $this->sumaZDysku($cel, $pozycja['cel']) !== $sha) {
                throw new BladProbyOdtworzenia('ODCZYT ZWROTNY NIEZGODNY po zapisie na dysk testowy');
            }

            return $rozmiar;
        } finally {
            fclose($bufor);
        }
    }

    /**
     * Kopiuje strumień z dysku do bufora, licząc SHA-256 w locie.
     *
     * @param  resource  $bufor
     * @return array{0: string, 1: int}
     */
    private function przepisz(Filesystem $dysk, string $klucz, $bufor): array
    {
        $strumien = $dysk->readStream($klucz);

        if (! is_resource($strumien)) {
            throw new BladProbyOdtworzenia('Nie da się odczytać obiektu z kopii.');
        }

        try {
            $hasz = hash_init('sha256');
            $rozmiar = 0;

            while (! feof($strumien)) {
                $kawalek = fread($strumien, 1048576);

                if ($kawalek === false) {
                    throw new BladProbyOdtworzenia('Odczyt z kopii przerwany.');
                }

                if ($kawalek === '') {
                    continue;
                }

                hash_update($hasz, $kawalek);
                $rozmiar += strlen($kawalek);
                fwrite($bufor, $kawalek);
            }

            return [hash_final($hasz), $rozmiar];
        } finally {
            fclose($strumien);
        }
    }

    private function sumaZDysku(Filesystem $dysk, string $klucz): string
    {
        $strumien = $dysk->readStream($klucz);

        if (! is_resource($strumien)) {
            throw new BladProbyOdtworzenia('Nie da się odczytać obiektu z dysku testowego.');
        }

        try {
            $hasz = hash_init('sha256');
            hash_update_stream($hasz, $strumien);

            return hash_final($hasz);
        } finally {
            fclose($strumien);
        }
    }

    /** Wiek migawki wynika z nazwy `migawka-RRRR-MM-DD/`; inna nazwa = brak liczby, nie zgadywanie. */
    private function wiekMigawki(string $prefiks): string
    {
        if (preg_match('~^migawka-(\d{4}-\d{2}-\d{2})/~', $prefiks, $m) !== 1) {
            return 'Wiek migawki (RPO): nazwa prefiksu nie ma postaci migawka-RRRR-MM-DD/, więc nie liczę. Policz ręcznie od daty ostatniej migawki.';
        }

        try {
            $data = CarbonImmutable::createFromFormat('!Y-m-d', $m[1], 'UTC');
        } catch (Throwable) {
            $data = null;
        }

        if ($data === null || $data->format('Y-m-d') !== $m[1]) {
            return 'Wiek migawki (RPO): data w nazwie prefiksu jest nieprawidłowa, więc nie liczę.';
        }

        $dni = (int) $data->diffInDays(CarbonImmutable::now('UTC')->startOfDay(), false);

        return 'Wiek migawki (RPO w chwili próby): '.$dni.' '.Odmiana::rzeczownik($dni, 'dzień', 'dni', 'dni').' (data z nazwy prefiksu).';
    }

    private function opis(Throwable $e): string
    {
        // Własne zdania tej komendy nie niosą kluczy; cudze wyjątki — tylko klasa i odcisk.
        return $e instanceof BladProbyOdtworzenia ? $e->getMessage() : BezpiecznyBlad::jednaLinia($e);
    }
}

/**
 * Wyjątek z własnym zdaniem po polsku, bez kluczy obiektów i bez treści storage.
 */
final class BladProbyOdtworzenia extends RuntimeException {}
