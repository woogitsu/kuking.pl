<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Alergeny;

/**
 * Czternaście alergenów z Załącznika II rozporządzenia (UE) nr 1169/2011
 * (#1902, D-333).
 *
 * To SŁOWNIK ZAMKNIĘTY dla spójności danych, a nie zobowiązanie prawne
 * serwisu: autor przepisu w serwisie społecznościowym nie jest podmiotem
 * działającym na rynku spożywczym. Wartość (`value`) to kod zapisany w bazie
 * (`recipes.allergens`) i w adresie filtra (`bez[]=gluten`); lista kodów
 * musi być identyczna z listą w CHECK-u `recipes_allergens_closed_list_check`
 * — pilnuje tego `AlergenyOgraniczeniaBazyTest::test_lista_w_enumie_jest_ta_sama_co_w_checku_bazy`.
 *
 * Nazwy w interfejsie są po polsku i bez form rodzajowych. Nigdy nie
 * składamy z nich haseł „bezpieczny”, „dla alergików” ani „bez alergenów”.
 */
enum Alergen: string
{
    case Gluten = 'gluten';
    case Skorupiaki = 'crustaceans';
    case Jaja = 'eggs';
    case Ryby = 'fish';
    case OrzeszkiZiemne = 'peanuts';
    case Soja = 'soy';
    case Mleko = 'milk';
    case Orzechy = 'nuts';
    case Seler = 'celery';
    case Gorczyca = 'mustard';
    case Sezam = 'sesame';
    case Siarczyny = 'sulphites';
    case Lubin = 'lupin';
    case Mieczaki = 'molluscs';

    /** Etykieta przy polu wyboru — z przykładami, żeby nie trzeba było zgadywać. */
    public function etykieta(): string
    {
        return match ($this) {
            self::Gluten => 'Zboża zawierające gluten (pszenica, żyto, jęczmień, owies)',
            self::Skorupiaki => 'Skorupiaki',
            self::Jaja => 'Jaja',
            self::Ryby => 'Ryby',
            self::OrzeszkiZiemne => 'Orzeszki ziemne',
            self::Soja => 'Soja',
            self::Mleko => 'Mleko (z laktozą)',
            self::Orzechy => 'Orzechy (migdały, laskowe, włoskie, nerkowca i inne)',
            self::Seler => 'Seler',
            self::Gorczyca => 'Gorczyca',
            self::Sezam => 'Sezam',
            self::Siarczyny => 'Dwutlenek siarki i siarczyny',
            self::Lubin => 'Łubin',
            self::Mieczaki => 'Mięczaki',
        };
    }

    /** Krótka nazwa do zdań: „Alergeny według autora: gluten, mleko, jaja”. */
    public function nazwa(): string
    {
        return match ($this) {
            self::Gluten => 'gluten',
            self::Skorupiaki => 'skorupiaki',
            self::Jaja => 'jaja',
            self::Ryby => 'ryby',
            self::OrzeszkiZiemne => 'orzeszki ziemne',
            self::Soja => 'soja',
            self::Mleko => 'mleko',
            self::Orzechy => 'orzechy',
            self::Seler => 'seler',
            self::Gorczyca => 'gorczyca',
            self::Sezam => 'sezam',
            self::Siarczyny => 'siarczyny',
            self::Lubin => 'łubin',
            self::Mieczaki => 'mięczaki',
        };
    }

    /** @return list<string> kody w kolejności deklaracji — ta sama, co w CHECK-u bazy */
    public static function kody(): array
    {
        return array_map(static fn (self $a): string => $a->value, self::cases());
    }

    /**
     * Kody z wejścia z zewnątrz: tylko znane, bez powtórzeń, w kolejności
     * słownika (stabilna — ta sama lista daje ten sam zapis i ten sam odcisk).
     * Nieznane wartości odpadają; ile ich było, mówi `nieznane()`.
     *
     * @param  array<array-key, mixed>  $wejscie
     * @return list<string>
     */
    public static function znormalizuj(array $wejscie): array
    {
        $podane = array_map(static fn (mixed $k): string => is_string($k) ? $k : '', $wejscie);

        return array_values(array_filter(
            self::kody(),
            static fn (string $kod): bool => in_array($kod, $podane, true),
        ));
    }

    /**
     * Ile wartości z wejścia nie należy do słownika.
     *
     * @param  array<array-key, mixed>  $wejscie
     */
    public static function nieznane(array $wejscie): int
    {
        $znane = self::kody();

        return count(array_filter(
            $wejscie,
            static fn (mixed $k): bool => ! is_string($k) || ! in_array($k, $znane, true),
        ));
    }

    /**
     * Lista nazw do zdania, w kolejności słownika.
     *
     * @param  list<string>  $kody
     */
    public static function nazwyZKodow(array $kody): string
    {
        $nazwy = [];
        foreach (self::znormalizuj($kody) as $kod) {
            $nazwy[] = self::from($kod)->nazwa();
        }

        return implode(', ', $nazwy);
    }
}
