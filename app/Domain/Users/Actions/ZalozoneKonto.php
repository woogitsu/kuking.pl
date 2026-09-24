<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Models\User;

/**
 * Wynik `ZalozKonto::handle()` — konto, które NA PEWNO istnieje, i to, czego
 * po jego zatwierdzeniu nie udało się dokończyć (#1373).
 *
 * DLACZEGO NIE SAM `User`
 * Po `COMMIT` konta nic go już nie cofnie, więc skutki poboczne (list
 * z potwierdzeniem adresu, wpis w dzienniku, obserwowanie gospodarza) nie
 * mogą zamienić udanej rejestracji w błąd. Awarię dostaje operator przez
 * `report()`, ale jedna z nich dotyczy CZŁOWIEKA: list z potwierdzeniem
 * nie wyszedł, a ekran po rejestracji mówi „wysłaliśmy Ci wiadomość".
 * Kontroler musi to wiedzieć, żeby nie posłać nikogo po list, którego nie
 * ma — stąd ta flaga, a nie drugi kanał (sesja, zdarzenie) z domeny.
 */
final readonly class ZalozoneKonto
{
    /**
     * Komunikat po rejestracji, gdy list z potwierdzeniem nie wyszedł.
     * Jeden tekst dla wszystkich trzech dróg (hasło, Google, Facebook) —
     * mówi, że konto jest, że nie ma na co czekać i CO kliknąć.
     */
    public const KOMUNIKAT_BEZ_LISTU = 'Konto gotowe. Nie udało się jednak wysłać wiadomości z potwierdzeniem adresu, '
        .'więc nie czekaj na nią. Kiedy zechcesz potwierdzić adres, wejdź w Ustawienia → Adres e-mail '
        .'i kliknij „Wyślij potwierdzenie jeszcze raz”.';

    public function __construct(
        public User $user,
        public bool $listPotwierdzajacyNieWyszedl = false,
    ) {}
}
