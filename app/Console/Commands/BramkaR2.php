<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * „Czy R2 naprawdę nie wystawia oryginałów?" — jedna komenda z odpowiedzią.
 *
 * PO CO TO ISTNIEJE
 * Issue #120 zamknęło stronę aplikacyjną (własny sterownik `r2` bez ACL,
 * osobne buckety, dysk oryginałów bez `url`), ale zostawiło DWANAŚCIE
 * punktów do sprawdzenia ręcznie na prawdziwym R2 — bo z PHP nie widać
 * panelu Cloudflare. Dwanaście ręcznych punktów to bramka, której nikt nie
 * przejdzie dwa razy: pierwszy raz z zapałem, drugi nigdy. A konfiguracja
 * bucketu może się zmienić bez jednej linijki w tym repozytorium.
 *
 * Ta komenda robi za człowieka wszystko, co da się zrobić Z SERWERA: pyta
 * prawdziwe R2 prawdziwymi żądaniami i mówi po polsku, co z nich wyszło.
 * Punkty, których z serwera sprawdzić NIE DA SIĘ (przełącznik `r2.dev`
 * w panelu, wgranie zdjęcia z telefonu, kasowanie z bazy), wypisuje na
 * końcu jako pozostałe do zrobienia — zamiast udawać, że ich nie ma.
 *
 * CZEGO TA KOMENDA NIE ROBI, I TO JEST ŚWIADOME
 * Nie kasuje niczego i domyślnie nic nie zapisuje. Sprawdzenie „skasowanie
 * zabiera oryginał i warianty" wymagałoby usunięcia czyjegoś zdjęcia
 * z produkcji, więc zostaje po stronie człowieka na środowisku testowym.
 * `--zapis` dokłada JEDEN plik tekstowy w prefiksie `bramka/` i kasuje go
 * po sprawdzeniu — i mówi o tym przed zrobieniem tego.
 *
 * BEZPIECZEŃSTWO: klucze API nigdy nie idą na wyjście, nawet fragmentami.
 * Adresów podpisanych też nie wypisujemy w całości — sygnatura w podpisanym
 * adresie jest jednorazowym prawem dostępu do czyjegoś zdjęcia, a wyjście
 * tej komendy trafia do zgłoszeń i do dokumentacji.
 */
class BramkaR2 extends Command
{
    protected $signature = 'kuking:bramka-r2
                            {--zapis : Dołóż próbę zapisu (jeden plik w prefiksie bramka/, kasowany po sprawdzeniu)}
                            {--media= : Sprawdź konkretne zdjęcie (UUID z tabeli media) zamiast najnowszego}';

    protected $description = 'Sprawdza na prawdziwym R2, czy warianty są dostępne podpisem, a oryginały nie są publiczne (issue #120)';

    /** Ile bajtów początku pliku wystarczy, żeby zobaczyć EXIF-a. */
    private const OKNO_EXIF = 131072;

    /** Ile sekund czekamy na odpowiedź z R2 przy jednym żądaniu. */
    private const LIMIT_CZASU = 15;

    /** Punkty, które oblały. */
    private int $oblane = 0;

    /** Punkty, których nie udało się sprawdzić — liczą się jak oblane. */
    private int $niesprawdzone = 0;

    public function handle(): int
    {
        $this->line('<options=bold>Bramka R2 (issue #120) — część do sprawdzenia z serwera</>');
        $this->newLine();

        $dyskOryginalow = (string) config('kuking.media.disk');
        $dyskWariantow = (string) config('kuking.media.public_disk');

        if (! $this->konfiguracjaJestR2($dyskOryginalow, $dyskWariantow)) {
            return self::FAILURE;
        }

        $media = $this->zdjecieDoSprawdzenia();

        if ($media === null) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<options=bold>Sprawdzenia</>');

        $this->oryginalIstniejePrzezApi($media);
        $wariant = $this->wariantIstniejePrzezApi($media);
        $this->trzyWarianty($media);

        if ($wariant !== null) {
            $this->podpisDzialaAAdresBezPodpisuNie($media, $wariant);
        }

        $this->oryginalNiePodpisemNieDaSiePobrac($media);
        $this->publicznyBucketBezOryginalow($dyskWariantow);
        $this->exifJestTylkoWOryginale($media, $wariant);

        if ($this->option('zapis')) {
            $this->probaZapisuBezAcl($dyskOryginalow);
        } else {
            $this->wiersz('zapis', 'PutObject przechodzi bez `x-amz-acl`', null,
                'Nie sprawdzone — dołóż `--zapis`, żeby komenda zapisała jeden plik w prefiksie `bramka/` i skasowała go.');
        }

        return $this->werdykt();
    }

