<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\DziennikWymazan;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * „Wymaż ponownie” — obowiązkowy krok po odtworzeniu bazy z kopii
 * (audyt B5, znalezisko 3; `docs/infra/KOPIE_I_ODTWORZENIE.md` §3).
 *
 * Czyta dziennik wymazań spoza bazy (`App\Domain\Compliance\DziennikWymazan`)
 * i każde konto, które w odtworzonej bazie NIE jest wymazane, wymazuje jeszcze
 * raz tym samym zakresem, jaki wykonaliśmy za pierwszym razem. Konto już
 * wymazane pomija. Komenda jest idempotentna — drugi przebieg nic nie zmienia.
 *
 * `--od` to data kopii: wymazania sprzed niej są w kopii i tak. Bez `--od`
 * bierzemy cały dziennik — wolniej, ale nie da się pomylić daty.
 */
class WymazPonownie extends Command
{
    protected $signature = 'kuking:wymaz-ponownie
                            {--od= : Chwila, z której pochodzi odtworzona kopia (np. 2026-10-05 03:00); domyślnie cały dziennik}
                            {--na-sucho : Pokaż, co zostałoby wymazane, ale niczego nie zmieniaj}';

    protected $description = 'Po odtworzeniu bazy z kopii wymazuje ponownie konta z dziennika wymazań spoza bazy (audyt B5).';

    public function handle(DziennikWymazan $dziennik, EraseAccountData $wymaz): int
    {
        $od = $this->option('od') !== null ? CarbonImmutable::parse((string) $this->option('od')) : null;
        $naSucho = (bool) $this->option('na-sucho');

        $wymazane = 0;
        $juzBylo = 0;
        $brakKonta = 0;
        $bledy = 0;

        foreach ($dziennik->wpisyOd($od) as $wpis) {
            $konto = User::query()->find($wpis['user_id']);

            if ($konto === null) {
                // Konta nie ma w kopii — powstało po niej, więc nie ma czego wymazywać.
                $brakKonta++;

                continue;
            }

            if ($konto->data_erased_at !== null) {
                $juzBylo++;

                continue;
            }

            if ($naSucho) {
                $this->line("Do wymazania: {$konto->getKey()} (zakres {$wpis['zakres']}).");
                $wymazane++;

                continue;
            }

            try {
                if ($konto->status !== User::STATUS_PENDING_DELETE) {
                    $konto->markForDeletion($wpis['zakres']);
                } elseif ($konto->delete_scope !== $wpis['zakres']) {
                    // Zakres wykonany to fakt z dziennika, nie wybór do zgadywania.
                    $konto->forceFill(['delete_scope' => $wpis['zakres']])->save();
                }

                $wymaz->handle($konto->fresh());
                $wymazane++;
            } catch (Throwable $e) {
                $bledy++;
                $this->error("Nie udało się wymazać konta {$konto->getKey()} ({$e->getMessage()}). Uruchom komendę jeszcze raz; jeśli błąd wraca, wymaż to konto ręcznie.");
            }
        }

        $this->info(($naSucho ? 'Do wymazania' : 'Wymazano ponownie').": {$wymazane}. Już wymazane w kopii: {$juzBylo}. Brak konta w kopii: {$brakKonta}. Błędy: {$bledy}.");

        return $bledy > 0 ? self::FAILURE : self::SUCCESS;
    }
}
