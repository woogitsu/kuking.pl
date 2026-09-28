<?php

declare(strict_types=1);

namespace Tests\Support;

/** Wąska kontrola aktywnych decyzji; nie skanuje całej dokumentacji projektu. */
final class HigienaDziennikaDecyzji
{
    /**
     * Historyczne wzmianki i obca numeracja bez wpisu w głównym dzienniku.
     * Kluczem jest dokładny plik i numer, więc nowy martwy odsyłacz w innym
     * wpisie nadal obleje kontrolę. Uzupełnić tylko po przeczytaniu kontekstu.
     *
     * @var array<string, list<string>>
     */
    private const HISTORYCZNE = [
        'D-030-wpis-nie-dostaje-pola-tytul-tytul.md' => ['D-110'], // system projektowy
        'D-034-kreator-przepisu-dostaje-trzy-adresy-po.md' => ['D-108'], // system projektowy
        'D-085-adres-bez-konta-dostaje-zaproszenie-do.md' => ['D-067'], // opis niepowstałego wpisu
        'D-092-analityka-odwiedzin-to-cloudflare-web-analytics.md' => ['D-066'], // opis dawnej sieroty
        'D-115-skala-tekstu-schodzi-do-70-bo.md' => ['D-111'], // system projektowy
        'D-233-rejestr-potwierdzen-rodo-tak-automatyczne.md' => ['D-228'], // dawny numer gałęzi
        'D-239-wspolny-licznik-calej-poczty-i-kolejnosc.md' => ['D-226'], // dawny numer gałęzi
        'D-242-wyjecie-z-zeszytu-jest-odwracalne-co.md' => ['D-243'], // historia kolizji gałęzi
        'D-312-pgbouncer-jawnie-uznany-za-jeszcze-niepotrzebny.md' => ['D-311'], // wpis na późniejszej bazie
    ];

    public function __construct(private readonly string $korzen) {}

    /** @return list<string> */
    public function usterki(): array
    {
        $pliki = glob($this->korzen.'/docs/decyzje/D-*.md') ?: [];
        $numery = [];
        $usterki = [];

        foreach ($pliki as $plik) {
            $nazwa = basename($plik);
            $pierwsza = strtok((string) file_get_contents($plik), "\n") ?: '';

            if (preg_match('/^## D-(\d{3})\b/u', $pierwsza, $m) === 1) {
                $numery['D-'.$m[1]] = true;
            }

            if (preg_match('/^D-\d{4,}-|robocza/i', $nazwa) === 1
                || preg_match('/^## D-\d{4,}\b|ROBOCZA/u', $pierwsza) === 1) {
                $usterki[] = $nazwa.' — aktywny numer musi mieć trzy cyfry i nie może być roboczy.';
            }
        }

        foreach ($pliki as $plik) {
            $nazwa = basename($plik);
            foreach (explode("\n", (string) file_get_contents($plik)) as $indeks => $linia) {
                preg_match_all('/\bD-(\d{3})(?!\d)\b/u', $linia, $trafienia);

                foreach ($trafienia[0] as $numer) {
                    if (isset($numery[$numer]) || in_array($numer, self::HISTORYCZNE[$nazwa] ?? [], true)) {
                        continue;
                    }

                    $usterki[] = $nazwa.':'.($indeks + 1).' — '.$numer.' nie ma wpisu w dzienniku. '
                        .'Jeśli to historia lub obca numeracja, dodaj wąski wyjątek z powodem.';
                }
            }
        }

        return array_values(array_unique($usterki));
    }
}
