<?php

declare(strict_types=1);

namespace App\Domain\Digest;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Twarda bramka przed wysyłką: jeśli `users.wants_weekly_digest` ma
 * `DEFAULT true`, digest nie startuje (audyt DB2, `docs/DECISIONS.md` D-072).
 *
 * CO TA KLASA PILNUJE
 * Kolumna z `DEFAULT true` znaczy, że KAŻDE nowe konto — z rejestracji,
 * z seedera, z importu, z komendy — wstaje zapisane na tygodniowy list,
 * chociaż formularz rejestracji o tę zgodę nie pyta ani jednym polem.
 * Wysyłka w takim stanie nie jest „trochę niezgodna": każdy list wychodzi
 * bez podstawy prawnej (art. 6 ust. 1 lit. a RODO), a list wysłany jest
 * nieodwracalny.
 *
 * DLACZEGO BRAMKA, A NIE SAM KOMENTARZ W MIGRACJI
 * Migracja `2026_09_07_400000_default_weekly_digest_to_off` naprawiła
 * `DEFAULT`, a jej `down()` od 10 września świadomie NIE przywraca starej
 * wartości. To zamyka jedną drogę powrotu tej wady — ale nie zamyka
 * pozostałych, a każda z nich jest realna na produkcji:
 *
 *   - ręczny `ALTER TABLE … SET DEFAULT true` przy grzebaniu w bazie,
 *   - przywrócenie kopii bazy sprzed tej migracji (odtworzenie po awarii),
 *   - `pg_restore` samego schematu z takiej kopii,
 *   - `migrate:rollback` uruchomiony na starszym wydaniu kodu, w którym
 *     `down()` jeszcze przywracał `DEFAULT true`.
 *
 * W żadnym z tych przypadków nikt nie czyta komentarza w pliku migracji.
 * Bramka pyta o STAN FAKTYCZNY schematu przy każdym uruchomieniu wysyłki,
 * więc łapie wszystkie cztery drogi jednym warunkiem.
 *
 * DLACZEGO PYTANIE IDZIE DO `information_schema`, A NIE DO KONFIGURACJI
 * Bo pytanie dotyczy tego, co zrobi BAZA przy `INSERT` bez tej kolumny —
 * a to wie tylko baza. Zmienna środowiskowa mówiłaby, co ktoś kiedyś
 * zadeklarował, i rozjechałaby się dokładnie w tych czterech sytuacjach
 * wyżej. Jedno zapytanie na dobę (komenda chodzi raz dziennie) nie jest
 * kosztem, o który warto się targować.
 *
 * POZA POSTGRESEM BRAMKA JEST OTWARTA. `information_schema.columns` w tej
 * postaci to konstrukcja Postgresa, a serwis chodzi wyłącznie na nim
 * (AGENTS.md §3, testy też). Sterownik pamięciowy w narzędziach nie jest
 * środowiskiem, w którym cokolwiek wychodzi pocztą, więc udawanie tam
 * sprawdzenia dałoby tylko fałszywe „nie wolno".
 */
final class BramkaDomyslnejZgody
{
    /**
     * Czy wolno wysyłać digest, patrząc WYŁĄCZNIE na domyślną wartość zgody.
     *
     * Nie sprawdza ani wyłącznika `KUKING_DIGEST_WLACZONY`, ani limitów
     * poczty — te mają własne miejsca i własne komunikaty.
     */
    public static function otwarta(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return true;
        }

        $wiersz = DB::selectOne(
            "select column_default from information_schema.columns
             where table_name = 'users' and column_name = 'wants_weekly_digest'",
        );

        // Kolumny nie ma — to nie jest przypadek tej bramki (wysyłka i tak
        // padnie na wyborze odbiorców, z własnym błędem). Bramka nie ma
        // zgadywać, więc nie zamyka drogi z powodu, którego nie zna.
        if ($wiersz === null) {
            return true;
        }

        // Postgres oddaje `column_default` jako tekst z rzutowaniem typu:
        // `true` albo `false`, a po `ALTER … SET DEFAULT true` bywa też
        // `true::boolean`. Stąd `str_starts_with`, a nie porównanie do
        // gołego `'true'` — porównanie równościowe przepuszczałoby wariant
        // z rzutowaniem, czyli dokładnie ten, który zapisuje ludzi bez zgody.
        return ! str_starts_with(strtolower(trim((string) $wiersz->column_default)), 'true');
    }

    /**
     * Co człowiek ma z tym zrobić — jedno zdanie do komendy i do dziennika.
     *
     * Komunikat mówi CO ZROBIĆ, nie „stan niezgodny" (AGENTS.md §5): osoba,
     * która to zobaczy o 8:30 rano w logu Railwaya, ma mieć gotowe polecenie,
     * a nie zagadkę.
     */
    public static function powod(): string
    {
        return 'Kolumna `users.wants_weekly_digest` ma domyślną wartość `true`, więc nowe konta '
            .'wstają zapisane na wysyłkę, o którą nikt ich nie zapytał — nie wysyłam nic. '
            .'Uruchom `php artisan migrate` (migracja 2026_09_07_400000_default_weekly_digest_to_off) '
            .'albo, jeśli migracje są już wykonane, wykonaj na bazie '
            .'`ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT false`, '
            .'a potem powtórz wysyłkę. Powód i decyzja: docs/DECISIONS.md D-072.';
    }
}
