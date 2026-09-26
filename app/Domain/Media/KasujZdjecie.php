<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Jobs\PurgePublicMediaCache;
use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Kasowanie zdjęcia razem z plikami — ale tylko wtedy, gdy nic go nie używa.
 *
 * PO CO OSOBNA KLASA
 * Kasowanie pliku jest nieodwracalne, a odpowiedź na pytanie „czy ktoś tego
 * jeszcze używa" wymaga znajomości WSZYSTKICH miejsc, które mogą wskazywać
 * na `media`. Ta wiedza musi żyć w jednym miejscu: dwie kopie listy odwołań
 * to dwie okazje do rozjazdu, a każdy rozjazd znaczy skasowane cudze zdjęcie.
 *
 * Korzystają z tego dwie różne rzeczy: sprzątanie zdjęć nieprzypiętych
 * do niczego (audyt C1) i wymazywanie konta po karencji (issue #93).
 */
final class KasujZdjecie
{
    /**
     * Tabele i kolumny, które mogą wskazywać na `media`.
     *
     * LISTA MUSI BYĆ PEŁNA, I TO JEST JEJ JEDYNE RYZYKO.
     * Pominięcie jednej kolumny znaczy kasowanie zdjęć, które ktoś ma
     * przypięte do przepisu albo do awatara — a pliku nie da się przywrócić.
     * Dlatego towarzyszy jej test, który czyta klucze obce ze schematu bazy
     * i pada, gdy pojawi się kolumna wskazująca na `media`, której tu nie ma.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const ODWOLANIA = [
        ['post_media', 'media_id'],
        ['cooked_event_media', 'media_id'],
        ['profiles', 'avatar_media_id'],
        ['recipes', 'hero_media_id'],
        ['recipes', 'source_scan_media_id'],
        ['recipe_steps', 'media_id'],
        // Kolaż w hero strony powitalnej. Zdjęcie wskazane do kolażu jest
        // UŻYWANE, choć nie wisi przy żadnym własnym wpisie autora — bez tego
        // wiersza sprzątacz osieroconych uznałby je za niczyje i skasował
        // plik, a strona powitalna straciłaby kafel bez jednego komunikatu.
        ['hero_picks', 'media_id'],
    ];

    /**
     * Kasuje zdjęcie i jego pliki, jeśli nic już na nie nie wskazuje.
     *
     * WIERSZ ZNIKA WYŁĄCZNIE PO KOMPLETNYM SKASOWANIU PLIKÓW (audyt/issue #17).
     *
     * Wcześniej wiersz znikał BEZWARUNKOWO, niezależnie od tego, czy
     * `skasujPliki()` naprawdę usunęła każdy plik. Awaria dysku w połowie
     * pętli (wyjątek z R2 — albo, gorzej, cichy `false` z dysku
     * `throw => false`) kasowała wiersz tak samo jak sukces: plik zostawał
     * na dysku na zawsze, a jedyny ślad, po którym dało się to zauważyć
     * i dokończyć — wiersz w `media` — znikał razem z nim.
     *
     * Niepełne skasowanie zostawia więc wiersz NA MIEJSCU. To jest cały
     * mechanizm ponowienia: `OsieroconeZdjecia`/`kuking:sprzataj-osierocone-zdjecia`
     * wybiera zdjęcia po wieku, nie po tym, czy poprzednia próba się nie
     * udała, więc wiersz, który przetrwał nieudaną próbę, trafi w kolejny
     * przebieg tak samo jak każdy inny — bez żadnej dodatkowej kolejki.
     *
     * KASOWANIE PLIKÓW IDZIE PO COMMICIE (issue #285, D-083).
     *
     * Przedtem cała ta metoda chodziła wewnątrz jednej transakcji otwartej
     * przez `OsieroconeZdjecia`, a decyzja „nikt tego nie używa" była zwykłym
     * `SELECT`-em bez blokady. Publikacja wpisu wybierała zdjęcia równie
     * niezobowiązująco, więc mieściła się w całości między tym sprawdzeniem
     * a skasowaniem plików: człowiek dostawał opublikowany wpis, a jego
     * jedyny egzemplarz zdjęcia znikał z R2. I to bez żadnego błędu — bo
     * `post_media.media_id` ma `ON DELETE CASCADE`, więc świeżo wstawione
     * powiązanie kasowało się po cichu razem z wierszem `media`.
     *
     * Teraz są dwa kroki i granica commitu między nimi, tak jak
     * w `EraseAccountData`:
     *
     *  1. `przejmij()` — krótka transakcja: blokada wiersza, REWALIDACJA pod
     *     blokadą i znacznik `status = deleted`. Nie ma w niej ani jednego
     *     wejścia na dysk, więc nikt nie czeka na R2 z założoną blokadą.
     *  2. dopiero PO jej zatwierdzeniu — pliki, a na samym końcu wiersz.
     *
     * Dzięki temu wycofanie transakcji nigdy nie zostawia skasowanego pliku,
     * a próba przerwana w połowie zostawia wiersz ze znacznikiem: jest z czego
     * ponowić i nic już tego zdjęcia nie przypnie.
     *
     * @return bool czy faktycznie skasowano W KOMPLECIE (wiersz i wszystkie pliki)
     */
    public function jesliNieuzywane(Media $zdjecie): bool
    {
        $przejete = $this->przejmij($zdjecie);

        if ($przejete === null) {
            return false;
        }

        // Pliki PRZED wierszem. Wiersz bez plików da się jeszcze zauważyć
        // i posprzątać; pliki bez wiersza są dla całej aplikacji niewidoczne
        // i zostają na dysku na zawsze.
        if (! $this->skasujPliki($przejete)) {
            return false;
        }

        $przejete->delete();

        return true;
    }

    /**
     * Przejmuje zdjęcie do skasowania: blokuje wiersz, sprawdza POD BLOKADĄ,
     * czy nadal nikt go nie używa, i oznacza je jako `deleted`.
     *
     * DLACZEGO ŚWIEŻY ODCZYT, A NIE MODEL Z ARGUMENTU (D-079 §3)
     * Model przyszedł z zapytania, które wybrało kandydatów do sprzątania —
     * między tamtym `SELECT`-em a tym wywołaniem ktoś mógł zdążyć opublikować
     * wpis z tym zdjęciem. Sama blokada tego nie powie: ona tylko ustawia
     * w kolejkę. Odpowiedź daje dopiero pytanie zadane PONOWNIE, już po jej
     * uzyskaniu.
     *
     * Znacznik `deleted` jest tu odpowiednikiem `data_erased_at`
     * z `EraseAccountData`: zatwierdzoną, widoczną dla innych transakcji
     * deklaracją „to zdjęcie odchodzi", której `ZdjeciaDoPrzypiecia` już nie
     * przepuści. Bez niej okno wracałoby natychmiast po zwolnieniu blokady,
     * a przed skasowaniem plików.
     *
     * @return Media|null świeży, przejęty wiersz albo `null`, gdy nie wolno go tknąć
     */
    private function przejmij(Media $zdjecie): ?Media
    {
        return DB::transaction(function () use ($zdjecie): ?Media {
            $swieze = Media::query()->whereKey($zdjecie->getKey())->lockForUpdate()->first();

            if ($swieze === null || $this->jestUzywane($swieze)) {
                return null;
            }

            // Ponowione sprzątanie tego samego wiersza (poprzednia próba padła
            // w połowie plików) trafia tu ze znacznikiem już ustawionym — nie
            // ma czego zapisywać drugi raz.
            if ($swieze->status !== Media::STATUS_DELETED) {
                $swieze->status = Media::STATUS_DELETED;
                $swieze->save();
            }

            return $swieze;
        });
    }

    /**
     * Przejmuje zdjęcie do skasowania BEZ pytania, czy ktoś go używa —
     * wyłącznie dla wymazania konta (`EraseAccountData`), które kasuje
     * komplet zdjęć jednej osoby, choć wskazują na nie jej własne wpisy.
     *
     * DLACZEGO WYMAZANIE TEŻ MUSI PRZEJĄĆ WIERSZ (issue #1003)
     * Wymazanie wczytuje modele zdjęć w swojej transakcji, a pliki kasuje po
     * jej zatwierdzeniu. Zadanie `ProcessUploadedImage`, które skończyło
     * pomiędzy, zapisało nowe warianty do `metadata` — a nieaktualny model
     * ich nie zna, więc zostałyby w publicznym buckecie po skasowaniu
     * wiersza. Blokada, znacznik `deleted` i ŚWIEŻY odczyt zamykają to okno
     * z obu stron: zadanie, które przyjdzie po nas, widzi `deleted` i sprząta
     * swoje pliki; zadanie, które skończyło przed nami, jest w tym odczycie.
     *
     * @return Media|null świeży, przejęty wiersz albo `null`, gdy wiersza już nie ma
     */
    public function przejmijDoWymazania(Media $zdjecie): ?Media
    {
        return DB::transaction(function () use ($zdjecie): ?Media {
            $swieze = Media::query()->whereKey($zdjecie->getKey())->lockForUpdate()->first();

            if ($swieze !== null && $swieze->status !== Media::STATUS_DELETED) {
                $swieze->status = Media::STATUS_DELETED;
                $swieze->save();
            }

            return $swieze;
        });
    }

    public function jestUzywane(Media $zdjecie): bool
    {
        foreach (self::ODWOLANIA as [$tabela, $kolumna]) {
            if (DB::table($tabela)->where($kolumna, $zdjecie->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Oryginał i wszystkie warianty — na wszystkich dyskach, na których mogą
     * fizycznie leżeć.
     *
     * ODPORNOŚĆ NA AWARIĘ W POŁOWIE PĘTLI (audyt/issue #17).
     *
     * Stary kod wywoływał `delete()` po kolei i nie patrzył ani na wyjątek,
     * ani na wynik. Dwa różne dyski w tym serwisie zachowują się przy awarii
     * inaczej, i oba psuły to samo:
     *
     *  - `r2`/`r2_publiczne`/`r2_legacy` mają `throw => true` — nieudane
     *    wywołanie RZUCA WYJĄTKIEM. Wyjątek z DRUGIEGO wariantu z trzech
     *    przerywał całą metodę: trzeci wariant i oryginał nie były nawet
     *    PRÓBOWANE, a wywołujący (`jesliNieuzywane()`, `EraseAccountData`)
     *    dostawał surowy wyjątek zamiast decyzji „udało się czy nie".
     *  - dysk `local`/`public` z `throw => false` (patrz `CleanUpDataExports`,
     *    ten sam wzorzec) zwraca po prostu `false` — BEZ WYJĄTKU. Stary kod
     *    tego wyniku w ogóle nie czytał, więc `jesliNieuzywane()` kasowała
     *    wiersz `media`, mimo że plik fizycznie zostawał na dysku. To jest
     *    dokładnie „wiersz w bazie już nie istnieje, więc nie ma z czego
     *    ponowić" z opisu tego zadania.
     *
     * Dlatego każde pojedyncze kasowanie idzie przez `skasujZDysku()`, która
     * łapie wyjątek, SPRAWDZA WYNIK PRZEZ `exists()` (jedyna odpowiedź
     * niezależna od tego, jak skonfigurowany jest dysk — patrz uzasadnienie
     * w `CleanUpDataExports::skasujPlik()`), loguje porażkę z pełnym
     * kontekstem i — najważniejsze — NIE PRZERYWA reszty pętli. Próbujemy
     * skasować KAŻDY plik z KAŻDEGO dysku, niezależnie od tego, co się stało
     * z poprzednim, i dopiero na końcu zwracamy jedną odpowiedź: czy
     * WSZYSTKO się udało.
     *
     * @return bool czy WSZYSTKIE pliki (warianty, oryginał, kopia z r2_legacy)
     *              naprawdę zniknęły ze wszystkich dysków, na których mogły leżeć
     */
    public function skasujPliki(Media $zdjecie): bool
    {
        $dyski = $this->dyskiDoWyczyszczenia($zdjecie);

        // Adresy zbieramy PRZED kasowaniem: po usunięciu pliku `url()` nie ma
        // już z czego ich zbudować, a to właśnie ich trzeba do wyczyszczenia
        // cache CDN-u.
        $doWyczyszczenia = [];
        $wszystkoSieUdalo = true;

        // Do budowania publicznego adresu bierzemy dysk wariantów Z WIERSZA —
        // dokładnie ten sam, na którym `MediaController` naprawdę je serwuje,
        // niezależnie od tego, ile dysków sprzątamy poniżej.
        $dyskWariantow = Storage::disk($zdjecie->variantsDisk());

        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $nazwa => $wariant) {
            if (is_array($wariant) && isset($wariant['key'])) {
                $klucz = (string) $wariant['key'];

                // DWA RODZAJE ADRESU, BO PO W7-02 SĄ DWA ŚWIATY NARAZ.
                //
                // 1. Adres pliku w buckecie. Istnieje już TYLKO dla dysku
                //    `r2_legacy` — jedynego, który ma jeszcze własną domenę.
                //    Dysk `r2_publiczne` świadomie stracił klucz `url`, więc
                //    `publicznyAdres()` zwróci dla niego `null` i nie ma tam
                //    czego czyścić: bez domeny nie ma cache CDN-u.
                //
                // 2. Adres TRASY aplikacji — dzisiejszy adres zdjęcia.
                //    Dla treści chronionej odpowiedź ma `private, no-store`
                //    i w żadnym cache nie leży. Dla treści naprawdę publicznej
                //    przekierowanie wolno trzymać we wspólnym cache, więc
                //    czyścimy je tak samo jak dawniej plik — inaczej
                //    „skasowane" znowu znaczyłoby „skasowane, ale jeszcze się
                //    otwiera" (audyt G-03).
                $doWyczyszczenia[] = $this->publicznyAdres($dyskWariantow, $klucz);
                $doWyczyszczenia[] = $this->adresTrasy($zdjecie, (string) $nazwa);

                if (! $this->skasujZKazdegoDysku($zdjecie, $klucz, $dyski)) {
                    $wszystkoSieUdalo = false;
                }
            }
        }

        // WARIANTY ZADANIA, KTÓRE NIE DOBIEGŁO KOŃCA (#601).
        //
        // `metadata.variants` powstaje dopiero po ostatnim wariancie, razem
        // ze statusem `ready`. Zadanie przerwane w połowie — wyjątkiem albo
        // `$timeout`, który ubija proces bez żadnego `catch` — zostawia
        // w publicznym buckecie pliki, których nie ma w tamtej tablicy.
        // `ProcessUploadedImage` zapisuje więc ich klucze OSOBNO, jeszcze
        // przed pętlą, a tu je sprzątamy. Klucz, pod którym plik nigdy nie
        // powstał, jest no-opem: `skasujZDysku()` pyta `exists()`.
        //
        // Pomijamy klucze obsłużone wyżej — po sukcesie lista jest pusta,
        // ale wiersz sprzed tej zmiany może mieć obie.
        $juzSkasowane = [];

        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $wariant) {
            if (is_array($wariant) && isset($wariant['key'])) {
                $juzSkasowane[] = (string) $wariant['key'];
            }
        }

        foreach ((array) ($zdjecie->metadata[Media::METADANE_WARIANTY_W_TRAKCIE] ?? []) as $klucz) {
            if (! is_string($klucz) || $klucz === '' || in_array($klucz, $juzSkasowane, true)) {
                continue;
            }

            $doWyczyszczenia[] = $this->publicznyAdres($dyskWariantow, $klucz);

            if (! $this->skasujZKazdegoDysku($zdjecie, $klucz, $dyski)) {
                $wszystkoSieUdalo = false;
            }
        }

        if ($zdjecie->object_key !== null
            && ! $this->skasujZKazdegoDysku($zdjecie, $zdjecie->object_key, $dyski)) {
            $wszystkoSieUdalo = false;
        }

        // SKASOWANIE PLIKU TO NIE TO SAMO CO ZNIKNIĘCIE Z INTERNETU (audyt G-03).
        //
        // Cloudflare ostrzega wprost: przy cache na własnej domenie skasowany
        // obiekt bywa dalej serwowany aż do wygaśnięcia. Przy wymazaniu konta
        // albo decyzji moderacyjnej znaczy to, że serwis mówi „skasowane",
        // a zdjęcie nadal się otwiera.
        //
        // Osobne zadanie, bo cudze API bywa niedostępne, a kasowanie zdjęcia
        // nie może się przez to nie udać — awaria Cloudflare zatrzymałaby
        // wtedy wymazywanie kont. Zlecamy je nawet przy częściowej porażce
        // powyżej: adresy, których PLIK naprawdę zniknął, mają prawo zniknąć
        // też z cache, niezależnie od losu pozostałych.
        $doWyczyszczenia = array_values(array_filter($doWyczyszczenia));

        if ($doWyczyszczenia !== []) {
            PurgePublicMediaCache::dispatch($doWyczyszczenia);
        }

        return $wszystkoSieUdalo;
    }

    /**
     * Nazwy WSZYSTKICH dysków, na których ten klucz mógł fizycznie zostać
     * zapisany — czyli dysk oryginału, dysk wariantów, i (audyt N01)
     * `r2_legacy`.
     *
     * DLACZEGO `r2_legacy` DOCHODZI TU, A NIE TYLKO PRZY STARYCH WIERSZACH
     * `kuking:przenies-zdjecia` kopiuje plik pod TYM SAMYM KLUCZEM do nowego
     * bucketu i CELOWO NIE KASUJE starej kopii — dopóki migracja trwa, stary
     * bucket jest jedyną kopią zapasową. Ale to jest decyzja o MIGRACJI
     * WSZYSTKICH zdjęć naraz, nie o losie JEDNEGO zdjęcia, które ktoś właśnie
     * każe skasować (wymazanie konta, decyzja moderacyjna, sprzątanie
     * osieroconych). Zdjęcie przeniesione do nowych bucketów ma dziś
     * `disk = r2` / `variants_disk = r2_publiczne` — `KasujZdjecie` nigdy nie
     * spojrzy więc na `r2_legacy`, a klucz zostawiony tam przez migrację
     * zostaje w publicznym buckecie NA ZAWSZE, mimo że wiersz `media` już
     * nie istnieje i nikt już nie wie, że tam jest.
     *
     * Sprawdzamy `exists()` per klucz (w `skasujZDysku()`), więc dopisanie
     * tego dysku dla zdjęcia, które nigdy przez `r2_legacy` nie przechodziło,
     * jest nieszkodliwym no-opem — nie inną operacją niż to, co
     * `kuking:przenies-zdjecia::skopiuj()` już zakłada o brakującym kluczu.
     *
     * Pomijamy `r2_legacy`, gdy: nie ma skonfigurowanego bucketu (środowisko
     * bez zmiennej `AWS_LEGACY_BUCKET` — lokalnie i w testach nie ma go wcale)
     * albo gdy TO WŁAŚNIE JEST dysk oryginału/wariantów tego zdjęcia — wtedy
     * już jest na liście i podwójna próba niczego by nie dodała.
     *
     * @return list<string>
     */
    private function dyskiDoWyczyszczenia(Media $zdjecie): array
    {
        $dyski = array_values(array_unique([$zdjecie->disk, $zdjecie->variantsDisk()]));

        if (in_array('r2_legacy', $dyski, true)) {
            return $dyski;
        }

        if ((string) config('filesystems.disks.r2_legacy.bucket') === '') {
            return $dyski;
        }

        $dyski[] = 'r2_legacy';

        return $dyski;
    }

    /**
     * Kasuje jeden klucz z każdego z podanych dysków, PRÓBUJĄC WSZYSTKIE —
     * porażka na jednym dysku nie ma prawa pominąć pozostałych.
     */
    private function skasujZKazdegoDysku(Media $zdjecie, string $klucz, array $nazwyDyskow): bool
    {
        $wszystkoSieUdalo = true;

        foreach ($nazwyDyskow as $nazwaDysku) {
            if (! $this->skasujZDysku($zdjecie, $nazwaDysku, $klucz)) {
                $wszystkoSieUdalo = false;
            }
        }

        return $wszystkoSieUdalo;
    }

    /**
     * Kasuje jeden plik z jednego dysku i SPRAWDZA, czy naprawdę zniknął —
     * dokładnie ten sam wzorzec co `CleanUpDataExports::skasujPlik()`, z tego
     * samego powodu: samo `delete()` nie jest dowodem, bo dysk z
     * `throw => false` zwraca po prostu `false`, a dysk z `throw => true`
     * potrafi rzucić w dowolnym miejscu serii wywołań.
     *
     * Plik, którego na tym dysku nigdy nie było (najczęstszy przypadek dla
     * `r2_legacy` — zdjęcie, które nigdy nie przeszło przez stary bucket),
     * liczy się jako sukces: nie ma czego kasować.
     */
    private function skasujZDysku(Media $zdjecie, string $nazwaDysku, string $klucz): bool
    {
        try {
            $dysk = Storage::disk($nazwaDysku);

            if (! $dysk->exists($klucz)) {
                return true;
            }

            $dysk->delete($klucz);

            if ($dysk->exists($klucz)) {
                Log::error('Plik zdjęcia nadal istnieje po próbie usunięcia', [
                    'media_id' => $zdjecie->getKey(),
                    'dysk' => $nazwaDysku,
                    'klucz' => $klucz,
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            // Wyjątek na JEDNYM pliku/dysku nie ma prawa pominąć reszty —
            // inaczej jedno padłe wywołanie R2 zostawia wszystkie kolejne
            // warianty (i oryginał) nietknięte, bez śladu w bazie, po którym
            // dałoby się to zauważyć. Log MUSI zostać: bez klucza i dysku nie
            // da się tego dokończyć ręcznie.
            Log::error('Nie udało się usunąć pliku zdjęcia', [
                'media_id' => $zdjecie->getKey(),
                'dysk' => $nazwaDysku,
                'klucz' => $klucz,
                // Klucz jest wyżej, z modelu. Z wyjątku klasa i kod —
                // komunikat klienta storage niesie adres żądania (#973).
                'error' => BezpiecznyBlad::kontekst($e),
            ]);

            return false;
        }
    }

    /**
     * Publiczny adres wariantu albo `null`, gdy dysk go nie zna.
     *
     * Dysk bez skonfigurowanego `url` (lokalny, testowy, a także dysk
     * ORYGINAŁÓW — tam brak URL-a jest celowy) rzuca wyjątek zamiast zwracać
     * adres. To jest poprawne zachowanie i nie może wywrócić kasowania:
     * po prostu nie ma wtedy czego czyścić w CDN-ie.
     */
    private function publicznyAdres(Filesystem $dysk, string $klucz): ?string
    {
        try {
            return $dysk->url($klucz);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Adres trasy `media.show` dla jednego wariantu.
     *
     * Budujemy go z nazwy wariantu, a nie przez `Media::url()`: tamta metoda
     * ma własną kolejność wyboru („żądany wariant → dowolny wygenerowany"),
     * więc dla trzech wariantów oddałaby trzy razy ten sam adres, gdyby akurat
     * któregoś brakowało. Do czyszczenia potrzebujemy każdego z osobna.
     */
    private function adresTrasy(Media $zdjecie, string $nazwaWariantu): ?string
    {
        try {
            return route('media.show', [
                'media' => $zdjecie->getKey(),
                'wariant' => $nazwaWariantu,
            ]);
        } catch (Throwable) {
            // Z tego samego powodu co `publicznyAdres()` wyżej: budowanie
            // adresu nie ma prawa wywrócić kasowania. Kasowanie zdjęcia musi
            // się udać także wtedy, gdy nie da się powiedzieć, co wyczyścić.
            return null;
        }
    }
}
