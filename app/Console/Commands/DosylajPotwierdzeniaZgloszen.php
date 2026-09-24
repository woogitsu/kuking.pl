<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Moderation\Actions\NotifyReporterReceipt;
use App\Models\Report;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * DOSYŁKA ZALEGŁYCH POTWIERDZEŃ PRZYJĘCIA ZGŁOSZENIA
 * (issue #797, D-252 — decyzja właściciela z 23.09.2026, DSA art. 16 ust. 4).
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
 * 3. Sprawa, której ROZSTRZYGNIĘCIE już do zgłaszającego doszło
 *    (`decision_sent_at IS NOT NULL`). Potwierdzenie mówi „Sprawdzimy je
 *    i napiszemy, co postanowiliśmy" — po decyzji to zdanie jest nieprawdą,
 *    a w liście powiadomień „mamy Twoje zgłoszenie" pod „postanowiliśmy"
 *    tylko miesza. Informacja o rozstrzygnięciu (art. 16 ust. 5) niesie ten
 *    sam numer sprawy i sama jest dowodem, że zgłoszenie doszło. Tego
 *    warunku pilnuje też warunkowy `UPDATE` w `NotifyReporterReceipt`, więc
 *    decyzja wysłana między odczytem partii a dosyłką też wygrywa.
 *    Sprawa rozstrzygnięta, której decyzja NIE doszła (`decision_sent_at`
 *    puste), dostaje potwierdzenie normalnie — to wtedy jedyny sygnał, że
 *    zgłoszenie w ogóle do nas trafiło.
 * 4. Zgłaszający z kontem WYMAZANYM (`users.status = erased`). Takie konto
 *    nie da się zalogować ani odzyskać, więc powiadomienie w serwisie nie ma
 *    czytelnika — to nie zaległość, tylko brak adresata, jak w punkcie 1.
 *    Konto zawieszone, zbanowane albo w karencji usunięcia potwierdzenie
 *    DOSTAJE (uzasadnienie w `NotifyReporterReceipt`: zawieszony ma prawo
 *    wiedzieć, że zgłoszenie doszło; karencję da się cofnąć).
 *
 * ── CZEGO TU ŚWIADOMIE NIE MA ──
 *
 * Górnej granicy wieku sprawy. Komenda dośle potwierdzenie także do
 * zgłoszenia sprzed roku. „Od kiedy jest za późno, żeby potwierdzać" jest
 * decyzją właściciela, a nie skutkiem ubocznym domyślnej wartości opcji —
 * `--ile` ogranicza WIELKOŚĆ jednego przebiegu, nie wiek spraw.
 *
 * ── SPRAWA, KTÓRA PADA STALE, NIE ZATYKA KOLEJKI ──
 *
 * Partia bierze najstarsze sprawy. Gdyby na jej czele stało `--ile` spraw,
 * które padają przy każdej próbie (popsuty wiersz adresata), żadna nowsza
 * zaległość nigdy by się nie zmieściła. Dlatego każda porażka jest liczona
 * per sprawa, a sprawa z co najmniej `ODLOZ_PO_PORAZKACH` porażkami idzie
 * NA KONIEC kolejki: przebieg bierze ją dopiero, gdy zostało miejsce po
 * sprawach zdrowych. Nie przepada — dalej jest zaległością, dalej liczy się
 * w „Zaległych w bazie", a jej porażka dalej daje kod ≠ 0.
 *
 * Licznik stoi w cache (w produkcji `database`), nie w kolumnie `reports`:
 * to stan roboczy dosyłki, nie fakt o sprawie. Jego utrata (wyczyszczony
 * cache) kosztuje najwyżej kilka dodatkowych prób, nie potwierdzenie.
 * Wpis wygasa po `PAMIEC_PORAZEK_DNI` dniach, a sprawa dosłana albo
 * przestała być zaległością wypada z niego przy najbliższym przebiegu.
 */
class DosylajPotwierdzeniaZgloszen extends Command
{
    protected $signature = 'kuking:dosylaj-potwierdzenia-zgloszen
                            {--ile=200 : Ile zaległych spraw obejść w jednym przebiegu}
                            {--na-sucho : Policz zaległości, ale niczego nie wysyłaj}';

    protected $description = 'Dosyła potwierdzenia przyjęcia zgłoszeń, które zostały bez potwierdzenia po awarii i do których nikt nie wrócił (issue #797, DSA art. 16 ust. 4).';

    /** Tyle porażek z rzędu i sprawa idzie na koniec kolejki (D-252). */
    public const ODLOZ_PO_PORAZKACH = 3;

    public const KLUCZ_PORAZEK = 'kuking:dosylka-potwierdzen:porazki';

    private const PAMIEC_PORAZEK_DNI = 30;

    public function handle(NotifyReporterReceipt $potwierdzenie): int
    {
        $ile = max(1, (int) $this->option('ile'));
        $naSucho = (bool) $this->option('na-sucho');

        /** @var array<string, int> $porazki */
        $porazki = Cache::get(self::KLUCZ_PORAZEK, []);
        $odlozone = array_keys(array_filter($porazki, fn (int $proby): bool => $proby >= self::ODLOZ_PO_PORAZKACH));

        $zalegle = $this->zalegle()
            ->when($odlozone !== [], fn (Builder $q) => $q->whereNotIn('id', $odlozone))
            ->orderBy('created_at')->limit($ile)->get();

        if ($odlozone !== [] && $zalegle->count() < $ile) {
            $zalegle = $zalegle->concat(
                $this->zalegle()->whereIn('id', $odlozone)
                    ->orderBy('created_at')->limit($ile - $zalegle->count())->get(),
            );
        }

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
                unset($porazki[$zgloszenie->getKey()]);
            } catch (Throwable $awaria) {
                // JEDNA SPRAWA NIE MOŻE ZATRZYMAĆ CAŁEJ DOSYŁKI. Zgłoszenie,
                // którego adresat ma popsuty wiersz, zostaje zaległością do
                // następnego przebiegu — reszta kolejki idzie dalej.
                $nieudane++;
                $porazki[$zgloszenie->getKey()] = ($porazki[$zgloszenie->getKey()] ?? 0) + 1;
                report($awaria);
            }
        }

        $this->zapamietajPorazki($porazki);

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
     * Zapisuje liczniki porażek, zostawiając tylko sprawy, które nadal są
     * zaległością — dosłane przez człowieka albo rozstrzygnięte wypadają.
     *
     * @param  array<string, int>  $porazki
     */
    private function zapamietajPorazki(array $porazki): void
    {
        if ($porazki !== []) {
            $nadalZalegle = $this->zalegle()->whereIn('id', array_keys($porazki))->pluck('id')->all();
            $porazki = array_intersect_key($porazki, array_flip($nadalZalegle));
        }

        if ($porazki === []) {
            Cache::forget(self::KLUCZ_PORAZEK);

            return;
        }

        Cache::put(self::KLUCZ_PORAZEK, $porazki, now()->addDays(self::PAMIEC_PORAZEK_DNI));
    }

    /**
     * Zaległość = sprawa z ADRESATEM w serwisie i bez znacznika potwierdzenia.
     *
     * Uzasadnienie każdego warunku stoi w nagłówku klasy („czego komenda nie
     * rusza", punkty 1–4); żaden nie wynika z pozostałych.
     *
     * @return Builder<Report>
     */
    private function zalegle(): Builder
    {
        return Report::query()
            ->whereNotNull('reporter_id')
            ->whereNull('receipt_sent_at')
            ->whereNull('decision_sent_at')
            ->whereHas('reporter', fn (Builder $konto) => $konto->where('status', '!=', User::STATUS_ERASED));
    }
}
