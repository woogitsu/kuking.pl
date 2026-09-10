<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Ekran prywatności obiecuje dokładnie to, co serwis naprawdę robi — G08.
 *
 * HISTORIA TEGO PLIKU JEST JEGO UZASADNIENIEM
 * Wcześniej pole wyboru mówiło „Jeden e-mail tygodniowo, nigdy więcej"
 * w czasie teraźniejszym, a w całym repozytorium nie było ani polecenia, ani
 * zadania w harmonogramie, które wysyłałoby cokolwiek. Poprawką był dopisek
 * „Tych listów jeszcze nie wysyłamy", a ten test pilnował PARY: dopóki nie
 * ma czym wysyłać, tekst ma ostrzegać.
 *
 * **Ten dzień właśnie nadszedł** (issue #11, `docs/DECISIONS.md` D-057).
 * Polecenie `kuking:wyslij-podsumowania` istnieje, zadanie w harmonogramie
 * chodzi codziennie o 08:30, więc ostrzeżenie stało się nieprawdą w drugą
 * stronę — i test odwrócił się razem z rzeczywistością zamiast zniknąć.
 *
 * DLACZEGO NIE SKASOWALIŚMY GO, SKORO SAM TAK RADZIŁ
 * Bo wartość tego pliku nigdy nie leżała w jednym zdaniu, tylko w PILNOWANIU
 * PARY „tekst na ekranie" ↔ „kod, który go spełnia". Ta para istnieje dalej
 * i dalej potrafi się rozjechać, tylko teraz w przeciwną stronę: gdyby ktoś
 * usunął wysyłkę (albo tylko wpis w harmonogramie), ekran prywatności dalej
 * obiecywałby list, którego nikt nie wyśle — czyli dokładnie usterka G08,
 * od której się zaczęło. Skasowanie testu byłoby wyrzuceniem czujnika
 * dlatego, że wykrył zdarzenie, do którego był zbudowany.
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
     *
     * @var list<string>
     */
    private const SLOWA_WYSYLKI = ['digest', 'podsumowanie', 'podsumowania'];

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

    public function test_ekran_prywatnosci_nie_mowi_juz_ze_listow_nie_ma(): void
    {
        $odpowiedz = $this->actingAs($this->user('czytelnik'))->get(route('settings.privacy'));

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('Tych listów jeszcze nie wysyłamy', escape: false);
        $odpowiedz->assertSee('Jeden e-mail tygodniowo', escape: false);
    }

    /**
     * Druga połowa pary: skoro ekran obiecuje list, musi istnieć coś, co go
     * wysyła. Ten test pada, gdy ktoś usunie polecenie albo wpis
     * w harmonogramie i zostawi obietnicę bez pokrycia.
     */
    public function test_obietnica_ma_pokrycie_w_kodzie_ktory_wysyla(): void
    {
        $this->assertNotSame(
            [],
            $this->nadawcyPodsumowania(),
            'Ekran prywatności obiecuje tygodniowe podsumowanie, ale nic go już nie wysyła: '
            .'nie ma ani polecenia, ani zadania w harmonogramie. Albo przywróć wysyłkę, '
            .'albo popraw tekst w resources/views/pages/settings/privacy.blade.php.',
        );
    }

    public function test_zadanie_w_harmonogramie_nie_moze_sie_nakladac(): void
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains(mb_strtolower($e->description ?? ''), 'podsumowania'));

        $this->assertNotNull($zadanie, 'Brak zadania `kuking:wyslij-podsumowania` w harmonogramie.');

        // `withoutOverlapping()` nie jest tu ostrożnością: znacznik
        // `weekly_digest_sent_at` stawiany jest DOPIERO PO pętli, więc dwa
        // przebiegi naraz wysłałyby część listów podwójnie.
        $this->assertNotEmpty(
            $zadanie->withoutOverlapping,
            'Zadanie wysyłające podsumowania musi mieć `withoutOverlapping()`.',
        );
    }
}
