<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ekrany paczki S (#2393) są mierzone przez `scripts/dostepnosc.mjs`
 * (axe-core, 320 px, tekst 140% i czcionka przeglądarki 200%) oraz
 * `scripts/audyt-ux50plus.mjs` (tekst >= 18 px, cele >= 48 px).
 *
 * PO CO TO JEST. Skan `PomiarDostepnosciObejmujeStronyPubliczneTest` pomija
 * trasy z parametrem w adresie i trasy za `auth`, a prawie wszystkie ekrany
 * tej paczki są jednym albo drugim: spiżarnia (za `auth`), „Ustaw termin"
 * (parametr `pantryItem`), strony sobotniego listu (podpis i parametr
 * `user`), „Wydrukuj zeszyt" (parametr `collection`), karta QR i historia
 * wersji (parametr `recipe`/`username`). Ich brak na liście niczego nie
 * psuje — raport świeci na zielono nad niepełną listą (pułapka 5,
 * docs/PULAPKI_TESTOW.md). Ten test wymusza, że każdy z tych ekranów ma
 * wpis w OBU skryptach, a wpis w `dostepnosc.mjs` ma `wymaga:` — dowód
 * treści, bez którego mierzony bywa pusty stan.
 *
 * @bez-kontroli-dodatniej Czyta źródła skryptów Node jako tekst; sprawdza obecność wpisów, nie uruchamia pomiaru.
 */
class EkranyPaczkiSSaMierzoneTest extends TestCase
{
    /**
     * Nazwa ekranu w obu skryptach => nazwa trasy, którą ten ekran pokrywa
     * (null: sam stan strony, bez własnej trasy, albo strona po POST).
     *
     * @var array<string, string|null>
     */
    private const EKRANY = [
        'spiżarnia — Co mam w domu' => 'pantry.index',
        'spiżarnia — ustaw termin' => 'pantry.edit',
        'spiżarnia — najpierw to, co się psuje' => 'pantry.cook',
        'sobotni list — wypisanie (pytanie)' => 'spizarnia.wypisz',
        'sobotni list — wypisano' => 'spizarnia.wypisz',
        'sobotni list — zgoda wróciła' => 'spizarnia.wracam',
        'sobotni list — link wygasł' => 'spizarnia.wracam',
        'zeszyt do druku' => 'collections.print',
        'karta z kodem QR — przepis' => 'recipes.qr-card',
        'karta z kodem QR — profil' => 'profile.qr-card',
        'tablica — wspomnienie z wykonania' => null,
        'historia wersji przepisu' => 'recipes.history',
        'historia wersji — jedna wersja' => 'recipes.history.version',
        'historia wersji — co się zmieniło' => 'recipes.history.changes',
    ];

    private function zrodlo(string $plik): string
    {
        $sciezka = base_path($plik);
        $this->assertFileExists($sciezka);

        return (string) file_get_contents($sciezka);
    }

    public function test_kazdy_ekran_paczki_s_ma_wpis_w_obu_pomiarach(): void
    {
        $brakujace = [];

        foreach (['scripts/dostepnosc.mjs', 'scripts/audyt-ux50plus.mjs'] as $plik) {
            $zrodlo = $this->zrodlo($plik);

            foreach (array_keys(self::EKRANY) as $nazwa) {
                if (! str_contains($zrodlo, "{ nazwa: '{$nazwa}',")) {
                    $brakujace[] = "{$plik}: {$nazwa}";
                }
            }
        }

        $this->assertSame([], $brakujace, "Te ekrany paczki S nie są mierzone:\n  ".implode("\n  ", $brakujace));
    }

    public function test_wpis_w_automacie_dostepnosci_dowodzi_tresci_ekranu(): void
    {
        $zrodlo = $this->zrodlo('scripts/dostepnosc.mjs');

        foreach (array_keys(self::EKRANY) as $nazwa) {
            $this->assertMatchesRegularExpression(
                '~\{ nazwa: \''.preg_quote($nazwa, '~').'\',[^\n]*wymaga: \'[^\']+\'~u',
                $zrodlo,
                "Ekran „{$nazwa}” nie ma `wymaga:` — mierzony bywa pusty stan, który przechodzi każdy audyt.",
            );
        }
    }

    public function test_trasy_pokrywane_przez_pomiar_istnieja(): void
    {
        foreach (array_filter(self::EKRANY) as $nazwa => $trasa) {
            $this->assertTrue(Route::has($trasa), "Ekran „{$nazwa}” odwołuje się do trasy `{$trasa}`, której nie ma — zaktualizuj pomiar.");
        }
    }

    public function test_wpisy_paczki_s_sa_w_liscie_ekranow_a_nie_w_innej_stalej(): void
    {
        // Pętle axe i układu czytają `EKRANY` (układ dokłada ją przez `...EKRANY`),
        // więc wpis poza tą listą nie byłby zmierzony przez żadną z nich.
        $zrodlo = $this->zrodlo('scripts/dostepnosc.mjs');
        $od = strpos($zrodlo, 'const EKRANY = [');
        $this->assertNotFalse($od);
        $do = strpos($zrodlo, "\n];", $od);
        $this->assertNotFalse($do);
        $lista = substr($zrodlo, $od, $do - $od);

        foreach (array_keys(self::EKRANY) as $nazwa) {
            $this->assertStringContainsString("{ nazwa: '{$nazwa}',", $lista, "„{$nazwa}” stoi poza listą `EKRANY`.");
        }
    }
}
