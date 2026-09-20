<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Moderation\Actions\NotifyReporterReceipt;
use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * DOSYŁKA ZALEGŁYCH POTWIERDZEŃ PRZYJĘCIA ZGŁOSZENIA
 * (issue #797, decyzja właściciela z 20.09.2026, DSA art. 16 ust. 4).
 *
 * ── CO ZOSTAWAŁO NIEDOKOŃCZONE ──
 *
 * Potwierdzenie przyjęcia stoi POZA transakcją zapisu sprawy — celowo, żeby
 * awaria powiadomienia nie zabrała człowiekowi zgłoszenia
 * (`ReportContent::handle()`). Po poprawce z #797 potwierdzenie i znacznik
 * `receipt_sent_at` idą w JEDNEJ transakcji, a `ReportContent` dokańcza
 * zaległość, gdy ktoś wróci do tej samej sprawy.
 *
 * Zostawała jedna dziura: SPRAWA, DO KTÓREJ NIKT NIE WRÓCI. Wyjątek szedł do
 * kanału błędów i na tym się kończyło — zgłoszenie żyło 36 miesięcy bez
 * potwierdzenia, którego przepis wymaga. Ta komenda obchodzi takie sprawy
 * sama, zamiast czekać na ponowne kliknięcie „Zgłoś".
 *
 * ── DLACZEGO TĄ SAMĄ DROGĄ, A NIE WŁASNYM ZAPYTANIEM ──
 *
 * Komenda woła `NotifyReporterReceipt::handle()`, czyli DOKŁADNIE to, co woła
 * formularz. Własna kopia „utwórz powiadomienie i ustaw znacznik" byłaby
 * drugim miejscem, które trzeba poprawiać razem z pierwszym — a takie pary
 * rozjeżdżają się przy pierwszej zmianie. Z tego samego powodu komenda NIE
 * ma własnego zamka: zamkiem jest warunkowy `UPDATE ... WHERE
 * receipt_sent_at IS NULL` w tamtej akcji. Gdy dosyłka zbiegnie się w czasie
 * z człowiekiem wracającym do sprawy, wiersz dostaje dokładnie jedno z nich,
 * a drugie widzi zero zajętych wierszy i nie tworzy niczego. Rozstrzyga o tym
 * baza, nie PHP — i mierzy to `tests/Dwa/DosylkaNieDublujePotwierdzeniaTest`
 * na dwóch osobnych połączeniach.
 *
 * ── CZEGO KOMENDA NIE RUSZA (i dlaczego `receipt_sent_at IS NULL` NIE
 *    WYSTARCZA JAKO WARUNEK) ──
 *
 * 1. `reporter_id IS NULL` to NIE zaległość. Tak wygląda zgłoszenie bez konta
 *    (DSA art. 16 ust. 2 lit. c) oraz oznaczenie automatu: w tabeli
 *    `notifications` nie ma dla nich adresata. Droga prawna ma WŁASNE,
 *    mailowe potwierdzenie (`ZglosNielegalnaTresc`) i własny znacznik na tej
 *    samej kolumnie. Potraktowanie wszystkich `null` jako zaległości
 *    wysyłałoby pingi w próżnię i kłamało w liczniku.
 * 2. Sprawa, której ping skasowała retencja, też nie jest zaległością — ale
 *    tego pilnuje już sam znacznik: `receipt_sent_at` odpowiada na pytanie
 *    „czy potwierdziliśmy odbiór", a nie „czy powiadomienie jeszcze istnieje".
 *    `RetencjaPowiadomien` świadomie kasuje ping po ogólnym okresie, a sprawa
 *    żyje 36 miesięcy. Dlatego komenda pyta o znacznik, NIGDY o istnienie
 *    powiadomienia.
 *
 * ── CZEGO TU ŚWIADOMIE NIE MA ──
 *
 * Górnej granicy wieku sprawy. Komenda dośle potwierdzenie także do
 * zgłoszenia sprzed roku. „Od kiedy jest za późno, żeby potwierdzać" jest
 * decyzją właściciela, a nie skutkiem ubocznym domyślnej wartości opcji —
 * `--ile` ogranicza WIELKOŚĆ jednego przebiegu, nie wiek spraw.
 */
class DosylajPotwierdzeniaZgloszen extends Command
{
    protected $signature = 'kuking:dosylaj-potwierdzenia-zgloszen
                            {--ile=200 : Ile zaległych spraw obejść w jednym przebiegu}
                            {--na-sucho : Policz zaległości, ale niczego nie wysyłaj}';

    protected $description = 'Dosyła potwierdzenia przyjęcia zgłoszeń, które zostały bez potwierdzenia po awarii i do których nikt nie wrócił (issue #797, DSA art. 16 ust. 4).';

    public function handle(NotifyReporterReceipt $potwierdzenie): int
    {
        $ile = max(1, (int) $this->option('ile'));
        $naSucho = (bool) $this->option('na-sucho');

        $zalegle = $this->zalegle()->orderBy('created_at')->limit($ile)->get();

        $wszystkich = $this->zalegle()->count();

        $this->info("Zaległych potwierdzeń w bazie: {$wszystkich}.");

        if ($zalegle->isEmpty()) {
            return self::SUCCESS;
        }

        $najstarsze = $zalegle->first()?->created_at;

        if ($najstarsze !== null) {
            $this->line('Najstarsza zaległość czeka od: '.$najstarsze->toDateTimeString().'.');
        }

        if ($naSucho) {
            $this->line("Do dosłania w tym przebiegu: {$zalegle->count()} (przebieg na sucho, nic nie wysłano).");

            return self::SUCCESS;
        }

        $doslane = 0;
        $juzPotwierdzone = 0;
        $nieudane = 0;

        foreach ($zalegle as $zgloszenie) {
            try {
                // Ta sama droga co przy formularzu. Zwrot `null` znaczy, że
                // zamek na `receipt_sent_at` dostał ktoś inny — czyli człowiek
                // wrócił do sprawy w tej samej chwili. To nie jest błąd.
                if ($potwierdzenie->handle($zgloszenie) !== null) {
                    $doslane++;
                } else {
                    $juzPotwierdzone++;
                }
            } catch (Throwable $awaria) {
                // JEDNA SPRAWA NIE MOŻE ZATRZYMAĆ CAŁEJ DOSYŁKI. Zgłoszenie,
                // którego adresat ma popsuty wiersz, zostaje zaległością do
                // następnego przebiegu — reszta kolejki idzie dalej.
                $nieudane++;
                report($awaria);
            }
        }

        $this->line("Dosłano potwierdzeń: {$doslane}.");
        $this->line("Pominięto spraw potwierdzonych w międzyczasie przez kogoś innego: {$juzPotwierdzone}.");

        if ($nieudane > 0) {
            $this->warn("Nie udało się dosłać potwierdzeń: {$nieudane} — szczegóły w kanale błędów.");

            // NIEZEROWY KOD WYJŚCIA, a nie sam wpis w logu. Harmonogram
            // sprawdza ten kod (`routes/console.php`), więc przebieg
            // z nieudanymi sprawami jest tam widoczny jako porażka zadania.
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Zaległość = sprawa z ADRESATEM w serwisie i bez znacznika potwierdzenia.
     *
     * Uzasadnienie obu warunków stoi w nagłówku klasy; są to dwa różne
     * warunki i żaden nie wynika z drugiego.
     *
     * @return Builder<Report>
     */
    private function zalegle(): Builder
    {
        return Report::query()
            ->whereNotNull('reporter_id')
            ->whereNull('receipt_sent_at');
    }
}
