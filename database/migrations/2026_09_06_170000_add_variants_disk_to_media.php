<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oryginał i warianty mogą leżeć na DWÓCH RÓŻNYCH dyskach (audyt G-01).
 *
 * PO CO
 * Do tej pory `media.disk` mówił, gdzie leży wszystko: oryginał pod `incoming/`
 * i warianty pod `media/`, w jednym buckecie R2. Prywatność oryginału opierała
 * się na zapisaniu go jako „private".
 *
 * Na R2 to rozróżnienie nie istnieje — Cloudflare nie implementuje S3-owych ACL
 * na obiektach, a publiczność jest cechą BUCKETU (własna domena). Bucket
 * wystawiony pod `cdn.kuking.pl` wystawiał więc także `incoming/`, czyli
 * oryginały z pełnym EXIF-em i współrzędnymi GPS. Adres oryginału dawało się
 * przy tym wyprowadzić z publicznego adresu wariantu: ten sam UUID, ta sama
 * data, wystarczyło zamienić prefiks i zgadnąć rozszerzenie z czterech.
 *
 * Dlatego oryginały idą do bucketu bez własnej domeny, a warianty do osobnego,
 * publicznego. Ta kolumna mówi, gdzie leżą TE DRUGIE.
 *
 * NULL ZNACZY „TAM, GDZIE ORYGINAŁ" i to jest celowe. Każdy istniejący wiersz
 * ma dziś warianty w tym samym buckecie co oryginał, więc `null` opisuje stan
 * faktyczny bez ruszania ani jednego wiersza i bez zgadywania. Backfill
 * wpisałby tam nazwę nowego dysku, czyli SKŁAMAŁ o tym, gdzie te pliki
 * naprawdę są — i `KasujZdjecie` szukałby ich w niewłaściwym buckecie,
 * zostawiając publiczne kopie na zawsze.
 *
 * Przeniesienie starych plików do nowych bucketów to osobna praca: robi ją
 * komenda `kuking:przenies-zdjecia`, kopiując obiekty i aktualizując wiersz
 * dopiero po sprawdzeniu, że kopia naprawdę powstała.
 *
 * SPROSTOWANIE DO PIERWSZEJ WERSJI TEGO KOMENTARZA (audyt W4-03).
 * Stało tu, że „oba źródła współistnieją i kod obsługuje jedno i drugie".
 * Nie było to prawdą: stare wiersze mają `disk = 'r2'`, a ta nazwa po
 * rozdzieleniu wskazuje NOWY, prywatny bucket, w którym tych plików nie ma.
 * Współistnienie wymaga osobnego dysku `r2_legacy` wskazującego stary bucket —
 * i dopiero on istnieje. To był komentarz pewniejszy niż kod.
 *
 * ROLLBACK: `down()` usuwa samą kolumnę. Bezstratnie — informacja o dysku
 * wariantów wraca wtedy do „ten sam co oryginał", czyli do stanu sprzed
 * rozdzielenia bucketów. Uwaga przy cofaniu na produkcji PO przeniesieniu
 * wariantów: wtedy ta kolumna niesie już prawdziwą wiedzę i jej utrata
 * oznacza, że aplikacja szuka wariantów w starym buckecie. Cofać więc przed
 * migracją danych, nie po.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->string('variants_disk', 40)->nullable()->after('disk');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropColumn('variants_disk');
        });
    }
};
