<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DRZWI WEJŚCIOWE NIE MOGĄ OBIECYWAĆ CZEGOŚ, CO SIĘ NIE STAŁO.
 *
 * SKĄD TE TESTY
 * 63-letnia osoba z grupy docelowej chciała założyć konto, trafiła na ekran
 * „Wyślij mi link do zalogowania" — bo jego pierwsze zdanie brzmiało „Podaj
 * adres e-mail, NA KTÓRY ZAKŁADASZ KONTO" — wpisała swój adres i dostała
 *
 * zielone „Wysłaliśmy list na adres e***@gmail.com". Wiadomość nigdy nie
 * przyszła, bo konta pod tym adresem nie było, a logowanie linkiem świadomie
 * odpowiada identycznie dla adresu z kontem i bez konta (D-056). W panelu
 * `/admin/uzytkownicy` też jej nie było — i to jest zgodne: konto nie
 * powstało.
 *
 * Czyli: produkt powiedział człowiekowi nieprawdę, a potem zostawił go
 * z pustą skrzynką i bez wyjścia.
 *
 * DWIE RZECZY, KTÓRE TE TESTY PILNUJĄ NARAZ:
 *  1. komunikat jest PRAWDZIWY dla obu przypadków i ma wyjście („załóż
 *     konto"), a ekran mówi, dla kogo jest;
 *  2. odpowiedź nadal jest IDENTYCZNA dla adresu z kontem i bez konta — bo
 *     inaczej naprawa jednej rzeczy zrobiłaby z tego ekranu wyrocznię „kto
 *     ma konto w Kuking".
 */
class DrzwiWejsciowePrawdaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // W testach `MAIL_MAILER=array`, a `App\Support\Poczta` uznaje to
        // (słusznie) za brak poczty — ekran pokazuje wtedy zupełnie inną
        // treść: „nie wysyłamy jeszcze wiadomości". Tu sprawdzamy ekran
        // działający, więc podstawiamy sterownik, który przechodzi kontrolę.
        // Tak samo robi `LogowanieLinkiemTest`.
        config(['mail.default' => 'smtp']);
    }

    #[Test]
    public function test_ekran_linku_mowi_ze_jest_dla_osob_z_kontem_i_prowadzi_do_rejestracji(): void
    {
        $odpowiedz = $this->get(route('login.link'))->assertOk();

        $odpowiedz->assertSee('już mają konto w Kuking', false);
        $odpowiedz->assertSee('Nie masz jeszcze konta?', false);
        $odpowiedz->assertSee(route('register'), false);

        // Zdanie, które wprowadziło w błąd, nie może wrócić.
        $odpowiedz->assertDontSee('na który zakładasz konto', false);
    }

    #[Test]
    public function test_komunikat_nie_twierdzi_ze_wyslal_gdy_konta_nie_ma(): void
    {
        Mail::fake();

        $this->post(route('login.link.send'), ['email' => 'nieznana@example.test'] + $this->turnstile())
            ->assertRedirect();

        $komunikat = (string) session('status');

        // „Wysłaliśmy" bez warunku było nieprawdą — tu nic nie wyszło.
        $this->assertStringNotContainsString('Wysłaliśmy list', $komunikat);
        $this->assertStringContainsString('Jeśli na adres', $komunikat);
        $this->assertStringContainsString('jest konto w Kuking', $komunikat);

        // Wyjście dla człowieka, który trafił tu zamiast na rejestrację.
        $this->assertStringContainsString(route('register'), $komunikat);
    }

    #[Test]
    public function test_odpowiedz_jest_identyczna_dla_konta_i_dla_braku_konta(): void
    {
        Mail::fake();

        $this->user('istniejaca', ['email' => 'istniejaca@example.test']);

        $this->post(route('login.link.send'), ['email' => 'istniejaca@example.test'] + $this->turnstile());
        $zKontem = (string) session('status');

        session()->forget('status');

        $this->post(route('login.link.send'), ['email' => 'istniejaca@example.test.brak'] + $this->turnstile());
        $bezKonta = (string) session('status');

        // MASKA ADRESU RÓŻNI SIĘ, BO BIERZE SIĘ Z WPISANEGO TEKSTU — reszta
        // zdania musi być identyczna, inaczej ekran zdradza, kto ma konto.
        $bezAdresu = static fn (string $tekst): string => (string) preg_replace('/\S+@\S+/u', 'ADRES', $tekst);

        $this->assertSame($bezAdresu($zKontem), $bezAdresu($bezKonta));

        // Asercja kontrolna: gdyby maskowanie zjadło całe zdanie, powyższe
        // porównanie przechodziłoby dla dwóch pustych napisów.
        $this->assertStringContainsString('jest konto w Kuking', $bezAdresu($zKontem));
    }

    #[Test]
    public function test_ekrany_nie_mowia_o_liscie_tam_gdzie_chodzi_o_e_mail(): void
    {
        /*
         * „List" to poczta w kopercie — zgłoszenie właściciela, dosłownie:
         * „nie »list« tylko email, mail, wiadomość email itp, bo list to
         * poczta, fizyczny".
         *
         * Sprawdzamy WYŁĄCZNIE formy jednoznaczne. „Listy", „liście"
         * i „listach" są wspólne dla „listu" i dla „listy składników", więc
         * ich tu nie ma — inaczej ten test oblewałby się o kreatora przepisu.
         */
        $formy = ['/\blistu\b/u', '/\blistem\b/u', '/\blistowi\b/u', '/\blistami\b/u', '/\blistom\b/u', '/\bWysłaliśmy list\b/u', '/\bten list\b/u'];

        $ekrany = [
            'ustawienia adresu' => 'resources/views/pages/settings/email.blade.php',
            'ustawienia prywatności' => 'resources/views/pages/settings/privacy.blade.php',
            'wypisano z podsumowania' => 'resources/views/pages/podsumowanie-wrocono.blade.php',
            'list w panelu wiadomości' => 'resources/views/pages/admin/wiadomosc.blade.php',
            'podsumowanie tygodnia' => 'resources/views/mail/podsumowanie-tygodnia.blade.php',
            'odpowiedź na wiadomość' => 'resources/views/mail/odpowiedz-na-wiadomosc.blade.php',
        ];

        foreach ($ekrany as $nazwa => $sciezka) {
            // Komentarze Blade (`{{-- … --}}`) i komentarze PHP nie są tekstem
            // dla człowieka — wycinamy je, tak samo jak robi to test
            // o dostępności jobów w CI.
            $tresc = (string) file_get_contents(base_path($sciezka));
            $tresc = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $tresc);
            $tresc = (string) preg_replace('/\{\{--.*$/s', '', $tresc);

            foreach ($formy as $forma) {
                $this->assertDoesNotMatchRegularExpression(
                    $forma,
                    $tresc,
                    "Ekran {$nazwa} mówi o liście, a chodzi o e-mail ({$sciezka}).",
                );
            }
        }
    }

    #[Test]
    public function test_zaznaczony_wybor_nie_jest_czerwony_jak_blad(): void
    {
        /*
         * ZDARZENIE, KTÓRE TEN TEST PILNUJE
         * Zaznaczony wybór miał `--color-brand` (#B3401F), błąd ma
         * `--color-danger` (#B3261E). Dla człowieka to ten sam czerwony.
         * 63-latka poprawiała w rejestracji dwa wielkie czerwone pudła, które
         * były POPRAWNIE zaznaczonymi zgodami, i nie zauważyła prawdziwego
         * błędu wypisanego czerwonym tekstem wyżej.
         */
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression(
            '/\.choice:has\(input:checked\)\s*\{[^}]*--color-success/',
            $css,
            'Zaznaczony wybór znowu jest w kolorze marki — czyli w kolorze błędu.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\.choice:has\(input:checked\)\s*\{[^}]*--color-brand-tint/',
            $css,
            'Zaznaczony wybór ma tło w czerwieni marki, nie do odróżnienia od błędu.',
        );

        // Czerwień zostaje dla błędu — i to też musi zostać prawdą, bo inaczej
        // naprawa jednego kolory zabrałaby jedyny sygnał, który ma być czerwony.
        $this->assertMatchesRegularExpression(
            '/\.field\.has-error \.choice:not\(:has\(input:checked\)\)\s*\{[^}]*--color-danger/',
            $css,
            'Niezaznaczona, wymagana zgoda nie jest czerwona — czyli błąd nie ma już koloru.',
        );
    }

    #[Test]
    public function test_po_bledzie_rejestracji_widac_o_co_chodzi_takze_bez_javascriptu(): void
    {
        // Wysłanie z pustymi zgodami: człowiek zostaje na dole formularza,
        // więc tam MUSI stać zdanie mówiące, że nic nie wyszło i gdzie patrzeć.
        // `followingRedirects`, bo błędy walidacji żyją w sesji dokładnie
        // jedno żądanie: osobne `get()` w teście dostaje już czystą sesję
        // i pokazałoby formularz bez błędów — czyli test sprawdzałby ekran,
        // którego człowiek w tej sytuacji nie widzi.
        $ekran = $this->followingRedirects()->from(route('register'))->post(route('register'), [
            'display_name' => 'Grażynka K',
            'username' => 'grazynka z podlasia',
            'email' => 'grazynka@example.test',
            'password' => 'zielonapietruszkarano',
        ])->assertOk();

        $ekran->assertSee('Formularz nie został wysłany', false);
        $ekran->assertSee('co trzeba poprawić', false);

        // Podsumowanie na górze zostaje — jedno nie zastępuje drugiego
        // (UX_50_PLUS.md: błąd przy polu ORAZ podsumowanie).
        $ekran->assertSee('Sprawdź formularz', false);
    }

    #[Test]
    public function test_javascript_przewija_do_podsumowania_bledow(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('.error-summary', $js);
        $this->assertStringContainsString('scrollIntoView', $js);

        // Zaznaczenie zgody ma natychmiast zdjąć czerwień, a nie czekać na
        // kolejne wysłanie formularza.
        $this->assertStringContainsString("classList.remove('has-error')", $js);
    }

    /** @return array<string, string> */
    private function turnstile(): array
    {
        // Turnstile jest w testach wyłączony brakiem kluczy (D-050), więc
        // token nie jest potrzebny — pole zostaje puste dla jasności.
        return [];
    }
}
