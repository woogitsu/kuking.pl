<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Compliance\UsuwanieWPartiach;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Retencja `importy_przepisow` (D-298).
 *
 *  - `odpowiedz_modelu` → `NULL` po 30 dniach. Surowa odpowiedź służy tylko
 *    diagnozie błędów odczytu i może zawierać tekst z czyjejś kartki.
 *  - wiersz znika po 90 dniach. Wiersz nie niesie treści przepisu — ta żyje
 *    w szkicu i znika razem z nim.
 *
 * KSIĘGA REZERWACJI (`ai_rezerwacje`): wiersz ZAMKNIĘTY (rozliczony albo
 * zwolniony) znika po tych samych 90 dniach. Otwartych retencja nie rusza —
 * domyka je `kuking:odzyskaj-importy`, a skasowanie otwartej zostawiłoby
 * jej kwotę w `zarezerwowano_mikrousd` na zawsze.
 *
 * JEDEN WYJĄTEK OD 90 DNI: zlecenie, którego szkic jest NADAL SZKICEM.
 * Wiersz zlecenia jest bramką publikacji (`BramkaPublikacjiOdczytu`): bez
 * niego szkic z odczytu dałoby się opublikować bez „Sprawdziłem odczytany
 * tekst”. Taki wiersz (już bez surowej odpowiedzi) zostaje, dopóki szkic
 * nie zostanie opublikowany albo usunięty — potem znika najbliższym
 * przebiegiem.
 */
final class PrzedawnioneImporty
{
    /** @return array{odpowiedzi: int, wiersze: int, rezerwacje: int} */
    public function posprzataj(bool $naSucho = false): array
    {
        $odpowiedzi = ImportPrzepisu::query()
            ->whereNotNull('odpowiedz_modelu')
            ->where('created_at', '<', now()->subDays(max(1, (int) config('kuking.import.retencja.odpowiedz_dni'))));

        $ileOdpowiedzi = $naSucho ? $odpowiedzi->count() : $odpowiedzi->update(['odpowiedz_modelu' => null]);

        $kandydaci = fn (): Builder => ImportPrzepisu::query()
            ->where('created_at', '<', now()->subDays(max(1, (int) config('kuking.import.retencja.wiersz_dni'))))
            ->where(fn (Builder $q) => $q->whereNull('recipe_id')
                ->orWhereNotExists(fn ($s) => $s->selectRaw('1')->from('recipes')
                    ->whereColumn('recipes.id', 'importy_przepisow.recipe_id')
                    ->where('recipes.status', Recipe::STATUS_DRAFT)));

        $ileWierszy = $naSucho
            ? $kandydaci()->count()
            : UsuwanieWPartiach::zKonfiguracji()->usun(fn () => $kandydaci()->toBase(), 'id', 'importy_przepisow');

        $rezerwacje = fn () => DB::table('ai_rezerwacje')
            ->whereIn('stan', [BudzetAi::STAN_ROZLICZONA, BudzetAi::STAN_ZWOLNIONA])
            ->where('created_at', '<', now()->subDays(max(1, (int) config('kuking.import.retencja.wiersz_dni'))));

        $ileRezerwacji = $naSucho
            ? $rezerwacje()->count()
            : UsuwanieWPartiach::zKonfiguracji()->usun($rezerwacje, 'id', 'ai_rezerwacje');

        return ['odpowiedzi' => $ileOdpowiedzi, 'wiersze' => $ileWierszy, 'rezerwacje' => $ileRezerwacji];
    }
}