    /**
     * Czy serwis w ogóle zapisuje dziś do R2.
     *
     * NAJWAŻNIEJSZY PUNKT CAŁEJ KOMENDY. Na dysku lokalnym każde sprawdzenie
     * niżej wyszłoby ładnie — bo lokalnie nie ma ani bucketu, ani publicznego
     * adresu, więc „oryginał nie jest publiczny" jest prawdą, która o R2 nie
     * mówi nic. Zielona bramka na dysku lokalnym byłaby dokładnie tym
     * rodzajem narzędzia, które melduje sukces, nie robiąc nic.
     */
    private function konfiguracjaJestR2(string $dyskOryginalow, string $dyskWariantow): bool
    {
        $sterownikOryginalow = (string) config("filesystems.disks.{$dyskOryginalow}.driver");
        $sterownikWariantow = (string) config("filesystems.disks.{$dyskWariantow}.driver");

        $this->line("Dysk oryginałów:  <options=bold>{$dyskOryginalow}</> (sterownik: {$sterownikOryginalow})");
        $this->line("Dysk wariantów:   <options=bold>{$dyskWariantow}</> (sterownik: {$sterownikWariantow})");

        if ($sterownikOryginalow !== 'r2') {
            $this->newLine();
            $this->error('Serwis NIE zapisuje zdjęć do R2 — ta bramka nie ma czego sprawdzić.');
            $this->line('Dysk oryginałów to `'.$dyskOryginalow.'`, nie `r2`. Ustaw w środowisku:');
            $this->line('  <options=bold>FILESYSTEM_DISK=r2</> i <options=bold>KUKING_MEDIA_DISK=r2</>');
            $this->line('i uruchom komendę ponownie. Do tego czasu bramka jest NIEPRZEJŚCIONA,');
            $this->line('a `cdn.kuking.pl` nie może stać przed bucketem mediów.');

            return false;
        }

        if ($dyskWariantow === $dyskOryginalow) {
            $this->newLine();
            $this->error('Warianty leżą w TYM SAMYM buckecie co oryginały.');
            $this->line('Na R2 publiczność jest cechą bucketu, nie obiektu — wystawienie tego');
            $this->line('bucketu wystawi razem z wariantami oryginały z EXIF-em i GPS-em.');
            $this->line('Ustaw <options=bold>KUKING_MEDIA_PUBLIC_DISK=r2_publiczne</>.');

            return false;
        }

        $bucketOryginalow = (string) config("filesystems.disks.{$dyskOryginalow}.bucket");
        $bucketWariantow = (string) config("filesystems.disks.{$dyskWariantow}.bucket");
        $endpoint = (string) config("filesystems.disks.{$dyskOryginalow}.endpoint");

        // Bucket wypisujemy — to nazwa, nie sekret. Endpointu NIE, bo niesie
        // identyfikator konta Cloudflare, a to wyjście trafia do zgłoszeń.
        $this->line("Bucket oryginałów: <options=bold>{$bucketOryginalow}</>");
        $this->line("Bucket wariantów:  <options=bold>{$bucketWariantow}</>");
        $this->line('Endpoint R2:       '.($endpoint === '' ? '<fg=red>BRAK</>' : 'ustawiony'));

        if ($bucketOryginalow === '' || $endpoint === '') {
            $this->newLine();
            $this->error('Brak nazwy bucketu albo adresu endpointu — bez nich nie ma czego pytać.');
            $this->line('Ustaw `AWS_BUCKET`, `AWS_PUBLIC_BUCKET` i `AWS_ENDPOINT`.');

            return false;
        }

        if ($bucketOryginalow === $bucketWariantow) {
            $this->newLine();
            $this->error('Oba dyski wskazują TEN SAM bucket — rozdział jest tylko w nazwach dysków.');
            $this->line('Ustaw `AWS_PUBLIC_BUCKET` na osobny bucket wariantów.');

            return false;
        }

        return true;
    }

