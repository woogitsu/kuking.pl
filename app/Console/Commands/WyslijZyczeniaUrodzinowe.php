<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Rocznice\Urodziny;
use App\Domain\Security\DziennyBudzetListow;
use App\Logging\BezpiecznyBlad;
use App\Mail\ZyczeniaUrodzinowe;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Listy z życzeniami urodzinowymi (issue #1755, etap c; #1956).
 *
 * TRZY BRAMKI, KAŻDA OSOBNO
 *   1. Zgoda: `wants_birthday_email = true` (osobna, jawna, z dowodem w
 *      dzienniku zgód) ORAZ włączone życzenia (`birthday_wishes_enabled`) —
 *      wyłącznik żałoby wyłącza też list.
 *   2. Sufity poczty: własny (`kuking.urodziny.mail_dzienny_sufit`) i wspólna
 *      pula w klasie, która gaśnie pierwsza. Brak miejsca = list nie wychodzi
 *      dziś, a komenda mówi, ile osób zostało — nic nie znika po cichu.
 *   3. Bariera przed dublem w BAZIE: warunkowy `UPDATE` zajmuje dzisiejszy
 *      dzień (`birthday_email_queued_on`) PRZED `Mail::queue()`. Ponowiony
 *      przebieg tego samego dnia nie wyśle drugiego listu.
 *
 * `birthday_email_sent_on` I `birthday_email_queued_on` TO NIE JEST TO SAMO
 * (issue #1956). Do 26 września 2026 był tu tylko ten pierwszy znacznik
 * i zajmował dzień PRZED `Mail::queue()` — czyli kolumna, której nazwa
 * obiecuje „list wyszedł", stawała się prawdziwa w chwili ZAKOLEJKOWANIA.
 * Awaria enqueue albo trwała porażka workera (dostawca padnięty przez trzy
 * próby) zostawiały znacznik ustawiony, mimo że żaden list nie doszedł —
 * a to jest jedyny list w roku dla tej osoby, więc pominięcie było TRWAŁE.
 *
 * Dziś rolę bariery przed dublem przejmuje `birthday_email_queued_on`.
 * `birthday_email_sent_on` ustawia WYŁĄCZNIE `ZyczeniaUrodzinowe::send()`,
 * i to dopiero PO tym, jak transport pocztowy PRZYJĄŁ wiadomość (ten sam
 * standard co PR #1861 dla `DecyzjaWSprawieZgloszenia`). Awaria samego
 * `Mail::queue()` (lokalny `INSERT` do `jobs`, rzadka) zwalnia rezerwację
 * TU, W TYM PRZEBIEGU, więc ponowienie tego samego dnia wysyła dokładnie
 * jeden list — i zwalnia też miejsce w dobowym budżecie, bo lista, która nie
 * trafiła do kolejki, nie zjadła nic u dostawcy.
 *
 * Pora jest stała (harmonogram w `routes/console.php`), rano, po ciszy nocnej.
 */
class WyslijZyczeniaUrodzinowe extends Command
{
    protected $signature = 'kuking:wyslij-zyczenia-urodzinowe
                            {--na-sucho : Policz i pokaż, ale nie wysyłaj i nie zapisuj niczego}';

    protected $description = 'Wysyła listy z życzeniami urodzinowymi do osób, które dały na nie osobną zgodę (issue #1755).';

    public function handle(): int
    {
        $naSucho = (bool) $this->option('na-sucho');

        if (! config('kuking.urodziny.mail_wlaczony') && ! $naSucho) {
            $this->info('Listy urodzinowe są wyłączone (KUKING_URODZINY_MAIL_WLACZONY). Nic nie wysyłam.');

            return self::SUCCESS;
        }

        $dzis = Czas::dzisiajData();
        $kandydaci = $this->kandydaci($dzis)->get();

        if ($kandydaci->isEmpty()) {
            $this->info('Nikt dziś nie czeka na list z życzeniami.');

            return self::SUCCESS;
        }

        $budzet = DziennyBudzetListow::dlaZyczenUrodzinowych();
        $wyslano = 0;
        $bezMiejsca = 0;
        $juzObsluzeni = 0;
        $bledyKolejkowania = 0;

        foreach ($kandydaci as $osoba) {
            if ($naSucho) {
                $wyslano++;

                continue;
            }

            if (! $budzet->sprobujZarezerwowac()) {
                $bezMiejsca++;

                continue;
            }

            if (! $this->zarezerwujDzien($osoba, $dzis)) {
                $budzet->zwolnij();
                $juzObsluzeni++;

                continue;
            }

            try {
                // W TRANSAKCJI (SAVEPOINT wewnątrz transakcji testu) — inaczej
                // odrzucony `INSERT` do `jobs` zostawia połączenie
                // w PostgreSQL w stanie „current transaction is aborted"
                // i żadne kolejne zapytanie (także zwolnienie rezerwacji
                // niżej) by się nie wykonało.
                DB::transaction(fn () => Mail::to((string) $osoba->email)->queue(new ZyczeniaUrodzinowe($osoba)));
            } catch (Throwable $e) {
                // ZAKOLEJKOWANIE PADŁO — REZERWACJA I MIEJSCE W BUDŻECIE
                // WRACAJĄ (issue #1956). Żaden list nie trafił do `jobs`,
                // więc dostawca niczego nie zjadł, a dzień nie ma prawa
                // zostać „zużyty" na wieczność jedynego listu tej osoby
                // w roku. Ponowienie komendy tego samego dnia ma dostać
                // tę osobę z powrotem na listę kandydatów.
                $this->zwolnijRezerwacje($osoba, $dzis);
                $budzet->zwolnij();
                $bledyKolejkowania++;

                Log::error('Nie udało się zakolejkować listu z życzeniami urodzinowymi.', [
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
            Log::warning('Listy urodzinowe: część osób bez listu z powodu sufitu poczty.', [
                'wyslano' => $wyslano,
                'bez_miejsca' => $bezMiejsca,
            ]);
        }

        return self::SUCCESS;
    }

    /** @return Builder<User> */
    private function kandydaci(string $dzis): Builder
    {
        $pary = Urodziny::dzisiejszePary();

        return User::query()
            ->where('wants_birthday_email', true)
            ->where('birthday_wishes_enabled', true)
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotNull('email_verified_at')
            ->where(function (Builder $q): void {
                $q->where('is_seeded', false)->orWhereNull('is_seeded');
            })
            ->where(function (Builder $q) use ($pary): void {
                foreach ($pary as [$dzien, $miesiac]) {
                    $q->orWhere(fn (Builder $para) => $para
                        ->where('birthday_day', $dzien)
                        ->where('birthday_month', $miesiac));
                }
            })
            // Wyklucza tego, kto ma dziś już ZAJĘTY dzień — niezależnie od
            // tego, czy list ostatecznie wyszedł, czy dopiero czeka w kolejce
            // (issue #1956). To jest bariera przed dublem; o tym, czy list
            // NAPRAWDĘ dotarł do transportu, mówi `birthday_email_sent_on`.
            ->where(function (Builder $q) use ($dzis): void {
                $q->whereNull('birthday_email_queued_on')->orWhere('birthday_email_queued_on', '<>', $dzis);
            })
            ->orderBy('id');
    }

    /**
     * Warunkowy UPDATE — jedyna rzecz, która naprawdę chroni przed dublem
     * zakolejkowania tego samego dnia. Zwraca `true` tylko temu przebiegowi,
     * który zajął dzień pierwszy.
     */
    private function zarezerwujDzien(User $osoba, string $dzis): bool
    {
        return DB::table('users')
            ->where('id', $osoba->getKey())
            ->where(function ($q) use ($dzis): void {
                $q->whereNull('birthday_email_queued_on')->orWhere('birthday_email_queued_on', '<>', $dzis);
            })
            ->update(['birthday_email_queued_on' => $dzis]) === 1;
    }

    /**
     * Zwrot rezerwacji po nieudanym zakolejkowaniu — WARUNKOWY, żeby nie
     * zdjąć rezerwacji zrobionej w międzyczasie przez INNY dzień (rzadkie,
     * ale przebieg komendy może przejść przez północ).
     */
    private function zwolnijRezerwacje(User $osoba, string $dzis): void
    {
        DB::table('users')
            ->where('id', $osoba->getKey())
            ->where('birthday_email_queued_on', $dzis)
            ->update(['birthday_email_queued_on' => null]);
    }
}
