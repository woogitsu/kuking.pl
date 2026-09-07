<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Report;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Odpowiedź dla zgłaszającego nie może mówić, że treść zniknęła, jeśli
 * została (audyt zewnętrzny N06).
 *
 * CO BYŁO ZEPSUTE
 * `DecyzjaWSprawieZgloszenia` liczyło skutek jednym warunkiem:
 *
 *     $usunieta = $this->decyzja->action !== ModerationAction::ACTION_NONE;
 *
 * czyli „cokolwiek poza brakiem działania znaczy, że treści już nie ma".
 * Tymczasem treść przestaje być dostępna WYŁĄCZNIE przy `hide` i `remove`.
 * Ostrzeżenie, zawieszenie konta i ban nie ruszają zgłoszonej treści —
 * `ModerationController::zastosuj()` robi dla nich odpowiednio nic z treścią,
 * `suspend()` i `ban()` na koncie.
 *
 * NAJGORSZY PRZYPADEK, i to on nadaje temu wagę: przy zgłoszeniu OSOBY
 * (`target_type = 'user'`) macierz `ModerationAction::DOZWOLONE` nie
 * dopuszcza ani `hide`, ani `remove`. Każde uznane za zasadne zgłoszenie
 * konta wysyłało więc zgłaszającemu zdanie, które nie mogło być prawdziwe.
 *
 * DLACZEGO NIE PISZEMY, CO DOKŁADNIE ZROBILIŚMY Z KONTEM
 * Bo to dane osobowe osoby trzeciej. Zgłaszający ma prawo wiedzieć, czy
 * jego zgłoszenie uznano i czy zgłoszona treść jest jeszcze dostępna —
 * nie ma prawa wiedzieć, kogo i jak ukarano. Stąd trzy komunikaty, nie
 * siedem: zasadne i treść zniknęła, zasadne ale treść zostaje, niezasadne.
 */
class OdpowiedzDlaZglaszajacegoMowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    private function tresc(string $akcja, string $celTypu = 'post'): string
    {
        // ZGŁOSZENIE PRAWNE, nie społecznościowe — bo tylko takie dostaje
        // ten mail (`maAdresDoOdpowiedzi()` w `ModerationController`).
        // Baza tego pilnuje dwoma CHECK-ami: społecznościowe musi mieć
        // konkretny cel, a prawne musi mieć uzasadnienie, oświadczenie
        // o dobrej wierze i imię zgłaszającego. Pierwsza wersja tych danych
        // testowych nie miała `source`, więc wpadła na pierwszy z nich —
        // i to jest dobra wiadomość: baza nie przyjmuje niespójnego wiersza.
        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => $celTypu,
            'target_id' => null,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo …',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_RESOLVED,
        ]);

        $decyzja = new ModerationAction(['action' => $akcja]);

        $mail = (new DecyzjaWSprawieZgloszenia($zgloszenie, $decyzja))
            ->toMail((object) []);

        return implode(' ', array_map(
            fn ($linia): string => is_string($linia) ? $linia : '',
            [$mail->subject ?? '', ...$mail->introLines, ...$mail->outroLines],
        ));
    }

    /**
     * KONTROLA. Przy prawdziwym usunięciu treści zdanie o niedostępności
     * jest PRAWDZIWE i musi zostać — bez tego testu naprawa mogłaby
     * wyciąć je zawsze i zgłaszający nie dowiedziałby się niczego.
     */
    public function test_przy_usunieciu_tresci_mowimy_ze_zniknela(): void
    {
        $tresc = $this->tresc(ModerationAction::ACTION_REMOVE);

        $this->assertStringContainsString('nie jest już dostępna', $tresc);
        $this->assertStringContainsString('zasadne', $tresc);
    }

    public function test_przy_ukryciu_tresci_mowimy_ze_zniknela(): void
    {
        $this->assertStringContainsString(
            'nie jest już dostępna',
            $this->tresc(ModerationAction::ACTION_HIDE),
        );
    }

    /**
     * WŁAŚCIWY POMIAR. Ostrzeżenie nie rusza treści.
     */
    public function test_przy_ostrzezeniu_nie_mowimy_ze_tresc_zniknela(): void
    {
        $tresc = $this->tresc(ModerationAction::ACTION_WARN);

        $this->assertStringNotContainsString(
            'nie jest już dostępna',
            $tresc,
            'Mail mówi zgłaszającemu, że treść zniknęła, a ostrzeżenie jej nie rusza.',
        );

        // Zgłoszenie BYŁO zasadne — to musi się dać odczytać, inaczej
        // zgłaszający nie wie, czy cokolwiek zrobiliśmy.
        $this->assertStringContainsString('zasadne', $tresc);
    }

    public function test_przy_zawieszeniu_konta_nie_mowimy_ze_tresc_zniknela(): void
    {
        $this->assertStringNotContainsString(
            'nie jest już dostępna',
            $this->tresc(ModerationAction::ACTION_SUSPEND),
            'Zawieszenie konta nie usuwa zgłoszonej treści, a mail twierdzi inaczej.',
        );
    }

    /**
     * Przy zgłoszeniu OSOBY `hide` i `remove` nie są w ogóle dozwolone, więc
     * każda zasadna decyzja tutaj to `warn`, `suspend` albo `ban`. Zdanie
     * o niedostępności treści nie mogło być prawdziwe ani razu.
     */
    public function test_przy_zgloszeniu_konta_nigdy_nie_mowimy_o_usunietej_tresci(): void
    {
        foreach ([ModerationAction::ACTION_WARN, ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN] as $akcja) {
            $this->assertStringNotContainsString(
                'nie jest już dostępna',
                $this->tresc($akcja, 'user'),
                "Decyzja `{$akcja}` na zgłoszeniu konta twierdzi, że treść zniknęła.",
            );
        }
    }

    /** KONTROLA drugiej strony: odmowa nadal mówi, że treść zostaje. */
    public function test_odmowa_nadal_mowi_ze_tresc_zostaje(): void
    {
        $tresc = $this->tresc(ModerationAction::ACTION_NONE);

        $this->assertStringContainsString('zostaje w serwisie', $tresc);
        $this->assertStringNotContainsString('zasadne', $tresc);
    }

    /**
     * Mail nigdy nie może nieść nazwy ani adresu ukaranej osoby — to dane
     * osobowe osoby trzeciej, a mechanizm zgłoszeń nie jest narzędziem do
     * ustalania, kogo ukarano.
     */
    public function test_mail_nie_zdradza_kogo_ukarano(): void
    {
        foreach ([ModerationAction::ACTION_WARN, ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN, ModerationAction::ACTION_REMOVE] as $akcja) {
            $tresc = mb_strtolower($this->tresc($akcja));

            foreach (['zawiesiliśmy', 'zbanowaliśmy', 'ostrzegliśmy autora', 'konto autora'] as $zdradza) {
                $this->assertStringNotContainsString($zdradza, $tresc, "Mail zdradza szczegół kary przy `{$akcja}`.");
            }
        }
    }
}
