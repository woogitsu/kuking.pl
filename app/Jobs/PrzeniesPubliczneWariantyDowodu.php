<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Zdjęcie zabezpieczone jako dowód przestaje być publiczne (ścieżka CSAM,
 * D-333, 1.10.2026).
 *
 * `ZabezpieczDowodCsam` zmienia status na `secured`, co zatrzymuje serwowanie
 * przez aplikację — ale warianty (miniatura, podgląd, feed, large) leżą
 * w PUBLICZNYM buckecie i do czasu tego zadania mogły być dostępne pod
 * adresem pliku albo w cache CDN. To zadanie, po zatwierdzeniu zabezpieczenia:
 *
 *  1. PRZENOSI każdy wariant do prywatnego dysku oryginału
 *     (`zabezpieczone/{id zdjęcia}/{nazwa pliku}`) — kopia, sprawdzenie, że
 *     jest, dopiero potem usunięcie z publicznego dysku. NIC nie jest kasowane
 *     bez kopii: to dowód. Oryginał nie jest ruszany.
 *  2. Zapisuje mapę „dawny klucz → nowy klucz” w `metadata`; `metadata.variants`
 *     zostaje, więc przywrócenie po decyzji prawnika jest możliwe.
 *  3. Zleca `PurgePublicMediaCache` dla adresów wariantów (adres pliku i trasa
 *     aplikacji) — przeniesienie pliku nie czyści CDN.
 *
 * IDEMPOTENTNE i bezpieczne do ponowienia: wariant, którego nie ma już na
 * dysku publicznym, jest pomijany; kopia już istniejąca w prywatnym magazynie
 * nie jest zapisywana drugi raz. Porażka rzuca wyjątek, kolejka ponawia.
 *
 * Gdy dysk wariantów to ten sam dysk co oryginałów (lokalnie, testy, stare
 * wiersze) nie ma osobnego, prywatnego miejsca — przenoszenie jest wtedy
 * pomijane, a czyszczenie CDN zostaje.
 */
