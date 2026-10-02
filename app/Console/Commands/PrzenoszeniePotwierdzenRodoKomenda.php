<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\PrzenoszeniePotwierdzenRodo;
use Illuminate\Console\Command;

/**
 * Jednorazowe przeniesienie starych wpisów `audit_log` `account.*` do
 * `potwierdzenia_zadan_rodo` (#2708). Kolejność wdrożenia: najpierw
 * `--dry-run` (tylko liczby), potem bez opcji, potem sprawdzenie, że
 * „Brakuje” = 0. Dopiero wtedy retencja audytu zaczyna kasować wpisy
 * `account.*` — wcześniej `PrzedawnioneWpisyAudytu` ich nie rusza.
 * Komenda niczego nie kasuje i jest idempotentna.
 */
class PrzenoszeniePotwierdzenRodoKomenda extends Command
{
    protected $signature = 'kuking:przenies-potwierdzenia-rodo
                            {--dry-run : Tylko policz i wypisz liczby, niczego nie zapisuj}';

    protected $description = 'Przenosi zamknięte sprawy usunięcia konta ze starych wpisów audit_log do potwierdzeń RODO (idempotentnie).';

    public function handle(PrzenoszeniePotwierdzenRodo $przenos): int
    {
        $naSucho = (bool) $this->option('dry-run');
        $w = $przenos->przenies($naSucho);

        $this->info(($naSucho ? 'DRY-RUN (nic nie zapisano). ' : '').'Zamknięte sprawy w audit_log: '.$w['zamkniecia_w_audycie'].'.');
        $this->line('Potwierdzenia w rejestrze przed przebiegiem: '.$w['potwierdzenia_w_rejestrze'].'.');
        $this->line(($naSucho ? 'Do utworzenia: ' : 'Utworzono: ').($naSucho ? $w['do_utworzenia'] : $w['utworzono']).' (wykonane: '.($naSucho ? '—' : $w['wykonane']).', cofnięte: '.($naSucho ? '—' : $w['cofniete']).').');
        $this->line('Sprawy otwarte, pominięte (zamknie je wymazanie): '.$w['otwarte_pominiete'].'.');
        $this->line('Zakres wykonania nieznany, przyjęto „minimum”: '.$w['zakres_domyslny'].'.');
        $this->line('Sprawy bez istniejącego konta, pominięte: '.$w['brak_konta'].'.');

        $brakuje = $przenos->ileBrakuje();
        $this->line('Brakuje po przebiegu: '.$brakuje.($brakuje === 0 ? ' (potwierdzenia pokrywają audyt).' : ' (retencja audytu nie kasuje wpisów account.* do czasu uzupełnienia).'));

        return $w['brak_konta'] > 0 && ! $naSucho ? self::FAILURE : self::SUCCESS;
    }
}
