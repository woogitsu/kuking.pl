<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Rocznice\Urodziny;
use App\Domain\Security\DziennyBudzetListow;
use App\Mail\ZyczeniaUrodzinowe;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Listy z życzeniami urodzinowymi (issue #1755, etap c).
 *
 * TRZY BRAMKI, KAŻDA OSOBNO
 *   1. Zgoda: `wants_birthday_email = true` (osobna, jawna, z dowodem w
 *      dzienniku zgód) ORAZ włączone życzenia (`birthday_wishes_enabled`) —
 *      wyłącznik żałoby wyłącza też list.
 *   2. Sufity poczty: własny (`kuking.urodziny.mail_dzienny_sufit`) i wspólna
 *      pula w klasie, która gaśnie pierwsza. Brak miejsca = list nie wychodzi
 *      dziś, a komenda mówi, ile osób zostało — nic nie znika po cichu.
 *   3. Bariera przed dublem w BAZIE: warunkowy `UPDATE` zajmuje dzisiejszy
 *      dzień (`birthday_email_sent_on`) PRZED `Mail::queue()`. Ponowiony
 *      przebieg tego samego dnia nie wyśle drugiego listu.
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

        foreach ($kandydaci as $osoba) {
            if ($naSucho) {
                $wyslano++;

                continue;
            }

            if (! $budzet->sprobujZarezerwowac()) {
                $bezMiejsca++;

                continue;
            }

            if (! $this->zajmijDzien($osoba, $dzis)) {
                $budzet->zwolnij();
                $juzObsluzeni++;

                continue;
            }

            Mail::to((string) $osoba->email)->queue(new ZyczeniaUrodzinowe($osoba));
            $wyslano++;
        }

        $this->info(($naSucho ? 'Do wysłania' : 'Wysłano').": {$wyslano}.");

        if ($juzObsluzeni > 0) {
            $this->info("Pominięto jako już obsłużone dziś: {$juzObsluzeni}.");
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
            ->where(function (Builder $q) use ($dzis): void {
                $q->whereNull('birthday_email_sent_on')->orWhere('birthday_email_sent_on', '<>', $dzis);
            })
            ->orderBy('id');
    }

    /**
     * Warunkowy UPDATE — jedyna rzecz, która naprawdę chroni przed dublem.
     * Zwraca `true` tylko temu przebiegowi, który zajął dzień pierwszy.
     */
    private function zajmijDzien(User $osoba, string $dzis): bool
    {
        return DB::table('users')
            ->where('id', $osoba->getKey())
            ->where(function ($q) use ($dzis): void {
                $q->whereNull('birthday_email_sent_on')->orWhere('birthday_email_sent_on', '<>', $dzis);
            })
            ->update(['birthday_email_sent_on' => $dzis]) === 1;
    }
}
