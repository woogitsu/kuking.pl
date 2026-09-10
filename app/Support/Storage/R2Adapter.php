<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Aws\S3\MultipartUploader;
use Aws\S3\ObjectUploader;
use Aws\S3\S3ClientInterface;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\AwsS3V3\PortableVisibilityConverter;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;

/**
 * Adapter Flysystem dla Cloudflare R2 — ZAPIS BEZ ANI JEDNEGO ACL.
 *
 * =========================================================================
 *  PO CO TO ISTNIEJE (issue #120, audyt G-02 i G-11)
 * =========================================================================
 *
 * Cloudflare R2 nie implementuje S3-owych ACL na obiektach: `x-amz-acl` jest
 * w tabeli zgodności oznaczony jako NIEOBSŁUGIWANY dla `PutObject`,
 * a `GetObjectAcl` i `PutObjectAcl` nie istnieją tam wcale. Publiczność w R2
 * jest cechą BUCKETU (własna domena albo `r2.dev`), nie obiektu.
 *
 * Domyślny adapter `league/flysystem-aws-s3-v3` (3.35.3) wysyła ACL ZAWSZE,
 * także wtedy, gdy nikt o widoczność nie prosił — `AwsS3V3Adapter::upload()`:
 *
 *     $acl = $options['params']['ACL'] ?? $this->determineAcl($config);
 *     $this->client->upload($this->bucket, $key, $body, $acl, $options);
 *
 * a `determineAcl()` przy braku widoczności zwraca `private`. Przez tę
 * ścieżkę NIE MA sposobu, żeby nie wysłać ACL w ogóle. Zdjęcie trzeciego
 * argumentu z `put()` (poprzedni krok, G-01) usunęło więc `public-read`,
 * ale nie usunęło nagłówka: każde `PutObject` szło z `x-amz-acl: private`.
 *
 * Dziś to przechodzi, bo R2 traktuje `private` jak brak żądania ACL —
 * w odróżnieniu od `public-read`, które szło tam wcześniej dla wariantów.
 * ALE TO NIE JEST ZACHOWANIE GWARANTOWANE PRZEZ CLOUDFLARE. Cała prywatność
 * oryginałów (pełny EXIF, czyli współrzędne GPS kuchni) opierała się na
 * życzliwości cudzej implementacji nagłówka, którego ta implementacja nie
 * obsługuje. To była bramka przed wystawieniem produkcyjnego bucketu pod
 * `cdn.kuking.pl`.
 *
 * =========================================================================
 *  CO TEN ADAPTER ROBI INACZEJ
 * =========================================================================
 *
 * Dziedziczy po `AwsS3V3Adapter` — odczyt, metadane, listowanie, `delete`,
 * adresy publiczne i podpisane zostają dokładnie te, co dotąd — i nadpisuje
 * WYŁĄCZNIE te operacje, które dotykają ACL:
 *
 *   write/writeStream  własny `PutObject` (albo multipart) BEZ `ACL`;
 *   createDirectory    znacznik katalogu też bez `ACL`;
 *   copy               `CopyObject` bez `ACL` i bez `GetObjectAcl`;
 *   setVisibility      wyjątek — na R2 nie ma czego ustawiać;
 *   visibility         wyjątek — `GetObjectAcl` na R2 nie istnieje.
 *
 * `upload()` w klasie nadrzędnej jest prywatne, więc nadpisanie `write()`
 * i `writeStream()` odcina JEDYNĄ drogę, którą ACL mogło stąd wyjść.
 * Klienta, bucket i prefiks trzymamy więc u siebie (pola rodzica są
 * prywatne) i przekazujemy je jeszcze raz do konstruktora rodzica, żeby
 * odziedziczone metody odczytu widziały to samo miejsce.
 *
 * DLACZEGO NADPISANIE ADAPTERA, A NIE WŁASNA WĄSKA USŁUGA MAGAZYNU
 * Issue #120 dopuszczało obie drogi. Wybraliśmy adapter, bo `docs/MEDIA_PIPELINE.md`
 * („Kod biznesowy korzysta z Laravel Filesystem") jest już rozstrzygnięciem:
 * dzięki tej warstwie dysk lokalny w testach, dysk publiczny przy pracy
 * lokalnej i R2 na produkcji to jedna i ta sama ścieżka kodu. Własna usługa
 * wokół `putObject/getObject` wymagałaby przepisania każdego miejsca zapisu
 * i odczytu (zdjęcia, warianty, paczki RODO, health check, komenda
 * przenosząca buckety), a przy okazji straciłaby `Storage::fake()` w testach
 * i `temporaryUrl()` w `MediaController`. Zysk byłby żaden: ACL trzeba
 * wyciąć z JEDNEGO miejsca — z żądania `PutObject`.
 *
 * CZEGO TEN KOD NIE UDOWODNI: że prawdziwy bucket R2 przyjmuje takie żądanie
 * i że oryginał naprawdę nie jest publiczny. Tego nie widać z PHP — bramka
 * z issue #120 (dwanaście punktów) zostaje ręcznym przebiegiem na prawdziwym
 * R2. Ten adapter sprawia, że jest co przez tę bramkę przepuścić.
 */
