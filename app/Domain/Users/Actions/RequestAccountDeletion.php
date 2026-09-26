<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Domain\Users\OdmowaOstatniegoAdministratora;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\AuditLogEntry;
use App\Models\User;

/**
 * Zgłoszenie usunięcia konta (RODO art. 17, D-022) — przypadek użycia
 * wyjęty z `DataSettingsController::requestDeletion()` bez zmiany zachowania
 * (issue #970). Para do `CancelAccountDeletion`: tamta klasa cofa, ta
 * przyjmuje. Hasło i haczyki sprawdza wcześniej warstwa HTTP
 * (`ProsbaOUsuniecieKontaRequest` i kontroler); tutaj jest transakcja
 * oznaczenia konta razem ze sprawą w rejestrze RODO i wpis w dzienniku.
 */
final class RequestAccountDeletion
{
    /** Wartość domyślna — ten sam powód co w `CancelAccountDeletion`. */
    public function __construct(private readonly RejestrPotwierdzenRodo $rejestr = new RejestrPotwierdzenRodo) {}

    /**
     * @param  User::DELETE_SCOPE_*  $zakres
     *
     * @throws OdmowaOstatniegoAdministratora ostatni czynny administrator (#1016) — nic nie zapisano
     * @throws BladDlaCzlowieka konto już jest w usuwaniu (drugie kliknięcie, druga karta — #980)
     */
    public function handle(User $user, string $zakres, ?string $ip = null): void
    {
        // OZNACZENIE KONTA I OTWARCIE SPRAWY W REJESTRZE RODO — JEDNA
        // TRANSAKCJA, nie dwie instrukcje obok siebie.
        //
        // `potwierdzenia_zadan_rodo` ma jedną sprawę na jedno żądanie
        // (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md`). Gdyby te dwa zapisy
        // szły osobno, zostawałby stan pośredni: konto oznaczone do usunięcia
        // BEZ sprawy w rejestrze (żądanie, którego nie ma jak potwierdzić —
        // i którego egzekutor karencji za 30 dni nie będzie miał czym
        // domknąć) albo sprawa w rejestrze bez oznaczonego konta (rejestr
        // twierdzący, że coś przyjęliśmy, choć nic się nie dzieje).
        //
        // Ta sama zasada, z tego samego powodu, wiąże domknięcie sprawy
        // z `EraseAccountData` i `CancelAccountDeletion`.
        // Transakcja z połączenia modelu — to samo połączenie co zapis konta.
        $user->getConnection()->transaction(function () use ($user, $zakres): void {
            $user->markForDeletion($zakres);

            $this->rejestr->przyjmijZadanieUsunieciaKonta($user);
        });

        // Zakres w audycie, bo to jest jedyny zapis tego, CO człowiek wybrał
        // i kiedy. Gdyby ktoś kiedyś zapytał „dlaczego moje przepisy
        // zniknęły" (albo „dlaczego NIE zniknęły"), odpowiedź musi dać się
        // znaleźć bez zgadywania.
        AuditLogEntry::record(
            'account.delete_requested',
            $user,
            $user,
            metadata: ['zakres' => $zakres],
            ip: $ip,
        );
    }
}
