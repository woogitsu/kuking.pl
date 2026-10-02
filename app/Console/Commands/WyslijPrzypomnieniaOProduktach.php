<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\PrzypomnienieDobowe;
use App\Domain\Pantry\PriorytetZuzycia;
use App\Logging\BezpiecznyBlad;
use App\Mail\PrzypomnienieOProduktach;
use App\Models\User;
use App\Poczta\DziennyBudzetListow;
use App\Support\Czas;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sobotnie przypomnienie o produktach do zużycia (#1903, D-333).
 *
 * CZTERY BRAMKI, KAŻDA OSOBNO
 *   1. Zgoda: `wants_pantry_reminder = true` — osobna, jawna, domyślnie
 *      wyłączona, z dowodem w dzienniku zgód. Założenie listy ani ustawienie
 *      terminu jej nie daje.
 *   2. Jest co wymienić: konto ma co najmniej jeden PILNY produkt (termin do
 *      dziś + N dni, nie mrożony, `PriorytetZuzycia`). Pusty list nie wychodzi.
 *   3. Sufity poczty: własny (`kuking.pantry.przypomnienie.dzienny_sufit`)
 *      i wspólna pula w klasie, która gaśnie pierwsza. Brak miejsca = list nie
 *      wychodzi dziś, a komenda mówi, ile osób zostało — nic nie znika po cichu.
 *   4. Deduplikacja w BAZIE: `PrzypomnienieDobowe::zarezerwuj()` zajmuje
 *      „jeden list na dobę na adres” PRZED `Mail::queue()`. Doba to DZIEŃ
 *      W POLSCE (`Czas::dzisiajData()`), nie data UTC: polska sobota obejmuje
 *      dwie daty UTC, więc klucz z UTC dopuszczał dwa listy (#2364). Ponowiony
 *      przebieg tego samego dnia nie wyśle drugiego listu.
 *
 * JEDEN LIST TYGODNIOWO: komenda wychodzi tylko w sobotę (w strefie
 * Europe/Warsaw); harmonogram w `routes/console.php` odpala ją raz w tygodniu.
 * Bez push (D-303 bez zmian): przypomnienie nie jest odzewem na własną treść.
 *
 * Pora jest stała, rano, po ciszy nocnej (21–8).
 */
class WyslijPrzypomnieniaOProduktach extends Command
{
    public const RODZAJ = 'przypomnienie-spizarni';

    protected $signature = 'kuking:wyslij-przypomnienia-spizarni
                            {--na-sucho : Policz i pokaż, ale nie wysyłaj i nie zapisuj niczego}';

    protected $description = 'Wysyła sobotnie przypomnienia o produktach do zużycia osobom, które dały na nie osobną zgodę (#1903).';

    public function handle(PrzypomnienieDobowe $dedup): int
    {
        $naSucho = (bool) $this->option('na-sucho');

        if (! config('kuking.pantry.przypomnienie.wlaczone') && ! $naSucho) {
            $this->info('Sobotnie przypomnienia są wyłączone (KUKING_SPIZARNIA_PRZYPOMNIENIE_WLACZONE). Nic nie wysyłam.');

            return self::SUCCESS;
        }

        if (! Czas::lokalnie(now())->isSaturday()) {
            $this->info('Przypomnienia wychodzą tylko w sobotę (czas polski). Dziś nic nie wysyłam.');

            return self::SUCCESS;
        }

        $dzis = PriorytetZuzycia::dzis();
        $kandydaci = $this->kandydaci(PriorytetZuzycia::granicaPilnych($dzis), $dzis)->get();

        if ($kandydaci->isEmpty()) {
            $this->info('Nikt nie czeka dziś na przypomnienie o produktach.');

            return self::SUCCESS;
        }

        // Doba deduplikacji to DZIEŃ W POLSCE (nie UTC): reguła brzmi „jeden list
        // na polską sobotę”, a ta obejmuje dwie daty UTC (#2364).
        $doba = Czas::dzisiajData();
        $budzet = DziennyBudzetListow::dlaPrzypomnienSpizarni();
        $wyslano = 0;
        $bezMiejsca = 0;
        $juzObsluzeni = 0;
        $bledyKolejkowania = 0;

        // ŚWIADOMY KOMPROMIS KOLEJNOŚCI: budżet → dedup → kolejka. Rezerwacje
        // robimy PRZED `Mail::queue()`, żeby równoległe przebiegi i ponowienie
        // nigdy nie dały drugiego listu. Cena: proces ubity dokładnie między
        // rezerwacją a wstawieniem do `jobs` zostawia znacznik bez listu, więc
        // ta osoba nie dostanie go w tę sobotę (za tydzień list znów wyjdzie).
        // Wolimy brak jednego listu niż dwa listy do tej samej osoby. Zwykłe
        // wyjątki kolejkowania łapiemy niżej i rezerwacje wracają.
        foreach ($kandydaci as $osoba) {
            if ($naSucho) {
                $wyslano++;

                continue;
            }

            if (! $budzet->sprobujZarezerwowac()) {
                $bezMiejsca++;

                continue;
            }

            if (! $dedup->zarezerwuj(self::RODZAJ, (string) $osoba->email, $doba)) {
                $budzet->zwolnij();
                $juzObsluzeni++;

                continue;
            }

            try {
                // W TRANSAKCJI (SAVEPOINT) — inaczej odrzucony `INSERT` do `jobs`
                // zostawia połączenie w stanie „current transaction is aborted”
                // i żadne kolejne zapytanie (także zwolnienie rezerwacji niżej)
                // by się nie wykonało.
                DB::transaction(fn () => Mail::to((string) $osoba->email)->queue(new PrzypomnienieOProduktach($osoba)));
            } catch (Throwable $e) {
                // ZAKOLEJKOWANIE PADŁO — rezerwacja dnia i miejsce w budżecie
                // wracają: żaden list nie trafił do `jobs`, więc kolejny
                // przebieg tego samego dnia ma prawo spróbować.
                $dedup->zwolnij(self::RODZAJ, (string) $osoba->email, $doba);
                $budzet->zwolnij();
                $bledyKolejkowania++;

                Log::error('Nie udało się zakolejkować sobotniego przypomnienia o produktach.', [
                    'user_id' => (string) $osoba->getKey(),
                    'error' => BezpiecznyBlad::kontekst($e),
                ]);

                continue;
            }

            $wyslano++;
        }

        $this->info(($naSucho ? 'Do wysłania' : 'Wysłano').": {$wyslano}.");

        if ($juzObsluzeni > 0) {
            $this->info("Pominięto jako już obsłużone dziś: {$juzObsluzeni}.");
        }

        if ($bledyKolejkowania > 0) {
            $this->warn("Nie udało się zakolejkować: {$bledyKolejkowania}. Kolejny przebieg spróbuje ponownie.");
        }

        if ($bezMiejsca > 0) {
            $this->warn("Dzienny sufit poczty wyczerpany — bez listu zostało dziś: {$bezMiejsca}.");
            Log::warning('Sobotnie przypomnienia o produktach: część osób bez listu z powodu sufitu poczty.', [
                'wyslano' => $wyslano,
                'bez_miejsca' => $bezMiejsca,
            ]);
        }

        return self::SUCCESS;
    }

    /** @return Builder<User> */
    private function kandydaci(string $granica, string $dzis): Builder
    {
        return User::query()
            ->where('wants_pantry_reminder', true)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('email_verified_at')
            ->where(function (Builder $q): void {
                $q->where('is_seeded', false)->orWhereNull('is_seeded');
            })
            ->whereExists(function ($q) use ($granica, $dzis): void {
                $q->selectRaw('1')
                    // Opakowania (#2568): pilne drugie opakowanie wystarcza, a mrożone
                    // jedno nie chowa drugiego — warunek liczy się na każdym osobno.
                    ->fromRaw(PriorytetZuzycia::OPAKOWANIA_SQL.' as p')
                    ->whereColumn('p.user_id', 'users.id')
                    ->whereNotNull('p.expires_on')
                    ->where('p.expires_on', '<=', $granica)
                    ->where('p.frozen', false)
                    ->whereRaw(PriorytetZuzycia::DOSTEPNY_SQL, [$dzis]);
            })
            // SPRAWIEDLIWA KOLEJNOŚĆ: przy sufcie mniejszym niż liczba zgód
            // `orderBy('id')` głodziłby co tydzień tych samych ostatnich. Najpierw
            // ci, którym list NIE wyszedł najdłużej (brak znacznika = NULL =
            // pierwsi), wg znacznika w `przypomnienia_dobowe` (klucz: skrót
            // adresu, tak jak liczy go `PrzypomnienieDobowe`; retencja 30 dni
            // starcza na cykl tygodniowy). Remis rozstrzyga `id` — deterministycznie.
            ->orderByRaw(
                "(select max(d.doba) from przypomnienia_dobowe d where d.rodzaj = ? and d.odbiorca = encode(sha256(convert_to(lower(btrim(users.email)), 'UTF8')), 'hex')) asc nulls first",
                [self::RODZAJ],
            )
            ->orderBy('id');
    }
}
