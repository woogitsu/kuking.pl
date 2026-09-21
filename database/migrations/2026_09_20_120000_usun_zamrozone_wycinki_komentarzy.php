<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * USUNIĘCIE ZAMROŻONYCH WYCINKÓW TREŚCI Z POWIADOMIEŃ O KOMENTARZACH.
 *
 * Decyzja właściciela z 20.09.2026 (D-229): powiadomienie liczy wycinek
 * z AKTUALNEJ treści komentarza, a eksport RODO zmienia się razem z nim.
 * Uzasadnieniem było zdanie: paczka ma pokazywać, co o kimś trzymamy DZIŚ.
 *
 * Po tamtej zmianie w `notifications.data` zostały kopie treści sprzed
 * poprawki — do 120 znaków cudzego tekstu. Nikt ich już nie czyta, ale
 * nadal je trzymamy, a to jest dokładnie ta różnica, którą decyzja
 * rozstrzyga: „nie trzymamy" zamiast „nie czytamy". Właściciel wybrał
 * wyczyszczenie ich migracją, nie czekanie na retencję.
 *
 * ZAKRES JEST WĄSKI CELOWO. Usuwamy wyłącznie klucz `excerpt` i wyłącznie
 * z dwóch typów powiadomień o komentarzach. `excerpt` zostaje w innych
 * typach, bo tam nie został zastąpiony niczym żywym — skasowanie go
 * zabrałoby treść, której nic nie odtworzy.
 *
 * WYCOFANIE NIE PRZYWRÓCI DANYCH. To jest jedyna uczciwa treść `down()`:
 * kasujemy wartości, których nie ma skąd odczytać z powrotem. Migracja
 * w drugą stronę, która milczy, twierdziłaby, że wycofanie jest pełne —
 * a nie jest. Utraty nie ma czego żałować (wycinek liczy się teraz
 * z komentarza), ale powiedzieć o niej trzeba.
 *
 * Plan wycofania dla człowieka: jeśli te wartości okażą się potrzebne,
 * jedynym źródłem jest kopia zapasowa bazy sprzed uruchomienia migracji.
 * Sama migracja nie umie ich odtworzyć i nie udaje, że umie.
 */
return new class extends Migration
{
    /** Typy, w których wycinek jest już liczony z żywej treści. */
    private const TYPY = ['comment.created', 'comment.replied'];

    public const WYCOFANIE_NIC_NIE_ROBI = 'Migracja kasuje klucz `excerpt` z `notifications.data`, '
        .'czyli zamrożone kopie cudzej treści sprzed decyzji D-229. Tych wartości nie ma skąd '
        .'odczytać z powrotem: wycinek liczy się teraz z ŻYWEJ treści komentarza, a kopii nigdzie '
        .'indziej nie trzymamy. `down()`, które cokolwiek wpisuje, wpisałoby wartość zmyśloną — '
        .'i to byłoby gorsze niż brak wycofania, bo wyglądałoby na prawdziwe. Jedynym źródłem '
        .'tych danych jest kopia zapasowa bazy sprzed uruchomienia migracji.';

    public function up(): void
    {
        /*
         * `data - 'excerpt'` to operator jsonb, nie tekstowy: zdejmuje klucz
         * i zostawia resztę nietkniętą. Warunek `data ? 'excerpt'` zawęża
         * zapis do wierszy, które ten klucz naprawdę mają — bez niego
         * przepisalibyśmy każdy wiersz obu typów po nic.
         *
         * Znak zapytania jest w PDO znakiem zastępczym, więc operator
         * `?` (czy jsonb ma klucz) trzeba podać jako `jsonb_exists`.
         */
        DB::table('notifications')
            ->whereIn('type', self::TYPY)
            ->whereRaw("jsonb_exists(data, 'excerpt')")
            ->update(['data' => DB::raw("data - 'excerpt'")]);
    }

    public function down(): void
    {
        /*
         * Świadomie puste. Patrz nagłówek: skasowanych wycinków nie ma
         * skąd odtworzyć, a `down()`, które coś wpisuje, wpisałoby zmyśloną
         * wartość. Pusty `down()` pozwala cofnąć migrację bez błędu
         * i nie kłamie o tym, co przywraca.
         */
    }
};
