<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\DB;

/**
 * Atomowa rezerwacja „jeden list danego rodzaju na dobę na adres" (#1333).
 *
 * REZERWACJA PRZED KOLEJKOWANIEM. `zarezerwuj()` wstawia wiersz
 * w `przypomnienia_dobowe` przez `insertOrIgnore` na kluczu głównym — przy
 * dwóch równoległych przebiegach wiersz wstawi dokładnie jeden, więc dokładnie
 * jeden wyśle list. Drugi dostaje `false` i milczy.
 *
 * NIEUDANE KOLEJKOWANIE ODDAJE MIEJSCE (`zwolnij()`): list, którego nie udało
 * się wstawić do kolejki, nie wyszedł, więc kolejny przebieg tego samego dnia
 * ma prawo spróbować. Bez tego jedna awaria kolejki zabierałaby całą dobę.
 * List, który do kolejki TRAFIŁ, a padł dopiero w workerze, jest
 * w `failed_jobs` i czujce kolejki — i następnego dnia wychodzi nowy.
 *
 * DOBA W UTC, tak jak dobowy sufit poczty (`DziennyBudzetListow`): obie
 * reguły liczą to samo wiadro listów, więc muszą się zgadzać co do tego,
 * kiedy zaczyna się nowy dzień. Harmonogram startuje o 07:10 UTC, gdzie
 * doba UTC i doba polska są tą samą datą.
 */
final class PrzypomnienieDobowe
{
    /** Ile dni znaczników trzymamy — tylko dziś ma znaczenie, reszta to zapas na dochodzenie. */
    private const RETENCJA_DNI = 30;

    public function zarezerwuj(string $rodzaj, string $adres): bool
    {
        DB::table('przypomnienia_dobowe')
            ->where('doba', '<', now()->subDays(self::RETENCJA_DNI)->toDateString())
            ->delete();

        return DB::table('przypomnienia_dobowe')->insertOrIgnore([
            'rodzaj' => $rodzaj,
            'doba' => self::doba(),
            'odbiorca' => self::odbiorca($adres),
        ]) === 1;
    }

    public function zwolnij(string $rodzaj, string $adres): void
    {
        DB::table('przypomnienia_dobowe')
            ->where('rodzaj', $rodzaj)
            ->where('doba', self::doba())
            ->where('odbiorca', self::odbiorca($adres))
            ->delete();
    }

    private static function doba(): string
    {
        return now()->toDateString();
    }

    /** Skrót, nie adres: do rozpoznania duplikatu e-mail w tabeli nie jest potrzebny. */
    private static function odbiorca(string $adres): string
    {
        return hash('sha256', mb_strtolower(trim($adres)));
    }
}