    /**
     * Zdjęcie, na którym da się cokolwiek udowodnić.
     *
     * MUSI BYĆ PRAWDZIWE. Odpowiedź 404 na wymyślony klucz nie mówi nic
     * o tym, czy bucket jest publiczny — mówi tylko, że takiego pliku nie ma
     * (uwaga do punktu 2 w `docs/infra/BRAMKA_R2.md`).
     */
    private function zdjecieDoSprawdzenia(): ?Media
    {
        $wskazane = $this->option('media');

        if (is_string($wskazane) && $wskazane !== '') {
            $media = Media::query()->find($wskazane);

            if ($media === null) {
                $this->newLine();
                $this->error('Nie ma zdjęcia o identyfikatorze '.$wskazane.'.');

                return null;
            }

            return $media;
        }

        $media = Media::query()
            ->where('status', Media::STATUS_READY)
            ->whereNotNull('object_key')
            ->latest('created_at')
            ->first();

        if ($media === null) {
            $this->newLine();
            $this->error('W bazie nie ma ani jednego gotowego zdjęcia — nie ma na czym sprawdzać.');
            $this->line('Wgraj jedno zdjęcie przez formularz na tym środowisku i uruchom komendę ponownie.');
            $this->line('Bramka jest do tego czasu NIEPRZEJŚCIONA — nie z powodu usterki, ale z braku dowodu.');

            return null;
        }

        $this->newLine();
        $this->line('Sprawdzam na zdjęciu <options=bold>'.$media->getKey().'</> z '
            .($media->created_at?->format('Y-m-d H:i') ?? 'nieznanej daty').'.');

        return $media;
    }

    private function oryginalIstniejePrzezApi(Media $media): void
    {
        try {
            $jest = $this->dysk($media->disk)->exists((string) $media->object_key);
        } catch (Throwable $e) {
            $this->wiersz('1', 'Oryginał widoczny przez API S3 z serwera', null,
                'Zapytanie do R2 się nie udało: '.$this->skrot($e->getMessage()));

            return;
        }

        $this->wiersz('1', 'Oryginał widoczny przez API S3 z serwera', $jest,
            $jest
                ? 'Klucz API działa i plik jest tam, gdzie mówi baza.'
                : 'Pliku nie ma pod kluczem z bazy — albo klucz API nie widzi tego bucketu, albo plik zniknął.');
    }

    /** @return array{nazwa: string, klucz: string}|null */
    private function wariantIstniejePrzezApi(Media $media): ?array
    {
        $wariant = $media->wariantDoSerwowania('feed');

        if ($wariant === null) {
            $this->wiersz('2', 'Wariant widoczny przez API S3 z serwera', false,
                'To zdjęcie nie ma ANI JEDNEGO wygenerowanego wariantu — worker nie dokończył pracy.');

            return null;
        }

        try {
            $jest = $this->dysk($media->variantsDisk())->exists($wariant['klucz']);
        } catch (Throwable $e) {
            $this->wiersz('2', 'Wariant widoczny przez API S3 z serwera', null,
                'Zapytanie do R2 się nie udało: '.$this->skrot($e->getMessage()));

            return null;
        }

        $this->wiersz('2', 'Wariant widoczny przez API S3 z serwera', $jest,
            $jest
                ? 'Wariant „'.$wariant['nazwa'].'" leży w buckecie wariantów.'
                : 'Metadane mówią o wariancie, którego w buckecie nie ma — obraz pokaże się jako pusta ramka.');

        return $jest ? $wariant : null;
    }

    private function trzyWarianty(Media $media): void
    {
        $brakujace = array_values(array_filter(
            ['thumb', 'feed', 'large'],
            fn (string $nazwa): bool => ($media->wariantDoSerwowania($nazwa)['nazwa'] ?? null) !== $nazwa,
        ));

        $this->wiersz('3', 'Worker wytworzył wszystkie trzy warianty', $brakujace === [],
            $brakujace === []
                ? 'thumb, feed i large — wszystkie trzy są w metadanych.'
                : 'Brakuje: '.implode(', ', $brakujace).'. Sprawdź kolejkę i log workera.');
    }

