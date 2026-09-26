<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\User;
use Carbon\CarbonInterface;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Zapisuje `users.ostatnio_widziany_at` — throttlowane, bo „zapis nie może
 * kosztować" (issue #114/#115, bramka V1 z `docs/ROADMAP.md`). Wołane z
 * `App\Http\Middleware\AktualizujOstatniaWizyte` przy KAŻDYM uwierzytelnionym
 * żądaniu, ale samo decyduje, czy naprawdę coś zapisać.
 *
 * DLACZEGO TU, A NIE W MIDDLEWARE
 * Ten sam podział co `ZapiszSygnal`/`NormalizeForwardedFor`: reguła („co
 * wolno zapisać, jak często") mieszka w jednym miejscu w `App\Domain`,
 * middleware tylko ją wywołuje. Gdyby próg throttla albo sposób zapisu
 * trzeba było kiedyś wywołać spoza HTTP (np. z komendy importującej aktywność
 * z innego źródła), logika już jest w klasie, którą da się wywołać wprost.
 *
 * DLACZEGO `DB::table()->update()`, NIE `$user->save()`
 * Trzy niezależne powody, każdy z osobna wystarczający:
 *
 * 1. **`ostatnio_widziany_at` celowo NIE jest w `$fillable`**
 *    (`App\Models\User::casts()`, patrz komentarz przy tym wpisie) — to nie
 *    jest pole do ustawienia masowym przypisaniem z żądania, tylko fakt
 *    ustalany WYŁĄCZNIE przez ten jeden zapis.
 * 2. `$user->save()` dotknąłby też `updated_at`, wysyłając sygnał „konto
 *    zostało zmienione" przy zwykłym czytaniu strony — to zepsułoby każde
 *    inne miejsce, które kiedyś zacznie czytać `updated_at` jako
 *    „kiedy dane konta naprawdę się zmieniły" (np. unieważnianie cache'u).
 * 3. Zapytanie `UPDATE ... WHERE id = ?` po kluczu głównym nie wymaga
 *    hydratacji modelu ani przejścia przez casty — jest tańsze niż zapis
 *    przez Eloquenta na drodze, którą przechodzi KAŻDE uwierzytelnione
 *    żądanie w serwisie.
 *
 * DLACZEGO `DB::transaction()`, TAK SAMO JAK `ZapiszSygnal`
 * Ten middleware jest GLOBALNY (`bootstrap/app.php`), więc każdy test
 * Feature — w tym testy, które same otwierają transakcję przez
 * `RefreshDatabase` — przechodzi przez ten zapis. Goły `try/catch` wokół
 * `UPDATE` nie chroni PostgreSQL: nieudane zapytanie w trakcie szerszej
 * transakcji zatruwa CAŁĄ transakcję („current transaction is aborted"),
 * łącznie z operacją, którą to żądanie miało wykonać. `DB::transaction()`
 * otwiera wtedy SAVEPOINT i cofa TYLKO jego — dokładnie ten sam mechanizm,
 * zmierzony i przypięty testem przy `ZapiszSygnal`
 * (`SygnalyProduktoweTest::test_awaria_zapisu_sygnalu_nie_wywraca_wgrywania_zdjecia`).
 *
 * DLACZEGO WYJĄTEK NIGDY NIE LECI DALEJ
 * Znacznik aktywności jest efektem ubocznym prawdziwego żądania, nie jego
 * warunkiem — strona ma się wyświetlić, nawet gdy nie da się zapisać, kiedy
 * ktoś ją ostatnio widział.
 *
 * PO ODPOWIEDZI, NIE PRZED NIĄ (issue #1044)
 * Middleware woła `zaplanuj()`, które odkłada `UPDATE` przez `defer()` na
 * czas po wysłaniu odpowiedzi. Zmierzone na PostgreSQL (lokalnie, prosta
 * trasa `web`, 350 żądań): zapis po progu dokładał ~2,8 ms mediany do czasu
 * zbudowania odpowiedzi (5,0 ms zamiast 1,9 ms) — commit transakcji czeka
 * na dysk. Trzy rzeczy są tu celowe:
 *
 * 1. `->always()` — zapis idzie także po 4xx/5xx, tak jak wtedy, gdy stał
 *    przed kontrolerem. Uwierzytelniona osoba, która trafiła na 404, była
 *    w serwisie.
 * 2. Odroczona funkcja dostaje tylko identyfikator i chwilę żądania — nie
 *    model ani `Request`. Po odpowiedzi model mógł się zmienić (wylogowanie,
 *    usunięcie konta w tym samym żądaniu).
 * 3. Próg i status sprawdza sam `UPDATE`. Dwa równoległe żądania po progu
 *    widzą ten sam stary model; zapisze tylko pierwsze. Konto zamknięte
 *    w trakcie żądania (np. prośba o usunięcie) nie zasili już metryki.
 */
final class ZanotujOstatniaWizyte
{
    /** Nazwa odroczonego zapisu — drugie wywołanie w tym samym żądaniu zastępuje pierwsze. */
    private const ODROCZONY = 'kuking.ostatnia-wizyta';

    /** Zapis teraz — dla wywołań spoza HTTP i dla testów domeny. */
    public function handle(User $user): void
    {
        if ($this->naleznyZapis($user)) {
            $this->zapisz((string) $user->getKey(), now());
        }
    }

    /** Zapis po wysłaniu odpowiedzi — dla middleware'u (patrz komentarz klasy). */
    public function zaplanuj(User $user): void
    {
        if (! $this->naleznyZapis($user)) {
            return;
        }

        $id = (string) $user->getKey();
        $chwila = now();

        defer(fn () => $this->zapisz($id, $chwila), self::ODROCZONY)->always();
    }

    private function zapisz(string $id, CarbonInterface $chwila): void
    {
        try {
            DB::transaction(function () use ($id, $chwila): void {
                DB::table('users')
                    ->where('id', $id)
                    ->whereNotIn('status', User::STATUSY_ZAMKNIETEGO_KONTA)
                    ->where(fn ($q) => $q->whereNull('ostatnio_widziany_at')
                        ->orWhere('ostatnio_widziany_at', '<=', $chwila->copy()->subMinutes($this->progMinut())))
                    ->update(['ostatnio_widziany_at' => $chwila]);
            });
        } catch (Throwable $e) {
            // Patrz komentarz klasy — ten log ma odpowiedzieć wyłącznie na
            // pytanie „czy zapisy zaczęły padać", nie zawierać treści
            // wyjątku: `getMessage()` na `QueryException` bywa doklejonym
            // przez Laravela `SQL: update ... where id = '<uuid>'`, czyli
            // identyfikatorem konta w logu (AGENTS.md §7).
            Log::warning('Nie udało się zapisać ostatniej wizyty użytkownika.', [
                'wyjatek' => $e::class,
            ]);
        }
    }

    /**
     * `NULL` (nigdy nie widziany od czasu wdrożenia kolumny) zawsze
     * kwalifikuje się do zapisu — throttl liczy odstęp od POPRZEDNIEGO
     * zapisu, a bez niego nie ma od czego liczyć.
     */
    private function naleznyZapis(User $user): bool
    {
        $ostatnio = $user->ostatnio_widziany_at;

        if ($ostatnio === null) {
            return true;
        }

        return $ostatnio->diffInMinutes(now()) >= $this->progMinut();
    }

    private function progMinut(): int
    {
        return max(1, (int) config('kuking.analytics.last_seen_throttle_minutes'));
    }
}
