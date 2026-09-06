<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Kasowanie zdjęcia razem z plikami — ale tylko wtedy, gdy nic go nie używa.
 *
 * PO CO OSOBNA KLASA
 * Kasowanie pliku jest nieodwracalne, a odpowiedź na pytanie „czy ktoś tego
 * jeszcze używa" wymaga znajomości WSZYSTKICH miejsc, które mogą wskazywać
 * na `media`. Ta wiedza musi żyć w jednym miejscu: dwie kopie listy odwołań
 * to dwie okazje do rozjazdu, a każdy rozjazd znaczy skasowane cudze zdjęcie.
 *
 * Korzystają z tego dwie różne rzeczy: sprzątanie zdjęć nieprzypiętych
 * do niczego (audyt C1) i wymazywanie konta po karencji (issue #93).
 */
final class KasujZdjecie
{
    /**
     * Tabele i kolumny, które mogą wskazywać na `media`.
     *
     * LISTA MUSI BYĆ PEŁNA, I TO JEST JEJ JEDYNE RYZYKO.
     * Pominięcie jednej kolumny znaczy kasowanie zdjęć, które ktoś ma
     * przypięte do przepisu albo do awatara — a pliku nie da się przywrócić.
     * Dlatego towarzyszy jej test, który czyta klucze obce ze schematu bazy
     * i pada, gdy pojawi się kolumna wskazująca na `media`, której tu nie ma.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const ODWOLANIA = [
        ['post_media', 'media_id'],
        ['cooked_event_media', 'media_id'],
        ['profiles', 'avatar_media_id'],
        ['recipes', 'hero_media_id'],
        ['recipes', 'source_scan_media_id'],
        ['recipe_steps', 'media_id'],
    ];

    /**
     * Kasuje zdjęcie i jego pliki, jeśli nic już na nie nie wskazuje.
     *
     * @return bool czy faktycznie skasowano
     */
    public function jesliNieuzywane(Media $zdjecie): bool
    {
        if ($this->jestUzywane($zdjecie)) {
            return false;
        }

        // Pliki PRZED wierszem. Wiersz bez plików da się jeszcze zauważyć
        // i posprzątać; pliki bez wiersza są dla całej aplikacji niewidoczne
        // i zostają na dysku na zawsze.
        $this->skasujPliki($zdjecie);
        $zdjecie->delete();

        return true;
    }

    public function jestUzywane(Media $zdjecie): bool
    {
        foreach (self::ODWOLANIA as [$tabela, $kolumna]) {
            if (DB::table($tabela)->where($kolumna, $zdjecie->getKey())->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Oryginał i wszystkie warianty. */
    public function skasujPliki(Media $zdjecie): void
    {
        // Dysk BIERZEMY Z WIERSZA, a nie z konfiguracji. Zdjęcie wgrane przed
        // przejściem na R2 ma w kolumnie `disk` starą wartość i tam fizycznie
        // leży — sięgnięcie po `config()` szukałoby go na nowym dysku, nie
        // znalazłoby i po cichu zostawiło plik na zawsze.
        $dysk = Storage::disk($zdjecie->disk);

        // WARIANTY MOGĄ LEŻEĆ NA INNYM DYSKU NIŻ ORYGINAŁ (audyt G-01).
        // Kasowanie ich z dysku oryginałów kończyłoby się cichym niczym:
        // `delete()` na nieistniejącym kluczu nie jest błędem, a publiczne
        // kopie zostawałyby w buckecie za CDN-em na zawsze — także po
        // wymazaniu konta.
        $dyskWariantow = Storage::disk($zdjecie->variantsDisk());

        foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $wariant) {
            if (is_array($wariant) && isset($wariant['key'])) {
                $dyskWariantow->delete((string) $wariant['key']);
            }
        }

        if ($zdjecie->object_key !== null) {
            $dysk->delete($zdjecie->object_key);
        }
    }
}
