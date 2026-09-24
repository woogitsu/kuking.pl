<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use League\Flysystem\UnableToWriteFile;

/**
 * Jeden mały wariant zdjęcia, zrobiony OD RAZU — w żądaniu, które je wgrywa
 * (issue #430).
 *
 * PO CO TO ISTNIEJE
 * Wgranie zdjęcia i opublikowanie wpisu to na Kuking JEDNO żądanie
 * (`PostController::store` → `StoreUploadedImage`). Przetwarzanie szło
 * w całości do kolejki, więc w chwili, w której przeglądarka renderowała
 * stronę wpisu, wariantów nie było jeszcze ŻADNYCH i być nie mogło — nie
 * z powodu obciążenia, tylko z powodu kolejności. Autorka klikała
 * „Opublikuj", widziała zielone „Opublikowane" i szare pole z napisem
 * „Twoje zdjęcie się jeszcze przygotowuje" zamiast swojego obiadu.
 *
 * Właściciel serwisu o tej usterce: „Starzy ludzie nie czytają i będzie
 * panika co się stało...". To jest pierwsza rzecz, jaką w Kuking robi nowa
 * osoba, i moment, w którym się z serwisu rezygnuje.
 *
 * DLACZEGO WARIANT, A NIE ORYGINAŁ — DWA POWODY, OBA TWARDE
 *
 * 1. EXIF. Oryginał idzie do bucketu bez współrzędnych GPS (`UsunGps`,
 *    D-023), ale z resztą metadanych: aparat, obiektyw, data, czasem nazwa
 *    właściciela urządzenia. Wariant powstaje przez przekodowanie do WebP,
 *    więc nie ma w nim niczego. Pokazanie oryginału znaczyłoby wystawienie
 *    tych danych każdemu, kto widzi wpis — a przy starych wierszach,
 *    zapisanych przed D-023, także GPS-u. Żadna bramka dostępu tego nie
 *    naprawia, bo tu nie chodzi o to, KTO patrzy, tylko CO dostaje.
 *
 * 2. ZMIERZONY TRANSFER (12.09.2026, zdjęcie 4032×3024, 12,2 Mpx):
 *
 *        oryginał z telefonu   6 438 105 B   (6,14 MB)
 *        wariant `podglad`        62 974 B   (61,5 kB)   ← 102× mniej
 *        wariant `feed`          177 404 B   (173,2 kB)
 *
 *    Na łączu komórkowym 3 Mb/s oryginał to kilkanaście sekund patrzenia
 *    na doładowujący się obrazek, i to zanim jeszcze przyjdzie prawdziwy
 *    wariant. Podgląd przychodzi w ułamku sekundy.
 *
 * ILE TO KOSZTUJE PO STRONIE SERWERA — I DLACZEGO JEST GÓRNY PRÓG
 *
 * Zmierzone tym samym przebiegiem, dekodowanie + `scaleDown` + WebP:
 *
 *        12,2 Mpx (typowy telefon)     453 ms    szczyt RSS 101 MB
 *        24,5 Mpx (iPhone 48 Mpx)      734 ms    szczyt RSS 154 MB
 *        49,9 Mpx (nasz limit)       1 327 ms    szczyt RSS 239 MB
 *
 * Te megabajty to NIE jest licznik PHP i `memory_limit` ich nie zatrzyma —
 * libgd alokuje bitmapę poza nim (mówi o tym wprost `docker/php.ini`:
 * „proces po prostu zniknie, zabity przez OOM kontenera, nie zostawiając
 * śladu NIGDZIE"). Kontener web ma na produkcji 1 GB na wszystkie procesy
 * PHP-FPM naraz. Dekodowanie zdjęcia 50 Mpx w żądaniu webowym zamieniłoby
 * więc „zdjęcie pokazuje się z opóźnieniem" na „publikacja wpisu zabija
 * proces bez śladu w dzienniku" — czyli usterkę znacznie gorszą od tej,
 * którą naprawiamy.
 *
 * Stąd próg `kuking.media.podglad.max_megapixels`. Powyżej niego podglądu
 * NIE ROBIMY i to jest świadomy wybór: takie zdjęcie pokaże się dopiero po
 * przetworzeniu w tle, gdzie worker ma własną pamięć i własny limit
 * (`QUEUE_MEMORY`, `--memory=700`). Człowiek zobaczy wtedy komunikat
 * zastępczy — dlatego ten komunikat musi być czytelny (issue #432), a nie
 * dlatego, że się z niego zrezygnowało.
 *
 * TA KLASA NIE MA PRAWA WYWRÓCIĆ PUBLIKACJI. Cokolwiek pójdzie nie tak —
 * brak pamięci, uszkodzony plik, bucket nie odpowiada — wracamy z pustą
 * tablicą i wpis publikuje się normalnie, a zdjęcie dorobi zadanie w tle.
 * Podgląd jest udogodnieniem, nie warunkiem zapisania czyjejś pracy
 * („poprawne dane nigdy nie znikają", docs/UX_50_PLUS.md).
 */
