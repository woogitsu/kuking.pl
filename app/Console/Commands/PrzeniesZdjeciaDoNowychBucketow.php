<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Przeniesienie zdjęć ze starego, jednego bucketu do dwóch nowych (audyt W4-03).
 *
 * PO CO
 * Rozdzielenie oryginałów (prywatny bucket) i wariantów (publiczny) zmieniło
 * to, gdzie ZAPISUJEMY. Nie przeniosło niczego, co już leży. Wiersze sprzed
 * tej zmiany mają `disk = 'r2'` i `variants_disk = NULL`, a ich pliki są
 * w starym, wspólnym buckecie — który w konfiguracji nazywa się teraz
 * `r2_legacy`.
 *
 * Dopóki ta komenda nie przejdzie do końca, jedno i drugie musi działać:
 * stare zdjęcia serwują się ze starego bucketu, nowe z nowych.
 *
 * KOLEJNOŚĆ MA ZNACZENIE I JEST TU CAŁĄ TREŚCIĄ
 * Najpierw kopiujemy, potem SPRAWDZAMY, że kopia istnieje, i dopiero na końcu
 * zmieniamy wiersz. Odwrotna kolejność — wiersz najpierw — dawałaby zdjęcie
 * wskazujące na plik, którego nie ma: znikające z serwisu i nie do odzyskania
 * bez ręcznego grzebania w buckecie.
 *
 * ORYGINAŁÓW NIE KASUJEMY ZE STAREGO BUCKETU. To jest osobna decyzja i osobne
 * uruchomienie: dopóki nie ma pewności, że komplet się przeniósł, stary bucket
 * jest jedyną kopią zapasową. Czyszczenie starego bucketu robi się ręcznie,
 * po sprawdzeniu, że ta komenda nie ma już nic do roboty.
 */
class PrzeniesZdjeciaDoNowychBucketow extends Command
{
    protected $signature = 'kuking:przenies-zdjecia
                            {--dry-run : Pokaż, co zostałoby przeniesione, i nic nie kopiuj}
                            {--limit=200 : Ile zdjęć wziąć w jednym przebiegu}';

    protected $description = 'Kopiuje zdjęcia ze starego bucketu do nowych: oryginały do prywatnego, warianty do publicznego';

    public function handle(): int
    {
        $stary = 'r2_legacy';

        if ((string) config("filesystems.disks.{$stary}.bucket") === '') {
            $this->error(
                'Dysk `r2_legacy` nie ma ustawionego bucketu (AWS_LEGACY_BUCKET). '
                .'Bez niego nie wiadomo, skąd kopiować — a zgadywanie oznacza tu utratę zdjęć.',
            );

            return self::FAILURE;
        }

        $doPrzeniesienia = Media::query()
            ->where('disk', $stary)
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($doPrzeniesienia->isEmpty()) {
            $this->info('Nie ma zdjęć do przeniesienia.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $przeniesione = 0;
        $nieudane = 0;

        foreach ($doPrzeniesienia as $zdjecie) {
            if ($dryRun) {
                $this->line('Do przeniesienia: '.$zdjecie->getKey());

                continue;
            }

            if ($this->przenies($zdjecie, $stary)) {
                $przeniesione++;
                $this->line('Przeniesione: '.$zdjecie->getKey());
            } else {
                $nieudane++;
                $this->line('NIE UDAŁO SIĘ: '.$zdjecie->getKey().' (wiersz bez zmian, spróbuję ponownie)');
            }
        }

        if ($dryRun) {
            $this->info('Tryb podglądu: '.$doPrzeniesienia->count().' zdjęć czeka na przeniesienie.');

            return self::SUCCESS;
        }

        $this->info("Przeniesione: {$przeniesione}. Nieudane: {$nieudane}.");

        // Zostawiamy ślad, ile jeszcze zostało — inaczej po pierwszym przebiegu
        // z limitem łatwo uznać robotę za skończoną.
        $zostalo = Media::query()->where('disk', $stary)->count();

        if ($zostalo > 0) {
            $this->warn("Zostało {$zostalo} zdjęć. Uruchom komendę ponownie.");
        } else {
            $this->info('Komplet przeniesiony. Publiczność starego bucketu można zdjąć DOPIERO teraz.');
        }

        return $nieudane > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Kopiuje pliki jednego zdjęcia i przestawia wiersz — w tej kolejności. */
    private function przenies(Media $zdjecie, string $stary): bool
    {
        $dyskStary = Storage::disk($stary);
        $dyskOryginalow = Storage::disk((string) config('kuking.media.disk'));
        $dyskPubliczny = Storage::disk((string) config('kuking.media.public_disk'));

        try {
            if ($zdjecie->object_key !== null
                && ! $this->skopiuj($dyskStary, $dyskOryginalow, $zdjecie->object_key)) {
                return false;
            }

            foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $wariant) {
                if (! is_array($wariant) || ! isset($wariant['key'])) {
                    continue;
                }

                if (! $this->skopiuj($dyskStary, $dyskPubliczny, (string) $wariant['key'])) {
                    return false;
                }
            }
        } catch (Throwable $e) {
            Log::error('Nie udało się przenieść zdjęcia do nowych bucketów', [
                'media_id' => $zdjecie->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        // DOPIERO TERAZ. Wszystkie pliki są na miejscu i sprawdzone.
        $zdjecie->update([
            'disk' => (string) config('kuking.media.disk'),
            'variants_disk' => (string) config('kuking.media.public_disk'),
        ]);

        return true;
    }

    /**
     * Kopiuje jeden obiekt i potwierdza, że dotarł.
     *
     * Plik, którego w starym buckecie już nie ma, uznajemy za skopiowany:
     * wiersz i tak trzeba przestawić, żeby nie wracał przy każdym przebiegu.
     * Brakujące zdjęcie to osobny problem — i widać go po tym, że wariantu
     * nie ma po żadnej stronie.
     */
    private function skopiuj(
        Filesystem $zrodlo,
        Filesystem $cel,
        string $klucz,
    ): bool {
        if ($cel->exists($klucz)) {
            return true;
        }

        if (! $zrodlo->exists($klucz)) {
            Log::warning('Zdjęcia nie ma w starym buckecie', ['klucz' => $klucz]);

            return true;
        }

        $strumien = $zrodlo->readStream($klucz);

        if ($strumien === null) {
            return false;
        }

        // Strumieniem, nie `get()`: oryginał może mieć 15 MB, a takich zdjęć
        // przenosimy setki w jednym przebiegu.
        $cel->writeStream($klucz, $strumien);

        if (is_resource($strumien)) {
            fclose($strumien);
        }

        // SPRAWDZENIE, NIE ZAŁOŻENIE. Bez niego wiersz zostałby przestawiony
        // na bucket, w którym pliku nie ma — a zdjęcie zniknęłoby z serwisu.
        return $cel->exists($klucz);
    }
}
