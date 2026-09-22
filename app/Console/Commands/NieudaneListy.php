<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MailFailure;
use App\Poczta\PowodOdmowy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * `kuking:nieudane-listy` — co przepadło, dlaczego i co z tym zrobić
 * (issue #234, D-062).
 *
 * PO CO TO ISTNIEJE, SKORO JEST `queue:failed`
 * Bo `queue:failed` odpowiada na inne pytanie. Pokazuje WSZYSTKIE nieudane
 * zadania — przetwarzanie zdjęć, eksporty, analizy treści — i o każdym mówi
 * to samo: klasa, kolejka, godzina. Nie mówi, czy to był list, do kogo,
 * ani czy warto powtarzać. A różnica między „wyczerpał się dobowy limit"
 * i „adres jest zły" to dla człowieka dwie zupełnie różne czynności.
 *
 * TA KOMENDA JEST DRUGIM ŚLADEM, NIE PIERWSZYM. Pierwszym jest `/health`
 * (pole `checks.listy`) i wpis `Log::error`, czyli rzeczy, które mówią
 * o sobie same i nie wymagają, żeby ktoś pamiętał o zaglądaniu. Tę komendę
 * uruchamia się WTEDY, gdy tamte zawołają.
 *
 * `--odhacz` GASI ALARM I TO JEST JEGO JEDYNE ZADANIE. Nie kasuje wiersza,
 * nie ponawia zadania, nie zmienia niczego w kolejce — stawia znacznik
 * „właściciel to przeczytał", po którym `/health` przestaje mówić
 * `degraded`. Ponowienie zostaje tam, gdzie jego miejsce: w
 * `php artisan queue:retry`.
 */
class NieudaneListy extends Command
{
    protected $signature = 'kuking:nieudane-listy
                            {--odhacz : Potwierdź, że wiesz o wypisanych listach — to gasi `degraded` w /health}
                            {--ile=20 : Ile najświeższych wierszy wypisać}';

    protected $description = 'Pokazuje listy, które nie wyszły, i mówi, co z każdym z nich zrobić';

    public function handle(): int
    {
        $ile = max(1, (int) $this->option('ile'));

        /** @var Collection<int, MailFailure> $nieodhaczone */
        $nieodhaczone = MailFailure::query()
            ->nieodhaczone()
            ->with('user.profile')
            ->orderByDesc('failed_at')
            ->limit($ile)
            ->get();

        $wszystkich = MailFailure::query()->nieodhaczone()->count();

        $this->newLine();
        $this->line('<options=bold>Listy, które nie wyszły</>');
        $this->newLine();

        if ($wszystkich === 0) {
            $this->info('Nic nie czeka. Żaden list nie przepadł — albo wszystkie są już odhaczone.');
            $this->line('To NIE znaczy, że każdy list doszedł do skrzynki: dostawca mógł go przyjąć');
            $this->line('i odłożyć do „Spamu”. O tym mówi panel EmailLabs, nie ta komenda.');

            return self::SUCCESS;
        }

        $this->warn('Nieodhaczonych: '.$wszystkich.($wszystkich > $ile ? ' (wypisuję '.$ile.' najświeższych)' : ''));
        $this->newLine();

        $this->table(
            ['Kiedy', 'Co', 'Kto czekał', 'Powód', 'HTTP', 'Prób', 'Zadanie'],
            $nieodhaczone->map(fn (MailFailure $slad): array => [
                $slad->failed_at->format('Y-m-d H:i').' UTC',
                class_basename($slad->rodzaj),
                $this->ktoCzekal($slad),
                $slad->powod->value,
                $slad->status_http === null ? '—' : (string) $slad->status_http,
                (string) $slad->prob,
                $slad->failed_job_uuid ?? '— (wysyłka bez kolejki)',
            ])->all(),
        );

        $this->coZrobic($nieodhaczone);

        if (! $this->option('odhacz')) {
            $this->newLine();
            $this->line('Gdy to przeczytasz i wiesz, co robisz, odhacz — inaczej `/health` będzie dalej mówił `degraded`:');
            $this->line('  php artisan kuking:nieudane-listy --odhacz');

            return self::SUCCESS;
        }

        // Odhaczamy WSZYSTKO nieodhaczone, także to, czego nie zmieściło się
        // na wypisanej liście — i mówimy o tym wprost. Odhaczanie po jednym
        // wierszu wymagałoby przepisywania uuid ręcznie, a alarm i tak jest
        // jeden dla całej tabeli.
        $odhaczonych = MailFailure::query()->nieodhaczone()->update(['zauwazony_at' => now()]);

        $this->newLine();
        $this->info('Odhaczonych wierszy: '.$odhaczonych.'. `/health` przestanie zgłaszać `degraded` przy następnym odpytaniu.');
        $this->line('Same wiersze zostają w bazie — po to, żeby dało się wrócić do pytania „co się stało 12 września”.');

        return self::SUCCESS;
    }

    /**
     * Co zrobić — jedna sekcja na kategorię, nie jedna na wiersz.
     *
     * Przy wyczerpanym limicie dobowym w tabeli leży kilkadziesiąt wierszy
     * z tym samym powodem; wypisanie instrukcji pod każdym z nich zamieniłoby
     * odpowiedź w ścianę tekstu, której nikt nie czyta do końca.
     *
     * @param  Collection<int, MailFailure>  $slady
     */
    private function coZrobic(Collection $slady): void
    {
        /** @var Collection<string, Collection<int, MailFailure>> $wgPowodu */
        $wgPowodu = $slady->groupBy(fn (MailFailure $slad): string => $slad->powod->value);

        foreach ($wgPowodu as $powod => $grupa) {
            $kategoria = PowodOdmowy::tryFrom((string) $powod) ?? PowodOdmowy::NIEZNANA;

            $this->newLine();
            $this->line('<options=bold>'.$grupa->count().' × '.$kategoria->value.'</> — '.$kategoria->opis());
            $this->line('  '.$kategoria->coZrobic());

            if ($kategoria === PowodOdmowy::LIMIT_DOBOWY) {
                $this->line('  Sufity per funkcja i rachunek całego wiadra 300 listów: `config/kuking.php`, sekcja `poczta`.');
            }
        }
    }

    /**
     * Kto czekał na list — imię z profilu, jeśli jest, inaczej sam
     * identyfikator konta.
     *
     * ADRESU E-MAIL TU NIE MA i to nie jest przeoczenie: wiersz `mail_failures`
     * go nie trzyma (AGENTS.md §7, minimalizacja — druga kopia adresu to
     * druga rzecz do skasowania przy żądaniu RODO). Gdy właściciel potrzebuje
     * adresu, ma go w koncie i w payloadzie zadania z `queue:failed`.
     */
    private function ktoCzekal(MailFailure $slad): string
    {
        if ($slad->user === null) {
            return $slad->user_id === null ? '— (nie ustalono)' : '— (konto usunięte)';
        }

        $nazwa = $slad->user->profile?->display_name;

        return $nazwa !== null && $nazwa !== '' ? $nazwa : $slad->user->getKey();
    }
}
