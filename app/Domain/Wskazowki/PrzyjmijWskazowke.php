<?php

declare(strict_types=1);

namespace App\Domain\Wskazowki;

use App\Domain\Social\ZamekPary;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * „Zgadzam się" (#2352, D-333): kucharz zgadza się, by jego uwaga z wykonania
 * stała przy przepisie jako wskazówka. Od tej chwili widzą ją wszyscy, którzy
 * widzą przepis i to wykonanie — dokładnie tak jak dotąd widzieli uwagę w
 * galerii „Komu wyszło", tylko wyróżnioną.
 *
 * Zamki jak przy prośbie (`ZamekPary`, potem wiersz wskazówki): blokada
 * założona w tej samej chwili albo wygrywa (odmowa pod zamkiem), albo
 * przychodzi po zgodzie i zasłania wskazówkę widzowi (`CookedEvent::widoczneDla`).
 * Wykonania ani przepisu NIE blokujemy — usunięcie wykonania kasuje wiersz
 * wskazówki kaskadą, a blokada na wykonaniu obok blokady na wskazówce
 * zakleszczyłaby się z tym usunięciem.
 *
 * Idempotentne: drugie kliknięcie „Zgadzam się" (w tej grupie norma) nic nie
 * zmienia i nie jest błędem. Prośba już odrzucona albo wycofana nie wraca.
 */
final class PrzyjmijWskazowke
{
    public const NIEAKTUALNE = 'Ta prośba jest już nieaktualna. Jeśli to wykonanie wciąż Cię dotyczy, autor przepisu może poprosić o wskazówkę innym razem.';

    public function handle(User $kucharz, RecipeHint $wskazowka, ?string $ip = null): RecipeHint
    {
        $autor = User::query()->whereKey($wskazowka->author_id)->first()
            ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

        return ZamekPary::zablokuj($kucharz, $autor, function (?User $swiezyKucharz, ?User $swiezyAutor) use ($wskazowka, $ip): RecipeHint {
            if ($swiezyKucharz === null || $swiezyAutor === null) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            $swieza = RecipeHint::query()->whereKey($wskazowka->getKey())->lockForUpdate()->first()
                ?? throw new BladDlaCzlowieka(self::NIEAKTUALNE);

            if ($swieza->cook_id !== $swiezyKucharz->getKey()) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            // Drugie kliknięcie tej samej osoby — zgoda już jest.
            if ($swieza->jestPrzyjeta()) {
                return $swieza;
            }

            $swieza->setRelation('author', $swiezyAutor);
            // Przepis skasowany (soft delete) nie wraca → `null` → Policy odmawia.
            $swieza->setRelation('recipe', Recipe::query()->whereKey($swieza->recipe_id)->first());

            if (Gate::forUser($swiezyKucharz)->denies('accept', $swieza)) {
                throw new BladDlaCzlowieka(self::NIEAKTUALNE);
            }

            $swieza->przyjmij();

            AuditLogEntry::record(
                action: 'recipe_hint.accepted',
                actor: $swiezyKucharz,
                subject: $swieza,
                metadata: ['recipe_id' => $swieza->recipe_id, 'cooked_event_id' => $swieza->cooked_event_id],
                ip: $ip,
            );

            return $swieza;
        });
    }
}
