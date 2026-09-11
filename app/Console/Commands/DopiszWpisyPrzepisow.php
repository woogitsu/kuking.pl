<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Recipes\WpisWskazujacyPrzepis;
use App\Support\Odmiana;
use Illuminate\Console\Command;

/**
 * Uzupełnia wpisy dla przepisów opublikowanych, ZANIM powstał mechanizm
 * z issue #368.
 *
 * DLACZEGO KOMENDA, A NIE MIGRACJA. To jest zmiana DANYCH, nie schematu —
 * żadna kolumna, żaden indeks i żadne ograniczenie się nie zmienia. Migracja
 * wykonuje się raz, sama, w środku wdrożenia i bez okazji do obejrzenia
 * wyniku; a tutaj chodzi o wstawienie wierszy, które od razu wyjdą ludziom
 * na stronę główną. Taką rzecz uruchamia się świadomie, najpierw
 * `--na-sucho`, i patrzy na liczbę.
 *
 * IDEMPOTENTNA. Pyta „które opublikowane przepisy nie mają wpisu", a nie
 * „które przepisy już przerobiłem" — więc drugie uruchomienie nie znajduje
 * nic i niczego nie dubluje. Przerwany przebieg (Ctrl+C, restart kontenera)
 * zostawia to, co zdążył, a kolejne uruchomienie dokańcza resztę.
 *
 * NIE URUCHAMIAJ JEJ NA PRODUKCJI BEZ ZGODY WŁAŚCICIELA. Nie jest
 * destrukcyjna, ale wystawia treść ludziom na oczy — a data publikacji wpisu
 * jest brana Z PRZEPISU, więc archiwalne przepisy wejdą w chronologię tam,
 * gdzie naprawdę powstały, a nie na górę strumieni.
 */
class DopiszWpisyPrzepisow extends Command
{
    protected $signature = 'kuking:dopisz-wpisy-przepisow
                            {--na-sucho : Policz, ale niczego nie zapisuj}';

    protected $description = 'Dopisuje brakujące wpisy dla przepisów opublikowanych przed issue #368.';

    public function handle(): int
    {
        $naSucho = (bool) $this->option('na-sucho');

        $ile = WpisWskazujacyPrzepis::uzupelnijZaleglosci($naSucho);

        if ($ile === 0) {
            $this->info('Nie ma czego dopisywać — każdy opublikowany przepis ma już swój wpis.');

            return self::SUCCESS;
        }

        // Liczebnik przez `Odmiana`, a nie „{$ile} wpisów" na sztywno.
        // Właściciel uruchomił tę komendę na produkcji i zobaczył
        // „Dopisano 1 wpisów" — a to jest jedyne zdanie, jakie ta komenda
        // o sobie mówi.
        $wpisy = Odmiana::rzeczownik($ile, 'wpis', 'wpisy', 'wpisów');

        $this->info($naSucho
            ? "Do dopisania: {$ile} {$wpisy} dla przepisów opublikowanych wcześniej. Nic nie zapisano."
            : "Dopisano {$ile} {$wpisy} dla przepisów opublikowanych wcześniej.");

        return self::SUCCESS;
    }
}
