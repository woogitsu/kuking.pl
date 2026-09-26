<?php

declare(strict_types=1);

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

/**
 * Strażnik z issue #1909, dopisany też do `AGENTS.md` §10 (lista rzeczy
 * w Pull Requeście): PR, który dopisuje w `CHANGELOG.md` wpis oznaczony jako
 * NOWA FUNKCJA, musi mieć też akapit w `resources/nowosci/tresc.md`.
 *
 * JAK OZNACZAMY „NOWĄ FUNKCJĘ" W CHANGELOGU. Najmniej uciążliwy sposób to
 * jeden dopisek na końcu wiersza: `[nowa funkcja]`. Nie osobna kolumna, nie
 * drugi plik, nie parsowanie numeru issue — jedno słowo do dopisania w tym
 * samym miejscu, w którym i tak piszemy wpis. Poprawka, zmiana kosmetyczna
 * i porządek za kulisami dopisku NIE dostają — decyzja właściciela z 26
 * września 2026 mówi wprost, że to jest jedno zbiorcze zdanie na wydanie,
 * a nie osobny akapit.
 *
 * CO TEN TEST NAPRAWDĘ LICZY. Nie parsuje diffu Pull Requesta (ten strażnik
 * chodzi w CI na zwykłym `phpunit`, bez dostępu do historii gita) — liczy
 * ZGODNOŚĆ DWÓCH LICZB w BIEŻĄCYM stanie repozytorium, tak jak inne
 * strażniki tekstu w tym projekcie (`scripts/kontrole-negatywne-alfa08.py`
 * czyta pliki wprost, nie różnicę commitów):
 *
 *   liczba wierszy `[nowa funkcja]` w sekcji „## Nieopublikowane" CHANGELOGA
 *   ==
 *   liczba nagłówków `###` w sekcji „## Najnowsze zmiany" pliku nowości.
 *
 * Sekcja „Nieopublikowane" to dokładnie to, co jeszcze nie ma numeru wydania
 * — czyli dokładnie to samo, co strona nowości nazywa „Najnowsze zmiany"
 * (patrz `resources/nowosci/tresc.md`, akapit pod tym nagłówkiem). Dlatego
 * porównujemy te dwie sekcje, a nie całe pliki: stare, już wydane release'y
 * CHANGELOGA nie miały tego oznaczenia i nie muszą go dostawać wstecz.
 *
 * Ktoś, kto dopisze `[nowa funkcja]` do CHANGELOGA i zapomni o akapicie
 * (albo dopisze akapit, ale zapomni oznaczyć wpis w CHANGELOGU), dostanie
 * czerwony test z konkretną liczbą po obu stronach — nie ogólnikowe
 * „coś się nie zgadza".
 */
class StraznikNowosciKazdaNowaFunkcjaMaAkapitTest extends TestCase
{
    private const CHANGELOG = 'CHANGELOG.md';

    private const TRESC_NOWOSCI = 'resources/nowosci/tresc.md';

    public function test_kazda_nowa_funkcja_w_nieopublikowanym_ma_akapit_w_najnowszych_zmianach(): void
    {
        $liczbaOznaczonychFunkcji = substr_count(
            $this->sekcja($this->wczytaj(self::CHANGELOG), '## Nieopublikowane'),
            '[nowa funkcja]',
        );

        $liczbaAkapitowNowosci = preg_match_all(
            '/^### /m',
            $this->sekcja($this->wczytaj(self::TRESC_NOWOSCI), '## Najnowsze zmiany'),
        );

        // KONTROLA POMIARU: zero po obu stronach przeszłoby zawsze, nic nie
        // dowodząc. Dziś repozytorium ma realne wpisy po obu stronach
        // (issue #23/D-301 i to samo issue #1909) — jeśli kiedyś oba spadną
        // do zera naraz, ten test i tak dalej coś sprawdza (0 === 0 jest
        // poprawnym stanem „nic nowego w tym wydaniu"), ale przynajmniej
        // ten komentarz zostaje jako ostrzeżenie, żeby nie uznać zera
        // za dowód działania strażnika.
        $this->assertSame(
            $liczbaOznaczonychFunkcji,
            $liczbaAkapitowNowosci,
            "CHANGELOG.md ma w sekcji „## Nieopublikowane” {$liczbaOznaczonychFunkcji} wpis(ów) "
            .'oznaczonych jako `[nowa funkcja]`, a resources/nowosci/tresc.md ma w sekcji '
            ."„## Najnowsze zmiany” {$liczbaAkapitowNowosci} akapit(ów) (nagłówków `###`). "
            .'PR, który dopisuje w CHANGELOGU wpis `[nowa funkcja]`, musi dopisać też akapit '
            .'w pliku nowości — i odwrotnie: akapit bez wpisu w CHANGELOGU zostaje osierocony.',
        );
    }

    private function wczytaj(string $sciezkaWzgledna): string
    {
        $sciezka = base_path($sciezkaWzgledna);

        if (! is_file($sciezka)) {
            throw new RuntimeException("Brak pliku {$sciezkaWzgledna}");
        }

        return (string) file_get_contents($sciezka);
    }

    /**
     * Wycina treść między podanym nagłówkiem `## ...` a NASTĘPNYM nagłówkiem
     * `## ` (albo końcem pliku), bez linii samego nagłówka.
     */
    private function sekcja(string $tresc, string $naglowek): string
    {
        $wzor = '/^'.preg_quote($naglowek, '/').'\R(.*?)(?=^## |\z)/ms';

        if (preg_match($wzor, $tresc, $dopasowanie) !== 1) {
            throw new RuntimeException("Nagłówek „{$naglowek}” nie istnieje albo jest niepoprawny.");
        }

        return $dopasowanie[1];
    }
}
