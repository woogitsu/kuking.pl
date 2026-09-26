<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BudzetAi;
use App\Domain\Import\KlientLuna;
use Illuminate\Console\Command;

/**
 * Mówi po polsku, czy odczyt przepisu modelem może działać i czego brakuje
 * (D-298). NIE wysyła żadnego żądania do OpenAI — każde żądanie kosztuje,
 * a konfigurację da się sprawdzić bez niego. Nigdy nie drukuje klucza.
 */
class SprawdzImport extends Command
{
    protected $signature = 'kuking:sprawdz-import';

    protected $description = 'Sprawdza konfigurację odczytu przepisów modelem (klucz, model, intensywność, cennik, budżet) bez wysyłania żądań';

    public function handle(BudzetAi $budzet): int
    {
        $this->line('<options=bold>Odczyt przepisów modelem (V2, D-298)</>');
        $this->newLine();

        $this->line('Model: '.(string) config('kuking.import.model.nazwa'));
        $this->line('Kolejka: '.(string) config('kuking.import.kolejka'));

        $wszystkoGotowe = true;

        foreach (KlientLuna::ZADANIA as $zadanie) {
            $braki = KlientLuna::braki($zadanie);
            $opis = $zadanie === KlientLuna::ZADANIE_OCR ? 'odczyt zdjęcia kartki (ocr)' : 'fragmenty tekstu strony/PDF (tekst)';

            if ($braki === []) {
                $this->info("✓ {$opis}: gotowe, intensywność ".KlientLuna::wysilek($zadanie).'.');

                continue;
            }

            $wszystkoGotowe = $wszystkoGotowe && $zadanie !== KlientLuna::ZADANIE_OCR;
            $this->warn("✗ {$opis}: wyłączone.");

            foreach ($braki as $brak) {
                $this->line('   - '.$brak);
            }
        }

        $stan = $budzet->stan();
        $usd = fn (int $mikro): string => number_format($mikro / 1_000_000, 2, ',', ' ').' USD';

        $this->newLine();
        $this->line('Budżet dzisiaj ('.$stan['dzien'].'): '.$usd($stan['dzisiaj_mikrousd']).' z '.$usd($stan['limit_dzienny_mikrousd'])
            .', wywołań: '.$stan['wywolan_dzisiaj'].'.');
        $this->line('Budżet w tym miesiącu: '.$usd($stan['miesiac_mikrousd']).' z '.$usd($stan['limit_miesieczny_mikrousd']).'.');

        $szacunek = BudzetAi::szacunek(KlientLuna::ZADANIE_OCR);

        if ($szacunek !== null) {
            $this->line('Rezerwacja na jeden odczyt zdjęcia (najgorszy przypadek): '.$usd($szacunek).'.');
        }

        $this->newLine();
        $this->line('Przed włączeniem na produkcji: umowa powierzenia (DPA) z OpenAI wpisana do '
            .'docs/legal/REJESTR_UMOW_POWIERZENIA.md, polityka prywatności z nowym celem, limit wydatków w panelu OpenAI.');

        return $wszystkoGotowe ? self::SUCCESS : self::FAILURE;
    }
}
