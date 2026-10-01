<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Domain\Users\DawneNazwyProfilu;
use App\Models\Profile;
use Illuminate\Support\Facades\DB;

/**
 * Zapis profilu z ustawień (imię, nazwa użytkownika, opis, region,
 * specjalność) razem z zapamiętaniem dawnej nazwy — w JEDNEJ transakcji,
 * żeby nie dało się zmienić nazwy bez przekierowania ani odwrotnie.
 *
 * Wyjątek unikalności (wyścig o tę samą wolną nazwę, #887) leci do
 * wołającego bez zmian; transakcja wycofuje wtedy także wiersz dawnej nazwy.
 */
final class ZapiszProfil
{
    public function __construct(private readonly DawneNazwyProfilu $dawneNazwy = new DawneNazwyProfilu) {}

    /** @param array<string, mixed> $dane */
    public function handle(Profile $profil, array $dane): void
    {
        DB::transaction(function () use ($profil, $dane): void {
            $dawna = (string) $profil->username;

            $profil->update($dane);

            // PO zapisie: zwolniona nazwa jest już wolna dla innych, a nowa
            // zajęta (unikalny indeks serializuje dwie osoby o tę samą).
            $this->dawneNazwy->zmieniono($profil->user, $dawna, (string) $profil->username);
        });
    }
}
