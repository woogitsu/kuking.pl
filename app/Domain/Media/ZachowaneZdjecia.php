<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Zdjęcia, które przetrwały nieudaną walidację formularza (audyt C1),
 * W KOLEJNOŚCI, W JAKIEJ PRZYSZŁY W `media_ids[]` (issue #934).
 *
 * DLACZEGO KOLEJNOŚĆ Z LISTY, NIE Z BAZY
 * `whereIn(...)` bez `ORDER BY` oddaje wiersze w kolejności planu wykonania.
 * Pierwsze zdjęcie otwiera układ, karuzelę i kolaż, więc błąd w zupełnie innym
 * polu (za długi opis) mógłby po cichu przestawić opowieść zdjęciową — mimo
 * komunikatu „Twoje zdjęcia są zachowane". Kolejność to też wpisana dana.
 *
 * JEDNA REGUŁA DLA KONTROLERA I OBU WIDOKÓW
 * Kontroler buduje z tego `old('media_ids')`, a formularze renderują ukryte
 * pola. Dwie osobne kwerendy to dwie okazje do rozjazdu — dlatego ta sama
 * bramka (właściciel + nieprzypięte) i ten sam porządek są tylko tutaj.
 * UUID w formularzu to nie autoryzacja (AGENTS.md §7).
 */
final class ZachowaneZdjecia
{
    /**
     * @return Collection<int, Media>
     */
    public static function wKolejnosci(mixed $mediaIds, int|string|null $ownerId): Collection
    {
        if ($ownerId === null) {
            return collect();
        }

        // Powtórzenia i śmieci odpadają tu, zanim trafią do kwerendy: kolumna
        // `id` jest typu uuid i PostgreSQL odrzuciłby cały SELECT.
        $ids = collect((array) $mediaIds)
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            // PostgreSQL oddaje uuid małymi literami; bez tego wielkie litery
            // z formularza nie trafiłyby w mapę poniżej.
            ->map(fn (string $id): string => strtolower($id))
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $dozwolone = Media::query()
            ->whereIn('id', $ids->all())
            ->where('owner_id', $ownerId)
            ->whereDoesntHave('posts')
            ->get()
            ->keyBy(fn (Media $media): string => (string) $media->getKey());

        // Przejście po LIŚCIE WEJŚCIOWEJ, nie po wyniku kwerendy. Odrzucenie
        // jednego identyfikatora nie przestawia pozostałych.
        return $ids
            ->map(fn (string $id): ?Media => $dozwolone->get($id))
            ->filter()
            ->values();
    }

    /**
     * @return list<string>
     */
    public static function identyfikatory(mixed $mediaIds, int|string|null $ownerId): array
    {
        return self::wKolejnosci($mediaIds, $ownerId)
            ->map(fn (Media $media): string => (string) $media->getKey())
            ->all();
    }
}
