<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CzytaJobDostepnosci;
use Tests\TestCase;

/**
 * Krok, który uruchamia się po awarii i melduje coś innego niż jej przyczyna,
 * jest gorszy niż krok pominięty.
 *
 * CO SIĘ STAŁO 9 WRZEŚNIA 2026
 * Job „Dostępność (axe-core) i wydajność (Lighthouse)" oblewał się na czterech
 * PR-ach naraz (#209, #211, #212, #214) komunikatem:
 *
 *     Nie udało się podnieść „php artisan serve" na porcie 38299.
 *     Brak odpowiedzi z /health przez 30 s.
 *
 * Komunikat był NIEPRAWDZIWY. W tym samym logu stało kilkadziesiąt wpisów
 * `/health ... ~ 0.06ms` — serwer wstał i odpowiadał. Odpowiadał BŁĘDEM, bo
 * `/health` sprawdza wykonane migracje, a bazy nikt nie zmigrował.
 *
 * Złożyły się na to dwie rzeczy, obie w konfiguracji, nie w kodzie aplikacji:
 *
 *   1. bazę migrował WYŁĄCZNIE `scripts/dostepnosc.mjs`, a `wydajnosc.mjs`
 *      na tym polegał — dwa niezależne narzędzia dzieliły stan, którego
 *      jedno z nich nie tworzy;
 *   2. krok Lighthouse miał `!cancelled()`, co ZNOSI domyślne pomijanie kroku
 *      po porażce poprzedniego. Gdy skan axe padał, Lighthouse startował mimo
 *      to i przykrywał prawdziwą przyczynę własnym błędem.
 *
 * Skutek: trzy godziny szukania usterki w PR-ach, które były zdrowe. Pełny
 * przebieg `dostepnosc.mjs` przechodził lokalnie za każdym razem.
 *
 * CZEGO TEN TEST NIE DOWODZI
 * Że job przechodzi — tego z PHP sprawdzić nie da się wcale. Dowodzi dwóch
 * rzeczy, które da się przeczytać z pliku: że Lighthouse nie ma już furtki
 * uruchamiającej go po awarii, i że baza jest przygotowana w kroku, a nie
 * przy okazji przez inny skrypt.
 */
class JobDostepnosciNieMyliPrzyczynyTest extends TestCase
{
    use CzytaJobDostepnosci;

    #[Test]
    public function zaden_krok_nie_uruchamia_sie_po_porazce_poprzedniego(): void
    {
        $job = $this->jobDostepnosci();

        // Kontrola metody pomiaru: bez tego test przechodziłby też wtedy,
        // gdybym czytał zły fragment pliku.
        $this->assertStringContainsString(
            'scripts/wydajnosc.mjs',
            $job,
            'W czytanym fragmencie nie ma wywołania Lighthouse. Czytam zły job albo zły plik.',
        );

        $wiersze = preg_split('/\R/', $job) ?: [];

        foreach ($wiersze as $numer => $wiersz) {
            // Komentarze mają prawo (i obowiązek) tłumaczyć, czego tu nie ma.
            $bezKomentarza = (string) preg_replace('/#.*$/', '', $wiersz);

            if (! str_contains($bezKomentarza, 'if:')) {
                continue;
            }

            $this->assertStringNotContainsString(
                '!cancelled()',
                $bezKomentarza,
                'Wiersz '.($numer + 1).' joba `dostepnosc` znowu ma `!cancelled()`. Ten warunek znosi '
                .'domyślne pomijanie kroku po porażce poprzedniego, więc krok uruchamia się na '
                .'nieprzygotowanym środowisku i zgłasza błąd, który nie ma nic wspólnego z prawdziwą '
                .'przyczyną. Dokładnie tak zniknęła przyczyna awarii z 9 września (issue #215). '
                .'Jeśli naprawdę chcesz, żeby jakiś krok chodził po porażce, użyj `always()` '
                .'świadomie i tylko przy zapisie wyniku.',
            );
        }
    }

    #[Test]
    public function baza_jest_migrowana_w_kroku_przygotowania(): void
    {
        $job = $this->jobDostepnosci();

        $this->assertMatchesRegularExpression(
            '/artisan\s+migrate/',
            $job,
            'Job `dostepnosc` nie migruje bazy w żadnym swoim kroku. Wtedy Lighthouse zależy od tego, '
            .'czy `scripts/dostepnosc.mjs` zdążył ją przygotować — a gdy nie zdąży, healthcheck '
            .'odpowiada błędem i komunikat mówi o czymś zupełnie innym (issue #215).',
        );
    }
}
