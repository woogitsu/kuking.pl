<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Żaden sekret nie wraca na ekran ani do sesji (audyt W7-03, W7-04, W7-12).
 *
 * DWIE DZIURY, JEDNA PRZYCZYNA
 * Produkt ma regułę „poprawnie wpisane dane nigdy nie znikają" i realizował
 * ją w dwóch miejscach, każde z własną czarną listą nazw pól:
 *
 *   - zawieszone konto: gołe `back()->withInput()`, czyli hasło do sesji
 *     i z powrotem do `value=` pola typu password;
 *   - ekran 419: filtr po fragmentach `password`, `haslo`, `token`, `secret`,
 *     `otp`, `cvv`. Formularz drugiego składnika nazywa pola `code`
 *     i `backup_code` — nie zawierają żadnej z tych cząstek, więc KOD
 *     ZAPASOWY wracał do HTML-a w ukrytym polu. Kod jednorazowy żyje
 *     30 sekund; zapasowy jest ważny aż do użycia.
 *
 * Czarna lista nazw pól przegrywa zawsze, bo wymaga, żeby autor następnego
 * formularza z sekretem nazwał pole tak, jak ktoś przewidział wcześniej.
 * Teraz decyduje NAZWA TRASY (`App\Support\OdzyskiwalneDane`) — i ten test
 * pilnuje obu stron tej zamiany: że sekrety nie wracają ORAZ że treść,
 * której szkoda, dalej wraca.
 */
class SekretyNieWracajaNaEkranTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'ZNACZNIK-HASLA-9d41';

    private const KOD_ZAPASOWY = 'ZNACZNIK-KODU-ZAPASOWEGO-7c02';

    /**
     * Prawdziwy 419, przez HTTP.
     *
     * `PreventRequestForgery` przepuszcza każde żądanie, gdy aplikacja
     * chodzi pod PHPUnitem (`runningUnitTests()`). Bez podmiany środowiska
     * ten test sprawdzałby własną atrapę zamiast tego, co widzi człowiek —
     * dostawałby 302 albo 200 i przechodził niezależnie od tego, co ta
     * strona naprawdę renderuje. Ta sama sztuczka co
     * w `StronyBleduPoPolskuTest`.
     *
     * @param  array<string, mixed>  $dane
     */
    private function ekran419(string $metoda, string $adres, array $dane): string
    {
        $poprzednie = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            $odpowiedz = $this->withSession(['_token' => 'token-sesji'])
                ->call($metoda, $adres, $dane + ['_token' => 'token-z-wygaslej-strony']);
        } finally {
            $this->app['env'] = $poprzednie;
        }

        $odpowiedz->assertStatus(419);

        return $odpowiedz->getContent() ?: '';
    }

    public function test_zawieszone_konto_nie_odklada_hasla_do_sesji(): void
    {
        // SEC-03. Ekran bezpieczeństwa jest podczas zawieszenia do ODCZYTU,
        // więc osoba zawieszona może wysłać formularz zmiany hasła — i wtedy
        // to middleware, nie kontroler, decyduje, co zostaje z jej żądania.
        $osoba = $this->user('zawieszony');
        $osoba->forceFill([
            'status' => User::STATUS_SUSPENDED,
            'status_expires_at' => now()->addWeek(),
        ])->save();

        $odpowiedz = $this->actingAs($osoba)
            ->from('/ustawienia/bezpieczenstwo')
            ->put(route('settings.security.password'), [
                '_token' => csrf_token(),
                'current_password' => self::HASLO.'-STARE',
                'password' => self::HASLO,
                'password_confirmation' => self::HASLO,
            ]);

        $odpowiedz->assertRedirect();

        $stare = session('_old_input', []);
        $this->assertIsArray($stare);
        $this->assertStringNotContainsString(
            self::HASLO,
            json_encode($stare, JSON_THROW_ON_ERROR),
            'Hasło trafiło do flasha sesji — stamtąd `old()` wstawi je z powrotem do formularza.',
        );

        // I najważniejsze: nie ma go też na ekranie, do którego wracamy.
        $this->actingAs($osoba)
            ->get('/ustawienia/bezpieczenstwo')
            ->assertOk()
            ->assertDontSee(self::HASLO, escape: false);
    }

    public function test_ekran_419_nie_oddaje_kodu_zapasowego_do_2fa(): void
    {
        // SEC-04. To jest ta dziura, której sześć fragmentów nazw nie łapało:
        // `code` i `backup_code` nie zawierają ani `password`, ani `token`,
        // ani `otp`.
        $html = $this->ekran419('POST', '/logowanie/kod', [
            'code' => '123456',
            'backup_code' => self::KOD_ZAPASOWY,
        ]);

        $this->assertStringNotContainsString(self::KOD_ZAPASOWY, $html,
            'Kod zapasowy do 2FA wrócił w HTML-u strony 419. Jest ważny aż do użycia.');
        $this->assertStringNotContainsString('123456', $html,
            'Kod jednorazowy wrócił w HTML-u strony 419.');
    }

    public function test_ekran_419_nie_oddaje_niczego_z_logowania_ani_ze_zmiany_hasla(): void
    {
        // Deny-by-default: te trasy nie są na liście tras treści, więc
        // odzyskiwanie na nich w ogóle się nie uruchamia.
        // Te trasy nie mają nawet nazwy, więc nie mogą trafić na listę tras
        // treści — i o to chodzi: nowy ekran logowania nie ma jak wejść na
        // tę listę przez przypadek.
        foreach (['/login', '/register', '/nowe-haslo'] as $adres) {
            $html = $this->ekran419('POST', $adres, [
                'login' => 'anna@example.com',
                'password' => self::HASLO,
                'password_confirmation' => self::HASLO,
            ]);

            $this->assertStringNotContainsString(self::HASLO, $html, "Hasło wróciło z {$adres}.");
            $this->assertStringNotContainsString('anna@example.com', $html, "Login wrócił z {$adres}.");
        }
    }

    public function test_ale_tresc_ktorej_szkoda_dalej_wraca(): void
    {
        // Druga strona tej samej reguły. Zamiana czarnej listy na białą jest
        // po coś tylko wtedy, gdy nie zabiła przy okazji funkcji, dla której
        // odzyskiwanie w ogóle powstało (#81).
        $dlugiKomentarz = 'Robiłam to wczoraj i wyszło znakomicie, tylko dałam mniej soli, '
            .'bo mój mąż ma nadciśnienie i lekarz kazał mu uważać.';

        $html = $this->ekran419('POST', '/przepisy/rosol-babci/komentarz', [
            'body' => $dlugiKomentarz,
        ]);

        $this->assertStringContainsString(
            $dlugiKomentarz,
            $html,
            'Komentarz przepadł przy wygasłej sesji — to jest dokładnie to, przed czym broni issue #81.',
        );
    }

    public function test_nawet_na_trasie_tresci_pole_z_sekretem_nie_wraca(): void
    {
        // Druga warstwa. Gdyby kiedyś na trasę treści trafiło pole z hasłem
        // (formularz importu z cudzego serwisu, cokolwiek), i tak nie wróci.
        $html = $this->ekran419('POST', '/przepisy/rosol-babci/komentarz', [
            'body' => 'Zwykły komentarz.',
            'password' => self::HASLO,
            'backup_code' => self::KOD_ZAPASOWY,
        ]);

        $this->assertStringContainsString('Zwykły komentarz.', $html);
        $this->assertStringNotContainsString(self::HASLO, $html);
        $this->assertStringNotContainsString(self::KOD_ZAPASOWY, $html);
    }

    public function test_pole_hasla_nigdy_nie_ma_wypelnionej_wartosci(): void
    {
        // Druga warstwa, na wypadek gdyby kiedyś coś jednak wpadło do flasha.
        $html = $this->withSession(['_old_input' => ['password' => self::HASLO]])
            ->get('/login')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::HASLO, $html);
    }
}
