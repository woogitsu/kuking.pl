<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cofnięcie zgłoszonego usunięcia konta (audyt A8, RODO art. 17).
 *
 * DLACZEGO TO NIE JEST TYLKO `$user->cancelDeletion()` W KONTROLERZE
 * Reguła „kiedy wolno cofnąć" jest regułą domenową, nie szczegółem HTTP —
 * gdyby żyła w kontrolerze, drugi endpoint (np. panel admina) mógłby ją
 * ominąć i cofnąć usunięcie kontu, którego dane już nie istnieją.
 *
 * Dwa warunki muszą być spełnione RAZEM:
 *
 *  1. Konto faktycznie jest `pending_delete` — cofać można tylko to, co
 *     zostało zgłoszone. Osoba, która pomyliła się co do własnego stanu
 *     konta, ma to usłyszeć wprost, a nie zobaczyć ciche „nic się nie stało".
 *  2. `data_erased_at` jest puste, czyli konto nie jest w stanie końcowym
 *     `erased` (D-022) — jeśli egzekutor karencji
 *     (`kuking:usun-wygasle-konta`) już wymazał dane, „cofnięcie" ustawiłoby
 *     tylko `status = active` na koncie bez e-maila, hasła i profilu: pusta
 *     powłoka, która wygląda jak wskrzeszone konto, a nie jest nim. To gorsze
 *     niż odmowa — więc odmawiamy, z wyjaśnieniem, co się stało i dokąd pisać.
 */
final class CancelAccountDeletion
{
    public function handle(User $user): void
    {
        // Transakcja z blokadą, nie odczyt z argumentu: formularz i egzekutor
        // karencji (`kuking:usun-wygasle-konta`) mogą teoretycznie zetknąć się
        // w tej samej chwili (ktoś klika „cofnij” dokładnie wtedy, gdy zegar
        // wykonuje karencję) — bez blokady wygrałby ten, kto zapisał drugi,
        // a „cofnięcie” mogłoby ustawić `active` na koncie, któremu w
        // międzyczasie wymazano e-mail i hasło.
        DB::transaction(function () use ($user): void {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            // KOLEJNOŚĆ TYCH DWÓCH SPRAWDZEŃ MA ZNACZENIE OD D-022.
            //
            // Wcześniej pierwszy warunek brzmiał „status musi być
            // `pending_delete`", a konto po wykonanej karencji ZOSTAWAŁO na
            // tym statusie — więc do drugiego warunku dochodziło się zawsze
            // i komunikat był właściwy. Od D-022 stan końcowy ma własną
            // wartość (`erased`), czyli pierwszy warunek łapałby też konto
            // wymazane i mówił mu „nie ma czego cofać". To nieprawda: było
            // co cofać, tylko jest za późno — a to jest zupełnie inna
            // informacja dla człowieka, który właśnie zrozumiał, że stracił
            // swoje przepisy.
            //
            // Dlatego najpierw pytamy o WYKONANIE, a potem o zgłoszenie.
            if ($fresh !== null && ($fresh->data_erased_at !== null || $fresh->isErased())) {
                throw new BladDlaCzlowieka(
                    'Tego konta nie da się już odzyskać — dane zostały trwale usunięte '
                    .$fresh->data_erased_at?->translatedFormat('j F Y').'. Jeśli to pomyłka, '
                    .'napisz do nas: '.config('kuking.community.contact_email').'.',
                );
            }

            if ($fresh === null || $fresh->status !== User::STATUS_PENDING_DELETE) {
                throw new BladDlaCzlowieka(
                    'To konto nie jest oznaczone do usunięcia — nie ma czego cofać. '
                    .'Jeśli to nie zgadza się z tym, czego się spodziewasz, napisz do nas: '
                    .config('kuking.community.contact_email').'.',
                );
            }

            $fresh->cancelDeletion();
        });
    }
}
