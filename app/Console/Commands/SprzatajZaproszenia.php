<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzedawnioneZaproszenia;
use App\Models\RegistrationInvite;
use Illuminate\Console\Command;

/**
 * Sprzątanie wygasłych zaproszeń do założenia konta (D-085).
 *
 * Termin każdego zaproszenia stoi w jego własnej kolumnie `expires_at`
 * (`config('kuking.login_link.zaproszenia.waznosc_godzin')` godzin od
 * wysłania), więc ta komenda nie ma żadnego progu do ustawiania — kasuje to,
 * co przeterminowane. Pełne uzasadnienie: `PrzedawnioneZaproszenia`.
 */
class SprzatajZaproszenia extends Command
{
    protected $signature = 'kuking:sprzataj-zaproszenia
                            {--na-sucho : Policz, ale niczego nie kasuj}
                            {--wszystkie : Skasuj TAKŻE ważne zaproszenia — tylko do wycofania migracji}';

    protected $description = 'Kasuje wygasłe zaproszenia do założenia konta (D-085).';

    public function handle(PrzedawnioneZaproszenia $sprzataj): int
    {
        $naSucho = (bool) $this->option('na-sucho');

        /*
         * `--wszystkie` KASUJE TAKŻE ŻYWE ZAPROSZENIA i jest tu wyłącznie po
         * to, żeby dało się wycofać migrację zakładającą tabelę: `down()`
         * odmawia, dopóki w tabeli leży choć jedno ważne zaproszenie, i wprost
         * odsyła do tej opcji. Każdy skasowany żywy wiersz to człowiek, który
         * ma w skrzynce wiadomość i zobaczy „ten link już nie działa" —
         * dlatego pytamy o potwierdzenie i mówimy, ilu osób to dotyczy.
         */
        if ((bool) $this->option('wszystkie')) {
            return $this->skasujWszystkie($naSucho);
        }

        $ile = $sprzataj->posprzataj($naSucho);

        $this->info($naSucho
            ? "Do skasowania: {$ile} wygasłych zaproszeń do założenia konta."
            : "Skasowano {$ile} wygasłych zaproszeń do założenia konta.");

        return self::SUCCESS;
    }

    private function skasujWszystkie(bool $naSucho): int
    {
        $wazne = RegistrationInvite::query()->where('expires_at', '>', now())->count();

        if ($naSucho) {
            $this->info("Do skasowania: WSZYSTKIE zaproszenia, w tym {$wazne} jeszcze ważnych.");

            return self::SUCCESS;
        }

        if ($wazne > 0 && ! $this->confirm(
            "W tabeli jest {$wazne} WAŻNYCH zaproszeń. Tyle osób ma w skrzynce wiadomość, "
            .'której jeszcze nie kliknęło — po skasowaniu zobaczą „ten link już nie działa". Kasować?',
            default: false,
        )) {
            $this->warn('Nic nie skasowano.');

            return self::FAILURE;
        }

        $ile = RegistrationInvite::query()->delete();

        $this->info("Skasowano {$ile} zaproszeń do założenia konta (w tym {$wazne} ważnych).");

        return self::SUCCESS;
    }
}
