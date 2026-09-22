<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wspomnienia: „Rok temu gotowałaś…" (issue #34).
 *
 * DWIE KOLUMNY, BO SĄ DWA RÓŻNE „NIE CHCĘ TEGO WIDZIEĆ"
 *
 * `users.memories_enabled` — wyłączenie mechaniki W CAŁOŚCI, jednym
 * przełącznikiem. To nie jest ustawienie wygody. Wpis z przepisem po mamie,
 * która zmarła w tym roku, wyświetlony bez ostrzeżenia na stronie głównej,
 * jest okrutny — i człowiek w żałobie musi mieć jak to wyłączyć od razu,
 * nie „odklikując" wspomnienie po wspomnieniu.
 *
 * `posts.hide_as_memory` — ukrycie JEDNEGO wpisu, przy zachowaniu całej
 * mechaniki. Bo zwykle boli jedna rzecz, a nie wszystkie.
 *
 * DLACZEGO KOLUMNA NA `posts`, A NIE OSOBNA TABELA
 * Wspomnienie to ZAWSZE własny wpis oglądającego — pokazujemy komuś jego
 * własne archiwum, nie cudze. Właściciel wpisu i osoba ukrywająca to ta sama
 * osoba, więc tabela `(user_id, post_id)` niosłaby tę samą informację
 * w dwóch kolumnach, z których jedna zawsze wynika z drugiej.
 *
 * Wpis pozostaje w archiwum profilu — ukrycie dotyczy WYŁĄCZNIE wypływania
 * na stronie głównej. „Nie przypominaj mi o tym" to nie to samo co „usuń to".
 *
 * ROLLBACK — POPRAWIONY PO #287 (D-088), TO BYŁA TA SAMA CHOROBA CO MIG-01
 * Ten akapit mówił wcześniej: „`down()` zdejmuje obie kolumny. Traci przy tym
 * listę ukrytych wspomnień (ustawienie wraca do domyślnego »pokazuj«), więc
 * po cofnięciu tej migracji człowiek zobaczy z powrotem to, co świadomie
 * schował. Przy cofaniu na produkcji najpierw kopia obu kolumn". Opis był
 * PRAWDZIWY, a mimo to bezwartościowy jako zabezpieczenie: ostatnie zdanie
 * przenosiło całą ochronę na CZYJĄŚ PAMIĘĆ w trakcie awaryjnego wdrożenia,
 * czyli w jedynym momencie, w którym nikt nie czyta komentarzy w migracjach.
 *
 * MIG-01 (#287) rozstrzygnęło ten wzorzec dla `users.delete_scope`, ale
 * przegląd migracji zrobiony wtedy pod tym kątem wymienił trzy inne pliki
 * i TĘ MIGRACJĘ PRZEOCZYŁ — a ona łamie dokładnie tę samą regułę D-088
 * („przy wartościach semantycznych: zgoda, zakres usunięcia, WIDOCZNOŚĆ,
 * prywatność zeszytu — rollback ma odmówić"). Obie kolumny tutaj są
 * wartościami semantycznymi i obie mają `DEFAULT` po stronie `up()`, więc
 * cykl `migrate:rollback` → `migrate` (czyli `migrate:refresh` w CI oraz
 * awaryjny rollback WDROŻENIA, nie tylko bazy) po cichu nadpisuje decyzję
 * człowieka wartością domyślną — ODWROTNĄ do tej, którą wybrał.
 *
 * ZMIERZONE NA PRAWDZIWEJ BAZIE, nie w teorii (opis w PR-ze #287):
 *
 *     PRZED:    memories_enabled=false  hide_as_memory=true
 *     PO CYKLU: memories_enabled=true   hide_as_memory=false
 *
 * Przeczytaj te dwie linijki jako zdanie o człowieku, nie o kolumnach:
 * wyłącznik, którym osoba w żałobie wyłączyła wspomnienia, WŁĄCZA SIĘ SAM,
 * a wpis z przepisem po mamie, który świadomie schowała, WRACA na stronę
 * główną. Bez błędu, bez ostrzeżenia, z poprawnymi kolumnami i poprawnymi
 * wartościami typu `boolean`. Dokładnie to nazywa `WspomnieniaTest`:
 * „zrobienie komuś przykrości drugi raz, po tym jak poprosił, żeby przestać".
 *
 * Naprawa idzie wariantem 2 z #287, tym samym co w
 * `2026_09_07_500000_add_erased_status_and_delete_scope_to_users`: `down()`
 * ODMAWIA, gdy ktokolwiek ma wartość inną od domyślnej — zamiast zgadywać
 * `DEFAULT`-em, czego chciał człowiek. Na bazie, w której wszyscy siedzą na
 * domyślnych (czyli praktycznie zawsze w CI i na świeżym stagingu), rollback
 * przechodzi bez pytania — inaczej „naprawą" byłoby zablokowanie rollbacku
 * na zawsze, błąd tej samej wagi w drugą stronę.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // DOMYŚLNIE WŁĄCZONE. Funkcja, która wymaga włączenia, nie istnieje
            // dla nikogo poza tym, kto o niej wie — a to jest mechanika dla
            // osoby gotującej od czterdziestu lat, nie dla osoby, która czyta
            // ustawienia.
            $table->boolean('memories_enabled')->default(true);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('hide_as_memory')->default(false);
        });
    }

    public function down(): void
    {
        // STRAŻNIK PRZED CICHYM ODWRÓCENIEM DECYZJI CZŁOWIEKA (#287, D-088).
        //
        // MUSI stać przed pierwszym `dropColumn` — sprawdzenie po fakcie
        // chroniłoby wyłącznie komunikat, bo po zdjęciu kolumny nie ma już
        // czego policzyć (ten sam błąd kolejności, którego pilnują
        // `CofniecieMigracjiNieKasujeZeszytowTest` i strażnik w migracji
        // `..._add_erased_status_and_delete_scope_to_users`).
        //
        // Dwa liczniki, a nie jeden, bo to są DWIE RÓŻNE decyzje i każda
        // ginie osobno: wyłącznik całej mechaniki (`users`) i schowanie
        // pojedynczego wpisu (`posts`). Konto z włączonymi wspomnieniami
        // i jednym schowanym wpisem nie ma nic w pierwszym liczniku, a mimo
        // to ma co stracić.
        //
        // `DB::table()` (query builder), nie surowe SQL — ta migracja nie ma
        // gałęzi `isPostgres()` i strażnik ma działać na każdym sterowniku,
        // tak jak `dropColumn` niżej.
        $zWylaczonymi = DB::table('users')->where('memories_enabled', false)->count();
        $zeSchowanymi = DB::table('posts')->where('hide_as_memory', true)->count();

        if ($zWylaczonymi > 0 || $zeSchowanymi > 0) {
            // Rzeczownik PRZED liczbą, liczba na końcu zdania — „1 kont ma
            // wyłączone" i „1 wpisów jest schowanych" to nie polszczyzna,
            // a jeden wiersz jest stanem prawdopodobniejszym niż pięć.
            // Mianownik przed dwukropkiem nie odmienia się wcale, więc oba
            // zdania są poprawne dla 1, 2, 5 i 22.
            throw new RuntimeException(
                'Cofnięcie odmówione. Liczba kont z wyłączonymi wspomnieniami '.
                '(memories_enabled = false): '.$zWylaczonymi.'. Liczba schowanych wpisów '.
                '(hide_as_memory = true): '.$zeSchowanymi.'. Obie te wartości są decyzją człowieka o tym, czego '.
                'NIE chce widzieć — a nie ustawieniem wygody. Stary schemat (sprzed tej migracji) '.
                'nie ma tych kolumn wcale: gdyby cofnięcie przeszło, kolejny `migrate` odtworzyłby '.
                'je z `DEFAULT`, czyli jako `memories_enabled = true` i `hide_as_memory = false` — '.
                'ODWROTNOŚĆ obu decyzji. Wyłącznik osoby w żałobie włączyłby się sam, a schowany '.
                'wpis wrócił na stronę główną (#287, D-088, ta sama choroba co MIG-01 '.
                "w `2026_09_07_500000_add_erased_status_and_delete_scope_to_users.php`).\n\n".
                "CO ZROBIĆ:\n".
                '  - jeśli cofasz z powodu awaryjnego rollbacku WDROŻENIA (obraz aplikacji), nie '.
                'cofaj TEJ migracji — kod sprzed niej nie zna obu kolumn i działa z nimi bez zmian '.
                '(docs/research/audyt-2026-09-10/26_MIGRACJE_ROLLBACK_I_BEZPIECZNE_WDROZENIA.md: '.
                "rollback obrazu i rollback bazy to dwie różne decyzje);\n".
                "  - jeśli naprawdę trzeba cofnąć SCHEMAT, zapisz obie listy PRZED cofnięciem:\n".
                "      SELECT id FROM users WHERE memories_enabled = false;\n".
                "      SELECT id FROM posts WHERE hide_as_memory = true;\n".
                '    a po powrocie na tę wersję schematu odtwórz je tym samym `UPDATE`, ZANIM '.
                'strona główna pokaże komukolwiek choć jedno wspomnienie.',
            );
        }

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('hide_as_memory');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('memories_enabled');
        });
    }
};
