<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Moderation\Actions\PotwierdzZgloszenieInnaDroga;
use App\Models\Report;
use App\Models\User;
use App\Support\AdresEmail;
use DomainException;
use Illuminate\Console\Command;

/**
 * Operator oznacza sprawę jako potwierdzoną inną drogą (#2218, kryterium 3).
 *
 * KOMENDA, NIE EKRAN PANELU — ten sam wybór i powód co `kuking:nadaj-role`
 * i `kuking:2fa-wylacz`: to rzadka, ręczna czynność po sprawdzeniu adresu
 * zgłaszającego poza serwisem, a panel moderacji nie ma dziś żadnej listy
 * „spraw na suficie prób" (stan widać w alarmie, w `/health` → `informacje`
 * i w dzienniku serwera), więc ekran byłby nowym modułem tylko dla tej
 * jednej czynności. Komenda wymaga powłoki produkcyjnej, czyli zaufania,
 * którego i tak trzeba, by zmienić coś w bazie ręcznie.
 *
 * KTO: `--operator` to login czynnego konta moderatora albo administratora.
 * Bez tego dziennik audytu miałby pusty `actor_id` (komenda z powłoki nie ma
 * zalogowanego człowieka), a pytanie „kto to potwierdził" — bez odpowiedzi.
 *
 * Cała logika i zapis w dzienniku: `PotwierdzZgloszenieInnaDroga`.
 */
class PotwierdzZgloszenieInnaDrogaKomenda extends Command
{
    protected $signature = 'kuking:potwierdz-zgloszenie-inna-droga
                            {numer : Numer sprawy, np. KU-ABCD-2345}
                            {--operator= : Login (e-mail albo nazwa użytkownika) moderatora lub administratora, który to robi}
                            {--droga= : Jak potwierdzono: telefon, poczta_papierowa, inny_email albo osobiscie}
                            {--tak : Nie pytaj o potwierdzenie}';

    protected $description = 'Oznacza zgłoszenie DSA jako potwierdzone inną drogą niż list z serwisu (np. po sufitie prób listu) — z wpisem w dzienniku audytu';

    public function handle(PotwierdzZgloszenieInnaDroga $akcja): int
    {
        $login = trim((string) $this->option('operator'));

        if ($login === '') {
            $this->error('Podaj, kto to robi: --operator=<login moderatora lub administratora>.');

            return self::FAILURE;
        }

        $operator = User::findByLogin($login);

        if ($operator === null) {
            $this->error('Nie znaleziono konta operatora o takim loginie.');

            return self::FAILURE;
        }

        // Uprawnienia PRZED pytaniem: nie ma sensu pytać „czy na pewno" kogoś,
        // kogo akcja i tak odrzuci. Akcja sprawdza to samo jeszcze raz —
        // obrona w głębi (akcję da się wywołać z innego miejsca niż ta komenda).
        if (! $operator->isModerator()) {
            $this->error(PotwierdzZgloszenieInnaDroga::KOMUNIKAT_BRAK_UPRAWNIEN);

            return self::FAILURE;
        }

        $droga = trim((string) $this->option('droga'));

        if ($droga === '') {
            $this->error('Podaj, jak potwierdzono zgłoszenie: --droga='.implode('|', PotwierdzZgloszenieInnaDroga::DROGI).'.');

            return self::FAILURE;
        }

        if (! in_array($droga, PotwierdzZgloszenieInnaDroga::DROGI, true)) {
            $this->error(PotwierdzZgloszenieInnaDroga::komunikatNieznanejDrogi($droga));

            return self::FAILURE;
        }

        $numer = (string) $this->argument('numer');

        // Stan sprawy do pytania i wczesna odmowa, gdy sprawy nie ma albo nie
        // czeka na potwierdzenie (akcja i tak sprawdzi to pod blokadą).
        try {
            $stanSprawy = $akcja->sprawaDoPotwierdzenia($numer);
        } catch (DomainException $powod) {
            $this->error($powod->getMessage());

            return self::FAILURE;
        }

        // Bez terminala `confirm()` po cichu odpowiada „nie" — „Anulowano."
        // z kodem 0 wyglądałoby jak sukces skryptu, który niczego nie zrobił.
        if (! $this->option('tak') && ! $this->input->isInteractive()) {
            $this->error('Brak terminala. Dodaj --tak, żeby potwierdzić bez pytania.');

            return self::FAILURE;
        }

        // Skrót adresu, nie całość — wyjście tej komendy zostaje w logu
        // platformy (jak w `kuking:nadaj-role`). Stan sprawy: status, sufit
        // i data — bez żadnych danych zgłaszającego.
        if (! $this->option('tak') && ! $this->confirm(
            "Oznaczyć sprawę {$stanSprawy->numer_sprawy} jako potwierdzoną drogą „{$droga}” (operator: ".AdresEmail::maska((string) $operator->email).')? '
            .'Stan sprawy: '.$this->opisStanu($stanSprawy, $akcja).'. '
            .'Zgłaszający NIE dostanie z tego powodu żadnego listu.',
        )) {
            $this->info('Anulowano.');

            return self::SUCCESS;
        }

        try {
            $sprawa = $akcja->handle($numer, $operator, $droga);
        } catch (DomainException $powod) {
            $this->error($powod->getMessage());

            return self::FAILURE;
        }

        $this->info("Sprawa {$sprawa->numer_sprawy} oznaczona jako potwierdzona (droga: {$droga}). Wpis w dzienniku audytu: ".PotwierdzZgloszenieInnaDroga::AKCJA_AUDYTU.'.');

        return self::SUCCESS;
    }

    private function opisStanu(Report $sprawa, PotwierdzZgloszenieInnaDroga $akcja): string
    {
        $status = match ($sprawa->status) {
            Report::STATUS_OPEN => 'otwarta',
            Report::STATUS_TRIAGE => 'w wstępnej ocenie',
            Report::STATUS_REVIEWING => 'w rozpatrywaniu',
            Report::STATUS_RESOLVED => 'rozstrzygnięta',
            Report::STATUS_REJECTED => 'odrzucona',
            default => (string) $sprawa->status,
        };

        return 'status: '.$status
            .'; stoi na suficie prób listu: '.($akcja->naSuficie($sprawa) ? 'tak' : 'nie')
            .'; zgłoszona: '.$sprawa->created_at?->format('Y-m-d H:i');
    }
}
