<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Media\OrientacjaZdjecia;
use App\Domain\Media\PodgladOdRazu;
use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Przetworzenie wgranego zdjęcia.
 *
 * Co się tu dzieje i dlaczego:
 *
 * 1. Obraz jest DEKODOWANY I ZAPISANY OD NOWA. Nie serwujemy nigdy pliku,
 *    który przyszedł od użytkownika. Re-enkodowanie zdejmuje przy okazji
 *    wszystkie metadane — w tym GPS z EXIF, czyli dokładny adres domu osoby,
 *    która zrobiła zdjęcie obiadu w kuchni. To nie jest opcja, to warunek.
 *
 * 2. Generujemy warianty (thumb/feed/large). Feed nigdy nie ładuje zdjęcia
 *    3000 px — na wolnym łączu to jest różnica między "działa" i "nie działa".
 *
 * 3. Dopiero na końcu status zmienia się na `ready`. Do tego momentu zdjęcie
 *    nie pokazuje się nigdzie w interfejsie.
 *
 * Jeśli cokolwiek pójdzie nie tak, a kolejka nie ma już prób, zdjęcie dostaje
 * status `rejected`, a powód ląduje w metadanych — użytkownik widzi wtedy
 * komunikat po polsku, a nie pustą ramkę. Między próbami zdjęcie zostaje
 * w `processing` (issue #1349): `rejected` jest dla widoku ostateczne.
 *
 * Zdjęcie NIGDY nie zostaje w `processing` — pilnuje tego zarówno `catch`
 * w `handle()`, jak i hook `failed()`. Ten drugi jest konieczny, bo przy
 * przekroczeniu `$timeout` proces dostaje sygnał w środku wykonania i nie ma
 * już żadnego wyjątku do przechwycenia: `catch` się nie wykona, a zdjęcie
 * zostałoby w `processing` na zawsze. Widok dla tego stanu mówi „odśwież
 * stronę za chwilę”, więc bez `failed()` człowiek dostaje obietnicę, która
 * nigdy się nie spełni, i nie ma w interfejsie żadnej drogi, żeby to naprawić
 * samodzielnie (issue #112). Ten sam mechanizm ma `GenerateUserExport`.
 */
class ProcessUploadedImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * Kolejka `media`, nie `default` (audyt W3-05).
     *
     * `docker/entrypoint.sh` uruchamia workera z `--queue=high,default,media,low`
     * i komentarz mówi, że interakcje użytkownika mają wyprzedzać ciężkie
     * przetwarzanie obrazów. Żaden job nie przypisywał się jednak do kolejki,
     * więc wszystkie lądowały na `default` — a kolejność w tej fladze nie
     * robiła nic.
     */
    private const KOLEJKA = 'media';

    public function __construct(public string $mediaId)
    {
        $this->onQueue(self::KOLEJKA);
    }

    public function handle(): void
    {
        // PRZEJĘCIE POD BLOKADĄ, NIE `find()` + `update()` (issue #1003).
        //
        // `deleted` znaczy w tym serwisie „kasowanie trwa" (D-083) i jest
        // TRWAŁĄ deklaracją, że nic już tego zdjęcia nie pokaże. Stary kod
        // odpuszczał wyłącznie `ready`, więc zadanie, które doczekało się
        // workera po rozpoczęciu kasowania, przestawiało `deleted` z powrotem
        // na `processing` i zapisywało świeże warianty do publicznego bucketu.
        //
        // Klucze wariantów (#601) zapisujemy W TEJ SAMEJ transakcji: kasowanie,
        // które przejmie wiersz po nas, widzi je od razu i posprząta każdy
        // plik, który zdążymy położyć.
        $media = $this->przejmij();

        if ($media === null) {
            return;
        }

        // Pliki, które TO zadanie naprawdę położyło w publicznym buckecie.
        // Tylko te wolno mu skasować, gdy okaże się, że zdjęcie odchodzi.
        $zapisane = [];

        try {
            // DWA DYSKI, NIE JEDEN (audyt G-01). Oryginał czytamy z bucketu
            // prywatnego, warianty zapisujemy do publicznego. Na R2
            // publiczność jest cechą bucketu, nie obiektu, więc trzymanie obu
            // w jednym buckecie wystawiało oryginały z EXIF-em i GPS-em pod
            // adresem dającym się wyprowadzić z adresu wariantu.
            $disk = Storage::disk($media->disk);
            $publiczny = Storage::disk($media->variantsDisk());
            $original = $disk->get($media->object_key);

            if ($original === null) {
                throw new \RuntimeException('Brak pliku źródłowego w storage.');
            }

            // `autoOrientation: false` — I TO NIE JEST OSTROŻNOŚĆ, TO
            // NAPRAWA PODWÓJNEGO OBROTU (audyt zewnętrzny T11).
            //
            // ZMIERZONE: plik 100×50 px z EXIF `Orientation = 6` (obróć
            // o 90° w prawo) wychodził z tego potoku jako wariant 100×50,
            // czyli POZIOMY — a poprawny wynik jest pionowy. Obrót liczył
            // się dwa razy.
            //
            // Dlaczego, mimo komentarza obok, że „GD nie czyta EXIF-u":
            // Intervention ma własny dekoder, który EXIF CZYTA, i domyślnie
            // orientuje obraz sam (`Config::$autoOrientation = true`,
            // `Drivers/Gd/Decoders/BinaryImageDecoder.php`). Nasze
            // `OrientacjaZdjecia` obracała go wtedy po raz drugi.
            //
            // WYŁĄCZAMY BIBLIOTEKĘ, A NIE USUWAMY WŁASNEGO OBROTU, i to
            // jest świadomy wybór między dwiema poprawkami:
            //   * zostawiamy jedną, jawną drogę obrotu, którą sami
            //     testujemy — zamiast polegać na domyślnej wartości
            //     biblioteki, która może się zmienić przy aktualizacji
            //     i cicho odwrócić zdjęcia wszystkim;
            //   * orientację i tak czytamy przy WGRANIU
            //     (`StoreUploadedImage`), bo zadanie w tle dostaje same
            //     bajty — ta wartość już istnieje i jest zapisana
            //     w `metadata`, więc nie ma czego oszczędzać na usuwaniu.
            //
            // Dla grupy 50+ obrócone zdjęcie nie jest drobiazgiem: osoba,
            // która wrzuci danie do góry nogami, nie zgłosi błędu — po
            // prostu przestanie wrzucać zdjęcia.
            $manager = ImageManager::gd(autoOrientation: false);

            // ZACZYNAMY OD PODGLĄDU, KTÓRY JUŻ JEST (issue #430).
            //
            // `metadata.variants` jest niżej NADPISYWANE w całości, więc bez
            // tej linii wariant `podglad` — zrobiony synchronicznie przy
            // wgraniu — wypadłby z metadanych, a jego plik ZOSTAŁBY
            // w publicznym buckecie na zawsze: `KasujZdjecie` chodzi właśnie
            // po `metadata.variants` i nie ma innego sposobu, żeby się o nim
            // dowiedzieć. Byłaby to sierota, której nie kasuje ani usunięcie
            // wpisu, ani wymazanie konta (RODO).
            //
            // Podgląd zostaje też dlatego, że jest uczciwym kandydatem
            // w `srcset`: 640 px między `thumb` (320) a `feed` (960).
            //
            // Stoi PIERWSZY w tablicy i to też ma znaczenie: gdy zadanie
            // padnie w połowie, `Media::url()` bierze „pierwszy lepszy
            // wygenerowany wariant", czyli właśnie jego.
            $variants = array_filter([
                PodgladOdRazu::NAZWA => $media->wariant(PodgladOdRazu::NAZWA),
            ]);

            $orientation = $media->metadata['exif_orientation'] ?? null;

            $sourceImage = $manager->read($original);
            OrientacjaZdjecia::zastosuj($sourceImage, $orientation);

            foreach (config('kuking.media.variants') as $name => $maxEdge) {
                // Dekodujemy bajty raz. Odczyt obiektu GD tworzy osobną ramkę,
                // a scaleDown zapisuje wynik w nowej bitmapie; źródło pozostaje
                // niezmienione. Każdy wariant powstaje z pełnej rozdzielczości,
                // nie z poprzedniej miniatury. Nie klonujemy dużej bitmapy GD.
                $image = $manager->read($sourceImage->core()->native());

                // scaleDown nigdy nie powiększa — małe zdjęcie zostaje małe,
                // zamiast być rozmyte na siłę.
                $image->scaleDown(width: $maxEdge, height: $maxEdge);

                $encoded = $image->toWebp(quality: 82);

                // Wariant idzie do PUBLICZNEGO prefiksu `media/`, oryginał
                // został w prywatnym `incoming/`. Liczy to `Media`, bo to
                // samo liczy `PodgladOdRazu` — dwie własne kopie tej
                // ścieżki dałyby pliki-sieroty w buckecie, bez żadnego
                // czerwonego testu (patrz `Media::kluczPublicznegoWariantu`).
                $variantKey = Media::kluczPublicznegoWariantu($media->object_key, $name);
                // BEZ `'public'`. Na R2 `x-amz-acl: public-read` jest wprost
                // nieobsługiwany dla `PutObject` — publiczność bierze się
                // z własnej domeny bucketu, a nie z ACL na obiekcie. Ten
                // argument nie dawał więc publiczności, a mógł żądanie wywrócić.
                //
                // Od issue #120 dyski R2 chodzą na własnym sterowniku `r2`
                // (`App\Support\Storage\R2Adapter`), który nie wysyła ACL
                // wcale — podanie tu widoczności byłoby dziś błędem, nie
                // pustym gestem, i padnie od razu.
                $publiczny->put($variantKey, (string) $encoded);
                $zapisane[] = $variantKey;

                $variants[$name] = [
                    'key' => $variantKey,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'bytes' => strlen((string) $encoded),
                ];
            }

            // PUBLIKACJA POD BLOKADĄ, Z PONOWNYM PYTANIEM O STAN (issue #1003).
            //
            // Kasowanie mogło zacząć się W TRAKCIE dekodowania — sprawdzenie
            // na początku zadania tego nie powie. Najgorszy przeplot:
            // kasowanie przejmuje wiersz, kasuje znane pliki i usuwa wiersz,
            // a dopiero potem to zadanie kładzie warianty. Końcowy `UPDATE`
            // nie ma już czego zmienić, a pliki zostają w publicznym buckecie
            // bez klucza w bazie — nic ich już nie znajdzie.
            //
            // Blokada wiersza szereguje nas z `KasujZdjecie::przejmij()`:
            // albo publikujemy pierwsi (i kasowanie zobaczy komplet w
            // `variants`), albo widzimy `deleted`/brak wiersza i sprzątamy
            // własne pliki. W transakcji nie ma ani jednego wejścia na dysk.
            $opublikowane = DB::transaction(function () use ($variants, $orientation): bool {
                $swieze = Media::query()->whereKey($this->mediaId)->lockForUpdate()->first();

                if ($this->odchodzi($swieze)) {
                    return false;
                }

                // Lista „w trakcie" znika po sukcesie: od tej chwili KAŻDY plik
                // ma swój klucz w `variants`, a dwa źródła prawdy o tym samym
                // pliku rozjechałyby się przy pierwszej zmianie listy wariantów.
                $metadane = array_merge($swieze->metadata ?? [], [
                    'variants' => $variants,
                    'exif_stripped' => true,
                    'orientation_applied' => $orientation !== null && $orientation !== 1,
                    'processed_at' => now()->toIso8601String(),
                ]);

                unset($metadane[Media::METADANE_WARIANTY_W_TRAKCIE]);

                $swieze->update([
                    'status' => Media::STATUS_READY,
                    'metadata' => $metadane,
                ]);

                return true;
            });

            if (! $opublikowane) {
                $this->sprzatnijWlasnePliki($media, $zapisane);
            }
        } catch (\Throwable $e) {
            Log::warning('Nie udało się przetworzyć zdjęcia', [
                'media_id' => $media->getKey(),
                'error' => $e->getMessage(),
            ]);

            // `rejected` NIE nadpisuje `deleted` (issue #1003). Zdjęcie, które
            // w międzyczasie zaczęło odchodzić, dostaje zamiast tego sprzątnięcie
            // plików, które to zadanie zdążyło położyć przed błędem.
            $zostaje = DB::transaction(function (): bool {
                $swieze = Media::query()->whereKey($this->mediaId)->lockForUpdate()->first();

                if ($this->odchodzi($swieze)) {
                    return false;
                }

                // `rejected` DOPIERO WTEDY, GDY KOLEJNEJ PRÓBY NIE BĘDZIE
                // (issue #1349). Widok traktuje `rejected` jako porażkę
                // ostateczną i radzi usunąć wpis — a kolejka za chwilę ponowi
                // zadanie, które może się udać. Między próbami zdjęcie zostaje
                // w `processing` (ustawionym przez `przejmij()`), z kluczami
                // wariantów w trakcie, a widok mówi „przygotowuje się". Ostatnią
                // próbę i timeout domyka `failed()`.
                if ($this->bedzieKolejnaProba()) {
                    return true;
                }

                $swieze->update([
                    'status' => Media::STATUS_REJECTED,
                    'metadata' => array_merge($swieze->metadata ?? [], [
                        'failure_reason' => 'processing_failed',
                    ]),
                ]);

                return true;
            });

            if (! $zostaje) {
                $this->sprzatnijWlasnePliki($media, $zapisane);

                return;
            }

            throw $e;
        }
    }

    /**
     * Przejmuje zdjęcie do przetworzenia: blokada wiersza, decyzja POD
     * blokadą i — w tej samej transakcji — status `processing` razem
     * z kluczami wariantów w trakcie.
     *
     * `null`, gdy wiersza nie ma, gdy zdjęcie jest już gotowe (spóźniona
     * kopia zadania) albo gdy odchodzi (`deleted`, issue #1003). Każdy inny
     * stan — `pending`, `processing` po przerwanej lub nieudanej próbie,
     * `rejected` z wcześniejszego zlecenia — wolno przetworzyć.
     */
    private function przejmij(): ?Media
    {
        return DB::transaction(function (): ?Media {
            $media = Media::query()->whereKey($this->mediaId)->lockForUpdate()->first();

            if ($media === null
                || $media->status === Media::STATUS_READY
                || $media->status === Media::STATUS_DELETED) {
                return null;
            }

            // KLUCZE WARIANTÓW ZAPISUJEMY, ZANIM POWSTANĄ PLIKI (#601).
            //
            // `KasujZdjecie` chodzi WYŁĄCZNIE po `metadata.variants`, a ta
            // tablica zapisuje się dopiero na końcu, razem ze statusem
            // `ready`. Dopóki zadanie nie skończy, pliki wariantów, które
            // już poszły do publicznego bucketu, NIE MAJĄ w bazie żadnego
            // klucza — więc nie skasuje ich ani usunięcie wpisu, ani
            // wymazanie konta (RODO), ani sprzątanie osieroconych.
            //
            // Zmierzone: zadanie przerwane na drugim wariancie zostawiało
            // plik pierwszego w publicznym buckecie NA ZAWSZE. Ta ścieżka
            // nie jest hipotetyczna — `$timeout` przy zdjęciu 50 Mpx ubija
            // proces W ŚRODKU pętli, bez żadnego `catch` (patrz `failed()`).
            //
            // Klucze są POLICZALNE Z GÓRY (`kluczPublicznegoWariantu` liczy
            // je z `object_key` i nazwy wariantu), więc zapisujemy całą listę
            // JEDNYM `update()` przed pętlą — zamiast dopisywać po każdym
            // pliku. Kasowanie klucza, pod którym plik nigdy nie powstał,
            // jest nieszkodliwe: `KasujZdjecie` sprawdza `exists()`.
            //
            // OSOBNY KLUCZ, NIE `variants`: `Media::wariantDoSerwowania()`
            // czyta `variants` i pokazałoby zdjęcie w połowie przetwarzania
            // pod nazwą wariantu, którego plik może jeszcze nie istnieć.
            $kluczeWTrakcie = [];

            foreach (array_keys(config('kuking.media.variants')) as $nazwaWariantu) {
                $kluczeWTrakcie[] = Media::kluczPublicznegoWariantu($media->object_key, (string) $nazwaWariantu);
            }

            $media->update([
                'status' => Media::STATUS_PROCESSING,
                'metadata' => array_merge($media->metadata ?? [], [
                    Media::METADANE_WARIANTY_W_TRAKCIE => $kluczeWTrakcie,
                ]),
            ]);

            return $media;
        });
    }

    /**
     * Czy kolejka jeszcze raz uruchomi to zadanie po wyjątku z `handle()`
     * (issue #1349, ten sam wzorzec co w `GenerateUserExport`).
     *
     * Bez zadania kolejki (`handle()` wołane wprost) nikt niczego nie ponowi,
     * więc porażka jest od razu ostateczna. `$tries` liczy WSZYSTKIE próby
     * razem z bieżącą — przy ostatniej kolejka woła już `failed()`.
     */
    private function bedzieKolejnaProba(): bool
    {
        return $this->job !== null && $this->attempts() < $this->tries;
    }

    /**
     * Czy zdjęcie odchodzi: wiersza już nie ma albo kasowanie go przejęło.
     *
     * Wymazanie konta i sprzątanie osieroconych przejmują wiersz przez
     * `KasujZdjecie`, więc oba zostawiają tu ten sam ślad (issue #1003).
     */
    private function odchodzi(?Media $media): bool
    {
        return $media === null || $media->status === Media::STATUS_DELETED;
    }

    /**
     * Kasuje warianty, które TO zadanie położyło w publicznym buckecie,
     * gdy okazało się, że zdjęcie odchodzi (issue #1003).
     *
     * Wyłącznie własne pliki: podgląd z wgrania i warianty sprzed tej próby
     * zna już `KasujZdjecie` z `metadata`. Kasujemy z weryfikacją przez
     * `exists()` — ten sam wzorzec co `KasujZdjecie::skasujZDysku()` — bo
     * cichy `false` z dysku `throw => false` nie jest dowodem. Porażka zostaje
     * w dzienniku z kluczem i dyskiem, bo bez nich nie da się tego dokończyć
     * ręcznie; wiersza, do którego można by klucz dopisać, może już nie być.
     *
     * @param  list<string>  $klucze
     */
    private function sprzatnijWlasnePliki(Media $media, array $klucze): void
    {
        $nazwaDysku = $media->variantsDisk();

        foreach ($klucze as $klucz) {
            try {
                $dysk = Storage::disk($nazwaDysku);

                if ($dysk->exists($klucz)) {
                    $dysk->delete($klucz);
                }

                if (! $dysk->exists($klucz)) {
                    continue;
                }
            } catch (\Throwable $e) {
                Log::error('Nie udało się usunąć wariantu zdjęcia, które odchodzi', [
                    'media_id' => $this->mediaId,
                    'dysk' => $nazwaDysku,
                    'klucz' => $klucz,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            Log::error('Wariant zdjęcia, które odchodzi, nadal istnieje po próbie usunięcia', [
                'media_id' => $this->mediaId,
                'dysk' => $nazwaDysku,
                'klucz' => $klucz,
            ]);
        }
    }

    /**
     * Ostatnia linia obrony: po wyczerpaniu prób — albo po timeoucie, po którym
     * nie ma wyjątku w `handle()` — zdjęcie nie może zostać w `processing`.
     *
     * Dekodowanie zdjęcia 45 Mpx i budowa trzech wariantów w GD to jest realnie
     * ten kawałek serwisu, który potrafi nie zmieścić się w limicie czasu
     * i pamięci workera. Bez tego hooka takie zdjęcie zostaje w stanie
     * przejściowym bez końca.
     *
     * LICZBY, O KTÓRE TU CHODZI (stało tu `--memory=384`, którego w tym
     * repozytorium nie ma — patrz D-064 §2):
     *   - `docker/entrypoint.sh` — `queue:work --memory="${QUEUE_MEMORY:-700}"`,
     *     MIĘKKI limit Laravela: kończy proces MIĘDZY jobami, więc nie ratuje
     *     joba, który przekroczył pamięć w środku. Ten hook ratuje.
     *   - `.railway/railway.ts` — kontener `worker` ma `memoryBytes: 1024 * MB`,
     *     twardy limit Railway (OOM-kill powyżej). To jest realny sufit.
     *   - `docker/php.ini` — `memory_limit=256M`, licznik PHP, który NIE widzi
     *     bufora GD: zmierzony szczyt RSS dla 50 Mpx to 452 MB przy liczniku
     *     pokazującym 28 MB (`docs/MEDIA_PIPELINE.md`).
     */
    public function failed(?\Throwable $e): void
    {
        $media = Media::find($this->mediaId);

        // `ready` zostawiamy nietknięte: `failed()` może dojść po spóźnionej
        // próbie, która i tak zakończyła się sukcesem. Cofnięcie gotowego
        // zdjęcia do `rejected` skasowałoby je z widoków bez powodu.
        //
        // `deleted` też (issue #1003): to trwała deklaracja „zdjęcie odchodzi"
        // (D-083), a nie stan przejściowy, który wolno nadpisać porażką.
        if ($media === null
            || $media->status === Media::STATUS_READY
            || $media->status === Media::STATUS_DELETED) {
            return;
        }

        Log::warning('Przetwarzanie zdjęcia nie powiodło się do końca', [
            'media_id' => $this->mediaId,
            // Bez treści wyjątku przy timeoucie — wtedy wyjątku po prostu nie ma.
            'error' => $e?->getMessage() ?? 'przekroczony limit czasu zadania',
        ]);

        $media->update([
            'status' => Media::STATUS_REJECTED,
            'metadata' => array_merge($media->metadata ?? [], [
                'failure_reason' => $e === null
                    ? 'processing_timeout'
                    : 'processing_failed_or_timeout',
            ]),
        ]);
    }
}
