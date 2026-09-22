<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Na list z Kuking musi się dać odpisać — żadnego `noreply@`.
 *
 * SKĄD TA ZASADA
 * `docs/brand/BRAND_EXTENDED.md` §5.1: „adres `kontakt@kuking.pl`, nie
 * `noreply@`. Odpisanie musi być możliwe." To nie jest preferencja
 * stylistyczna. Osoba, która dostała list z linkiem do zmiany hasła i nie
 * rozumie, co się dzieje, robi jedną rzecz: odpisuje na tego maila. Przy
 * skrzynce, która odbija odpowiedzi, jedyny kanał kontaktu, jaki ta osoba
 * zna, kończy się automatem po angielsku — a ona zostaje bez konta.
 *
 * DLACZEGO TEST, A NIE SAM KOMENTARZ W KONFIGURACJI
 * Adres nadawcy jest ustawiany w CZTERECH miejscach i każde z nich potrafi
 * się rozjechać z pozostałymi:
 *
 *   1. `config/mail.php` — wartość domyślna, używana, gdy nikt nie ustawi
 *      zmiennej środowiskowej (a na Railway zmienne ustawia człowiek);
 *   2. `.env.example` — z niego kopiuje się każde nowe środowisko;
 *   3. `.railway/railway.ts` — TO on wygrywa na produkcji, bo
 *      `railway config apply` nadpisuje nim zmienną w panelu;
 *   4. panel Railway — poza zasięgiem testów, wyłącznie w rękach właściciela.
 *
 * Pierwsze trzy da się sprawdzić tutaj i to jest cała treść tego pliku.
 * Czwartego nie da się i dlatego `kuking:sprawdz-poczte` wypisuje adres
 * nadawcy wprost, zanim cokolwiek wyśle.
 *
 * CZEGO TU NIE MA
 * Nie sprawdzamy, czy skrzynka faktycznie odbiera pocztę — tego nie da się
 * zrobić z suity testów i udawanie takiej kontroli byłoby gorsze niż jej
 * brak. Sprawdzamy to, co jest sprawdzalne: że adres nie jest z góry
 * ustawiony na taki, który odpowiedzi nie przyjmie.
 */
final class NadawcaPocztyNieJestNoreplyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Warianty zapisu tego samego pomysłu. Sprawdzamy początek adresu, a nie
     * całość, bo zakazane jest `noreply@czegokolwiek`, nie jeden konkretny
     * adres.
     *
     * @var list<string>
     */
    private const ZAKAZANE_POCZATKI = [
        'noreply@',
        'no-reply@',
        'no_reply@',
        'donotreply@',
        'do-not-reply@',
        'nie-odpowiadaj@',
        'nieodpowiadaj@',
        'bounce@',
    ];

    /** Konfiguracja załadowana w aplikacji — czyli to, czym poleci najbliższy list. */
    public function test_konfiguracja_nie_ma_nadawcy_bez_odpowiedzi(): void
    {
        $this->assertAdresPrzyjmujeOdpowiedzi(
            (string) config('mail.from.address'),
            'config(mail.from.address)',
        );
    }

    /**
     * Wartość DOMYŚLNA z `config/mail.php` — ta, która zostaje, gdy nikt nie
     * ustawi `MAIL_FROM_ADDRESS`. Na Railway zmienne ustawia człowiek
     * i człowiek potrafi ich nie ustawić.
     */
    public function test_domyslna_wartosc_w_konfiguracji_nie_jest_noreply(): void
    {
        $repozytorium = Env::getRepository();
        $kopia = $repozytorium->get('MAIL_FROM_ADDRESS');
        $repozytorium->clear('MAIL_FROM_ADDRESS');

        try {
            /** @var array{from: array{address: string}} $poczta */
            $poczta = require base_path('config/mail.php');

            $this->assertAdresPrzyjmujeOdpowiedzi($poczta['from']['address'], 'config/mail.php');
        } finally {
            if ($kopia !== null) {
                $repozytorium->set('MAIL_FROM_ADDRESS', $kopia);
            }
        }
    }

    /** Prawdziwie wysłana wiadomość — nagłówek `From`, a nie sama konfiguracja. */
    public function test_wyslany_list_ma_nadawce_przyjmujacego_odpowiedzi(): void
    {
        $user = $this->user(null, ['email' => 'basia@example.com']);

        $user->sendPasswordResetNotification(Password::createToken($user));

        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        $wiadomosci = $transport->messages();

        $this->assertNotEmpty($wiadomosci, 'Aplikacja nie wysłała żadnej wiadomości.');

        /** @var Email $email */
        $email = $wiadomosci->last()->getOriginalMessage();

        $this->assertAdresPrzyjmujeOdpowiedzi($email->getFrom()[0]->getAddress(), 'nagłówek From');
    }

    /**
     * `.env.example` — z niego kopiuje się każde nowe środowisko, więc zła
     * wartość tutaj rozjeżdża się po wszystkich naraz.
     */
    public function test_env_example_nie_podpowiada_noreply(): void
    {
        $this->assertPlikBezNoreply(base_path('.env.example'));
    }

    /**
     * `.railway/railway.ts` — TO ten plik wygrywa na produkcji: `railway
     * config apply` nadpisuje nim zmienną w panelu, więc poprawienie samego
     * `config/mail.php` nic tam nie zmienia. Dokładnie tak przeżył w tym
     * pliku adres `kuchnia@kuking.pl` po tym, jak reszta repozytorium
     * dostała już `kontakt@`.
     */
    public function test_konfiguracja_railway_nie_ustawia_noreply(): void
    {
        $this->assertPlikBezNoreply(base_path('.railway/railway.ts'));
    }

    private function assertPlikBezNoreply(string $sciezka): void
    {
        $this->assertFileExists($sciezka);

        $tresc = (string) file_get_contents($sciezka);

        $this->assertNotSame('', trim($tresc), "Plik {$sciezka} jest pusty — kontrola nic by nie sprawdziła.");

        // KONTROLA POZYTYWNA: plik w ogóle mówi o nadawcy. Bez niej asercja
        // „nie ma noreply" przechodziłaby także wtedy, gdyby ktoś usunął
        // ustawienie adresu w całości.
        $this->assertStringContainsString(
            'MAIL_FROM_ADDRESS',
            $tresc,
            "Plik {$sciezka} nie ustawia już MAIL_FROM_ADDRESS — ten test przestał czegokolwiek pilnować.",
        );

        preg_match_all('/MAIL_FROM_ADDRESS[^\n]*/u', $tresc, $trafienia);

        foreach ($trafienia[0] as $linia) {
            foreach (self::ZAKAZANE_POCZATKI as $zakazany) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $zakazany,
                    $linia,
                    "W {$sciezka} nadawcą jest adres, który nie przyjmuje odpowiedzi: {$linia}",
                );
            }
        }
    }

    private function assertAdresPrzyjmujeOdpowiedzi(string $adres, string $gdzie): void
    {
        $adres = mb_strtolower(trim($adres));

        $this->assertNotSame('', $adres, "Adres nadawcy ({$gdzie}) jest pusty.");

        foreach (self::ZAKAZANE_POCZATKI as $zakazany) {
            $this->assertFalse(
                str_starts_with($adres, $zakazany),
                "Nadawcą ({$gdzie}) jest „{$adres}”. `docs/brand/BRAND_EXTENDED.md` tego zabrania: "
                .'na list z Kuking ma się dać odpisać.',
            );
        }
    }
}
