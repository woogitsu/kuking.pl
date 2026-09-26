<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Import\BudzetAi;
use App\Domain\Import\Cennik;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\ObrazDoOdczytu;
use App\Domain\Import\OdczytKartki;
use App\Domain\Import\OdpowiedzModelu;
use App\Domain\Import\Rezerwacja;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\ImportPrzepisu;
use App\Models\Media;
use App\Models\Recipe;
use App\Moderacja\ExceptionContext;
use App\Moderacja\ModelChwilowoNiedostepny;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Odczyt jednego zdjęcia kartki modelem i wpisanie wyniku do SZKICU (D-298).
 *
 * KOLEJKA `low` (uzasadnienie w D-298 i `config/kuking.php` → `import.kolejka`).
 *
 * KOLEJNOŚĆ SPRAWDZEŃ PRZED WYSYŁKĄ — każde świeżo z bazy, w zadaniu, nie
 * tylko w formularzu (D-240, D-296):
 *   szkic nadal szkicem i nietknięty → zdjęcie gotowe → funkcja włączona →
 *   JPEG ≤ 2000 px bez metadanych → REZERWACJA budżetu → ZGODA „odczyt AI”
 *   (ostatnia, tuż przed żądaniem) → żądanie → rozliczenie z `usage`.
 *
 * WYNIK TRAFIA WYŁĄCZNIE DO SZKICU: `PublishRecipe` z `publish: false`,
 * `visibility: private`. Szkic, który człowiek w międzyczasie zaczął pisać,
 * nie jest nadpisywany (`szkic_zmieniony`).
 *
 * PONOWIENIA: chwilowa awaria modelu (429, 5xx, timeout) wraca do kolejki
 * z opóźnieniem 30 s / 120 s, najwyżej `PROBY_MODELU` razy. Zdjęcie jeszcze
 * w obróbce (`pending`/`processing`) — krótkie odłożenie, bez wywołania.
 */
class OdczytajPrzepis implements ShouldQueue
{
    use Queueable;

    public const PROBY_MODELU = 3;

    /** Opóźnienia przed 2. i 3. próbą modelu. */
    private const OPOZNIENIA = [30, 120];

    /** Ile razy najwyżej czekamy na obróbkę zdjęcia. */
    private const CZEKANIE_NA_ZDJECIE = 5;

    // Suma: próby modelu + czekanie na zdjęcie. `release()` zjada próbę.
    public int $tries = self::PROBY_MODELU + self::CZEKANIE_NA_ZDJECIE;

    public int $timeout = 120;

    public function __construct(public string $importId)
    {
        $this->onQueue((string) config('kuking.import.kolejka', 'low'));
    }

    public function handle(
        KlientLuna $klient,
        BudzetAi $budzet,
        PrzestawZgodeNaOdczytAi $zgoda,
        ObrazDoOdczytu $obraz,
        PublishRecipe $przepisy,
    ): void {
        $zlecenie = ImportPrzepisu::query()->find($this->importId);

        if ($zlecenie === null || $zlecenie->jestKoncowy()) {
            return;
        }

        $zlecenie->forceFill(['status' => ImportPrzepisu::STATUS_W_TOKU, 'rozpoczeto_at' => $zlecenie->rozpoczeto_at ?? now()])->save();

        $szkic = $zlecenie->recipe;

        if (! $this->szkicNietkniety($szkic)) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_SZKIC_ZMIENIONY);

