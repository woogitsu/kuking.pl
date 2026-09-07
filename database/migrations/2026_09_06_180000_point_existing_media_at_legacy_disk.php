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
 * ROLLBACK: przestawienie z powrotem na `r2`. Bezstratne dla plików — kolumna
 * to nazwa logiczna, nie ścieżka. Uwaga: cofać PRZED `kuking:przenies-zdjecia`,
 * nie po; potem `r2` byłoby już prawdą dla części wierszy.
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
            throw new RuntimeException(
                "W bazie jest {$ile} zdjęć zapisanych przed rozdzieleniem bucketów R2. "
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
        DB::table('media')->where('disk', 'r2_legacy')->update([
            'disk' => 'r2',
            'variants_disk' => null,
        ]);
    }
};