class R2Adapter extends AwsS3V3Adapter
{
    /**
     * Próg, od którego zapis idzie multipartem — ten sam co w AWS SDK (16 MB).
     *
     * Jedno zdjęcie ma limit 15 MB (`kuking.media.max_bytes`), więc potok
     * zdjęć nigdy tu nie dochodzi i zawsze idzie jednym `PutObject`.
     * Multipart jest dla paczek RODO (`r2_eksporty`): kopia całego konta
     * razem ze zdjęciami bywa dużo większa, a `writeStream()` dostaje wtedy
     * strumień o NIEZNANYM rozmiarze — taki też musi przejść.
     */
    private const PROG_MULTIPART = ObjectUploader::DEFAULT_MULTIPART_THRESHOLD;

    /**
     * Parametry, których do R2 nie wysyłamy NIGDY — nawet gdy ktoś wpisze je
     * w `options` dysku albo poda w wywołaniu.
     *
     * To sedno tej klasy. `ACL` i cała rodzina `Grant*` to jeden mechanizm:
     * uprawnienia na obiekcie, których R2 nie zna. Wysłanie ich nie daje
     * ani prywatności, ani publiczności — daje tylko nagłówek, o który
     * cudza implementacja może się potknąć.
     */
    private const ZAKAZANE_PARAMETRY = [
        'ACL',
        'GrantFullControl',
        'GrantRead',
        'GrantReadACP',
        'GrantWriteACP',
    ];

    private const WYJASNIENIE_ACL = 'Cloudflare R2 nie zna widoczności obiektu (ACL). '
        .'Publiczność jest cechą bucketu — ustaw ją w panelu Cloudflare, nie w kodzie.';

    private readonly PathPrefixer $prefikser;

    private readonly MimeTypeDetector $detektorMime;

    /**
     * @param  array<string, mixed>  $opcje  Domyślne opcje dysku (`options` w `config/filesystems.php`).
     */
    public function __construct(
        private readonly S3ClientInterface $klient,
        private readonly string $bucket,
        string $prefiks = '',
        private readonly array $opcje = [],
        bool $streamReads = false,
        ?MimeTypeDetector $detektorMime = null,
    ) {
        // Rodzicowi podajemy to samo, bo z niego bierzemy odczyt, metadane,
        // listowanie i `delete`. Konwerter widoczności jest mu wymagany
        // w konstruktorze, ale w naszych ścieżkach zapisu nie jest już
        // pytany o nic.
        parent::__construct(
            $klient,
            $bucket,
            $prefiks,
            new PortableVisibilityConverter,
            $detektorMime,
            $opcje,
            $streamReads,
        );

        $this->prefikser = new PathPrefixer($prefiks);
        $this->detektorMime = $detektorMime ?? new FinfoMimeTypeDetector;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->odrzucWidocznosc($config);

        $this->wyslij($path, $contents, $config);
    }

