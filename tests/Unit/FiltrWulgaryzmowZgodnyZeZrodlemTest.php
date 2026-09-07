<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Tags\FiltrWulgaryzmow;
use ReflectionClass;
use Tests\TestCase;

/**
 * LISTA W KODZIE MUSI ZGADZAĆ SIĘ ZE ŹRÓDŁEM, Z KTÓREGO POWSTAŁA
 *
 * `FiltrWulgaryzmow::SLOWA` nie jest już listą pisaną z pamięci: powstała
 * z zamówionej kuracji językowej, która leży w repozytorium jako
 * `database/seeders/dane/lista-wulgaryzmow.json` (hasła, wyjątki, kolizje
 * i uzasadnienia). Stała w kodzie istnieje po to, żeby filtr nie czytał
 * pliku przy każdym sprawdzeniu nazwy tagu — a nie po to, żeby żyć własnym
 * życiem obok źródła.
 *
 * Ten test pilnuje trzech rzeczy naraz:
 *
 *  1. stała = hasła ze źródła PLUS rodziny, które przywróciliśmy do blokady
 *     wbrew wyłączeniu dostawcy (`PRZYWROCONE_WBREW_ZRODLU`) — czyli rozjazd
 *     w KAŻDĄ stronę zapala się tutaj, także dopisanie hasła prosto do kodu;
 *  2. wyłączenia, które PRZYJĘLIŚMY, naprawdę nie są blokowane — bo to była
 *     świadoma decyzja, a nie przeoczenie, i ma prawo zostać zauważona,
 *     gdyby ktoś ją cofnął;
 *  3. żadne hasło nie blokuje istniejącej, dozwolonej nazwy tagu ani aliasu
 *     — kontrola liczona na prawdziwym słowniku, nie na próbce.
 */
final class FiltrWulgaryzmowZgodnyZeZrodlemTest extends TestCase
{
    /**
     * @return array{hasla: list<array{token: string, kategoria: string}>, wyjatki: list<array{slowo: string, dlaczego: string}>}
     */
    private function zrodlo(): array
    {
        $surowe = file_get_contents(database_path('seeders/dane/lista-wulgaryzmow.json'));

        $this->assertIsString($surowe, 'Bez pliku źródłowego ten test nie ma czego porównywać.');

        /** @var array{hasla: list<array{token: string, kategoria: string}>, wyjatki: list<array{slowo: string, dlaczego: string}>} $dane */
        $dane = json_decode($surowe, true, 512, JSON_THROW_ON_ERROR);

        return $dane;
    }

    /**
     * @param  non-empty-string  $nazwa
     * @return list<string>
     */
    private function stala(string $nazwa): array
    {
        /** @var list<string> $wartosc */
        $wartosc = (new ReflectionClass(FiltrWulgaryzmow::class))->getConstant($nazwa);

        return $wartosc;
    }

