<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Google;
use Closure;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use ReflectionFunction;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Trzy zdania polityki prywatności, które rozjechały się z kodem
 * (audyt prywatności 30.09.2026, znaleziska Z8, Z10 i Z11, issue #2283).
 *
 *  - Z8: nieudany list zostaje w `failed_jobs` razem z adresem przez 30 dni
 *    (`queue:prune-failed --hours=720`), a polityka obiecywała usunięcie
 *    adresu z zaproszenia „najwyżej dobę po wygaśnięciu” i milczała o tym
 *    śladzie przy zwykłych listach. Termin w polityce liczymy z tego samego
 *    zdarzenia harmonogramu, które naprawdę chodzi o 05:20.
 *  - Z10: §9 zapowiadał e-mail o zmianie polityki „gdy będziemy już wysyłać
 *    wiadomości”. Serwis wysyła pocztę od 9.09.2026, a listu o zmianie
 *    polityki nie ma — o zmianie mówi pasek w serwisie (D-327).
 *  - Z11: logowanie Google prosi o zakres `profile`, który Google na ekranie
 *    zgody opisuje jako imię i zdjęcie, a polityka mówiła o samym imieniu.
 */
class PolitykaMowiPrawdeOPoczcieGoogleIZmianachTest extends TestCase
{
    public function test_slad_nieudanej_wysylki_ma_w_polityce_termin_z_harmonogramu(): void
    {
        $dni = $this->dniRetencjiFailedJobs();
        $tresc = $this->polityka();

        foreach (['| Wiadomości e-mail (', '| Zaproszenie do założenia konta'] as $poczatek) {
            $wiersz = $this->linia($tresc, $poczatek);

            $this->assertStringContainsString('ślad', $wiersz, "Wiersz „{$poczatek}” nie mówi o śladzie nieudanej wysyłki.");
            $this->assertStringContainsString(
                "najwyżej **{$dni} dni**",
                $wiersz,
                "Wiersz „{$poczatek}” nie podaje, że ślad nieudanej wysyłki (failed_jobs) żyje {$dni} dni — tyle, ile queue:prune-failed w harmonogramie.",
            );
        }
    }

    public function test_polityka_mowi_o_zdjeciu_gdy_google_dostaje_zakres_profile(): void
    {
        $zakresy = explode(' ', Google::ZAKRES);
        $akapit = $this->linia($this->polityka(), '**Co dostajemy od Google i czego nie dostajemy.**');

        if (! in_array('profile', $zakresy, true)) {
            $this->assertStringNotContainsString('zdjęcie profilowe', $akapit, 'Bez zakresu profile Google nie pyta o zdjęcie — usuń to zdanie z polityki.');

            return;
        }

        $this->assertStringContainsString(
            '**zdjęcie profilowe**',
            $akapit,
            'Zakres Google zawiera profile (imię i zdjęcie na ekranie zgody), a polityka nie mówi, że Google pyta o zdjęcie profilowe.',
        );
        $this->assertStringContainsString('Nie zapisujemy ani nie pobieramy Twojego zdjęcia z Google', $akapit);
        $this->assertStringNotContainsString('oraz **Twoje imię** —', $akapit, 'Polityka znów wylicza samo imię jako trzecią rzecz, o którą prosimy Google.');
    }

    public function test_paragraf_9_nie_obiecuje_listu_o_zmianie_ktorego_nie_wysylamy(): void
    {
        $paragraf = $this->sekcja($this->polityka(), '## 9. Zmiany Polityki Prywatności');

        $this->assertDoesNotMatchRegularExpression(
            '/gdy będziemy już wysyłać|także e-mailem/u',
            $paragraf,
            'Polityka §9 obiecuje e-mail o zmianie polityki, a serwis takiego listu nie wysyła (o zmianie mówi pasek w serwisie, D-327).',
        );
        $this->assertStringContainsString('O istotnych zmianach poinformujemy z wyprzedzeniem powiadomieniem w serwisie', $paragraf);
        $this->assertStringContainsString('nie piszemy do Ciebie e-mailem', $paragraf);
        $this->assertStringContainsString('„Co się zmieniło”', $paragraf);

        // Gdyby list o zmianie polityki kiedyś powstał, §9 znów musi o nim
        // mówić. Szukamy go po kluczu konfiguracji wersji polityki.
        $pliki = array_merge(File::allFiles(app_path('Notifications')), File::allFiles(app_path('Mail')));
        $this->assertGreaterThan(10, count($pliki), 'Skaner listów nie znalazł klas w app/Notifications i app/Mail.');

        foreach ($pliki as $plik) {
            $this->assertStringNotContainsString(
                'wersja_polityki',
                $plik->getContents(),
                "{$plik->getFilename()} wygląda na list o zmianie polityki — dopisz go do §9 polityki i popraw ten test.",
            );
        }
    }

    private function dniRetencjiFailedJobs(): int
    {
        $zadanie = collect(app(Schedule::class)->events())->firstWhere('description', 'queue:prune-failed');
        $this->assertInstanceOf(CallbackEvent::class, $zadanie, 'W harmonogramie brak zadania queue:prune-failed.');

        $callback = (new ReflectionProperty(CallbackEvent::class, 'callback'))->getValue($zadanie);
        $this->assertInstanceOf(Closure::class, $callback);

        $godziny = (new ReflectionFunction($callback))->getStaticVariables()['parametry']['--hours'] ?? null;
        $this->assertIsInt($godziny, 'queue:prune-failed bez parametru --hours.');
        $this->assertSame(0, $godziny % 24, 'Retencja failed_jobs nie jest pełną liczbą dni — polityka podaje dni.');

        return intdiv($godziny, 24);
    }

    private function polityka(): string
    {
        return (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
    }

    private function linia(string $tresc, string $poczatek): string
    {
        foreach (explode("\n", $tresc) as $linia) {
            if (str_starts_with($linia, $poczatek)) {
                return $linia;
            }
        }

        $this->fail("Brak wiersza zaczynającego się od „{$poczatek}”.");
    }

    private function sekcja(string $tresc, string $naglowek): string
    {
        $start = mb_strpos($tresc, $naglowek);
        $this->assertNotFalse($start, "Brak sekcji „{$naglowek}”.");

        $reszta = mb_substr($tresc, $start + mb_strlen($naglowek));
        $koniec = mb_strpos($reszta, "\n## ");

        return $koniec === false ? $reszta : mb_substr($reszta, 0, $koniec);
    }
}
