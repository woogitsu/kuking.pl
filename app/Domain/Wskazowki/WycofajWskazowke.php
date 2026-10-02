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
 * „Wycofaj zgodę" (#2352, D-333): kucharz w każdej chwili odbiera zgodę i
 * wskazówka znika ze strony przepisu. Wycofanie jest ostateczne (autor nie
 * prosi drugi raz o to samo wykonanie) i nie powiadamia nikogo.
 *
 * Zostaje kucharzowi także przy blokadzie z autorem i przy zawieszeniu konta:
 * wycofanie zgody musi być zawsze możliwe (RODO art. 7 ust. 3). Wiersz
 * wskazówki zostaje w bazie jako ślad decyzji (żeby prośba nie wróciła);
 * tekst uwagi należy do wykonania kucharza i jego los zależy od niego, nie
 * od tej tabeli. Idempotentne i wyścigowo-bezpieczne: równoległe wycofanie i
 * drugie wycofanie ustawiają się w kolejce na wierszu.
 *
 * KOLEJNOŚĆ ZAMKÓW: najpierw oba konta (`ZamekPary`), potem wiersz wskazówki —
 * jak w pozostałych akcjach tego modułu (patrz `OdrzucWskazowke`).
 */
final class WycofajWskazowke
{
    public function handle(User $kucharz, RecipeHint $wskazowka, ?string $ip = null): RecipeHint
    {
        $autor = User::query()->whereKey($wskazowka->author_id)->first()
            ?? throw new BladDlaCzlowieka(PrzyjmijWskazowke::NIEAKTUALNE);

        return ZamekPary::zablokuj($kucharz, $autor, function (?User $swiezyKucharz) use ($wskazowka, $ip): RecipeHint {
            if ($swiezyKucharz === null) {
                throw new BladDlaCzlowieka(PrzyjmijWskazowke::NIEAKTUALNE);
            }

            $swieza = RecipeHint::query()->whereKey($wskazowka->getKey())->lockForUpdate()->first()
                ?? throw new BladDlaCzlowieka(PrzyjmijWskazowke::NIEAKTUALNE);

            if ($swieza->cook_id !== $swiezyKucharz->getKey()) {
                throw new BladDlaCzlowieka(PrzyjmijWskazowke::NIEAKTUALNE);
            }

            // Drugie kliknięcie albo wycofanie z drugiej karty — zgody już nie ma.
            if ($swieza->status === RecipeHint::STATUS_WITHDRAWN) {
                return $swieza;
            }

            if (Gate::forUser($swiezyKucharz)->denies('withdraw', $swieza)) {
                throw new BladDlaCzlowieka(PrzyjmijWskazowke::NIEAKTUALNE);
            }

            $swieza->wycofaj();

            AuditLogEntry::record(
                action: 'recipe_hint.withdrawn',
                actor: $swiezyKucharz,
                subject: $swieza,
                metadata: ['recipe_id' => $swieza->recipe_id, 'cooked_event_id' => $swieza->cooked_event_id],
                ip: $ip,
            );

            return $swieza;
        });
    }
}