    /**
     * @param  resource  $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->odrzucWidocznosc($config);

        $this->wyslij($path, $contents, $config);
    }

    /**
     * Znacznik „katalogu" — na R2 katalogów nie ma, jest pusty obiekt z `/`
     * na końcu klucza.
     *
     * Nie woła `odrzucWidocznosc()`: Flysystem sam wstawia tu domyślną
     * widoczność katalogów, więc żądanie widoczności nie pochodzi wtedy od
     * nikogo z tego repozytorium. I tak jest ignorowana.
     */
    public function createDirectory(string $path, Config $config): void
    {
        $this->wyslij(rtrim($path, '/').'/', '', $config);
    }

    /**
     * Na R2 nie ma czego ustawić — i lepiej powiedzieć to wyjątkiem niż
     * po cichu nic nie zrobić.
     *
     * Ciche `return` byłoby powtórzeniem błędu, który to zgłoszenie naprawia:
     * kod wyglądałby, jakby ustawiał prywatność, a nie ustawiałby niczego.
     */
    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, self::WYJASNIENIE_ACL);
    }

    /**
     * `GetObjectAcl` na R2 nie istnieje, więc pytanie o widoczność obiektu
     * nie ma tam odpowiedzi. Wyjątek, nie zgadywanie: „private" byłoby
     * nieprawdą dla bucketu z własną domeną, a „public" dla bucketu bez niej.
     */
    public function visibility(string $path): FileAttributes
    {
        throw UnableToRetrieveMetadata::visibility($path, self::WYJASNIENIE_ACL);
    }

    /**
     * `CopyObject` bez `ACL`.
     *
     * Wersja z klasy nadrzędnej jest na R2 podwójnie nie do użycia: najpierw
     * woła `visibility($source)`, czyli `GetObjectAcl` (nie istnieje), a potem
     * dokłada wyliczone ACL do samego kopiowania.
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) {
            return;
        }

        $klucz = $this->prefikser->prefixPath($source);

        $parametry = $this->parametryZapisu($config) + [
            'Bucket' => $this->bucket,
            'Key' => $this->prefikser->prefixPath($destination),
            // Format i kodowanie jak w `Aws\S3\ObjectCopier::getSourcePath()`.
            'CopySource' => '/'.$this->bucket.'/'.rawurlencode($klucz),
            'MetadataDirective' => (string) ($config->get('MetadataDirective') ?? 'COPY'),
        ];

        try {
            $this->klient->copyObject($parametry);
        } catch (Throwable $e) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $e);
        }
    }

    /**
     * Jedyna droga zapisu w tej klasie — i jedyne miejsce, które buduje
     * parametry żądania. Bez `ACL`, bez `Grant*`.
     *
     * @param  string|resource  $cialo
     */
    private function wyslij(string $path, $cialo, Config $config): void
    {
        $klucz = $this->prefikser->prefixPath($path);
        $parametry = $this->parametryZapisu($config);

        // Typ treści ustawiamy tak samo jak adapter nadrzędny. Bez tego
        // wariant WebP wyjechałby z R2 jako `application/octet-stream`,
        // czyli jako plik do pobrania, a nie zdjęcie do pokazania.
        if (! array_key_exists('ContentType', $parametry)) {
            // Tak samo jak w adapterze nadrzędnym: przy strumieniu detektor
            // nie ma czego obejrzeć i zjeżdża na rozszerzenie klucza.
            $mime = $this->detektorMime->detectMimeType($klucz, $cialo);

            if ($mime !== null) {
                $parametry['ContentType'] = $mime;
            }
        }

        $strumien = Utils::streamFor($cialo);

        // Strumień od wywołującego zamykamy PRZEZ ODCZEPIENIE, nie przez
        // `fclose` — tak samo jak `Aws\S3\ObjectUploader`. Kod, który podał
        // ten uchwyt (np. `PrzeniesZdjeciaDoNowychBucketow`), zamyka go sam.
        if (is_resource($cialo)) {
            $strumien = \Aws\detach_on_close_stream($strumien);
        }

        $ustawienia = $config->withDefaults($this->opcje);
        $prog = (int) ($ustawienia->get('mup_threshold') ?? self::PROG_MULTIPART);
        $rozmiar = $strumien->getSize();

        try {
            // Rozmiar znany i poniżej progu — jedno `PutObject`. Tą drogą
            // idzie każde zdjęcie i każdy wariant.
            if ($rozmiar !== null && $rozmiar < $prog) {
                $this->klient->putObject($parametry + [
                    'Bucket' => $this->bucket,
                    'Key' => $klucz,
                    'Body' => $strumien,
                ]);

                return;
            }

            // Multipart. `MultipartUploader` dokłada `ACL` do
            // `CreateMultipartUpload` tylko wtedy, gdy w konfiguracji jest
            // klucz `acl` (`isset()` w `MultipartUploadingTrait::getInitiateParams()`) —
            // my go tu NIE podajemy, więc nie idzie żadne ACL.
            $konfiguracja = [
                'bucket' => $this->bucket,
                'key' => $klucz,
                'params' => $parametry,
            ];

            foreach (['part_size', 'concurrency', 'before_upload', 'add_content_md5'] as $opcja) {
                $wartosc = $ustawienia->get($opcja);

                if ($wartosc !== null) {
                    $konfiguracja[$opcja] = $wartosc;
                }
            }

            (new MultipartUploader($this->klient, $strumien, $konfiguracja))->upload();
        } catch (Throwable $e) {
            throw UnableToWriteFile::atLocation($path, $e->getMessage(), $e);
        }
    }

    /**
     * Parametry żądania z konfiguracji dysku i z wywołania — z wyciętym ACL.
     *
     * Filtrujemy po liście `AVAILABLE_OPTIONS` klasy nadrzędnej, a nie po
     * własnej kopii: gdy nowa wersja Flysystema dołoży tam parametr, dostaniemy
     * go za darmo, a `ZAKAZANE_PARAMETRY` i tak zostaną odcięte.
     *
     * @return array<string, mixed>
     */
    private function parametryZapisu(Config $config): array
    {
        $ustawienia = $config->withDefaults($this->opcje);
        $parametry = [];

        foreach (self::AVAILABLE_OPTIONS as $opcja) {
            if (in_array($opcja, self::ZAKAZANE_PARAMETRY, true)) {
                continue;
            }

            $wartosc = $ustawienia->get($opcja, '__NIE_USTAWIONO__');

            if ($wartosc !== '__NIE_USTAWIONO__') {
                $parametry[$opcja] = $wartosc;
            }
        }

        // Własna nazwa Flysystema na typ treści.
        $mimetype = $ustawienia->get('mimetype');

        if ($mimetype !== null) {
            $parametry['ContentType'] = $mimetype;
        }

        return $parametry;
    }

    /**
     * Zapis z JAWNIE podaną widocznością jest błędem, nie życzeniem.
     *
     * `Storage::disk('r2')->put($klucz, $bajty, 'public')` wyglądało jak
     * ustawienie publiczności i nie ustawiało niczego — dokładnie ten rodzaj
     * kodu-obietnicy, który stoi za tym zgłoszeniem. Teraz taki zapis padnie
     * od razu, przy pierwszym uruchomieniu, a nie po wystawieniu bucketu.
     */
    private function odrzucWidocznosc(Config $config): void
    {
        if ($config->get(Config::OPTION_VISIBILITY) === null) {
            return;
        }

        throw new \InvalidArgumentException(
            'Zapis na dysk R2 z podaną widocznością obiektu. '.self::WYJASNIENIE_ACL,
        );
    }
}