    /**
     * Podpisany adres wariantu działa, a ten sam adres bez podpisu — nie.
     *
     * To jest sedno bramki. Jeżeli adres bez sygnatury oddaje 200, bucket
     * jest publiczny i wystawia wszystko, co w nim leży.
     *
     * @param  array{nazwa: string, klucz: string}  $wariant
     */
    private function podpisDzialaAAdresBezPodpisuNie(Media $media, array $wariant): void
    {
        $dysk = $this->dysk($media->variantsDisk());

        if (! $dysk->providesTemporaryUrls()) {
            $this->wiersz('4', 'Podpisany adres wariantu oddaje 200', null,
                'Ten dysk nie umie podpisywać adresów — na R2 umie, więc to znaczy, że komenda chodzi nie na R2.');

            return;
        }

        try {
            $podpisany = $dysk->temporaryUrl($wariant['klucz'], now()->addMinutes(2));
        } catch (Throwable $e) {
            $this->wiersz('4', 'Podpisany adres wariantu oddaje 200', null,
                'Nie udało się podpisać adresu: '.$this->skrot($e->getMessage()));

            return;
        }

        $kodZPodpisem = $this->kodOdpowiedzi($podpisany);

        // Znowu: brak odpowiedzi to NIE WIEMY. Bez tego rozróżnienia komenda
        // pisałaby „R2 odmawia podpisanego odczytu" o sytuacji, w której nikt
        // niczego nie odmówił, bo żądanie w ogóle nie doszło.
        $this->wiersz('4', 'Podpisany adres wariantu oddaje 200', $kodZPodpisem === null ? null : $kodZPodpisem === 200,
            $kodZPodpisem === null
                ? 'Nie było odpowiedzi — sprawdź sieć wychodzącą z serwera.'
                : ($kodZPodpisem === 200
                    ? 'Zdjęcia naprawdę dojdą do przeglądarki.'
                    : 'R2 odmawia podpisanego odczytu (HTTP '.$kodZPodpisem.') — zdjęcia nie wyświetlą się nikomu.'));

        // Ten sam adres z uciętą sygnaturą. Nie zgadujemy schematu adresu —
        // bierzemy ten, którym serwis naprawdę się posługuje.
        $bezPodpisu = Str::before($podpisany, '?');
        $kodBezPodpisu = $this->kodOdpowiedzi($bezPodpisu);

        // `null` (brak odpowiedzi) to NIE WIEMY, nie NIE. Oba oblewają bramkę,
        // ale etykieta ma mówić prawdę o tym, co się stało: „adres bez podpisu
        // nie został odrzucony" i „nie wiemy, czy został" to dwa różne zdania.
        $this->wiersz('5', 'Ten sam adres BEZ podpisu jest odrzucany', $kodBezPodpisu === null ? null : $kodBezPodpisu !== 200,
            $kodBezPodpisu === null
                ? 'Nie było odpowiedzi — bez niej nie wolno uznać, że bucket jest zamknięty.'
                : ($kodBezPodpisu === 200
                    ? 'ALARM: bucket wariantów oddaje pliki BEZ podpisu. Wyłącz `r2.dev` i publiczną domenę.'
                    : 'HTTP '.$kodBezPodpisu.' — bez sygnatury R2 nie oddaje nic.'));
    }

    /**
     * Oryginał nie daje się pobrać publicznie — po kolei każdą drogą,
     * którą da się wyprowadzić z konfiguracji.
     */
    private function oryginalNiePodpisemNieDaSiePobrac(Media $media): void
    {
        $dysk = $media->disk;
        $endpoint = rtrim((string) config("filesystems.disks.{$dysk}.endpoint"), '/');
        $bucket = (string) config("filesystems.disks.{$dysk}.bucket");
        $klucz = ltrim((string) $media->object_key, '/');

        if ($endpoint === '' || $bucket === '' || $klucz === '') {
            $this->wiersz('6', 'Oryginał nie do pobrania bez podpisu', null,
                'Brak endpointu, bucketu albo klucza — nie ma czego zapytać.');

            return;
        }

        $host = (string) parse_url($endpoint, PHP_URL_HOST);

        $adresy = [
            'ścieżkowy' => $endpoint.'/'.$bucket.'/'.$klucz,
            'wirtualny host' => 'https://'.$bucket.'.'.$host.'/'.$klucz,
        ];

        $wyniki = [];
        $wystawiony = false;
        $bezOdpowiedzi = false;

        foreach ($adresy as $nazwa => $adres) {
            $kod = $this->kodOdpowiedzi($adres);
            $wyniki[] = $nazwa.': '.($kod === null ? 'brak odpowiedzi' : 'HTTP '.$kod);

            if ($kod === 200) {
                $wystawiony = true;
            }

            if ($kod === null) {
                $bezOdpowiedzi = true;
            }
        }

        $this->wiersz('6', 'Oryginał nie do pobrania bez podpisu', $wystawiony ? false : ($bezOdpowiedzi ? null : true),
            $wystawiony
                ? 'ALARM: oryginał z pełnym EXIF-em (GPS kuchni) jest publicznie do pobrania. '.implode(' · ', $wyniki)
                : ($bezOdpowiedzi
                    ? 'Któraś droga nie odpowiedziała: '.implode(' · ', $wyniki)
                    : 'Żadna droga nie oddała pliku ('.implode(' · ', $wyniki).').'));
    }

