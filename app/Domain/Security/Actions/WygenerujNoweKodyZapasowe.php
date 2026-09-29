<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Security\WynikNowychKodowZapasowych;
use App\Domain\Users\ZamekKonta;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Nowy komplet kodów zapasowych przy włączonej 2FA — sprawdzenia i zapis pod
 * blokadą konta jako nazwany przypadek użycia.
 *
 * Wyjęte z `Settings\TwoFactorSettingsController::regenerateCodes()` bez
 * zmiany zachowania (issue #970): 2FA wyłączona kończy przed sprawdzeniem
 * hasła, migawka zaszyfrowanego kompletu z początku żądania, skróty PRZED
 * blokadą, porównanie migawki ze świeżym wierszem pod `ZamekKonta` (#2057).
 * Flash z kodami kontroler ustawia dopiero po zwrocie, czyli po zatwierdzeniu
 * transakcji. Nic nie trafia do dziennika audytu — tak jak przed wyjęciem.
 */
final class WygenerujNoweKodyZapasowe
{
    public function __construct(private readonly TwoFactorAuthenticator $totp) {}

    public function handle(User $user, string $haslo): WynikNowychKodowZapasowych
    {
        if (! $user->hasTwoFactorConfirmed()) {
            return new WynikNowychKodowZapasowych(WynikNowychKodowZapasowych::WYLACZONE);
        }

        if (! Hash::check($haslo, $user->password)) {
            return new WynikNowychKodowZapasowych(WynikNowychKodowZapasowych::ZLE_HASLO);
        }

        // DWIE KARTY, DWA KOMPLETY (#2057).
        //
        // Migawka to zaszyfrowany komplet z chwili, w której żądanie wczytało
        // konto. Pod blokadą wiersza porównujemy ją ze stanem bieżącym: inny
        // komplet znaczy, że w trakcie tego żądania ktoś (druga karta) już
        // zapisał nowe kody. Wtedy niczego nie nadpisujemy i niczego nie
        // pokazujemy — komplet z tamtej karty zostaje ważny. Sama blokada
        // by nie wystarczyła: kolejkuje, ale drugie żądanie po wejściu i tak
        // nadpisałoby komplet, który pierwsza karta właśnie pokazała.
        $migawka = $user->getRawOriginal('two_factor_backup_codes');

        // Skróty liczymy PRZED blokadą — bcrypt kilka razy nie ma czego
        // szukać w transakcji, która trzyma wiersz konta.
        $kodyJawne = $this->totp->generateBackupCodes();
        $skroty = $this->totp->hashBackupCodes($kodyJawne);

        $wynik = ZamekKonta::zablokuj($user, static function (?User $swiezy) use ($migawka, $skroty): string {
            if ($swiezy === null || ! $swiezy->hasTwoFactorConfirmed()) {
                return WynikNowychKodowZapasowych::WYLACZONE;
            }

            if ($swiezy->getRawOriginal('two_factor_backup_codes') !== $migawka) {
                return WynikNowychKodowZapasowych::ZMIENIONE;
            }

            $swiezy->replaceTwoFactorBackupCodes($skroty);

            return WynikNowychKodowZapasowych::ZAPISANE;
        });

        $user->refresh();

        return new WynikNowychKodowZapasowych(
            $wynik,
            $wynik === WynikNowychKodowZapasowych::ZAPISANE ? $kodyJawne : [],
        );
    }
}