    public function test_kontrola_filtr_w_ogole_dziala(): void
    {
        // Bez tej asercji cała reszta mogłaby przechodzić na filtrze,
        // który nie blokuje niczego.
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('kurwa'));
        $this->assertTrue(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('zupa kurwa pomidorowa'));
    }

    public function test_stala_zgadza_sie_ze_zrodlem_i_z_przywroceniami(): void
    {
        $zrodlo = $this->zrodlo();

        $oczekiwane = array_map(
            static fn (array $haslo): string => $haslo['token'],
            $zrodlo['hasla'],
        );
        $oczekiwane = array_merge($oczekiwane, $this->stala('PRZYWROCONE_WBREW_ZRODLU'));

        sort($oczekiwane);
        $oczekiwane = array_values(array_unique($oczekiwane));

        $wKodzie = $this->stala('SLOWA');
        sort($wKodzie);

        $this->assertSame($oczekiwane, $wKodzie, 'Stała SLOWA rozjechała się ze źródłem — dopisz hasło do JSON-a albo do listy przywróceń, nie prosto do stałej.');
    }

    public function test_przyjete_wylaczenia_naprawde_nie_sa_blokowane(): void
    {
        $wylaczenia = $this->stala('PRZYJETE_WYLACZENIA');

        $this->assertNotEmpty($wylaczenia, 'Bez tej listy test nie ma treści.');

        foreach ($wylaczenia as $slowo) {
            $this->assertFalse(
                FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($slowo),
                "„{$slowo}” było na starej liście i zostało z niej zdjęte świadomie — blokada wróciła bez decyzji.",
            );
        }
    }

    public function test_rodziny_przywrocone_wbrew_zrodlu_sa_blokowane(): void
    {
        $przywrocone = $this->stala('PRZYWROCONE_WBREW_ZRODLU');

        $this->assertNotEmpty($przywrocone);

        foreach ($przywrocone as $slowo) {
            $this->assertTrue(
                FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($slowo),
                "„{$slowo}” przywróciliśmy do blokady wbrew wyłączeniu dostawcy, a filtr go przepuszcza.",
            );
        }
    }

    public function test_wyjatki_ze_zrodla_nie_sa_blokowane(): void
    {
        $zrodlo = $this->zrodlo();
        $przywrocone = $this->stala('PRZYWROCONE_WBREW_ZRODLU');

        $sprawdzonych = 0;

        foreach ($zrodlo['wyjatki'] as $wyjatek) {
            // Rodziny przywrócone do blokady są jedynym miejscem, w którym
            // świadomie nie zgadzamy się ze źródłem — pomijamy je tutaj,
            // a osobny test dowodzi, że naprawdę są blokowane.
            if (in_array($wyjatek['slowo'], $przywrocone, true)) {
                continue;
            }

            $this->assertFalse(
                FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($wyjatek['slowo']),
                "„{$wyjatek['slowo']}” jest w źródle wyjątkiem ({$wyjatek['dlaczego']}), a filtr go blokuje.",
            );

            $sprawdzonych++;
        }

        $this->assertGreaterThan(100, $sprawdzonych, 'Źródło miało kilkaset wyjątków — jeśli sprawdziliśmy garść, to plik jest nie ten.');
    }

    public function test_zadne_haslo_nie_blokuje_istniejacej_nazwy_tagu(): void
    {
        $frazy = [];

        foreach (['slownik-tagow.json', 'slownik-tagow-uzupelnienia.json', 'slownik-tagow-v1.1.json'] as $plik) {
            $surowe = file_get_contents(database_path('seeders/dane/'.$plik));
            $this->assertIsString($surowe);

            /** @var array{tagi: list<array{nazwa: string, aliasy?: list<string>}>, nowe_aliasy?: list<array{tag: string, aliasy: list<string>}>} $dane */
            $dane = json_decode($surowe, true, 512, JSON_THROW_ON_ERROR);

            foreach ($dane['tagi'] as $tag) {
                $frazy[] = $tag['nazwa'];
                $frazy = array_merge($frazy, $tag['aliasy'] ?? []);
            }

            foreach ($dane['nowe_aliasy'] ?? [] as $wpis) {
                $frazy[] = $wpis['tag'];
                $frazy = array_merge($frazy, $wpis['aliasy']);
            }
        }

        $this->assertGreaterThan(3000, count($frazy), 'Za mało fraz — kontrola liczona na próbce nie jest kontrolą.');

        $zablokowane = [];

        foreach ($frazy as $fraza) {
            if (FiltrWulgaryzmow::zawieraNiedozwoloneSlowo($fraza)) {
                $zablokowane[] = $fraza;
            }
        }

        $this->assertSame([], $zablokowane, 'Filtr blokuje nazwę tagu z dostarczonego słownika — to fałszywe trafienie wobec człowieka działającego w dobrej wierze.');
    }

    public function test_kontrola_z_drugiej_strony_niewinne_frazy_przechodza(): void
    {
        // Gdyby ktoś naprawił obejścia filtra dopasowaniem podciągu, te
        // zapaliłyby się natychmiast. Dwa pierwsze to prawdziwe fałszywe
        // trafienia z historii tego projektu.
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('konfitura'));
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('kuchnia łęczycka'));
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('murzynek'));
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('kuchnia żydowska'));
        $this->assertFalse(FiltrWulgaryzmow::zawieraNiedozwoloneSlowo('pieprz ziołowy'));
    }
}
