<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\WariantyKontrakt;
use App\Domain\Media\WariantyMetadanychNiepelne;
use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use App\Support\Odmiana;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
 * OBECNOŚĆ KLUCZA TO NIE KOPIA (#2228)
 * Do 30 września 2026 `skopiuj()` uznawało plik za przeniesiony, gdy
 * w nowym buckecie istniał obiekt pod TYM SAMYM kluczem — bez względu na
 * treść. Ucięty upload z przerwanego przebiegu albo obcy obiekt pod tym
 * kluczem (kolizja) przechodził jako „ok", wiersz dostawał nowy `disk`,
 * wypadał z kolejki i serwował uszkodzone zdjęcie bez żadnego alarmu.
 *
 * Dziś każda kopia — świeżo zapisana i zastana — jest porównywana ZE ŹRÓDŁEM
 * w starym buckecie: najpierw rozmiar, potem SHA-256 liczone strumieniowo
 * z bajtów obu obiektów. Niezgodność to `WYNIK_NIEZGODNA`: wiersz bez zmian,
 * osobna sekcja w raporcie, kod wyjścia niezerowy. Obcego obiektu NIE
 * nadpisujemy sami — nie wiemy, czyj jest; decyzję podejmuje operator.
 *
 * Dlaczego źródło, a nie `media.bytes`/`media.checksum_sha256`:
 * `media.bytes` to rozmiar pliku PRZED zdjęciem GPS-u, nie obiektu w buckecie,
 * a `checksum_sha256` starszych wierszy bywa liczona z innych bajtów niż te,
 * które leżą w starym buckecie (patrz historia `StoreUploadedImage`). Zadanie
 * migratora to wierna kopia TEGO, co leży — więc wzorcem jest źródło.
 * Dlaczego nie ETag: w R2/S3 ETag jest MD5 tylko przy pojedynczym PutObject;
 * po uploadzie wieloczęściowym to skrót skrótów części, zależny od podziału,
 * a kontrakt `Filesystem` Laravela go nie wystawia (a `Storage::fake` nie ma
 * go wcale). Dwa obiekty o tej samej treści mogą więc mieć różne ETagi —
 * to nie jest wiarygodne porównanie treści.
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

    /**
     * W nowym buckecie leży obiekt pod tym kluczem, ale NIE TEN (inny rozmiar
     * albo inna suma niż źródło) — albo nie ma źródła, z którym dałoby się go
     * porównać. Ponowienie samo tego nie naprawi (#2228).
     */
    private const WYNIK_NIEZGODNA = 'niezgodna';

    protected $signature = 'kuking:przenies-zdjecia
                            {--dry-run : Tryb tylko-raport: sprawdź i pokaż, co by się stało, ale niczego nie kopiuj ani nie zmieniaj}
                            {--tylko-raport : To samo co --dry-run, nazwane po polsku}
                            {--limit=200 : Ile zdjęć wziąć w jednym przebiegu}
                            {--po= : Zacznij od zdjęć o identyfikatorze większym niż podany (kursor z poprzedniego przebiegu)}';

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

        // KURSOR PO `id` (#1031, uzupełnienie).
        //
        // Wiersz z brakującym plikiem CELOWO zostaje przy `r2_legacy` — ale
        // przy zapytaniu „najstarsze N" wracał też na POCZĄTEK każdej partii.
        // N takich wierszy (przy `--limit=1` wystarczał jeden) i kolejne
        // przebiegi sprawdzały w kółko te same rekordy, a zdrowe, nowsze
        // zdjęcia nie były kopiowane nigdy. `--po` przesuwa start za ostatni
        // wiersz poprzedniej partii; przebieg BEZ `--po` zaczyna od początku,
        // więc pominięte wiersze nie giną — to jawna droga powrotu do nich.
        $po = $this->option('po');
        $po = is_string($po) && $po !== '' ? $po : null;

        if ($po !== null && ! Str::isUuid($po)) {
            $this->error('Opcja --po przyjmuje identyfikator zdjęcia (UUID) wypisany przez poprzedni przebieg. '
                .'Skopiuj go z linii „Następna partia”.');

            return self::FAILURE;
        }

        $doPrzeniesienia = Media::query()
            ->where('disk', $stary)
            ->when($po !== null, fn ($zapytanie) => $zapytanie->where('id', '>', $po))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($doPrzeniesienia->isEmpty()) {
            $this->info($po === null
                ? 'Nie ma zdjęć do przeniesienia.'
                : 'Za podanym --po nie ma już zdjęć do przeniesienia. Uruchom bez --po, żeby sprawdzić pominięte wcześniej.');

            return self::SUCCESS;
        }

        $tylkoRaport = (bool) $this->option('dry-run') || (bool) $this->option('tylko-raport');

        $przeniesione = 0;
        /** @var list<string> $pominiete */
        $pominiete = [];
        /** @var list<string> $nieudane */
        $nieudane = [];
        /** @var list<string> $niezgodne */
        $niezgodne = [];

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

            if ($wynik === self::WYNIK_NIEZGODNA) {
                // SEDNO #2228: obiekt pod kluczem jest, ale nie ten sam co
                // źródło. Wiersz zostaje przy `r2_legacy`.
                $niezgodne[] = $id.' — '.$powod;
                $this->warn('NIEZGODNA KOPIA (wiersz BEZ ZMIAN): '.$id.' — '.$powod);

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

        $ostatni = (string) $doPrzeniesienia->last()->getKey();

        if (Media::query()->where('disk', $stary)->where('id', '>', $ostatni)->exists()) {
            $this->warn('Następna partia: uruchom z --po='.$ostatni
                .' (bez --po wrócisz na początek, razem z pominiętymi).');
        }

        return $tylkoRaport
            ? $this->podsumujRaport($doPrzeniesienia->count(), $przeniesione, $pominiete, $nieudane, $niezgodne)
            : $this->podsumujPrzebieg($stary, $przeniesione, $pominiete, $nieudane, $niezgodne);
    }

    /**
     * @param  list<string>  $pominiete
     * @param  list<string>  $nieudane
     * @param  list<string>  $niezgodne
     */
    private function podsumujRaport(int $ile, int $przeniesione, array $pominiete, array $nieudane, array $niezgodne): int
    {
        // Odmienia się rzeczownik I czasownik: 1 zdjęcie czeka,
        // 2 zdjęcia czekają, 5 zdjęć czeka.
        $zdjecia = Odmiana::rzeczownik($ile, 'zdjęcie', 'zdjęcia', 'zdjęć');
        $czeka = Odmiana::rzeczownik($ile, 'czeka', 'czekają', 'czeka');

        $this->info("Tryb podglądu: {$ile} {$zdjecia} {$czeka} na przeniesienie.");
        $this->info('Gotowe do przeniesienia: '.$przeniesione.'. Do pominięcia (brak pliku): '
            .count($pominiete).'. Do ponowienia: '.count($nieudane).'. Niezgodne kopie: '.count($niezgodne).'.');

        $this->wypiszPowody($pominiete, $nieudane, $niezgodne);

        // Tryb raportu NICZEGO nie zmienia, ale musi umieć powiedzieć „źle":
        // inaczej sprawdzenie przed prawdziwym przebiegiem byłoby zawsze
        // zielone i nie niosłoby żadnej informacji.
        return $pominiete === [] && $nieudane === [] && $niezgodne === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $pominiete
     * @param  list<string>  $nieudane
     * @param  list<string>  $niezgodne
     */
    private function podsumujPrzebieg(string $stary, int $przeniesione, array $pominiete, array $nieudane, array $niezgodne): int
    {
        $this->info('Przeniesione: '.$przeniesione.'. Pominięte (brak pliku): '
            .count($pominiete).'. Nieudane: '.count($nieudane).'. Niezgodne kopie: '.count($niezgodne).'.');

        $this->wypiszPowody($pominiete, $nieudane, $niezgodne);

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

        return $pominiete === [] && $nieudane === [] && $niezgodne === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $pominiete
     * @param  list<string>  $nieudane
     * @param  list<string>  $niezgodne
     */
    private function wypiszPowody(array $pominiete, array $nieudane, array $niezgodne): void
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

        if ($niezgodne !== []) {
            $this->warn('Niezgodne kopie — w nowym buckecie pod tym kluczem leży INNY obiekt niż w starym '
                .'(ucięty albo obcy). Wiersz został przy `r2_legacy`, a obiektu nie nadpisałem. '
                .'Co zrobić: sprawdź obiekt w nowym buckecie, usuń go stamtąd i uruchom komendę ponownie:');

            foreach ($niezgodne as $wpis) {
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
            [$wynik, $szczegol] = $this->skopiuj($dyskStary, $dyskOryginalow, $zdjecie->object_key, $tylkoRaport);

            if ($wynik !== self::WYNIK_OK) {
                return [$wynik, 'oryginał: '.$zdjecie->object_key.$szczegol];
            }

            try {
                $warianty = WariantyKontrakt::wyciagnij($zdjecie);
            } catch (WariantyMetadanychNiepelne) {
                // KONTRAKT ZŁAMANY, NIE BRAK PLIKU (issue #1905). Do 26 września
                // 2026 pusta/uszkodzona `metadata.variants` była nieodróżnialna
                // od kompletu poprawnych wariantów — `foreach` po prostu nie
                // miał po czym iterować i wiersz przechodził dalej, do
                // przestawienia `disk`. Dziś to jest jawny błąd danych: wiersz
                // NIE jest ruszany (jak przy `WYNIK_BLAD`), a powód trafia do
                // raportu z bezpiecznym identyfikatorem medium.
                return [self::WYNIK_BLAD, 'metadata.variants niepełne: puste albo uszkodzone.'];
            }

            foreach ($warianty as $nazwa => $klucz) {
                [$wynik, $szczegol] = $this->skopiuj($dyskStary, $dyskPubliczny, $klucz, $tylkoRaport);

                if ($wynik !== self::WYNIK_OK) {
                    return [$wynik, 'wariant '.$nazwa.': '.$klucz.$szczegol];
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
            // (`exists`, `size`, `readStream` przy porównaniu zastanej kopii),
            // niżej jest jedyny zapis do bazy w całej komendzie.
            return [self::WYNIK_OK, ''];
        }

        // DOPIERO TERAZ. Wszystkie pliki są na miejscu i mają rozmiar oraz
        // SHA-256 zgodne ze źródłem (#2228).
        $zdjecie->update([
            'disk' => (string) config('kuking.media.disk'),
            'variants_disk' => (string) config('kuking.media.public_disk'),
        ]);

        return [self::WYNIK_OK, ''];
    }

    /**
     * Kopiuje jeden obiekt i potwierdza, że dotarł BEZ ZMIAN.
     *
     * Plik, którego nie ma po ŻADNEJ stronie, to `WYNIK_BRAK` — i to jest
     * powód, żeby wiersza NIE ruszać (#1031). Przestawienie go znaczyłoby
     * „zdjęcie leży w nowym buckecie", czyli nieprawdę, po której wiersz
     * wypada z kolejki i nikt się już o braku nie dowie.
     *
     * Plik, który jest już w NOWYM buckecie, to `WYNIK_OK` WYŁĄCZNIE wtedy,
     * gdy ma rozmiar i SHA-256 źródła (#2228) — na tym stoi idempotencja:
     * przebieg przerwany w połowie można po prostu powtórzyć, a to, co
     * zdążyło się skopiować w całości, nie jest kopiowane drugi raz. Zastany
     * obiekt o innej treści to `WYNIK_NIEZGODNA` i NIE jest nadpisywany.
     *
     * @return array{0: string, 1: string} wynik i doklejka do powodu (pusta, gdy nic do dodania)
     */
    private function skopiuj(
        Filesystem $zrodlo,
        Filesystem $cel,
        string $klucz,
        bool $tylkoRaport,
    ): array {
        if ($cel->exists($klucz)) {
            if (! $zrodlo->exists($klucz)) {
                return [self::WYNIK_NIEZGODNA, ' — kopia jest w nowym buckecie, ale w starym nie ma źródła, '
                    .'więc nie da się potwierdzić, że to ten sam plik'];
            }

            // Tani sprawdzian najpierw: inny rozmiar rozstrzyga bez pobierania.
            $rozmiarZrodla = $zrodlo->size($klucz);
            $rozmiarKopii = $cel->size($klucz);

            if ($rozmiarZrodla !== $rozmiarKopii) {
                return [self::WYNIK_NIEZGODNA, ' — inny rozmiar: stary bucket '.$rozmiarZrodla
                    .' B, nowy '.$rozmiarKopii.' B'];
            }

            $zrodlowy = $this->sumaIRozmiar($zrodlo, $klucz, null);

            if ($zrodlowy === null) {
                return [self::WYNIK_BLAD, ' — nie da się odczytać źródła do porównania'];
            }

            return $this->porownajKopie($cel, $klucz, $zrodlowy);
        }

        if (! $zrodlo->exists($klucz)) {
            Log::warning('Zdjęcia nie ma w starym buckecie — wiersz zostaje nieruszony', [
                'klucz' => $klucz,
            ]);

            return [self::WYNIK_BRAK, ''];
        }

        if ($tylkoRaport) {
            // Jest co kopiować i jest skąd. Raport na tym kończy — żadnego
            // zapisu, ani do bucketu, ani do bazy.
            return [self::WYNIK_OK, ''];
        }

        // Strumieniem, nie `get()`: oryginał może mieć 15 MB, a takich zdjęć
        // przenosimy setki w jednym przebiegu. Źródło czytamy RAZ — do bufora
        // tymczasowego (powyżej 4 MB ląduje na dysku, nie w pamięci), licząc
        // po drodze SHA-256 i rozmiar. Z bufora idzie zapis, a suma jest
        // wzorcem dla odczytu zwrotnego z nowego bucketu.
        $bufor = fopen('php://temp/maxmemory:4194304', 'w+b');

        if ($bufor === false) {
            return [self::WYNIK_BLAD, ''];
        }

        try {
            $zrodlowy = $this->sumaIRozmiar($zrodlo, $klucz, $bufor);

            if ($zrodlowy === null) {
                return [self::WYNIK_BLAD, ''];
            }

            rewind($bufor);

            if ($cel->writeStream($klucz, $bufor) === false) {
                return [self::WYNIK_BLAD, ''];
            }
        } finally {
            if (is_resource($bufor)) {
                fclose($bufor);
            }
        }

        // SPRAWDZENIE, NIE ZAŁOŻENIE. Bez niego wiersz zostałby przestawiony
        // na bucket, w którym pliku nie ma — a zdjęcie zniknęłoby z serwisu.
        if (! $this->istnieje($cel, $klucz)) {
            return [self::WYNIK_BLAD, ''];
        }

        // I nie sama obecność (#2228): zapis mógł się uciąć albo trafić
        // w coś, co w międzyczasie położył ktoś inny.
        return $this->porownajKopie($cel, $klucz, $zrodlowy);
    }

    /**
     * Czyta kopię w nowym buckecie i porównuje jej bajty ze źródłem.
     *
     * @param  array{0: string, 1: int}  $zrodlowy  SHA-256 i rozmiar źródła
     * @return array{0: string, 1: string}
     */
    private function porownajKopie(Filesystem $cel, string $klucz, array $zrodlowy): array
    {
        $kopia = $this->sumaIRozmiar($cel, $klucz, null);

        if ($kopia === null) {
            return [self::WYNIK_BLAD, ' — nie da się odczytać kopii z nowego bucketu do porównania'];
        }

        if ($kopia[1] !== $zrodlowy[1]) {
            return [self::WYNIK_NIEZGODNA, ' — inny rozmiar: stary bucket '.$zrodlowy[1]
                .' B, nowy '.$kopia[1].' B'];
        }

        if (! hash_equals($zrodlowy[0], $kopia[0])) {
            return [self::WYNIK_NIEZGODNA, ' — ten sam rozmiar ('.$kopia[1].' B), ale inna suma SHA-256: '
                .'stary bucket '.substr($zrodlowy[0], 0, 12).'…, nowy '.substr($kopia[0], 0, 12).'…'];
        }

        return [self::WYNIK_OK, ''];
    }

    /**
     * SHA-256 i rozmiar liczone z bajtów obiektu, strumieniowo. Rozmiar
     * z przeczytanych bajtów, nie z metadanych: ucięty strumień ma być
     * widać tak samo jak ucięty obiekt.
     *
     * @param  resource|null  $kopiaDo  opcjonalny bufor, do którego bajty są przepisywane
     * @return array{0: string, 1: int}|null null, gdy obiektu nie da się odczytać do końca
     *
     * @phpstan-impure
     */
    private function sumaIRozmiar(Filesystem $dysk, string $klucz, $kopiaDo): ?array
    {
        $strumien = $dysk->readStream($klucz);

        if (! is_resource($strumien)) {
            return null;
        }

        try {
            $hasz = hash_init('sha256');
            $rozmiar = 0;

            while (! feof($strumien)) {
                $kawalek = fread($strumien, 1048576);

                if ($kawalek === false) {
                    return null;
                }

                if ($kawalek === '') {
                    continue;
                }

                hash_update($hasz, $kawalek);
                $rozmiar += strlen($kawalek);

                if ($kopiaDo !== null && fwrite($kopiaDo, $kawalek) !== strlen($kawalek)) {
                    return null;
                }
            }

            return [hash_final($hasz), $rozmiar];
        } finally {
            fclose($strumien);
        }
    }

    /**
     * Pytanie o plik zadane PO zapisie — stan dysku zmienił się od pierwszego
     * `exists()` w `skopiuj()`, więc analiza nie może uznać odpowiedzi za tę samą.
     *
     * @phpstan-impure
     */
    private function istnieje(Filesystem $dysk, string $klucz): bool
    {
        return $dysk->exists($klucz);
    }
}
