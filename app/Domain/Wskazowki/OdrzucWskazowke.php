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
 * „Nie" (#2352, D-333): kucharz odmawia. Odpowiedź jest OSTATECZNA — autor
 * nie może poprosić o to samo wykonanie drugi raz (unikalność w bazie), a
 * odmowa nie powiadamia nikogo, żeby nie była naciskiem ani wiadomością
 * „ktoś Ci odmówił". Dla autora prośba po prostu przestaje być widoczna.
 *
 * Odmowa zostaje kucharzowi także przy blokadzie i zawieszeniu: niczego nie
 * publikuje, więc nie ma powodu jej odbierać. Idempotentna.
 *
 * KOLEJNOŚĆ ZAMKÓW jak w `PrzyjmijWskazowke` i `ZaproponujWskazowke`: najpierw
 * oba konta (`ZamekPary`), potem wiersz wskazówki. Odmowa zapisuje wpis w
 * `audit_log` (klucz obcy do konta kucharza = współdzielona blokada wiersza
 * `users`), więc gdyby brała wiersz wskazówki PRZED kontami, „Zgadzam się"
 * (konta → wskazówka) zakleszczyłoby się z nią (wskazówka → konto). Złapane
 * testem na dwóch połączeniach (`tests/Dwa/WskazowkiNaDwochPolaczeniachTest`).
 */
final class OdrzucWskazowke
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

            // Drugie kliknięcie „Nie" — odpowiedź już jest.
            if ($swieza->status === RecipeHint::STATUS_DECLINED) {
                return $swieza;
            }

            if (Gate::forUser($swiezyKucharz)->denies('decline', $swieza)) {
                throw new BladDlaCzlowieka(PrzyjmijWskazowke::NIEAKTUALNE);
            }

            $swieza->odrzuc();

            AuditLogEntry::record(
                action: 'recipe_hint.declined',
                actor: $swiezyKucharz,
                subject: $swieza,
                metadata: ['recipe_id' => $swieza->recipe_id, 'cooked_event_id' => $swieza->cooked_event_id],
                ip: $ip,
            );

            return $swieza;
        });
    }
}
