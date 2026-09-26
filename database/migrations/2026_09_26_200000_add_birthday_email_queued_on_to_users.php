<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rozdzielenie „zajęto dzień" od „list wyszedł" (issue #1956).
 *
 * CO BYŁO ZEPSUTE
 * `kuking:wyslij-zyczenia-urodzinowe` zajmowało dzień warunkowym `UPDATE`
 * na `users.birthday_email_sent_on` PRZED `Mail::queue()`. Kolumna, której
 * nazwa obiecuje „list wyszedł", stawała się prawdziwa w chwili
 * ZAKOLEJKOWANIA — a `Mail::queue()` to wstawienie wiersza do `jobs`,
 * nie wysyłka. Awaria enqueue albo trwała porażka workera (dostawca padnięty
 * przez trzy próby) zostawiały znacznik ustawiony, mimo że żaden list nie
 * doszedł, a `kandydaci()` wyklucza dzisiaj każdego z niepustym znacznikiem
 * na dziś — więc drugiej szansy tego dnia nie było, a to jest jedyny list
 * w roku dla tej osoby.
 *
 * CO JEST
 * `users.birthday_email_queued_on` (`date NULL`) przejmuje rolę BARIERY
 * przed podwójnym zakolejkowaniem tego samego dnia — dokładnie ten warunkowy
 * `UPDATE`, który wcześniej stał na `birthday_email_sent_on`. Sama kolumna
 * `birthday_email_sent_on` zostaje, ale znaczenie ma teraz dosłowne: ustawia
 * ją WYŁĄCZNIE `App\Mail\ZyczeniaUrodzinowe::send()`, i to dopiero PO tym,
 * jak `parent::send()` wróci bez wyjątku — czyli po tym, jak transport
 * pocztowy PRZYJĄŁ wiadomość (ten sam standard co PR #1861 dla decyzji
 * moderacyjnych w sprawach prawnych, `DecyzjaWSprawieZgloszenia`).
 *
 * Awaria samego `Mail::queue()` (rzadka — to lokalny `INSERT` do `jobs`, nie
 * wywołanie dostawcy) zwalnia rezerwację `birthday_email_queued_on`
 * z powrotem do `NULL` w tym samym przebiegu komendy, więc ponowienie tego
 * samego dnia wysyła dokładnie jeden list. Trwała porażka workera (po
 * wyczerpaniu prób) zostawia `birthday_email_queued_on` ustawione (dzień
 * i tak jest już nieodwracalnie „zużyty" wobec dostawcy — list poszedł do
 * kolejki i próbował wyjść) i `birthday_email_sent_on` puste — dokładnie tak,
 * jak dziś wygląda porażka `weekly_digest_sends` (D-077): świadomy wybór
 * „pominięcie zamiast duplikatu" DLA TEGO SAMEGO DNIA, ale — inaczej niż
 * przy cotygodniowym podsumowaniu — `birthday_email_queued_on` NIE blokuje
 * kolejnych DNI: jutro nie jest dzisiaj, więc rocznica sprzed roku i tak
 * wraca za rok, a to jest jedyny scenariusz, w którym ta osoba w ogóle
 * ponownie kwalifikuje się do listu.
 *
 * ROLLBACK
 * `down()` NIE odmawia (D-088 dotyczy wartości SEMANTYCZNYCH — zgody, zakresu
 * usunięcia, widoczności czyjejś decyzji). Ta kolumna nie niesie decyzji
 * człowieka: to wewnętrzna bariera przeciw podwójnemu zakolejkowaniu, ważna
 * WYŁĄCZNIE w dniu, w którym stoi, i zerująca się sama następnego dnia —
 * ta sama klasa wartości co `theme` czy `posts.display_mode`, świadomie bez
 * strażnika (uzasadnienie tamtych dwóch w D-088). Po cofnięciu komenda wraca
 * do zajmowania dnia na `birthday_email_sent_on` WYŁĄCZNIE wtedy, gdy kod
 * z przed tej zmiany też wróci — a to nie jest coś, co robi migracja.
 * Najgorszy skutek samego zdjęcia kolumny bez cofnięcia kodu: nowy kod
 * odwołujący się do brakującej kolumny rzuci błąd przy próbie wysyłki, co
 * jest bezpiecznym kierunkiem awarii (żaden list nie wyjdzie podwójnie).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('birthday_email_queued_on')->nullable()->after('birthday_email_sent_on');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('birthday_email_queued_on');
        });
    }
};
