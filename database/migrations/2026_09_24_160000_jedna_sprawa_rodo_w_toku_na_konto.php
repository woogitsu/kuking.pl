<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jedna otwarta sprawa RODO na konto — gwarancja w bazie (issue #1346).
 *
 * `RejestrPotwierdzenRodo::domknij()` zamyka JEDNĄ sprawę `w_toku` danego
 * konta. Gdyby było ich więcej, reszta zostawałaby otwarta na zawsze przy
 * żądaniu, które już wykonano albo cofnięto — fałszywy stan w dowodzie
 * obsługi żądania. Aplikacja nie dopuszcza drugiej (świeży wiersz konta pod
 * `ZamekKonta` w `User::markForDeletion()`, #980); ten indeks pilnuje tego
 * samego dla każdej innej drogi zapisu, także takiej, której jeszcze nie ma.
 *
 * ISTNIEJĄCE DUPLIKATY: migracja ich NIE rozstrzyga sama, tylko odmawia.
 * Który z dwóch wierszy jest „prawdziwym" żądaniem, to decyzja o treści
 * dowodu, nie krok techniczny — więc należy do człowieka z instrukcją niżej.
 *
 * WYCOFANIE: zdjęcie indeksu. Nie usuwa żadnego wiersza, więc nie ma tu
 * czego odmawiać (D-088 — odmowa tylko tam, gdzie wycofanie niszczy dane).
 */
return new class extends Migration
{
    private const INDEKS = 'potwierdzenia_zadan_rodo_jedna_w_toku_na_konto';

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $zdublowane = DB::table('potwierdzenia_zadan_rodo')
            ->where('wynik', 'w_toku')
            ->whereNotNull('konto_id')
            ->groupBy('konto_id')
            ->havingRaw('count(*) > 1')
            ->pluck('konto_id');

        if ($zdublowane->isNotEmpty()) {
            throw new RuntimeException(
                'Odmawiam założenia indeksu '.self::INDEKS.'. Liczba kont z więcej niż jedną otwartą '
                .'sprawą RODO (wynik = w_toku): '.$zdublowane->count().'. Identyfikatory kont: '
                .$zdublowane->implode(', ').'.'
                ."\n\nCO ZROBIĆ: dla każdego z tych kont zostaw otwartą tę sprawę, której `otrzymano` "
                .'zgadza się z `users.delete_requested_at` (albo, gdy konto już nie jest w usuwaniu, '
                .'domknij wszystkie tak, jak zostało obsłużone żądanie), resztę domknij ręcznie z tym '
                .'samym wynikiem — i powtórz migrację. Nie kasuj wierszy: to są dowody obsługi żądań.',
            );
        }

        DB::statement('CREATE UNIQUE INDEX '.self::INDEKS.' ON potwierdzenia_zadan_rodo (konto_id) '
            ."WHERE wynik = 'w_toku' AND konto_id IS NOT NULL");
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
    }
};
