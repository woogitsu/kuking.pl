<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Models\Media;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Zdjęcia, które wolno TERAZ przypiąć — wybrane POD BLOKADĄ wiersza (D-083).
 *
 * PO CO TO ISTNIEJE
 * Przypinanie zdjęcia i sprzątanie zdjęć osieroconych to dwie poprawne
 * operacje na tym samym wierszu `media`, które do issue #285 nie miały ani
 * jednej wspólnej sekcji krytycznej. Publikacja wybierała `media_id` PRZED
 * transakcją i już nigdy do tego wyboru nie wracała; sprzątacz pytał
 * „czy używane" zwykłym `SELECT`-em. Między jedno a drugie mieściło się
 * całe kasowanie plików w R2 — a `post_media.media_id` ma `ON DELETE
 * CASCADE`, więc świeżo wstawione powiązanie znikało po cichu, bez błędu
 * i bez śladu, razem z jedynym egzemplarzem zdjęcia.
 *
 * CO DAJE BLOKADA
 * `SELECT … FOR UPDATE` na wierszu `media` zderza się z blokadą `FOR KEY
 * SHARE`, którą PostgreSQL bierze sam przy sprawdzaniu klucza obcego przy
 * `INSERT`-cie do `post_media`. Dzięki temu przypięcie i przejęcie zdjęcia
 * do skasowania ustawiają się w kolejkę zamiast się mijać — kto przyjdzie
 * drugi, ZOBACZY skutek pierwszego, zamiast działać na obrazie sprzed
 * jego commitu.
 *
 * BLOKADA TO ZA MAŁO — POTRZEBNA JEST REWALIDACJA POD NIĄ (D-079 §3).
 * Blokada serializuje, ale nie mówi żądaniu, że świat zmienił się, gdy ono
 * czekało. Dlatego warunek `owner_id` i `status` stoi w TYM SAMYM zapytaniu,
 * co `lockForUpdate()` — jest sprawdzany dopiero po jej uzyskaniu, a nie
 * przepisany z odczytu sprzed czekania.
 *
 * DETERMINISTYCZNA KOLEJNOŚĆ
 * `ORDER BY id`: dwa równoległe wysłania formularza z częściowo wspólnym
 * zestawem zdjęć blokują wiersze w tej samej kolejności i nie zakleszczą się
 * nawzajem. Bez tego jedno wzięłoby A i czekało na B, a drugie odwrotnie.
 */
final class ZdjeciaDoPrzypiecia
{
    /**
     * Identyfikatory zdjęć tej osoby, zablokowane do końca BIEŻĄCEJ
     * transakcji.
     *
     * Kolejność wyniku jest kolejnością blokowania (rosnąco po id), a nie
     * kolejnością wybraną przez człowieka w formularzu — o tę drugą dba
     * wywołujący, bo tylko on wie, co znaczy „pierwsze zdjęcie".
     *
     * @param  list<string>  $mediaIds
     * @return list<string>
     */
    public static function zablokuj(string $wlascicielId, array $mediaIds): array
    {
        if ($mediaIds === []) {
            return [];
        }

        // Blokada wiersza żyje wyłącznie w transakcji. Wywołanie poza nią
        // zwróciłoby ten sam wynik co zwykły `SELECT` i cicho przywróciło
        // dokładnie ten wyścig, który ta klasa zamyka — więc niech pada
        // głośno, przy pierwszym uruchomieniu testów, a nie w produkcji.
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'ZdjeciaDoPrzypiecia::zablokuj() wymaga otwartej transakcji — bez niej blokada wiersza nic nie trzyma.',
            );
        }

        /** @var list<string> $zablokowane */
        $zablokowane = array_values(Media::query()
            ->where('owner_id', $wlascicielId)
            ->whereIn('id', $mediaIds)
            ->where('status', '!=', Media::STATUS_DELETED)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->all());

        return $zablokowane;
    }
}
