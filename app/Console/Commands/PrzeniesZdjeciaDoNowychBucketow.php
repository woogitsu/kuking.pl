<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use App\Support\Odmiana;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Przeniesienie zdjęć ze starego, jednego bucketu do dwóch nowych (audyt W4-03).
 *
 * PO CO
 * Rozdzielenie oryginałów (prywatny bucket) i wariantów (publiczny) zmieniło
 * to, gdzie ZAPISUJEMY. Nie przeniosło niczego, co już leży. Wiersze sprzed
 * tej zmiany mają `disk = 'r2'` i `variants_disk = NULL`, a ich pliki są
 * w starym, wspólnym buckecie — który w konfiguracji nazywa się teraz
 * `r2_legacy`.
 *
 * Dopóki ta komenda nie przejdzie do końca, jedno i drugie musi działać:
 * stare zdjęcia serwują się ze starego bucketu, nowe z nowych.
 *
 * KOLEJNOŚĆ MA ZNACZENIE I JEST TU CAŁĄ TREŚCIĄ
 * Najpierw kopiujemy, potem SPRAWDZAMY, że kopia istnieje, i dopiero na końcu
 * zmieniamy wiersz. Odwrotna kolejność — wiersz najpierw — dawałaby zdjęcie
 * wskazujące na plik, którego nie ma: znikające z serwisu i nie do odzyskania
 * bez ręcznego grzebania w buckecie.
 *
 * BRAKUJĄCY PLIK TO BŁĄD, NIE SUKCES (#1031)
 * Do 22 września 2026 `skopiuj()` zwracało `true`, kiedy pliku NIE BYŁO
 * w starym buckecie — z uzasadnieniem, że wiersz i tak trzeba przestawić,
 * żeby nie wracał przy każdym przebiegu. Skutek był odwrotny do zamierzonego
 * i cichy: wiersz dostawał `disk` nowego bucketu, w którym pliku nie ma,
 * ZNIKAŁ z zapytania `where('disk', 'r2_legacy')` i żaden kolejny przebieg
 * nie miał go już jak znaleźć. Zdjęcia nie ma, baza twierdzi, że jest, i nic
 * tego nie wykrywa.
 *
 * Dziś brak pliku POWSTRZYMUJE przestawienie wiersza, jest liczony osobno
 * i wypisany z powodem, a komenda kończy się błędem. Wiersz zostaje przy
 * `r2_legacy` — czyli wraca przy każdym następnym przebiegu i przy każdym
 * widać go w raporcie. Lepiej mieć hałaśliwy, powtarzalny wiersz niż cichą,
 * bezpowrotną utratę.
 *
 * ORYGINAŁÓW NIE KASUJEMY ZE STAREGO BUCKETU. To jest osobna decyzja i osobne
 * uruchomienie: dopóki nie ma pewności, że komplet się przeniósł, stary bucket
 * jest jedyną kopią zapasową. Czyszczenie starego bucketu robi się ręcznie,
 * po sprawdzeniu, że ta komenda nie ma już nic do roboty.
 *
 * @see SprawdzZdjeciaPoPrzenosinach — znajduje wiersze,
 *      które przestawiła jeszcze STARA wersja tej komendy.
 */
class PrzeniesZdjeciaDoNowychBucketow extends Command
{
    /** Plik jest na miejscu w nowym buckecie (skopiowany teraz albo wcześniej). */
    private const WYNIK_OK = 'ok';

    /** Pliku nie ma ani w nowym, ani w starym buckecie — nie ma czego przenieść. */
    private const WYNIK_BRAK = 'brak';

    /** Plik jest, ale kopiowanie się nie udało — warto ponowić. */
    private const WYNIK_BLAD = 'blad';

    protected $signature = 'kuking:przenies-zdjecia
                            {--dry-run : Tryb tylko-raport: sprawdź i pokaż, co by się stało, ale niczego nie kopiuj ani nie zmieniaj}
                            {--tylko-raport : To samo co --dry-run, nazwane po polsku}
                            {--limit=200 : Ile zdjęć wziąć w jednym przebiegu}';

    protected $description = 'Kopiuje zdjęcia ze starego bucketu do nowych: oryginały do prywatnego, warianty do publicznego';

    public function handle(): int
    {
        $stary = 'r2_legacy';

        if ((string) config("filesystems.disks.{$stary}.bucket") === '') {
            $this->error(
                'Dysk `r2_legacy` nie ma ustawionego bucketu (AWS_LEGACY_BUCKET). '
                .'Bez niego nie wiadomo, skąd kopiować — a zgadywanie oznacza tu utratę zdjęć.',
            );

            return self::FAILURE;
        }

        $doPrzeniesienia = Media::query()
            ->where('disk', $stary)
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($doPrzeniesienia->isEmpty()) {
            $this->info('Nie ma zdjęć do przeniesienia.');

            return self::SUCCESS;
        }

        $tylkoRaport = (bool) $this->option('dry-run') || (bool) $this->option('tylko-raport');

        $przeniesione = 0;
        /** @var list<string> $pominiete */
        $pominiete = [];
        /** @var list<string> $nieudane */
        $nieudane = [];

        foreach ($doPrzeniesienia as $zdjecie) {
            [$wynik, $powod] = $this->przenies($zdjecie, $stary, $tylkoRaport);

            $id = (string) $zdjecie->getKey();

            if ($wynik === self::WYNIK_BRAK) {
                // SEDNO #1031: wiersz NIE jest przestawiany. Zostaje przy
                // `r2_legacy`, więc wróci w następnym przebiegu i nie da się
                // o nim zapomnieć.
                $pominiete[] = $id.' — '.$powod;
                $this->warn('POMINIĘTE (wiersz BEZ ZMIAN): '.$id.' — '.$powod);

                continue;
            }

            if ($wynik === self::WYNIK_BLAD) {
                $nieudane[] = $id.' — '.$powod;
                $this->line('NIE UDAŁO SIĘ: '.$id.' — '.$powod.' (wiersz bez zmian, spróbuję ponownie)');

                continue;
            }

            $przeniesione++;
            $this->line(($tylkoRaport ? 'Do przeniesienia: ' : 'Przeniesione: ').$id);
        }

        return $tylkoRaport
            ? $this->podsumujRaport($doPrzeniesienia->count(), $przeniesione, $pominiete, $nieudane)
            : $this->podsumujPrzebieg($stary, $przeniesione, $pominiete, $nieudane);
    }

    /**
     * @param  list<string>  $pominiete
     * @param  list<string>  $nieudane
     */
    private function podsumujRaport(int $ile, int $przeniesione, array $pominiete, array $nieudane): int
    {
        // Odmienia się rzeczownik I czasownik: 1 zdjęcie czeka,
        // 2 zdjęcia czekają, 5 zdjęć czeka.
        $zdjecia = Odmiana::rzeczownik($ile, 'zdjęcie', 'zdjęcia', 'zdjęć');
        $czeka = Odmiana::rzeczownik($ile, 'czeka', 'czekają', 'czeka');

        $this->info("Tryb podglądu: {$ile} {$zdjecia} {$czeka} na przeniesienie.");
        $this->info('Gotowe do przeniesienia: '.$przeniesione.'. Do pominięcia (brak pliku): '
            .count($pominiete).'. Do ponowienia: '.count($nieudane).'.');

        $this->wypiszPowody($pominiete, $nieudane);

        // Tryb raportu NICZEGO nie zmienia, ale musi umieć powiedzieć „źle":
        // inaczej sprawdzenie przed prawdziwym przebiegiem byłoby zawsze
        // zielone i nie niosłoby żadnej informacji.
        return $pominiete === [] && $nieudane === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $pominiete
     * @param  list<string>  $nieudane
     */
    private function podsumujPrzebieg(string $stary, int $przeniesione, array $pominiete, array $nieudane): int
    {
        $this->info('Przeniesione: '.$przeniesione.'. Pominięte (brak pliku): '
            .count($pominiete).'. Nieudane: '.count($nieudane).'.');

        $this->wypiszPowody($pominiete, $nieudane);

        // Zostawiamy ślad, ile jeszcze zostało — inaczej po pierwszym przebiegu
        // z limitem łatwo uznać robotę za skończoną.
        $zostalo = Media::query()->where('disk', $stary)->count();

        if ($zostalo > 0) {
            $zostaloSlowo = Odmiana::rzeczownik($zostalo, 'Zostało', 'Zostały', 'Zostało');
            $zdjec = Odmiana::rzeczownik($zostalo, 'zdjęcie', 'zdjęcia', 'zdjęć');

            $this->warn("{$zostaloSlowo} {$zostalo} {$zdjec}. Uruchom komendę ponownie.");
        } else {
            $this->info('Komplet przeniesiony. Publiczność starego bucketu można zdjąć DOPIERO teraz.');
        }

        return $pominiete === [] && $nieudane === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $pominiete
     * @param  list<string>  $nieudane
     */
    private function wypiszPowody(array $pominiete, array $nieudane): void
    {
        if ($pominiete !== []) {
            $this->warn('Pominięte — pliku nie ma ANI w starym, ANI w nowym buckecie. '
                .'Wiersz został przy `r2_legacy` i wróci w kolejnym przebiegu:');

            foreach ($pominiete as $wpis) {
                $this->warn('  - '.$wpis);
            }
        }

        if ($nieudane !== []) {
            $this->warn('Nieudane — plik jest, ale kopiowanie się nie powiodło:');

            foreach ($nieudane as $wpis) {
                $this->warn('  - '.$wpis);
            }
        }
    }

    /**
     * Kopiuje pliki jednego zdjęcia i przestawia wiersz — w tej kolejności.
     *
     * @return array{0: string, 1: string}
     */
    private function przenies(Media $zdjecie, string $stary, bool $tylkoRaport): array
    {
        $dyskStary = Storage::disk($stary);
        $dyskOryginalow = Storage::disk((string) config('kuking.media.disk'));
        $dyskPubliczny = Storage::disk((string) config('kuking.media.public_disk'));

        try {
            if ($zdjecie->object_key !== null) {
                $wynik = $this->skopiuj($dyskStary, $dyskOryginalow, $zdjecie->object_key, $tylkoRaport);

                if ($wynik !== self::WYNIK_OK) {
                    return [$wynik, 'oryginał: '.$zdjecie->object_key];
                }
            }

            foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $nazwa => $wariant) {
                if (! is_array($wariant) || ! isset($wariant['key'])) {
                    continue;
                }

                $klucz = (string) $wariant['key'];
                $wynik = $this->skopiuj($dyskStary, $dyskPubliczny, $klucz, $tylkoRaport);

                if ($wynik !== self::WYNIK_OK) {
                    return [$wynik, 'wariant '.(string) $nazwa.': '.$klucz];
                }
            }
        } catch (Throwable $e) {
            $blad = BezpiecznyBlad::kontekst($e);

            Log::error('Nie udało się przenieść zdjęcia do nowych bucketów', [
                'media_id' => $zdjecie->getKey(),
                'error' => $blad,
            ]);

            // Na konsolę też bez komunikatu: klient R2 wkleja w niego pełny
            // adres żądania, a wyjście komendy ląduje w logu wdrożenia.
            return [self::WYNIK_BLAD, 'wyjątek: '.$blad['wyjatek'].' w '.($blad['miejsce_w_app'] ?? $blad['miejsce']).' (odcisk '.$blad['odcisk'].')'];
        }

        if ($tylkoRaport) {
            // Tryb raportu kończy się TUTAJ. Wyżej były wyłącznie odczyty
            // (`exists`), niżej jest jedyny zapis do bazy w całej komendzie.
            return [self::WYNIK_OK, ''];
        }

        // DOPIERO TERAZ. Wszystkie pliki są na miejscu i sprawdzone.
        $zdjecie->update([
            'disk' => (string) config('kuking.media.disk'),
            'variants_disk' => (string) config('kuking.media.public_disk'),
        ]);

        return [self::WYNIK_OK, ''];
    }

    /**
     * Kopiuje jeden obiekt i potwierdza, że dotarł.
     *
     * Plik, którego nie ma po ŻADNEJ stronie, to `WYNIK_BRAK` — i to jest
     * powód, żeby wiersza NIE ruszać (#1031). Przestawienie go znaczyłoby
     * „zdjęcie leży w nowym buckecie", czyli nieprawdę, po której wiersz
     * wypada z kolejki i nikt się już o braku nie dowie.
     *
     * Plik, który jest już w NOWYM buckecie, to `WYNIK_OK` — na tym stoi
     * idempotencja: przebieg przerwany w połowie można po prostu powtórzyć,
     * a to, co zdążyło się skopiować, nie jest kopiowane drugi raz.
     */
    private function skopiuj(
        Filesystem $zrodlo,
        Filesystem $cel,
        string $klucz,
        bool $tylkoRaport,
    ): string {
        if ($cel->exists($klucz)) {
            return self::WYNIK_OK;
        }

        if (! $zrodlo->exists($klucz)) {
            Log::warning('Zdjęcia nie ma w starym buckecie — wiersz zostaje nieruszony', [
                'klucz' => $klucz,
            ]);

            return self::WYNIK_BRAK;
        }

        if ($tylkoRaport) {
            // Jest co kopiować i jest skąd. Raport na tym kończy — żadnego
            // zapisu, ani do bucketu, ani do bazy.
            return self::WYNIK_OK;
        }

        $strumien = $zrodlo->readStream($klucz);

        if ($strumien === null) {
            return self::WYNIK_BLAD;
        }

        // Strumieniem, nie `get()`: oryginał może mieć 15 MB, a takich zdjęć
        // przenosimy setki w jednym przebiegu.
        $zapisano = $cel->writeStream($klucz, $strumien);

        if (is_resource($strumien)) {
            fclose($strumien);
        }

        if ($zapisano === false) {
            return self::WYNIK_BLAD;
        }

        // SPRAWDZENIE, NIE ZAŁOŻENIE. Bez niego wiersz zostałby przestawiony
        // na bucket, w którym pliku nie ma — a zdjęcie zniknęłoby z serwisu.
        return $cel->exists($klucz) ? self::WYNIK_OK : self::WYNIK_BLAD;
    }
}
