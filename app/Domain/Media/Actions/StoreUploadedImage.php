<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Media\PodgladOdRazu;
use App\Domain\Media\UsunGps;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\ProcessUploadedImage;
use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use App\Models\User;
use App\Support\RozpoznanieZdjecia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Przyjęcie zdjęcia od użytkownika.
 *
 * Zasada nadrzędna: NIE UFAMY NICZEMU, co przyszło od klienta — ani
 * rozszerzeniu, ani nagłówkowi Content-Type, ani nazwie pliku.
 *
 * Kolejność sprawdzeń jest celowa i idzie od najtańszej do najdroższej:
 *   1. rozmiar w bajtach (nie czytamy zawartości),
 *   2. czy PHP w ogóle rozpoznaje to jako obraz (getimagesize — czyta nagłówek),
 *   3. limit megapikseli — obrona przed "decompression bomb": plik 2 MB może
 *      się rozpakować do 30 000 × 30 000 px i położyć serwer,
 *   4. dopiero potem zapis i przetworzenie w tle.
 *
 * Wynikowy obiekt Media ma status `pending`. Widoki nie pokazują nic, co nie
 * jest `ready`, więc zdjęcie z nieusuniętym EXIF-em nigdy nie trafia na stronę.
 *
 * SYGNAŁ `photo_upload_failed` (issue #115)
 * Każdy `throw` niżej zapisuje NAJPIERW jeden wiersz `product_signals`
 * (przez `ZapiszSygnal` — jedyne miejsce, które tam pisze) i DOPIERO POTEM
 * rzuca wyjątek. Kolejność jest celowa: zapis sygnału jest opakowany
 * w try/catch WEWNĄTRZ `ZapiszSygnal::handle()`, więc nawet jego porażka
 * nie przeszkodzi w rzuceniu wyjątku, a komunikat i tak dojdzie do człowieka.
 *
 * Właściwości sygnału niosą wyłącznie `reason` i — gdzie to ma sens —
 * LICZBY (rozmiar w bajtach, megapiksele). NIGDY nazwy pliku od klienta:
 * `$file->getClientOriginalName()` jest dokładnie tym, czemu AGENTS.md §7
 * każe nie ufać, i tej samej natury co treść komentarza — a więc danymi
 * osobowymi, nie telemetrią.
 */
final class StoreUploadedImage
{
    public function __construct(
        private readonly ZapiszSygnal $sygnaly = new ZapiszSygnal,
        private readonly PodgladOdRazu $podglad = new PodgladOdRazu,
    ) {}

    public function handle(User $owner, UploadedFile $file, ?string $altText = null): Media
    {
        $maxBytes = (int) config('kuking.media.max_bytes');
        $bytes = $file->getSize();

        if ($bytes === false || $bytes <= 0) {
            $this->sygnaly->handle($owner, ZapiszSygnal::PHOTO_UPLOAD_FAILED, ['reason' => ZapiszSygnal::REASON_UNREADABLE]);

            throw new BladDlaCzlowieka('Nie udało się odczytać pliku. Spróbuj wybrać zdjęcie jeszcze raz.');
        }

        if ($bytes > $maxBytes) {
            $limitMb = (int) round($maxBytes / 1024 / 1024);

            $this->sygnaly->handle($owner, ZapiszSygnal::PHOTO_UPLOAD_FAILED, [
                'reason' => ZapiszSygnal::REASON_TOO_LARGE,
                'bytes' => $bytes,
                'max_bytes' => $maxBytes,
            ]);

            throw new BladDlaCzlowieka(
                "To zdjęcie waży za dużo. Maksymalny rozmiar to {$limitMb} MB — wybierz mniejsze zdjęcie.",
            );
        }

        // JEDNO SPRAWDZENIE, TO SAMO CO W WALIDACJI FORMULARZA (audyt W3-08).
        //
        // Wcześniej formularze używały laravelowej reguły `image`, a to jest
        // inna lista formatów niż nasza: bez AVIF, za to z GIF-em, BMP i SVG.
        // Zdjęcie AVIF odpadało więc w formularzu, mimo że potok umie je
        // przetworzyć, a komunikat wymieniał trzeci zestaw formatów.
        //
        // To sprawdzenie zostaje TUTAJ mimo reguły walidacyjnej: to jest
        // prawdziwa granica i musi trzymać także wtedy, gdy ktoś ominie
        // formularz. Reguła istnieje po to, żeby człowiek dostał komunikat
        // przy polu, a nie wyjątek.
        //
        // `rozpoznaj()`, NIE `coJestNieTak()` — potrzebujemy KODU powodu dla
        // sygnału, nie tylko komunikatu (patrz `WynikRozpoznania`).
        $wynik = RozpoznanieZdjecia::rozpoznaj($file->getRealPath());

        if ($wynik !== null) {
            $this->sygnaly->handle(
                $owner,
                ZapiszSygnal::PHOTO_UPLOAD_FAILED,
                ['reason' => $wynik->powod, ...$wynik->kontekst],
            );

            throw new BladDlaCzlowieka($wynik->komunikat);
        }

        $info = @getimagesize($file->getRealPath());

        // Po sprawdzeniu wyżej `getimagesize` nie może już zawieść — ale kod
        // niżej potrzebuje wymiarów i typu, a udawanie, że `false` się nie
        // zdarzy, kończy się „Trying to access array offset on bool".
        //
        // Ten `throw` jest dziś NIEOSIĄGALNY (patrz komentarz wyżej — gdyby
        // `getimagesize` miał zawieść, `RozpoznanieZdjecia::rozpoznaj()` już
        // by to złapał i rzucił wcześniej) — zostaje jako siatka bezpieczeństwa,
        // gdyby to się kiedyś rozjechało, więc sygnał zapisujemy i tutaj.
        if ($info === false) {
            $this->sygnaly->handle(
                $owner,
                ZapiszSygnal::PHOTO_UPLOAD_FAILED,
                ['reason' => RozpoznanieZdjecia::POWOD_NIECZYTELNY],
            );

            throw new BladDlaCzlowieka('Ten plik nie wygląda na zdjęcie. Spróbuj wybrać inne.');
        }

        [$width, $height] = $info;
        $detectedMime = (string) ($info['mime'] ?? '');

        $disk = (string) config('kuking.media.disk');
        $extension = $this->extensionFor($detectedMime);

        // Klucz obiektu generujemy sami. Nazwa pliku od użytkownika nigdy nie
        // trafia do ścieżki — to zamyka drogę do path traversal i do plików
        // udających skrypty.
        // PREFIKS `incoming/`, NIE `media/` — i zapis jako PRYWATNY.
        //
        // Warianty publikowane na stronie powstają przez przekodowanie do WebP,
        // więc EXIF w nich nie ma. ORYGINAŁ zachowuje go prawie w całości —
        // BEZ WSPÓŁRZĘDNYCH GPS, które zdejmuje `UsunGps` niżej (D-023).
        // Aparat, obiektyw i data zostają, bo to informacja o ZDJĘCIU, którą
        // właściciel może chcieć odzyskać z eksportu. Lokalizacja wypada, bo
        // to informacja o CZŁOWIEKU — adres jego kuchni.
        //
        // Wcześniej oryginał lądował pod `media/` jako `public`, a klucz
        // wariantu powstawał z niego przez odcięcie rozszerzenia. Znając
        // publiczny adres miniatury:
        //
        //     media/{id}/2026/09/{uuid}_feed.webp
        //
        // wystarczyło odciąć `_feed.webp` i dopisać `.jpg`, żeby pobrać
        // oryginał w pełnej rozdzielczości, z GPS-em włącznie. Nic tego adresu
        // nie publikowało, ale „nie linkujemy" nie jest zabezpieczeniem.
        //
        // Oryginału NIE kasujemy po przetworzeniu: eksport danych (RODO
        // art. 20) ma oddać człowiekowi jego własne zdjęcie, a nie zmniejszoną
        // kopię. Aplikacja czyta go po stronie serwera, więc prywatny dostęp
        // niczego nie psuje.
        //
        // Zgodne z architekturą opisaną w INFRA_DECISION.md: `incoming/`
        // prywatne, `media/…` publiczne.
        $objectKey = sprintf(
            'incoming/%s/%s/%s.%s',
            $owner->getKey(),
            now()->format('Y/m'),
            Str::uuid()->toString(),
            $extension,
        );

        // BEZ TRZECIEGO ARGUMENTU (dawniej `'private'`).
        //
        // Na R2 widoczność obiektu nie istnieje: `x-amz-acl` jest w tabeli
        // zgodności Cloudflare oznaczony jako NIEOBSŁUGIWANY dla `PutObject`.
        // Ten argument nie robił więc tego, co obiecywał — prywatność
        // oryginału zapewnia dziś to, że ten bucket nie ma własnej domeny
        // ani `r2.dev` (audyt G-01, G-02).
        //
        // Nie dokłada go już też Flysystem. Wbudowany sterownik `s3` liczył
        // `ACL` zawsze — także bez podanej widoczności, i wtedy wypadało
        // `private`, które R2 tylko z życzliwości traktuje jak brak żądania.
        // Dyski R2 mają dziś sterownik `r2` (`App\Support\Storage\R2Adapter`),
        // który nie wysyła ani `x-amz-acl`, ani `x-amz-grant-*` (issue #120).
        // Pilnuje tego `ZapisDoR2BezAclTest` — na prawdziwym, podpisanym
        // żądaniu HTTP, bo w `Storage::fake()` nagłówki nie istnieją.
        // GPS wypada TU, a nie w zadaniu w tle: gdyby leciało asynchronicznie,
        // między wgraniem a przetworzeniem istniałoby okno, w którym w
        // buckecie leży plik ze współrzędnymi. Krótkie okno to nadal okno.
        $oryginal = UsunGps::zBajtow($file->get());

        $dyskWariantow = (string) config('kuking.media.public_disk');

        // KOMPENSACJA, GDY WIERSZ NIE POWSTANIE (issue #962).
        //
        // Pliki idą do storage PRZED `Media::create()`, a transakcja SQL nie
        // cofnie zapisu do bucketu. Błąd bazy w tym oknie (zerwane połączenie,
        // naruszone ograniczenie, wyjątek obserwatora) zostawiał oryginał —
        // a przy małym zdjęciu i publiczny podgląd — BEZ wiersza `media`.
        // Wszystko, co w tym serwisie sprząta i kasuje zdjęcia (`KasujZdjecie`,
        // sprzątanie osieroconych, wymazanie konta), zaczyna od tej tabeli,
        // więc takiego obiektu nie znalazłoby już nic. Kasujemy go więc tu,
        // od razu, a pierwotny wyjątek leci dalej do wywołującego.
        try {
            Storage::disk($disk)->put($objectKey, $oryginal);

            // Orientację czytamy TERAZ, dopóki mamy plik na dysku — zadanie w tle
            // dostaje ze storage same bajty, a dekoder chodzi z wyłączonym
            // automatycznym obrotem (patrz `ProcessUploadedImage`). Wartość
            // wędruje w dwa miejsca: do podglądu niżej i do `metadata`, skąd
            // weźmie ją potem zadanie w tle. Oba muszą obrócić zdjęcie tak samo,
            // inaczej obiad obracałby się przy odświeżeniu strony.
            $orientacja = $this->readOrientation($file->getRealPath());

            // PODGLĄD OD RAZU (issue #430) — jeszcze przed utworzeniem wiersza,
            // żeby PIERWSZY render strony wpisu miał już co pokazać. Wgranie
            // i publikacja to jedno żądanie, więc „dorobimy to zaraz po zapisie"
            // znaczyłoby „za późno".
            //
            // Ta linia nie może wywrócić publikacji: `PodgladOdRazu` łapie
            // wszystko i przy niepowodzeniu oddaje pustą tablicę. Wtedy
            // `metadata.variants` jest puste, widok pokazuje komunikat zastępczy
            // i zdjęcie dorabia zadanie w tle — czyli dokładnie to, co działo się
            // przed #430.
            $warianty = $this->podglad->zrob(
                bajty: $oryginal,
                objectKey: $objectKey,
                dyskWariantow: $dyskWariantow,
                orientacja: $orientacja,
                szerokosc: $width,
                wysokosc: $height,
            );

            $media = Media::create([
                'owner_id' => $owner->getKey(),
                'disk' => $disk,
                // Gdzie trafią WARIANTY. Zapisujemy to teraz, a nie czytamy
                // z konfiguracji przy każdym odczycie: konfiguracja może się
                // zmienić, a pliki zostaną tam, gdzie je położono (audyt G-01).
                'variants_disk' => $dyskWariantow,
                'object_key' => $objectKey,
                'mime_type' => $detectedMime,
                'bytes' => $bytes,
                'width' => $width,
                'height' => $height,
                'status' => Media::STATUS_PENDING,
                'alt_text' => $altText,
                // Suma z tego, CO NAPRAWDĘ LEŻY W BUCKECIE, nie z pliku przed
                // zdjęciem GPS-u — inaczej opisywałaby plik, którego nigdzie nie
                // ma. Nic jej dziś nie czyta, ale suma kontrolna, która nie
                // zgadza się z obiektem, jest gorsza niż jej brak.
                'checksum_sha256' => hash('sha256', $oryginal),
                'metadata' => [
                    'original_name_length' => mb_strlen($file->getClientOriginalName()),
                    // Bez tej wartości zdjęcia z telefonu publikowałyby się
                    // obrócone. Osoba 50+ tego nie zgłosi, po prostu przestanie
                    // wrzucać zdjęcia. Czytane wyżej, przy pliku na dysku.
                    'exif_orientation' => $orientacja,
                    // Pusta tablica, gdy podglądu nie zrobiliśmy — i wtedy
                    // `wariantDoSerwowania()` oddaje `null`, a widok pokazuje
                    // komunikat zastępczy. `ProcessUploadedImage` DOPISUJE do
                    // tego `thumb`/`feed`/`large`, zamiast nadpisywać całość.
                    'variants' => $warianty,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->posprzatajPoNieudanymZapisie($disk, $objectKey, $dyskWariantow);

            throw $e;
        }

        ProcessUploadedImage::dispatch($media->getKey());

        return $media;
    }

    /**
     * Kasuje oryginał i podgląd zdjęcia, dla którego nie powstał wiersz
     * `media` (issue #962).
     *
     * Klucz podglądu LICZYMY, a nie bierzemy z wyniku `PodgladOdRazu`: gdy
     * błąd przyszedł w trakcie jego zapisu, wyniku nie ma, a plik może już
     * leżeć w buckecie. Klucz, pod którym pliku nie ma, to no-op (`exists()`).
     *
     * Sam `delete()` nie jest dowodem — dysk z `throw => false` oddaje cichy
     * `false` — więc sprawdzamy `exists()`, jak `KasujZdjecie::skasujZDysku()`.
     * Porażka NIE udaje sprzątnięcia: zostaje w dzienniku jako błąd z dyskiem
     * i kluczem (bez treści pliku), bo to jedyny ślad, po którym da się to
     * dokończyć — wiersza, do którego można by klucz dopisać, nie ma.
     * Kompensacja nigdy nie rzuca: wywołujący ma dostać PIERWOTNY wyjątek.
     */
    private function posprzatajPoNieudanymZapisie(string $disk, string $objectKey, string $dyskWariantow): void
    {
        $doSkasowania = [
            [$disk, $objectKey],
            [$dyskWariantow, Media::kluczPublicznegoWariantu($objectKey, PodgladOdRazu::NAZWA)],
        ];

        foreach ($doSkasowania as [$nazwaDysku, $klucz]) {
            try {
                $dysk = Storage::disk($nazwaDysku);

                if ($dysk->exists($klucz)) {
                    $dysk->delete($klucz);
                }

                if (! $dysk->exists($klucz)) {
                    continue;
                }

                Log::error('Plik zdjęcia bez wiersza media nadal istnieje po próbie usunięcia', [
                    'dysk' => $nazwaDysku,
                    'klucz' => $klucz,
                ]);
            } catch (\Throwable $blad) {
                Log::error('Nie udało się usunąć pliku zdjęcia bez wiersza media', [
                    'dysk' => $nazwaDysku,
                    'klucz' => $klucz,
                    'error' => BezpiecznyBlad::kontekst($blad),
                ]);
            }
        }
    }

    /**
     * Wartość znacznika EXIF Orientation (1-8) albo null.
     *
     * Czytamy wyłącznie ten jeden znacznik — reszta EXIF-u, w tym GPS,
     * i tak znika przy re-enkodowaniu i nie chcemy jej nigdzie zapisywać.
     */
    private function readOrientation(string $path): ?int
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($path);

        if ($exif === false || ! isset($exif['Orientation'])) {
            return null;
        }

        $orientation = (int) $exif['Orientation'];

        return ($orientation >= 1 && $orientation <= 8) ? $orientation : null;
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            // HEIC nie przejdzie już walidacji wyżej, więc tej gałęzi nie da
            // się dziś osiągnąć. Zostaje, bo w bazie MOGĄ leżeć wiersze zapisane,
            // gdy format był na liście dozwolonych, a `ExportPhotoPlan` nazywa
            // po niej pliki w paczce RODO. Usunięcie jej dałoby tym zdjęciom
            // rozszerzenie `.bin` w archiwum człowieka.
            'image/heic', 'image/heif' => 'heic',
            default => 'bin',
        };
    }
}
