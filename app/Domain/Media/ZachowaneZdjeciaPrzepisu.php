<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;
use Illuminate\Support\Str;

/**
 * Zdjęcia formularza przepisu, które przetrwały nieudaną walidację (issue #2050).
 *
 * SKĄD TO SIĘ BIERZE
 * Przeglądarka nie wypełnia `<input type="file">` po `back()->withInput()`.
 * Wpis i „Ugotowałem" rozwiązały to od audytu C1 (#872): zdjęcie trafia do
 * `media` PRZED walidacją reszty, a przez błąd przechodzi jako identyfikator
 * w ukrytym polu. Formularz przepisu (zdjęcie główne, zdjęcie kartki, zdjęcia
 * kroków) ma przejść tą samą drogą — ta klasa jest jej bramką.
 *
 * UUID W UKRYTYM POLU TO NIE AUTORYZACJA (AGENTS.md §7)
 * Identyfikator przychodzi od klienta, więc wolno go przyjąć wyłącznie, gdy:
 *
 *   1. zdjęcie należy do osoby, która wysyła formularz;
 *   2. nic jeszcze na nie nie wskazuje (`KasujZdjecie::ODWOLANIA` — ta sama
 *      lista, po której sprzątacz rozpoznaje zdjęcia osierocone). Zdjęcie
 *      z cudzego wpisu, z własnego „Ugotowałem" albo z innego przepisu nie
 *      zostanie w ten sposób podpięte; zdjęcie z własnego wpisu ma osobną
 *      drogę z Policy (#1334);
 *   3. nie zostało odrzucone ani skasowane;
 *   4. jest świeże — patrz niżej.
 *
 * Kontrola pod blokadą wiersza (`ZdjeciaDoPrzypiecia`) zostaje w `PublishRecipe`
 * bez zmian. Ta klasa rozstrzyga, co formularz w ogóle POKAŻE jako zapamiętane
 * i co przekaże dalej; blokada rozstrzyga wyścig ze sprzątaczem.
 *
 * DLACZEGO WAŻNOŚĆ JEST KRÓTSZA NIŻ KARENCJA SPRZĄTACZA
 * `OsieroconeZdjecia` kasuje nieprzypięte zdjęcie starsze niż doba. Gdyby
 * formularz nadal pokazywał je jako „zapamiętane", człowiek poprawiłby błąd
 * po 25 godzinach, zobaczył „Przepis zapisany" — i przepis bez zdjęcia,
 * bo `ZdjeciaDoPrzypiecia` odfiltrowuje skasowany wiersz po cichu. Zapas
 * kilku godzin sprawia, że zdjęcie przestaje być „zapamiętane", ZANIM
 * sprzątacz ma prawo go dotknąć; formularz może wtedy powiedzieć wprost:
 * wybierz je jeszcze raz.
 *
 * KLUCZE ZOSTAJĄ Z WEJŚCIA
 * Zdjęcia kroków idą pod numerem WIERSZA FORMULARZA (`steps.3.…`), jak pliki
 * w `ZapisPrzepisuRequest::zdjeciaKrokow()`. Odrzucenie jednego wpisu nie
 * przesuwa pozostałych — inaczej zdjęcie „obierz ziemniaki" wylądowałoby
 * przy „wyjmij z piekarnika".
 */
final class ZachowaneZdjeciaPrzepisu
{
    /** Musi zostać wyraźnie krócej niż karencja `OsieroconeZdjecia` (24 h). */
    public const GODZIN_WAZNOSCI = 20;

    /**
     * @param  array<array-key, mixed>  $mediaIds  np. `['hero' => uuid, 'steps.2' => uuid]`
     * @return array<array-key, Media> tylko przyjęte, pod kluczami z wejścia
     */
    public static function przyjete(array $mediaIds, int|string|null $ownerId): array
    {
        if ($ownerId === null) {
            return [];
        }

        // Śmieci odpadają przed kwerendą: kolumna `id` jest typu uuid
        // i PostgreSQL odrzuciłby cały SELECT. Powtórzenie tego samego
        // zdjęcia w drugim miejscu formularza odpada — zostaje pierwsze.
        $ids = [];
        foreach ($mediaIds as $klucz => $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                continue;
            }
            $id = strtolower($id);
            if (! in_array($id, $ids, true)) {
                $ids[$klucz] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $zapytanie = Media::query()
            ->whereIn('id', array_values($ids))
            ->where('owner_id', $ownerId)
            ->whereIn('status', [Media::STATUS_PENDING, Media::STATUS_PROCESSING, Media::STATUS_READY])
            ->where('created_at', '>=', now()->subHours(self::GODZIN_WAZNOSCI));

        foreach (KasujZdjecie::ODWOLANIA as [$tabela, $kolumna]) {
            $zapytanie->whereNotExists(function ($sub) use ($tabela, $kolumna): void {
                $sub->selectRaw('1')->from($tabela)->whereColumn("{$tabela}.{$kolumna}", 'media.id');
            });
        }

        $dozwolone = $zapytanie->get()->keyBy(fn (Media $media): string => (string) $media->getKey());

        $wynik = [];
        foreach ($ids as $klucz => $id) {
            $media = $dozwolone->get($id);
            if ($media instanceof Media) {
                $wynik[$klucz] = $media;
            }
        }

        return $wynik;
    }
}