final class PodgladOdRazu
{
    public const NAZWA = 'podglad';

    /**
     * Wariant `podglad` w kształcie, w jakim trafia do `metadata.variants`.
     *
     * Pusta tablica znaczy „nie zrobiliśmy" — i jest to normalny wynik, nie
     * awaria. Wywołujący ma ją po prostu zapisać: `metadata.variants` z tym
     * jednym wpisem albo bez niego.
     *
     * @return array<string, array{key: string, width: int, height: int, bytes: int}>
     */
    public function zrob(
        string $bajty,
        string $objectKey,
        string $dyskWariantow,
        ?int $orientacja,
        int $szerokosc,
        int $wysokosc,
    ): array {
        if (! $this->doZrobienia($szerokosc, $wysokosc)) {
            return [];
        }

        try {
            // `autoOrientation: false` i obrót własnym kodem — TA SAMA
            // decyzja i ten sam kod co w `ProcessUploadedImage` (audyt
            // zewnętrzny T11, `OrientacjaZdjecia`). Dwie różne orientacje
            // tego samego zdjęcia znaczyłyby, że po odświeżeniu strony
            // obiad się obraca.
            $obraz = ImageManager::gd(autoOrientation: false)->read($bajty);

            OrientacjaZdjecia::zastosuj($obraz, $orientacja);

            // `scaleDown` nigdy nie powiększa — małe zdjęcie zostaje małe,
            // zamiast być rozmyte na siłę. Przy zdjęciu węższym niż krawędź
            // podglądu wariant wyjdzie więc w oryginalnych wymiarach i to
            // jest w porządku: nadal jest przekodowany, czyli bez EXIF-u.
            $krawedz = $this->krawedz();
            $obraz->scaleDown(width: $krawedz, height: $krawedz);

            $zakodowane = (string) $obraz->toWebp(quality: 82);

            $klucz = Media::kluczPublicznegoWariantu($objectKey, self::NAZWA);

            // `false` z dysku `throw => false` to nieudany zapis (issue #961):
            // bez tego `metadata.variants` deklarowałoby plik, którego nie ma,
            // a pierwszy render pokazałby martwy obrazek zamiast uczciwego
            // „zdjęcie się przygotowuje". Wyjątek ląduje w `catch` niżej.
            if (Storage::disk($dyskWariantow)->put($klucz, $zakodowane) === false) {
                throw UnableToWriteFile::atLocation($klucz, 'Dysk zwrócił false z put() dla podglądu.');
            }

            return [self::NAZWA => [
                'key' => $klucz,
                'width' => $obraz->width(),
                'height' => $obraz->height(),
                'bytes' => strlen($zakodowane),
            ]];
        } catch (\Throwable $e) {
            // GŁOŚNO W DZIENNIKU, CICHO DLA CZŁOWIEKA. Bez tego wpisu
            // rezygnacja z podglądu wygląda dokładnie tak samo jak zdjęcie
            // za duże na próg — a to dwie zupełnie różne rzeczy.
            Log::warning('Nie udało się zrobić podglądu od razu; zdjęcie pokaże się po przetworzeniu w tle', [
                'object_key' => $objectKey,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Czy wolno dekodować to zdjęcie w żądaniu webowym — patrz rachunek
     * pamięci w docblocku klasy.
     */
    private function doZrobienia(int $szerokosc, int $wysokosc): bool
    {
        $prog = (int) config('kuking.media.podglad.max_megapixels');

        if ($prog <= 0) {
            return false;
        }

        return ($szerokosc * $wysokosc) / 1_000_000 <= $prog;
    }

    private function krawedz(): int
    {
        return max(1, (int) config('kuking.media.podglad.krawedz'));
    }
}
