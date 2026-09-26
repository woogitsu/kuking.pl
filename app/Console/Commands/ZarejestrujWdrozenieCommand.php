<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wydania\Actions\ZarejestrujWdrozenie;
use App\Logging\BezpiecznyBlad;
use Illuminate\Console\Command;
use Throwable;

/**
 * Krok wdrożenia (issue #1932, D-318): dopisuje BIEŻĄCY commit do dziennika
 * `wdrozenia`, żeby stopka i strona „Co nowego" mogły pokazać numer z
 * końcówką, np. „Alfa 0.68.005".
 *
 * WPIĘTA W `.railway/railway.ts`, `preDeployCommand`, ZARAZ PO
 * `php artisan migrate --force` — tam, gdzie dziś zaczyna się cała
 * automatyka wdrożenia (patrz komentarz w tym pliku przy `preDeployCommand`
 * i `docs/infra/DEPLOYMENT_RUNBOOK.md`). Musi iść PO migracjach: potrzebuje
 * tabeli `wdrozenia`, którą ta migracja dopiero zakłada.
 *
 * LOKALNIE I W PODGLĄDACH BEZ `RAILWAY_GIT_COMMIT_SHA` (`--commit` też nie
 * podane) komenda kończy się NATYCHMIAST, z kodem 0 i jednym zdaniem —
 * ŚWIADOMIE nie próbuje zgadywać commita z `git rev-parse`: build Railwaya
 * może mieć płytki klon albo działać bez `.git` w ogóle (ten sam powód, dla
 * którego numer w ogóle NIE jest liczony z historii gita — patrz komentarz
 * migracji `2026_09_26_130000_utworz_dziennik_wdrozen.php`). Brak commita
 * nie jest błędem tej komendy, tylko stanem „nie wiadomo, bo to nie jest
 * wdrożenie" — dokładnie tak samo, jak `App\Support\Wersja::wydanie()`
 * traktuje brak `RAILWAY_GIT_COMMIT_SHA` jako „lokalnie", nie jako wyjątek.
 *
 * IDEMPOTENTNA I BEZPIECZNA PRZY RÓWNOLEGŁYM STARCIE — cała logika mieszka
 * w `App\Domain\Wydania\Actions\ZarejestrujWdrozenie`, opisana tam. Ta
 * komenda jest cienką powłoką: czyta argumenty, woła akcję, melduje wynik.
 */
final class ZarejestrujWdrozenieCommand extends Command
{
    protected $signature = 'kuking:zarejestruj-wdrozenie
        {--commit= : Pełny SHA-1 gita — domyślnie zmienna RAILWAY_GIT_COMMIT_SHA}
        {--etykieta= : Etykieta wersji — domyślnie kuking.wersja.etykieta}';

    protected $description = 'Dopisuje bieżące wdrożenie do dziennika `wdrozenia` (numer wersji z końcówką, issue #1932).';

    public function handle(ZarejestrujWdrozenie $akcja): int
    {
        $commit = $this->option('commit') ?: config('kuking.wersja.commit');
        $etykieta = $this->option('etykieta') ?: config('kuking.wersja.etykieta');

        if (! is_string($commit) || trim($commit) === '') {
            $this->info('Brak commita (RAILWAY_GIT_COMMIT_SHA nie jest ustawione) — to nie jest wdrożenie, nic do zrobienia.');

            return self::SUCCESS;
        }

        try {
            $numer = $akcja->handle($commit, (string) $etykieta);
        } catch (Throwable $e) {
            $this->error('Rejestracja wdrożenia nie powiodła się: '.BezpiecznyBlad::jednaLinia($e));

            return self::FAILURE;
        }

        $this->info(sprintf('Wdrożenie zarejestrowane: %s.%03d (commit %s).', $etykieta, $numer, substr($commit, 0, 7)));

        return self::SUCCESS;
    }
}
