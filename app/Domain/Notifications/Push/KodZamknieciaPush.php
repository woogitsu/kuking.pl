<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

/**
 * Dlaczego rezerwacja pushu skończyła się bez wysyłki (`notifications.push_wynik`, #2053).
 *
 * Lista jest zamknięta i powtórzona CHECK-iem w bazie. Kod jest też
 * JEDYNĄ przyczyną, jaką czujka `kuking:sprawdz-push` wynosi do alarmu —
 * bez identyfikatorów, adresów urządzeń i treści.
 */
enum KodZamknieciaPush: string
{
    /** Wyczerpane próby transportu — AWARIA, alarmuje do ręcznego rozliczenia. */
    case PorazkaTransportu = 'porazka_transportu';

    /** Świadome anulowanie ponowienia (#2052): przeczytane, niewidoczne, konto bez dostępu, rezerwacja ponad 48 h. */
    case Anulowano = 'anulowano';

    /** Operator rozliczył porażkę albo utracone ponowienie według runbooka #2053. */
    case ZamknietoRecznie = 'zamknieto_recznie';
}
