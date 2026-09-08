<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Ekran prywatności nie obiecuje listu, którego nikt nie wysyła — G08.
 *
 * CO BYŁO NIE TAK
 * Pole wyboru mówiło: „Jeden e-mail tygodniowo, nigdy więcej" — w czasie
 * teraźniejszym, jakby to się działo. Nie działo się: w całym repozytorium
 * nie ma ani polecenia, ani zadania w harmonogramie, które wysyłałoby
 * podsumowanie tygodnia. Kolumna `wants_weekly_digest` zapisywała zgodę
 * i na tym się kończyło.
 *
 * CZEGO TU CELOWO NIE ZROBIONO
 * Pole wyboru NIE zostało usunięte. Digest stoi w
 * `docs/product/RETENTION_LOOPS.md` (pętla 9) jako funkcja MVP i jedyny
 * kanał docierający do ludzi, którzy nie zaglądają codziennie —
 * wykasowanie go byłoby wycięciem funkcji z planu, a nie naprawą usterki.
 * Zgoda jest już opt-in (`default false`, migracja
 * `2026_09_07_400000_default_weekly_digest_to_off`), więc nikt nie jest
 * zapisany bez pytania. Zmienił się sam TEKST — z obietnicy na prawdę.
 *
 * DRUGA POŁOWA TEGO PLIKU JEST WAŻNIEJSZA OD PIERWSZEJ
 * Zdanie „jeszcze nie wysyłamy" jest prawdziwe DZIŚ. W dniu, w którym
 * digest ruszy, stanie się kłamstwem w drugą stronę — a nikt o tym nie
 * pamięta w miesiąc po fakcie. Test niżej pilnuje tej pary: dopóki nie ma
 * czym wysyłać, tekst ma ostrzegać; gdy pojawi się polecenie albo zadanie
 * w harmonogramie, test padnie i przypomni, że tekst trzeba zaktualizować.
 *
 * TEGO PLIKU NIE MA W CZĘŚCI O SAMEJ ZGODZIE — pilnuje jej osobno
 * `ZgodaNaPrzegladNieJestDomyslnaTest` (rejestracja nie zapisuje nikogo,
 * `DEFAULT` w bazie jest wyłączony, ekran ustawień nadal pozwala się
 * zapisać). Tutaj chodzi wyłącznie o to, czy TEKST mówi prawdę.
 */
class ObietnicaTygodniowegoMailaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Słowa, po których poznajemy wysyłkę podsumowania — i tylko ją.
     *
     * Świadomie NIE ma tu samego „tydzień": `kuking:wac` liczy Weekly
     * Active Cooks co tydzień i nie ma nic wspólnego z wysyłaniem listów.
     * Test, który zapala się na cudzej nazwie, przestaje cokolwiek znaczyć.
     */
    private const SLOWA_WYSYLKI = ['digest', 'podsumowanie'];

    /** @return list<string> */
    private function nadawcyPodsumowania(): array
    {
        $trafienia = [];

        foreach (array_keys(Artisan::all()) as $nazwa) {
            foreach (self::SLOWA_WYSYLKI as $slowo) {
                if (str_contains(mb_strtolower($nazwa), $slowo)) {
                    $trafienia[] = "polecenie {$nazwa}";
                }
            }
        }

        foreach (app(Schedule::class)->events() as $zadanie) {
            $opis = mb_strtolower($zadanie->description ?? $zadanie->command ?? '');

            foreach (self::SLOWA_WYSYLKI as $slowo) {
                if ($opis !== '' && str_contains($opis, $slowo)) {
                    $trafienia[] = "zadanie {$opis}";
                }
            }
        }

        return array_values(array_unique($trafienia));
    }

    public function test_ekran_prywatnosci_mowi_ze_listow_jeszcze_nie_ma(): void
    {
        $odpowiedz = $this->actingAs($this->user('czytelnik'))->get(route('settings.privacy'));

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('Tych listów jeszcze nie wysyłamy', escape: false);
    }

    /**
     * Para, którą trzeba trzymać razem: jeśli powstanie coś, co wysyła
     * podsumowanie, tekst na ekranie prywatności PRZESTAJE być prawdziwy.
     */
    public function test_gdy_powstanie_wysylka_podsumowan_tekst_trzeba_poprawic(): void
    {
        $nadawcy = $this->nadawcyPodsumowania();

        $this->assertSame(
            [],
            $nadawcy,
            'Coś zaczęło wysyłać podsumowania tygodnia ('.implode(', ', $nadawcy).'), '
            .'więc ekran prywatności nie może już mówić „Tych listów jeszcze nie wysyłamy”. '
            .'Popraw tekst w resources/views/pages/settings/privacy.blade.php i usuń ten test.',
        );
    }
}