    private function publicznyBucketBezOryginalow(string $dyskWariantow): void
    {
        try {
            // `allFiles`, nie `files`: klucze oryginałów mają w środku datę
            // (`incoming/2026/09/…`), więc listowanie jednego poziomu
            // pokazałoby pusto i bramka przeszłaby przy oryginałach
            // leżących w publicznym buckecie. Ten test już raz oblał
            // dokładnie na tym.
            $klucze = $this->dysk($dyskWariantow)->allFiles('incoming');
        } catch (Throwable $e) {
            $this->wiersz('7', 'W publicznym buckecie nie ma kluczy `incoming/`', null,
                'Nie udało się wylistować bucketu: '.$this->skrot($e->getMessage()));

            return;
        }

        $ile = count($klucze);

        $this->wiersz('7', 'W publicznym buckecie nie ma kluczy `incoming/`', $ile === 0,
            $ile === 0
                ? 'Prefiks `incoming/` jest w tym buckecie pusty.'
                : 'ALARM: leży tam '.$ile.' plik(ów) z prefiksu oryginałów. Przenieś je i skasuj z tego bucketu.');
    }

    /**
     * EXIF ma być w oryginale i NIE MA GO BYĆ w wariancie.
     *
     * Wariant idzie do przeglądarki każdego, kto widzi wpis. EXIF w nim
     * znaczy, że razem ze zdjęciem rosołu idzie adres kuchni.
     *
     * @param  array{nazwa: string, klucz: string}|null  $wariant
     */
    private function exifJestTylkoWOryginale(Media $media, ?array $wariant): void
    {
        if ($wariant === null) {
            $this->wiersz('8', 'Wariant nie niesie EXIF-u', null,
                'Nie ma sprawdzonego wariantu, więc nie ma czego przeszukać.');

            return;
        }

        try {
            $bajty = $this->poczatekPliku($media->variantsDisk(), $wariant['klucz']);
        } catch (Throwable $e) {
            $this->wiersz('8', 'Wariant nie niesie EXIF-u', null,
                'Nie udało się odczytać wariantu: '.$this->skrot($e->getMessage()));

            return;
        }

        $maExif = $bajty !== null && (str_contains($bajty, "Exif\x00\x00") || str_contains($bajty, 'EXIF'));

        $this->wiersz('8', 'Wariant nie niesie EXIF-u', $bajty === null ? null : ! $maExif,
            $bajty === null
                ? 'Wariant odczytał się jako pusty — bez bajtów nie wolno uznać, że EXIF-u nie ma.'
                : ($maExif
                    ? 'ALARM: w wariancie siedzi blok EXIF. Przekodowanie miało go zdjąć.'
                    : 'W przeszukanym początku pliku nie ma bloku EXIF.'));
    }

    /**
     * Jedna prawdziwa próba zapisu — dowód, że `PutObject` przechodzi bez ACL.
     *
     * Plik idzie w osobny prefiks `bramka/`, żeby nie mieszał się ze
     * zdjęciami, i jest kasowany w `finally` — także wtedy, gdy sprawdzenie
     * po drodze rzuci wyjątkiem.
     */
    private function probaZapisuBezAcl(string $dyskOryginalow): void
    {
        $klucz = 'bramka/'.now()->format('Y-m-d').'-'.Str::uuid()->toString().'.txt';
        $tresc = 'Bramka R2, issue #120, '.now()->toIso8601String()."\n";

        $this->line('  Zapisuję plik próbny <options=bold>'.$klucz.'</> i zaraz go skasuję.');

        $dysk = $this->dysk($dyskOryginalow);
        $zapisany = false;

        try {
            $dysk->put($klucz, $tresc);
            $zapisany = true;

            $odczytane = (string) $dysk->get($klucz);

            $this->wiersz('9', 'PutObject przechodzi bez `x-amz-acl`', $odczytane === $tresc,
                $odczytane === $tresc
                    ? 'Zapis i odczyt bez ACL — własny sterownik `r2` działa na prawdziwym buckecie.'
                    : 'Plik zapisał się, ale wrócił inny — sprawdź, czy nic nie przepisuje treści po drodze.');
        } catch (Throwable $e) {
            $this->wiersz('9', 'PutObject przechodzi bez `x-amz-acl`', false,
                'Zapis odmówiony: '.$this->skrot($e->getMessage()));
        } finally {
            if ($zapisany) {
                try {
                    $dysk->delete($klucz);
                    $this->line('  Plik próbny skasowany.');
                } catch (Throwable $e) {
                    $this->warn('  UWAGA: nie udało się skasować pliku próbnego '.$klucz.' — skasuj go ręcznie.');
                }
            }
        }
    }

