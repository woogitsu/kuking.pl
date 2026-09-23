<?php

declare(strict_types=1);

namespace App\Moderacja;

/**
 * KATEGORIE Z API MODERACJI → ZDANIA PO POLSKU (D-055).
 *
 * DLACZEGO TO ISTNIEJE JAKO OSOBNA KLASA
 * Bo moderator, który w kolejce dostaje `sexual/minors: 0.62`, nie wie, czego
 * szukać w treści — a przy setkach kont nie zgaduje, tylko odklikuje. Powód
 * ma być zdaniem, które da się przeczytać: „Model wskazał treść seksualną
 * z udziałem dziecka (pewność 62%)". Surowy wynik ZOSTAJE, ale obok zdania,
 * nie zamiast niego.
 *
 * DRUGI POWÓD: KATEGORIE SĄ CUDZE. Nazwy pochodzą z API OpenAI i mogą się
 * zmienić bez naszego udziału. Trzymanie ich w jednym miejscu znaczy, że
 * dopisanie nowej kategorii jest jedną zmianą, a kategoria NIEZNANA nie
 * wywraca oceny — dostaje zdanie ogólne (`opis()`), zamiast trafić na ekran
 * jako angielski kod.
 */
final class KategorieModeracji
{
    /**
     * Kategorie, przy których zwłoka jednego dnia jest realną szkodą.
     *
     * Obie mają w `resources/legal/zasady.md` własną sekcję „Czego nie
     * tolerujemy w ogóle". To jest CAŁA lista i ma taka zostać: gdyby
     * „pilne" znaczyło pięć kategorii, list natychmiastowy przestałby
     * znaczyć cokolwiek, a moderator nauczyłby się go nie otwierać.
     *
     * @var list<string>
     */
    public const PILNE = [
        'sexual/minors',
        'sexual',
    ];

    /**
     * Kategoria → zdanie po polsku.
     *
     * @var array<string, string>
     */
    private const OPISY = [
        'sexual' => 'treść seksualna',
        'sexual/minors' => 'treść seksualna z udziałem dziecka',
        'harassment' => 'nękanie albo obraźliwy język wobec konkretnej osoby',
        'harassment/threatening' => 'groźba wobec konkretnej osoby',
        'hate' => 'mowa nienawiści',
        'hate/threatening' => 'mowa nienawiści z groźbą',
        'violence' => 'przemoc',
        'violence/graphic' => 'drastyczny opis albo obraz przemocy',
        'self-harm' => 'samookaleczenie',
        'self-harm/intent' => 'zapowiedź samookaleczenia',
        'self-harm/instructions' => 'instrukcja samookaleczenia',
        'illicit' => 'instrukcja popełnienia przestępstwa',
        'illicit/violent' => 'instrukcja popełnienia przestępstwa z użyciem przemocy',
    ];

    public static function jestPilna(string $kategoria): bool
    {
        return in_array($kategoria, self::PILNE, true);
    }

    public static function jestZnana(string $kategoria): bool
    {
        return array_key_exists($kategoria, self::OPISY);
    }

    /**
     * Zdanie dla moderatora.
     *
     * Kategoria spoza listy nie jest błędem: API może dołożyć nową w każdej
     * chwili, a my mamy wtedy powiedzieć prawdę ogólną, nie wyświetlić
     * angielskiego kodu osobie, która ma w trzy sekundy zdecydować, czy
     * warto to czytać.
     */
    public static function opis(string $kategoria): string
    {
        return self::OPISY[$kategoria] ?? 'treść wymagająca przejrzenia';
    }
}
