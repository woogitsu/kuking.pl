<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Przyjęcie żądania usunięcia konta (RODO art. 17, D-022) — TRZY ZAPISY,
 * JEDNA TRANSAKCJA.
 *
 *  1. `users`: `pending_delete` z wybranym zakresem i datą zgłoszenia,
 *  2. `potwierdzenia_zadan_rodo`: sprawa `w_toku`
 *     (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`),
 *  3. `audit_log`: `account.delete_requested` z zakresem.
 *
 * DZIENNIK W TEJ SAMEJ TRANSAKCJI (#1347, D-249 klasa 1). Wpis stał za
 * zatwierdzeniem: jego awaria dawała 500 przy koncie już oznaczonym do
 * usunięcia, bez wylogowania i bez komunikatu o karencji, a ponowienie
 * odbijało się od „to konto jest już oznaczone". Ten wpis jest jedynym
 * trwałym zapisem, CO człowiek wybrał (`NIGDY_NIE_KASUJ`): sprawa w rejestrze
 * ma `zakres = NULL` do wykonania, a `cancelDeletion()` zeruje
 * `delete_scope`. Bez niego nie zostaje pełny ślad — więc pada razem z resztą.
 * Awaria cofa wszystko, konto działa jak dotąd, a ponowienie daje jeden komplet.
 *
 * DRUGIE RÓWNOLEGŁE ŻĄDANIE (#1346). Rozstrzyga świeży wiersz konta pod
 * `ZamekKonta` w `markForDeletion()` (#980): drugie dostaje `BladDlaCzlowieka`
 * i nie nadpisuje zakresu ani daty pierwszego. Indeks częściowy
 * `potwierdzenia_zadan_rodo_jedna_w_toku_na_konto` jest drugą warstwą — w bazie.
 */
final class PrzyjmijZadanieUsunieciaKonta
{
    public function __construct(private readonly RejestrPotwierdzenRodo $rejestr = new RejestrPotwierdzenRodo) {}

    /**
     * @param  User::DELETE_SCOPE_*  $zakres
     *
     * @throws BladDlaCzlowieka konto już jest w usuwaniu
     * @throws OdmowaOstatniegoAdministratora
     */
    public function handle(User $user, string $zakres, ?string $ip = null): void
    {
        // `markForDeletion()` przepisuje świeży wiersz do `$user`. Po
        // wycofaniu transakcji model ma znów mówić to, co baza — inaczej
        // reszta żądania widziałaby `pending_delete`, którego nie ma.
        $przed = $user->getAttributes();

        try {
            DB::transaction(function () use ($user, $zakres, $ip): void {
                $user->markForDeletion($zakres);

                $this->rejestr->przyjmijZadanieUsunieciaKonta($user);

                AuditLogEntry::record(
                    'account.delete_requested',
                    $user,
                    $user,
                    metadata: ['zakres' => $zakres],
                    ip: $ip,
                );
            });
        } catch (Throwable $e) {
            $user->setRawAttributes($przed, true);

            throw $e;
        }
    }
}
