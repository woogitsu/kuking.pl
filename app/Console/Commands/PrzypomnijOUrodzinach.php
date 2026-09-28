<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Notifications\TerminPowiadomieniaZewnetrznego;
use App\Domain\Rocznice\Urodziny;
use App\Models\Notification;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * „Dziś urodziny: Ania" — przypomnienie dla obserwujących (issue #1755, etap d).
 *
 * GRANICE, WSZYSTKIE Z DECYZJI WŁAŚCICIELA I Z ZASAD PROJEKTU
 *   - tylko solenizant, który SAM włączył `birthday_visible_to_followers`
 *     (domyślnie wyłączone);
 *   - powiadomienie w serwisie, NIE wpis w feedzie — feed obserwowanych
 *     zostaje chronologiczny, bez wstawek (AGENTS.md §8);
 *   - limit na odbiorcę na dobę (`kuking.urodziny.przypomnienia_na_odbiorce_dziennie`)
 *     i najwyżej jedno przypomnienie na parę (odbiorca, solenizant) na dobę;
 *   - cisza nocna: w godzinach `kuking.notifications.zewnetrzne.cisza_*`
 *     (21–8 w strefie człowieka) komenda nie tworzy niczego — harmonogram
 *     stawia ją rano, a ta bramka pilnuje ręcznego uruchomienia o złej porze;
 *   - blokady, zamknięte konta i powiadomienie o własnej akcji odcina
 *     `NotifyUser`, jak przy każdym innym powiadomieniu.
 */
class PrzypomnijOUrodzinach extends Command
{
    protected $signature = 'kuking:przypomnij-o-urodzinach';

    protected $description = 'Tworzy powiadomienia „Dziś urodziny" dla obserwujących osób, które to włączyły (issue #1755).';

    public function handle(NotifyUser $powiadom): int
    {
        $teraz = Carbon::now();

        if ($this->wCiszyNocnej($teraz)) {
            $this->info('Cisza nocna — przypomnienia o urodzinach powstaną rano.');

            return self::SUCCESS;
        }

        $limit = max(0, (int) config('kuking.urodziny.przypomnienia_na_odbiorce_dziennie', 3));
        $poczatekDoby = TerminPowiadomieniaZewnetrznego::poczatekDoby($teraz);
        $utworzono = 0;
        $ponadLimit = 0;

        foreach ($this->solenizanci()->cursor() as $solenizant) {
            $solenizant->followers()
                ->where('users.status', User::STATUS_ACTIVE)
                ->orderBy('users.id')
                ->chunk(200, function ($obserwujacy) use ($solenizant, $powiadom, $poczatekDoby, $limit, &$utworzono, &$ponadLimit): void {
                    foreach ($obserwujacy as $odbiorca) {
                        $dzisiejsze = Notification::query()
                            ->where('user_id', $odbiorca->getKey())
                            ->where('type', Notification::TYPE_BIRTHDAY)
                            ->where('created_at', '>=', $poczatekDoby);

                        if ((clone $dzisiejsze)->where('actor_id', $solenizant->getKey())->exists()) {
                            continue;
                        }

                        if ($dzisiejsze->count() >= $limit) {
                            $ponadLimit++;

                            continue;
                        }

                        if ($powiadom->handle($odbiorca, Notification::TYPE_BIRTHDAY, $solenizant) !== null) {
                            $utworzono++;
                        }
                    }
                });
        }

        $this->info("Utworzono przypomnień: {$utworzono}. Pominięto przez dobowy limit: {$ponadLimit}.");

        return self::SUCCESS;
    }

    /** @return Builder<User> */
    private function solenizanci(): Builder
    {
        $pary = Urodziny::dzisiejszePary();

        return User::query()
            ->where('birthday_visible_to_followers', true)
            ->where('status', User::STATUS_ACTIVE)
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
            ->orderBy('id');
    }

    private function wCiszyNocnej(Carbon $teraz): bool
    {
        $od = (int) config('kuking.notifications.zewnetrzne.cisza_od_godziny', 21);
        $do = (int) config('kuking.notifications.zewnetrzne.cisza_do_godziny', 8);
        $godzina = Czas::lokalnie($teraz)->hour;

        if ($od === $do) {
            return false;
        }

        return $od > $do
            ? ($godzina >= $od || $godzina < $do)
            : ($godzina >= $od && $godzina < $do);
    }
}