    private function werdykt(): int
    {
        $this->newLine();

        if ($this->oblane > 0 || $this->niesprawdzone > 0) {
            $this->error('BRAMKA NIEPRZEJŚCIONA: '.$this->oblane.' oblanych, '.$this->niesprawdzone.' niesprawdzonych.');
            $this->line('Punkt niesprawdzony liczy się jak oblany — „nie wiemy" nigdy nie znaczy „jest dobrze".');
            $this->line('Nie wystawiaj `cdn.kuking.pl` przed bucketem mediów.');

            return self::FAILURE;
        }

        $this->info('Część serwerowa bramki PRZESZŁA w całości ('.now()->format('Y-m-d H:i').').');
        $this->newLine();
        $this->line('<options=bold>Zostaje do zrobienia przez człowieka</> (z serwera tego nie widać):');
        $this->line('  · `r2.dev` wyłączone na buckecie oryginałów — panel R2 → bucket → Settings → Public access');
        $this->line('  · żadnej publicznej domeny na buckecie oryginałów');
        $this->line('  · wgranie zdjęcia ~14,9 MB przez formularz (limit `kuking.media.max_bytes`)');
        $this->line('  · po jednej PRAWDZIWEJ próbce JPEG, PNG, WebP i AVIF z aparatu, nie z generatora');
        $this->line('  · skasowanie wpisu zabiera oryginał i wszystkie warianty (na środowisku testowym!)');
        $this->line('  · błędny sekret daje polski komunikat i alert w Sentry, a nie „opublikowano" i pustą ramkę');
        $this->newLine();
        $this->line('Wynik wpisz do <options=bold>docs/infra/BRAMKA_R2.md</> razem z datą — bez daty dowód nie mówi nic:');
        $this->line('  część serwerowa: PRZESZŁA · '.now()->format('Y-m-d'));

        return self::SUCCESS;
    }

    /** Jeden wiersz wyniku. `null` znaczy „nie wiemy", nie „w porządku". */
    private function wiersz(string $numer, string $co, ?bool $wynik, string $mowi): void
    {
        $etykieta = match ($wynik) {
            true => '<fg=green;options=bold>TAK</>',
            false => '<fg=red;options=bold>NIE</>',
            null => '<fg=yellow;options=bold>NIE WIEMY</>',
        };

        if ($wynik === false) {
            $this->oblane++;
        }

        if ($wynik === null) {
            $this->niesprawdzone++;
        }

        $this->line("  [{$numer}] {$etykieta}  {$co}");
        $this->line("        {$mowi}");
    }

    private function dysk(string $nazwa): Filesystem
    {
        return Storage::disk($nazwa);
    }

    /**
     * Kod odpowiedzi na zwykłym GET, bez żadnych własnych nagłówków.
     *
     * `null` znaczy „nie było odpowiedzi" i jest traktowane jak brak dowodu.
     * Świadomie GET, nie HEAD: R2 potrafi odpowiadać na HEAD inaczej niż na
     * GET, a przeglądarka użyje GET-a.
     */
    private function kodOdpowiedzi(string $adres): ?int
    {
        try {
            return Http::withoutRedirecting()
                ->timeout(self::LIMIT_CZASU)
                ->get($adres)
                ->status();
        } catch (Throwable) {
            return null;
        }
    }

    private function poczatekPliku(string $dysk, string $klucz): ?string
    {
        $strumien = $this->dysk($dysk)->readStream($klucz);

        if (! is_resource($strumien)) {
            return null;
        }

        try {
            $bajty = fread($strumien, self::OKNO_EXIF);
        } finally {
            fclose($strumien);
        }

        return is_string($bajty) && $bajty !== '' ? $bajty : null;
    }

    /** Cudzy komunikat błędu skrócony — potrafi nieść klucz albo adres. */
    private function skrot(string $tekst): string
    {
        $jednaLinia = trim((string) preg_replace('/\s+/', ' ', $tekst));

        return mb_strimwidth($jednaLinia, 0, 200, '…');
    }
}
