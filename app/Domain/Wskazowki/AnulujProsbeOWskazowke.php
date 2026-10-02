<?php

declare(strict_types=1);

namespace App\Domain\Wskazowki;

use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * „Anuluj prośbę" (#2352, decyzja właściciela z 1.10.2026): autor przepisu
 * wycofuje własną CZEKAJĄCĄ prośbę o wskazówkę. Kucharz nie dostaje żadnej
 * wiadomości; na stronie swojego wykonania widzi, że prośba została wycofana.
 *
 * Anulowana prośba zwalnia miejsce w limicie przepisu (limit liczy tylko
 * przyjęte i czekające), ale NIE odnawia prawa do ponownej prośby o to samo
 * wykonanie: unikalność `cooked_event_id` zostaje (patrz D-333 — anulowanie
 * jest końcem jak „Nie" i wycofanie zgody, żeby anuluj-i-poproś-znowu nie
 * stało się sposobem na ponawianie próśb do tej samej osoby).
 *
 * KOLEJNOŚĆ ZAMKÓW jak w pozostałych akcjach modułu: najpierw oba konta
 * (`ZamekPary`), potem wiersz wskazówki. Dzięki temu anulowanie i „Zgadzam
 * się" ustawiają się w kolejce na tym samym wierszu: wygrywa pierwsze, a
 * drugie widzi nowy stan pod blokadą (po zgodzie anulować już nie można, po
 * anulowaniu zgodzić się już nie można). Idempotentne.
 */
final class AnulujProsbeOWskazowke
{
    /** Jedno zdanie bez powodu: „Nie” kucharza nie wolno zdradzać autorowi. */
    public const NIEAKTUALNE = 'Tej prośby nie można już anulować.';

    public function handle(User $autor, RecipeHint $wskazowka, ?string $ip = null): RecipeHint
    {
        $kucharz = User::query()->whereKey($wskazowka->cook_id)->first()
            ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

        return ZamekPary::zablokuj($autor, $kucharz, function (?User $swiezyAutor, ?User $swiezyKucharz) use ($wskazowka, $ip): RecipeHint {
            if ($swiezyAutor === null || $swiezyKucharz === null) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            $swieza = RecipeHint::query()->whereKey($wskazowka->getKey())->lockForUpdate()->first()
                ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

            if ($swieza->author_id !== $swiezyAutor->getKey()) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // Drugie kliknięcie albo anulowanie z drugiej karty — już anulowana.
            if ($swieza->jestAnulowana()) {
                return $swieza;
            }

            if (Gate::forUser($swiezyAutor)->denies('cancel', $swieza)) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            $swieza->anuluj();

            AuditLogEntry::record(
                action: 'recipe_hint.cancelled',
                actor: $swiezyAutor,
                subject: $swieza,
                metadata: ['recipe_id' => $swieza->recipe_id, 'cooked_event_id' => $swieza->cooked_event_id],
                ip: $ip,
            );

            return $swieza;
        });
    }
}
