<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Users\ZamekKonta;
use App\Models\AuditLogEntry;
use App\Models\User;

/**
 * Zdjęcie JEDNEGO zawieszenia, któremu minął termin — tylko jeśli nadal
 * nim jest (#2019).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ŹLE
 * ══════════════════════════════════════════════════════════════════════
 *
 * `kuking:zdejmij-wygasle-kary` czyta listę kandydatów BEZ blokady, a potem
 * konto po koncie wołał `$user->reinstate()`. Ta metoda bierze świeży wiersz
 * pod `ZamekKonta`, ale nie pyta, dlaczego ją wołano — zamienia na `active`
 * każdy status poza cyklem usuwania, bo służy też moderatorowi, który
 * zdejmuje karę ręcznie. Między odczytem listy a przywróceniem mijają
 * milisekundy albo (przy długiej liście, wolnym audycie) sekundy — i jeśli
 * w tym czasie moderator zbanował konto albo zawiesił je na NOWY termin,
 * automat odwracał tę decyzję i zapisywał w dzienniku „kara wygasła”.
 * Czyli: dostęp wracał osobie, której właśnie go odebrano, a w historii
 * konta zostawał fałszywy ślad, że stało się to zgodnie z planem.
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO ROBI TA KLASA
 * ══════════════════════════════════════════════════════════════════════
 *
 * Pod TĄ SAMĄ blokadą co każda zmiana stanu konta (`ZamekKonta`) ocenia
 * ŚWIEŻY wiersz jeszcze raz — status `suspended`, niepusty termin, termin
 * nie później niż teraz — i dopiero wtedy przywraca konto oraz zapisuje
 * `account.suspension_expired`. Gdy warunek już nie zachodzi, nie zmienia
 * NICZEGO i niczego nie zapisuje: nowsza decyzja moderatora wygrywa, a konto,
 * które kiedyś znów będzie wygasłym zawieszeniem, znajdzie kolejny przebieg.
 *
 * Moderator nakładający karę (`suspend()`/`ban()`) bierze ten sam zamek,
 * więc są tylko dwie kolejności: albo kara zatwierdziła się przed nami
 * i widzimy ją w świeżym wierszu (pomijamy), albo czeka na nas i nakłada się
 * na już przywrócone konto (jej decyzja i tak zostaje ostatnia).
 *
 * DLACZEGO OSOBNA KLASA, A NIE WARUNEK W `User::reinstate()`. `reinstate()`
 * ma dwóch wołających o różnych intencjach: moderatora, który zdejmuje karę
 * z DECYZJI (każdą, także ban), i zegar, który zdejmuje karę z TERMINU.
 * Warunek „termin minął” należy do zegara — w `reinstate()` odebrałby
 * moderatorowi możliwość zdjęcia bana. Przejście nadal idzie przez nazwaną
 * metodę modelu (AGENTS.md §7, `status` poza `$fillable`); ta klasa tylko
 * decyduje, CZY je wykonać.
 *
 * AUDYT W TEJ SAMEJ TRANSAKCJI (D-249, klasa 1; #1894). `ZamekKonta` otwiera
 * transakcję; `record()` rzuca w niej, więc awaria dziennika cofa także
 * przywrócenie — konto zostaje `suspended` i trafi w kolejny przebieg.
 * `reinstate()` w środku bierze zamek drugi raz na tym samym wierszu, w tej
 * samej transakcji — to nic nie kosztuje (patrz `ZamekKonta`).
 */
final class ZdejmijWygasleZawieszenie
{
    /**
     * @return bool `true`, gdy konto przywrócono; `false`, gdy świeży stan
     *              nie jest już wygasłym zawieszeniem i nic nie zmieniono
     */
    public function handle(User $user): bool
    {
        return ZamekKonta::zablokuj($user, static function (?User $swiezy): bool {
            if ($swiezy === null || ! self::nadalWygasle($swiezy)) {
                return false;
            }

            $swiezy->reinstate();

            AuditLogEntry::record('account.suspension_expired', null, $swiezy);

            return true;
        });
    }

    /**
     * Ten sam warunek co zapytanie listy w `RestoreExpiredSuspensions` —
     * tyle że liczony na wierszu odczytanym pod blokadą.
     */
    private static function nadalWygasle(User $konto): bool
    {
        return $konto->status === User::STATUS_SUSPENDED
            && $konto->status_expires_at !== null
            && $konto->status_expires_at->lessThanOrEqualTo(now());
    }
}