            return;
        }

        $media = $szkic->sourceScan;

        if (! $media instanceof Media || in_array($media->status, [Media::STATUS_REJECTED, Media::STATUS_DELETED], true)) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_ZDJECIE_NIEDOSTEPNE);

            return;
        }

        if ($media->status !== Media::STATUS_READY) {
            if ($this->wroci() && $this->attempts() < $this->tries) {
                $zlecenie->forceFill(['status' => ImportPrzepisu::STATUS_OCZEKUJE])->save();
                $this->release(15);

                return;
            }

            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_ZDJECIE_NIEDOSTEPNE);

            return;
        }

        if (! KlientLuna::skonfigurowany(KlientLuna::ZADANIE_OCR) || ! config('kuking.import.zrodla.zdjecie')) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_WYLACZONY);

            return;
        }

        $jpeg = $obraz->jpeg($media);

        if ($jpeg === null) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_ZDJECIE_NIEDOSTEPNE);

            return;
        }

        $rezerwacja = $budzet->zarezerwuj(BudzetAi::szacunek(KlientLuna::ZADANIE_OCR) ?? PHP_INT_MAX);

        if (! $rezerwacja instanceof Rezerwacja) {
            $this->zakoncz(
                $zlecenie,
                $rezerwacja === BudzetAi::ODMOWA_DZIEN ? ImportPrzepisu::KOD_BUDZET_DZIENNY : ImportPrzepisu::KOD_BUDZET_MIESIECZNY,
                ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM,
            );

            return;
        }

        $zlecenie->forceFill([
            'rezerwacja_mikrousd' => $rezerwacja->mikroUsd,
            'rezerwacja_dzien' => $rezerwacja->dzien,
        ])->save();

        // ZGODA SPRAWDZANA OSTATNIA, tuż przed żądaniem — wycofanie w trakcie
        // kolejki ma znaczyć zero wysłanych bajtów (D-296).
        if (! $zgoda->udzielona($zlecenie->user)) {
            $budzet->zwolnij($rezerwacja);
            $this->bezRezerwacji($zlecenie);
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BRAK_ZGODY);

            return;
        }

        $zlecenie->forceFill(['proby' => $zlecenie->proby + 1])->save();

        try {
            $odpowiedz = $klient->odczytaj(
                KlientLuna::ZADANIE_OCR,
                OdczytKartki::INSTRUKCJA,
                OdczytKartki::tresc($jpeg),
                OdczytKartki::NAZWA_SCHEMATU,
                OdczytKartki::schemat(),
            );
        } catch (ModelChwilowoNiedostepny $awaria) {
            // Żądanie mogło dojść i zostać policzone — rezerwacja idzie
            // w wydatki (D-297: lepiej zawyżyć niż przekroczyć).
            $this->rozlicz($zlecenie, $budzet, $rezerwacja, null);

            if ($this->wroci() && $zlecenie->proby < self::PROBY_MODELU) {
                $zlecenie->forceFill(['status' => ImportPrzepisu::STATUS_OCZEKUJE])->save();
                $this->release($this->opoznienie($zlecenie->proby, $awaria));

                return;
            }

            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY);

            return;
        }

        if ($odpowiedz === null) {
            // Żądanie nie wyszło albo odpadło na stałe (4xx) — nic nie kosztowało.
            $budzet->zwolnij($rezerwacja);
            $this->bezRezerwacji($zlecenie);
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_ODPOWIEDZ_BLEDNA);

            return;
        }

        $this->rozlicz($zlecenie, $budzet, $rezerwacja, $odpowiedz);

        if ($odpowiedz->dane === null) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_ODPOWIEDZ_BLEDNA);

            return;
        }

        $wynik = OdczytKartki::wynik($odpowiedz->dane);

        if ($wynik === null) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_NIECZYTELNE);

            return;
        }

        $this->wpiszDoSzkicu($zlecenie, $szkic, $wynik, $przepisy);
    }

    /**
     * Zadanie padło (timeout, wyjątek) — zlecenie nie może zostać w `w_toku`
     * na zawsze. Rezerwacja, której nikt nie rozliczył, idzie w wydatki.
     */
    public function failed(?Throwable $blad): void
    {
        $zlecenie = ImportPrzepisu::query()->find($this->importId);

        if ($zlecenie === null || $zlecenie->jestKoncowy()) {
            return;
        }

        if ($zlecenie->rezerwacja_mikrousd !== null && $zlecenie->koszt_mikrousd === null) {
            app(BudzetAi::class)->rozlicz(new Rezerwacja((string) $zlecenie->rezerwacja_dzien, (int) $zlecenie->rezerwacja_mikrousd), null);
            $zlecenie->forceFill(['koszt_mikrousd' => $zlecenie->rezerwacja_mikrousd]);
        }

        Log::warning('Odczyt przepisu ze zdjęcia nie powiódł się.', [
            'import_id' => $this->importId,
            ...($blad === null ? ['stage' => 'import_failed'] : ExceptionContext::forStage($blad, 'import_failed')),
        ]);

        $this->zakoncz($zlecenie, ImportPrzepisu::KOD_BLAD_WEWNETRZNY);
    }

    private function szkicNietkniety(?Recipe $szkic): bool
    {
        return $szkic !== null
            && $szkic->status === Recipe::STATUS_DRAFT
            && ! $szkic->ingredients()->exists()
            && ! $szkic->steps()->exists();
    }

    /**
     * @param  array{tytul: ?string, porcje: ?string, uwagi: ?string, skladniki: list<array{text: string, group_name: ?string}>, kroki: list<array{instruction: string}>}  $wynik
     */
    private function wpiszDoSzkicu(ImportPrzepisu $zlecenie, Recipe $szkic, array $wynik, PublishRecipe $przepisy): void
    {
        $zapisano = DB::transaction(function () use ($szkic, $wynik, $przepisy): bool {
            $swiezy = Recipe::query()->whereKey($szkic->getKey())->lockForUpdate()->first();

            if (! $this->szkicNietkniety($swiezy)) {
                return false;
            }

            $porcje = $wynik['porcje'];
            $liczbaPorcji = $porcje !== null && preg_match('/^\s*(\d{1,3})\s*$/', $porcje, $m) === 1 && (int) $m[1] > 0 ? (int) $m[1] : null;
            $opis = trim(implode("\n\n", array_filter([
                $porcje !== null && $liczbaPorcji === null ? 'Porcje (z kartki): '.$porcje : null,
                $wynik['uwagi'],
            ])));

            $przepisy->handle(
                author: $swiezy->author,
                attributes: [
                    'title' => $wynik['tytul'] ?? $swiezy->title,
                    'summary' => $opis === '' ? $swiezy->summary : $opis,
                    'servings' => $liczbaPorcji ?? $swiezy->servings,
                    'prep_minutes' => $swiezy->prep_minutes,
                    'cook_minutes' => $swiezy->cook_minutes,
                    'difficulty' => $swiezy->difficulty,
                    // Szkic z odczytu jest ZAWSZE prywatny (D-298).
                    'visibility' => 'private',
                    'hero_media_id' => $swiezy->hero_media_id,
                    'source_type' => $swiezy->source_type,
                    'source_url' => $swiezy->source_url,
                    'source_person' => $swiezy->source_person,
                    'source_note' => $swiezy->source_note,
                    'family_since_year' => $swiezy->family_since_year,
                    'source_scan_media_id' => $swiezy->source_scan_media_id,
                ],
                ingredients: $wynik['skladniki'],
                steps: $wynik['kroki'],
                publish: false,
                existing: $swiezy,
            );

            return true;
        });

        if (! $zapisano) {
            $this->zakoncz($zlecenie, ImportPrzepisu::KOD_SZKIC_ZMIENIONY);

            return;
        }

        $zlecenie->forceFill([
            'status' => ImportPrzepisu::STATUS_GOTOWY,
            'kod_bledu' => null,
            'zakonczono_at' => now(),
        ])->save();
    }

    private function rozlicz(ImportPrzepisu $zlecenie, BudzetAi $budzet, Rezerwacja $rezerwacja, ?OdpowiedzModelu $odpowiedz): void
    {
        $cennik = Cennik::zKonfiguracji();
        $faktyczny = $odpowiedz !== null && $odpowiedz->maUsage() && $cennik !== null
            ? $cennik->koszt((int) $odpowiedz->tokenyWejscia, (int) $odpowiedz->tokenyWyjscia)
            : null;

        $wydano = $budzet->rozlicz($rezerwacja, $faktyczny);

        $zlecenie->forceFill([
            'koszt_mikrousd' => (int) $zlecenie->koszt_mikrousd + $wydano,
            'tokeny_wejscia' => $odpowiedz?->tokenyWejscia,
            'tokeny_wyjscia' => $odpowiedz?->tokenyWyjscia,
            'odpowiedz_modelu' => $odpowiedz?->surowa,
            // Rezerwacja rozliczona — kolejna próba zarezerwuje od nowa.
            'rezerwacja_mikrousd' => null,
            'rezerwacja_dzien' => null,
        ])->save();
    }

    private function bezRezerwacji(ImportPrzepisu $zlecenie): void
    {
        $zlecenie->forceFill(['rezerwacja_mikrousd' => null, 'rezerwacja_dzien' => null])->save();
    }

    private function zakoncz(ImportPrzepisu $zlecenie, string $kod, string $status = ImportPrzepisu::STATUS_NIEUDANY): void
    {
        $zlecenie->forceFill([
            'status' => $status,
            'kod_bledu' => $kod,
            'zakonczono_at' => now(),
        ])->save();
    }

    private function wroci(): bool
    {
        return $this->job !== null && ! $this->job instanceof SyncJob;
    }

    private function opoznienie(int $proba, ModelChwilowoNiedostepny $awaria): int
    {
        $podstawa = self::OPOZNIENIA[min(max(1, $proba), count(self::OPOZNIENIA)) - 1];
        $podstawa = max($podstawa, min(600, $awaria->ponowZaSekund ?? 0));

        return $podstawa + random_int(0, intdiv($podstawa, 5));
    }
}
