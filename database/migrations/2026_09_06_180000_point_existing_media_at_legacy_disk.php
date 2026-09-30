<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zdjęcia sprzed rozdzielenia bucketów wskazują na dysk zgodności (audyt W4-03).
 *
 * PROBLEM
 * Rozdzielenie oryginałów (prywatny bucket) i wariantów (publiczny) zmieniło
 * to, co znaczy nazwa dysku `r2`: wcześniej był to JEDEN, wspólny i publiczny
 * bucket, teraz jest to bucket prywatny, w którym leżą wyłącznie oryginały
 * zapisane PO tej zmianie.
 *
 * Wiersze zapisane wcześniej mają w kolumnie `disk` wartość `r2` — i ich pliki
 * są w starym buckecie, nie w nowym. Po przestawieniu `AWS_BUCKET` ta sama
 * nazwa wskazywałaby więc miejsce, w którym tych plików nie ma: warianty
 * przestałyby się wyświetlać, a oryginałów nie dałoby się dołączyć do paczki
 * RODO. Zdjęcia ludzi po prostu by zniknęły.
 *
 * ROZWIĄZANIE
 * Stary bucket dostał własną nazwę dysku (`r2_legacy`, z zachowanym publicznym
 * URL-em, bo stamtąd stare warianty nadal się wyświetlają). Ta migracja
 * przestawia na nią istniejące wiersze. Fizyczne przeniesienie plików robi
 * potem `kuking:przenies-zdjecia`, wiersz po wierszu i dopiero po sprawdzeniu,
 * że kopia naprawdę powstała.
 *
 * MIGRACJA ODMAWIA ZGADYWANIA
 * Jeśli są wiersze do przestawienia, a `AWS_LEGACY_BUCKET` nie jest ustawiony,
 * migracja PRZERYWA. Wskazanie dysku bez bucketu znaczyłoby „pliki są tam,
 * gdzie nigdzie" — a to jest gorsze niż stan sprzed migracji, bo wygląda na
 * uporządkowane.
 *
 * Na środowisku bez ani jednego zdjęcia na R2 (dziś: każde, bo pierwszego
 * wdrożenia jeszcze nie było — issue #3) migracja nie robi nic i przechodzi
 * bez pytania.
 *
 * ROLLBACK: przestawienie z powrotem na `r2` — ale tylko wtedy, gdy da się je
 * odwrócić (D-088, issue #2329).
 *
 * Po `up()` nazwa `r2` znaczy już wyłącznie NOWY, prywatny bucket: `up()`
 * przestawiło wszystkie stare wiersze, więc każdy wiersz z `disk = 'r2'` jest
 * albo przeniesiony przez `kuking:przenies-zdjecia` (produkcyjny
 * `KUKING_MEDIA_DISK` to `r2`), albo dodany po rozdzieleniu bucketów. Gdyby
 * `down()` przestawiło wtedy stare wiersze na `r2`, obie grupy dostałyby tę
 * samą nazwę i nikt już by ich nie odróżnił: stare zdjęcia wskazywałyby
 * bucket, w którym ich plików nie ma, a następne `up()` (każdy `migrate` po
 * cofnięciu) przestawiłoby na `r2_legacy` także przeniesione i nowe — na
 * bucket, w którym nowych plików nigdy nie było. Zdjęcia by zniknęły.
 *
 * Dlatego `down()` ODMAWIA, gdy jest choć jeden wiersz z `disk = 'r2'`,
 * i niczego wtedy nie zmienia. Odmowa jest wąska: na środowisku bez zdjęć na
 * R2 oraz na produkcji przed pierwszym nowym zdjęciem i przed przenosinami
 * rollback przechodzi jak dawniej (wtedy jest bezstratny — kolumna to nazwa
 * logiczna, nie ścieżka, a `up()` odtwarza ją w całości). Dawne zdanie
 * „cofać PRZED przenosinami" w komentarzu nie było zabezpieczeniem;
 * zabezpieczeniem jest `throw` (AGENTS.md §6). `up()` jest bez zmian.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ile = DB::table('media')->where('disk', 'r2')->count();

        if ($ile === 0) {
            return;
        }

        if ((string) config('filesystems.disks.r2_legacy.bucket') === '') {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „jest 1
            // zdjęć" to nie polszczyzna, a jedno zdjęcie jest stanem
            // prawdopodobniejszym niż pięć. Mianownik przed dwukropkiem nie
            // odmienia się wcale, więc zdanie jest poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Liczba zdjęć zapisanych przed rozdzieleniem bucketów R2: '.$ile.'. '
                .'Ich pliki leżą w starym, wspólnym buckecie, a nazwa `r2` wskazuje teraz nowy, '
                ."prywatny.\n\n"
                ."Ustaw AWS_LEGACY_BUCKET na nazwę STAREGO bucketu i uruchom migrację ponownie.\n"
                .'Bez tego wiersze wskazywałyby dysk bez bucketu, czyli „pliki są nigdzie" — '
                .'a zdjęcia zniknęłyby z serwisu.',
            );
        }

        DB::table('media')->where('disk', 'r2')->update([
            'disk' => 'r2_legacy',
            'variants_disk' => 'r2_legacy',
        ]);
    }

    public function down(): void
    {
        $nowe = DB::table('media')->where('disk', 'r2')->count();

        if ($nowe > 0) {
            // Rzeczownik przed liczbą, liczba na końcu zdania — jak w `up()`.
            throw new RuntimeException(
                'Cofnięcie migracji przerwane, niczego nie zmieniono. '
                .'Liczba zdjęć, które już wskazują NOWY bucket (`disk = r2`): '.$nowe.'. '
                ."To zdjęcia przeniesione przez `kuking:przenies-zdjecia` albo dodane po rozdzieleniu bucketów.\n\n"
                .'Po cofnięciu nie dałoby się ich odróżnić od starych, a kolejne `migrate` przestawiłoby je '
                ."na stary bucket, w którym ich plików nie ma — zdjęcia zniknęłyby z serwisu.\n\n"
                ."Co zrobić: cofnij wdrożenie BEZ cofania tej migracji.\n"
                .'Jeśli cofnięcie danych jest naprawdę konieczne: zrób kopię tabeli `media`, uruchom '
                .'`php artisan kuking:zaleznosc-od-starego-bucketu --pliki` i przestaw wiersze ręcznie, '
                .'każdy według tego, w którym buckecie naprawdę leży jego plik.',
            );
        }

        DB::table('media')->where('disk', 'r2_legacy')->update([
            'disk' => 'r2',
            'variants_disk' => null,
        ]);
    }
};
