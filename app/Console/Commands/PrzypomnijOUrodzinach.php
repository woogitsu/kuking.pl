<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\Actions\NotifyUser;
use App\Domain\Notifications\TerminPowiadomieniaZewnetrznego;
use App\Domain\Rocznice\Urodziny;
use App\Models\Notification;
use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
    /** Przestrzeń blokad doradczych (numer issue #1755). */
    private const PRZESTRZEN_BLOKAD = 1755;

    private const UTWORZONO = 'utworzono';

    private const PONAD_LIMIT = 'ponad-limit';

    private const JUZ_BYLO = 'juz-bylo';

    private const POMINIETO = 'pominieto';

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
                        $wynik = $this->przypomnijJednemu($powiadom, $odbiorca, $solenizant, $poczatekDoby, $limit);
                        $utworzono += $wynik === self::UTWORZONO ? 1 : 0;
                        $ponadLimit += $wynik === self::PONAD_LIMIT ? 1 : 0;
                    }
                });
        }

        $this->info("Utworzono przypomnień: {$utworzono}. Pominięto przez dobowy limit: {$ponadLimit}.");

        return self::SUCCESS;
    }

    /**
     * Sprawdzenie „już dziś było" i limitu doby oraz zapis — w JEDNEJ sekcji
     * krytycznej na odbiorcę (#2318). Bez niej dwa równoległe uruchomienia
     * (ręczne obok harmonogramu, dwie instancje po utracie blokady
     * `onOneServer`) oba czytały „brak" i oba tworzyły powiadomienie.
     * Blokada doradcza na ODBIORCĘ, nie na parę: limit doby liczy wszystkie
     * przypomnienia odbiorcy, więc para nie wystarczy.
     */
    private function przypomnijJednemu(NotifyUser $powiadom, User $odbiorca, User $solenizant, CarbonInterface $poczatekDoby, int $limit): string
    {
        return DB::transaction(static function () use ($powiadom, $odbiorca, $solenizant, $poczatekDoby, $limit): string {
            DB::selectOne(
                'SELECT pg_advisory_xact_lock('.self::PRZESTRZEN_BLOKAD.', hashtext(?))',
                [(string) $odbiorca->getKey()],
            );

            // Wstępny wybór solenizantów odbywa się poza transakcją. Decyzja
            // o widoczności albo data mogła się od tej chwili zmienić. SHARE
            // serializuje zapis powiadomienia z UPDATE konta, także gdy
            // człowiek wyłącza przypomnienia w trakcie przebiegu komendy.
            $aktualnySolenizant = User::query()
                ->whereKey($solenizant->getKey())
                ->sharedLock()
                ->first();

            if ($aktualnySolenizant === null
                || $aktualnySolenizant->status !== User::STATUS_ACTIVE
                || ! $aktualnySolenizant->birthday_visible_to_followers
                || $aktualnySolenizant->is_seeded
                || ! Urodziny::czyDzis($aktualnySolenizant)) {
                return self::POMINIETO;
            }

            $dzisiejsze = Notification::query()
                ->where('user_id', $odbiorca->getKey())
                ->where('type', Notification::TYPE_BIRTHDAY)
                ->where('created_at', '>=', $poczatekDoby);

            if ((clone $dzisiejsze)->where('actor_id', $solenizant->getKey())->exists()) {
                return self::JUZ_BYLO;
            }

            if ($dzisiejsze->count() >= $limit) {
                return self::PONAD_LIMIT;
            }

            return $powiadom->handle($odbiorca, Notification::TYPE_BIRTHDAY, $aktualnySolenizant) !== null
                ? self::UTWORZONO
                : self::POMINIETO;
        });
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
