<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Wydania\Actions\ZarejestrujWdrozenie;
use App\Logging\BezpiecznyBlad;
use App\Support\ZaufaneHosty;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Krok wdrożenia (issue #1932, D-318): dopisuje BIEŻĄCY commit do dziennika
 * `wdrozenia`, żeby stopka i strona „Co nowego" mogły pokazać numer z
 * końcówką, np. „Alfa 0.68.005".
 *
 * WOŁANA Z `docker/entrypoint.sh` (role `web` i `all`, funkcja
 * `rejestruj_wdrozenie_po_gotowosci`), W TLE, Z OPCJĄ `--po-gotowosci` —
 * NIE z `preDeployCommand` w `.railway/railway.ts`. Pre-deploy kończy się
 * PRZED seedem-importem-healthcheckiem nowego kontenera, więc rejestracja
 * tam zużywała numer i opisywała funkcje jako wydane także wtedy, gdy rollout
 * padł (audyt z 28 września 2026, issue #1932). Z `--po-gotowosci` komenda
 * najpierw czeka, aż lokalny `/health` TEGO kontenera odpowie 2xx (ten sam
 * sprawdzian co healthcheck Railwaya), i dopiero wtedy zapisuje. Bez
 * odpowiedzi w limicie kończy się błędem i NIC nie zapisuje — numer
 * dostanie dopiero kontener, który naprawdę wstał. Bez opcji (ręczne
 * wywołanie, testy) rejestruje od razu.
 *
 * Tabela `wdrozenia` istnieje wtedy na pewno: kontener startuje po
 * pre-deploy z `kuking:migruj-pod-blokada`.
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
        {--etykieta= : Etykieta wersji — domyślnie kuking.wersja.etykieta}
        {--po-gotowosci : Najpierw poczekaj, aż lokalny /health tego kontenera odpowie 2xx}
        {--adres= : Adres sprawdzianu gotowości — domyślnie http://127.0.0.1:$PORT/health}
        {--limit=300 : Ile sekund czekać na gotowość (najwyżej, z zaokrągleniem do próby)}
        {--odstep=3 : Przerwa między próbami, w sekundach}';

    protected $description = 'Dopisuje bieżące wdrożenie do dziennika `wdrozenia` (numer wersji z końcówką, issue #1932).';

    public function handle(ZarejestrujWdrozenie $akcja): int
    {
        $commit = $this->option('commit') ?: config('kuking.wersja.commit');
        $etykieta = $this->option('etykieta') ?: config('kuking.wersja.etykieta');

        if (! is_string($commit) || trim($commit) === '') {
            $this->info('Brak commita (RAILWAY_GIT_COMMIT_SHA nie jest ustawione) — to nie jest wdrożenie, nic do zrobienia.');

            return self::SUCCESS;
        }

        if ($this->option('po-gotowosci') && ! $this->czekajNaGotowosc()) {
            $this->error('Kontener nie odpowiedział na /health w limicie — wdrożenia NIE rejestruję (numer dostanie kontener, który naprawdę wstał).');

            return self::FAILURE;
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

    /**
     * Czeka na 2xx z `/health` TEGO kontenera. Liczy PRÓBY, nie zegar
     * (`ceil(limit / odstęp)`), żeby test nie zależał od czasu; każda próba
     * ma własny limit 5 s. Host `healthcheck.railway.app` jest na liście
     * zaufanych (`ZaufaneHosty`), więc TrustHosts przepuszcza sondę tak samo
     * jak Railway — nagłówek nie zależy od domeny środowiska.
     */
    private function czekajNaGotowosc(): bool
    {
        $adres = (string) ($this->option('adres') ?: 'http://127.0.0.1:'.(getenv('PORT') ?: 8080).'/health');
        $odstep = max(1, (int) $this->option('odstep'));
        $proby = max(1, (int) ceil(max(1, (int) $this->option('limit')) / $odstep));

        for ($i = 1; $i <= $proby; $i++) {
            try {
                if (Http::withHeaders(['Host' => ZaufaneHosty::HEALTHCHECK_RAILWAY])->timeout(5)->get($adres)->successful()) {
                    return true;
                }
            } catch (Throwable) {
                // Serwer jeszcze nie słucha — normalny stan tuż po starcie.
            }

            if ($i < $proby) {
                Sleep::for($odstep)->seconds();
            }
        }

        return false;
    }
}
