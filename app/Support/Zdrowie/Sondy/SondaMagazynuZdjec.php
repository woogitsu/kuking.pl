<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sonda `media` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaMagazynuZdjec implements Sonda
{
    public function nazwa(): string
    {
        return 'media';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_ZDJECIA;
    }

    /**
     * Czy da się zapisać i odczytać plik na dysku ze zdjęciami — i czy droga
     * publiczna do niego prowadzi tam, gdzie powinna.
     *
     * Sam zapis nie wystarcza: dokładnie tak wyglądała poprzednia awaria.
     * `ProcessUploadedImage` kończył się powodzeniem, plik leżał na dysku,
     * a przeglądarka dostawała 404, bo `public/storage` był martwym linkiem
     * albo katalog zniknął razem z kontenerem.
     */
    public function sprawdz(): void
    {
        $nazwaDysku = (string) config('kuking.media.disk');

        // UDANA próbka jest pamiętana krótko (audyt A5-05): bez tego każde
        // wywołanie `/health` zapisywało i czytało obiekt w R2. Porażki nie
        // pamiętamy, więc awaria nie chowa się za starym „ok". Cache może
        // leżeć w bazie — jego awaria nie przewraca sondy, tylko ją powtarza.
        $kluczProbki = 'health:probka-magazynu:'.$nazwaDysku;

        try {
            $probkaUdana = Cache::get($kluczProbki) === true;
        } catch (Throwable) {
            $probkaUdana = false;
        }

        if (! $probkaUdana) {
            $this->zapiszIOdczytajProbke($nazwaDysku);

            try {
                Cache::put($kluczProbki, true, (int) config('kuking.health.probka_magazynu_sekund'));
            } catch (Throwable) {
                // Zostaje bez pamięci — następne pytanie spróbuje od nowa.
            }
        }

        $this->sprawdzDrogePubliczna($nazwaDysku);
    }

    private function zapiszIOdczytajProbke(string $nazwaDysku): void
    {
        // Nazwa z kropką na początku i losowym sufiksem: nie zderzy się
        // z niczyim plikiem i nie trafi do listingów.
        $probka = '.health/'.Str::uuid()->toString();

        try {
            // `Storage::disk()` jest TUTAJ, a nie wyżej, bo dla dysku lokalnego
            // to ono tworzy katalog główny — awaria woluminu bez prawa zapisu
            // wychodzi więc już na tej linijce, a nie dopiero na `put()`.
            $dysk = Storage::disk($nazwaDysku);
            $dysk->put($probka, 'kuking');
        } catch (Throwable $e) {
            // Bez `finally` z kasowaniem: skoro zapis się nie udał, nie ma
            // czego kasować, a `delete()` na zepsutym dysku rzuciłby drugi
            // wyjątek i przykrył ten prawdziwy.
            throw new KontrolaZdrowiaNieprzeszla(Powody::POWOD_ZAPIS_NIEMOZLIWY, $e->getMessage(), $e);
        }

        try {
            if ($dysk->get($probka) !== 'kuking') {
                throw new KontrolaZdrowiaNieprzeszla(
                    Powody::POWOD_ODCZYT_NIEZGODNY,
                    'Zapis się udał, ale odczyt zwrócił co innego.',
                );
            }
        } catch (KontrolaZdrowiaNieprzeszla $e) {
            // Nasz własny wyjątek ma już kod — przepuszczamy go bez zmian,
            // inaczej gałąź niżej owinęłaby go po raz drugi.
            throw $e;
        } catch (Throwable $e) {
            throw new KontrolaZdrowiaNieprzeszla(Powody::POWOD_ODCZYT_NIEZGODNY, $e->getMessage(), $e);
        } finally {
            $dysk->delete($probka);
        }
    }

    /**
     * Dla dysku lokalnego droga publiczna to symlink `public/storage`.
     * Przy R2 pliki idą prosto z CDN-u i ten link nie ma znaczenia —
     * sprawdzanie go zgłaszałoby wtedy awarię, której nie ma.
     */
    private function sprawdzDrogePubliczna(string $nazwaDysku): void
    {
        if (config("filesystems.disks.{$nazwaDysku}.driver") !== 'local') {
            return;
        }

        $link = public_path('storage');
        $cel = (string) config("filesystems.disks.{$nazwaDysku}.root");

        if (! is_dir($link)) {
            throw new KontrolaZdrowiaNieprzeszla(
                Powody::POWOD_BRAK_DROGI_PUBLICZNEJ,
                "Brak drogi publicznej do zdjęć: {$link} nie prowadzi do katalogu. "
                .'Uruchom `php artisan storage:link`.',
            );
        }

        // `realpath` rozwija symlink. Porównanie celów łapie przypadek,
        // w którym link istnieje, ale wskazuje na poprzedni katalog —
        // np. sprzed zamontowania woluminu.
        if (realpath($link) !== realpath($cel)) {
            throw new KontrolaZdrowiaNieprzeszla(
                Powody::POWOD_DROGA_GDZIE_INDZIEJ,
                "Droga publiczna do zdjęć (`{$link}`) prowadzi gdzie indziej "
                ."niż dysk `{$nazwaDysku}` (`{$cel}`).",
            );
        }
    }
}
