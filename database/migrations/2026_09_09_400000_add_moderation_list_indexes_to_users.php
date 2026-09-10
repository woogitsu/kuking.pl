<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indeksy pod listę kont w panelu moderacji (`/admin/uzytkownicy`).
 *
 * PO CO — I DLACZEGO DOPIERO TERAZ
 * `users` do tej pory nie było tabelą, po której się CHODZI. Czytało się z niej
 * pojedyncze konto po `id` albo po `lower(email)` przy logowaniu — na jedno
 * i drugie indeks jest od pierwszego dnia. Lista moderacyjna zadaje dwa
 * pytania, których wcześniej nie zadawał nikt:
 *
 *   1. „pokaż konta po dacie rejestracji, najnowsze na górze" (i zakresem:
 *      „kto przyszedł dzisiaj") — `ORDER BY users.created_at DESC`;
 *   2. „czyje to konto" z fragmentem adresu e-mail — `kuking_normalize(email)
 *      LIKE '%…%'`.
 *
 * Przy dwudziestu kontach (D-012) obie odpowiedzi dawał skan sekwencyjny
 * i było to bez znaczenia. Założenie się zmieniło: właściciel zapowiada
 * przejście grupy z Garnek.pl, czyli setki, a potem tysiące kont. Wtedy
 * pierwsze pytanie znaczy „posortuj całą tabelę, żeby pokazać 25 wierszy",
 * a drugie — „policz `unaccent()` dla każdego wiersza z osobna".
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  DWA INDEKSY, NIE CZTERY
 * ══════════════════════════════════════════════════════════════════════════
 *
 * `users_created_at_idx (created_at DESC)`
 *     Domyślny widok ekranu i filtr zakresu dat. `DESC` w definicji, bo
 *     dokładnie tak czyta go zapytanie; PostgreSQL umie iść indeksem w obie
 *     strony, więc „najstarsze na górze" korzysta z tego samego indeksu.
 *
 * `users_email_trgm_idx gin (kuking_normalize(email) gin_trgm_ops)`
 *     Szukanie po fragmencie adresu. Indeks stoi na WYRAŻENIU — tak samo jak
 *     `ingredients_name_trgm_idx` — bo warunek pyta o dokładnie to samo
 *     wyrażenie, a indeks na surowej kolumnie nie byłby użyty (to jest ta
 *     sama pułapka, którą opisuje migracja `2026_09_05_001300_fix_search_indexes`).
 *
 * ŚWIADOMIE BEZ `(status, created_at)`. Kusi, bo zakładki filtra zawężają po
 * statusie — ale `active` to będzie zdecydowana większość wierszy, więc dla
 * najczęstszego widoku taki indeks nie daje nic ponad ten wyżej, a pozostałe
 * zakładki dotyczą garstki kont, którą baza i tak odsieje tanio. Drugi indeks
 * na tym samym `created_at` kosztowałby każdy zapis do `users` (a te lecą przy
 * każdym odświeżeniu `ostatnio_widziany_at`) bez zmierzonego zysku. To jest
 * następny krok, nie ten — wraca, gdy zawieszonych kont będą tysiące.
 *
 * ŚWIADOMIE BEZ KOLUMNY GENEROWANEJ `email_search`. `docs/DATABASE.md` opisuje
 * kolumny `*_search` jako wzorzec domyślny i słusznie — ale tam chodzi
 * o recheck przy operatorze `%` na dziesiątkach tysięcy kandydatów
 * w wyszukiwarce treści. Tutaj zapytanie robi jeden moderator, kilka razy
 * dziennie, a kolumna oznaczałaby DRUGĄ kopię adresu e-mail w tabeli —
 * czyli więcej danych osobowych w bazie za oszczędność, której na tym ekranie
 * nie da się zauważyć. Indeks przechowuje trigramy, nie adres.
 *
 * ROLLBACK
 * `down()` kasuje oba indeksy. Bezstratny w pełnym tego słowa znaczeniu:
 * indeks nie trzyma danych, których nie ma w tabeli, a ekran działa bez nich
 * dalej — tylko wolniej. Wykonuje się w sekundy, bez blokowania zapisów
 * (`DROP INDEX` bierze blokadę na tabelę na moment; przy tej wielkości to
 * pojedyncze milisekundy).
 *
 * UWAGA PRZY BUDOWANIU NA PRODUKCJI: `CREATE INDEX` bez `CONCURRENTLY`
 * blokuje zapisy do `users` na czas budowy. Przy tysiącach wierszy to ułamek
 * sekundy i nie komplikujemy migracji o tryb bez transakcji. Gdyby tabela
 * urosła do setek tysięcy kont, ten indeks zakłada się ręcznie
 * (`CREATE INDEX CONCURRENTLY`), a migracja przechodzi wtedy „na sucho"
 * dzięki `IF NOT EXISTS`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS users_created_at_idx ON users (created_at DESC)');

        // `public.` przy funkcji i przy klasie operatorów — od PostgreSQL 17
        // `CREATE INDEX` chodzi z ograniczonym `search_path`, więc
        // niekwalifikowana nazwa potrafi tu przestać być widoczna. Pełne
        // uzasadnienie: docs/DATABASE.md, „Wyszukiwarka: funkcja
        // kuking_normalize()".
        DB::statement(
            'CREATE INDEX IF NOT EXISTS users_email_trgm_idx '
            .'ON users USING gin (public.kuking_normalize(email) public.gin_trgm_ops)',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS users_created_at_idx');
    }
};