class PrzeniesPubliczneWariantyDowodu implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /** @param list<string> $mediaIds zdjęcia o statusie `secured` */
    public function __construct(public array $mediaIds)
    {
        $this->onQueue('media');
    }

    public function handle(): void
    {
        $adresy = [];
        $niepowodzenia = 0;

        foreach (Media::query()->whereKey($this->mediaIds)->orderBy('id')->get() as $zdjecie) {
            // Tylko zdjęcie nadal zabezpieczone; inny stan to nie nasza sprawa.
            if ($zdjecie->status !== Media::STATUS_SECURED) {
                continue;
            }

            try {
                $this->przenies($zdjecie, $adresy);
            } catch (Throwable $e) {
                $niepowodzenia++;

                Log::error('Nie udało się przenieść publicznych wariantów zabezpieczonego zdjęcia', [
                    'media_id' => $zdjecie->getKey(),
                    'error' => BezpiecznyBlad::kontekst($e),
                ]);
            }
        }

        // Adresy czyścimy NAWET przy częściowej porażce: te, których plik
        // zniknął z publicznego dysku, mają prawo zniknąć z CDN.
        $adresy = array_values(array_unique(array_filter($adresy)));

        if ($adresy !== []) {
            PurgePublicMediaCache::dispatch($adresy);
        }

        if ($niepowodzenia > 0) {
            throw new \RuntimeException('Nie wszystkie warianty zabezpieczonych zdjęć udało się przenieść.');
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::critical('Publiczne warianty zabezpieczonego zdjęcia mogą nadal leżeć w publicznym buckecie', [
            'media_ids' => $this->mediaIds,
            'error' => $e === null ? null : BezpiecznyBlad::kontekst($e),
        ]);
    }

    /**
     * @param  list<string|null>  $adresy  adresy do wyczyszczenia w CDN — dopisywane
     *                                     PRZED przenoszeniem, więc zostają także wtedy, gdy przenoszenie rzuci wyjątek
     */
    private function przenies(Media $zdjecie, array &$adresy): void
    {
        $prywatny = $zdjecie->disk;
        $publiczne = array_values(array_unique(array_filter([$zdjecie->variantsDisk(), $this->legacy()])));

        $stara = (array) ($zdjecie->metadata[Media::METADANE_WARIANTY_ZABEZPIECZONE] ?? []);
        $mapa = $stara;

        foreach ($this->klucze($zdjecie) as $nazwa => $klucz) {
            foreach ($publiczne as $nazwaDysku) {
                $adresy[] = $this->adresPliku(Storage::disk($nazwaDysku), $klucz);
            }

            if (is_string($nazwa)) {
                $adresy[] = $this->adresTrasy($zdjecie, $nazwa);
            }

            foreach ($publiczne as $nazwaDysku) {
                // Ten sam dysk co oryginał: nie ma gdzie przenieść, nie ruszamy.
                if ($nazwaDysku === $prywatny) {
                    continue;
                }

                $nowy = $this->przeniesJeden($zdjecie, Storage::disk($nazwaDysku), Storage::disk($prywatny), $klucz);

                if ($nowy !== null) {
                    $mapa[$klucz] = $nowy;
                }
            }
        }

        if ($mapa !== $stara) {
            DB::transaction(function () use ($zdjecie, $mapa): void {
                $swieze = Media::query()->whereKey($zdjecie->getKey())->lockForUpdate()->first();

                if ($swieze === null) {
                    return;
                }

                $swieze->update(['metadata' => array_merge($swieze->metadata ?? [], [
                    Media::METADANE_WARIANTY_ZABEZPIECZONE => $mapa,
                ])]);
            });
        }
    }

    /**
     * Kopiuje i dopiero po sprawdzeniu usuwa. `null`, gdy nigdzie nic nie leżało.
     */
    private function przeniesJeden(Media $zdjecie, Filesystem $zrodlo, Filesystem $cel, string $klucz): ?string
    {
        $nowy = 'zabezpieczone/'.$zdjecie->getKey().'/'.basename($klucz);

        if (! $zrodlo->exists($klucz)) {
            return $cel->exists($nowy) ? $nowy : null;
        }

        if (! $cel->exists($nowy)) {
            $tresc = $zrodlo->get($klucz);

            if ($tresc === null || $cel->put($nowy, $tresc) === false || ! $cel->exists($nowy)) {
                throw new \RuntimeException('Nie udało się zapisać kopii wariantu w prywatnym magazynie.');
            }
        }

        $zrodlo->delete($klucz);

        if ($zrodlo->exists($klucz)) {
            throw new \RuntimeException('Wariant nadal leży na dysku publicznym po próbie usunięcia.');
        }

        return $nowy;
    }

    /**
     * Klucze wariantów: nazwa => klucz dla wariantów z metadanych oraz
     * kolejne liczby dla kluczy „w trakcie” (zadanie przetwarzania przerwane
     * w połowie zostawia pliki, których nie ma w `variants`).
     *
     * @return array<int|string, string>
     */
    private function klucze(Media $zdjecie): array
    {
        $klucze = [];

        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $nazwa => $wariant) {
            if (is_array($wariant) && isset($wariant['key']) && is_string($wariant['key'])) {
                $klucze[(string) $nazwa] = $wariant['key'];
            }
        }

        foreach ((array) ($zdjecie->metadata[Media::METADANE_WARIANTY_W_TRAKCIE] ?? []) as $klucz) {
            if (is_string($klucz) && $klucz !== '' && ! in_array($klucz, $klucze, true)) {
                $klucze[] = $klucz;
            }
        }

        return $klucze;
    }

    private function legacy(): ?string
    {
        return (string) config('filesystems.disks.r2_legacy.bucket') === '' ? null : 'r2_legacy';
    }

    private function adresPliku(Filesystem $dysk, string $klucz): ?string
    {
        try {
            /** @phpstan-ignore method.notFound */
            return $dysk->url($klucz);
        } catch (Throwable) {
            return null;
        }
    }

    private function adresTrasy(Media $zdjecie, string $wariant): ?string
    {
        try {
            return route('media.show', ['media' => $zdjecie->getKey(), 'wariant' => $wariant]);
        } catch (Throwable) {
            return null;
        }
    }
}
