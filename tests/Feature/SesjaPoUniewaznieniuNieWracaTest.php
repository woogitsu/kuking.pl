<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\PendingEmailChange;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * #1046: żądanie rozpoczęte PRZED unieważnieniem sesji nie może jej odtworzyć.
 *
 * DWA PRAWDZIWE PROCESY, NIE SYMULACJA ZAPISU. Wolne żądanie to osobny proces
 * PHP z realnym kernelem, database sessions i zaszyfrowanymi cookies
 * (`tests/Support/remember-request.php`). Zatrzymuje się na barierze PO
 * kontrolerze, a PRZED zapisem sesji przez `StartSession` — w tym czasie
 * drugi proces wykonuje operację bezpieczeństwa, potem bariera puszcza
 * i wolne żądanie kończy się zapisem sesji wczytanej przed odwołaniem.
 *
 * KTÓRE ŻĄDANIE NAPRAWDĘ WSKRZESZA. Zwykły GET z istniejącą sesją zapisuje
 * się `UPDATE`-em (`DatabaseSessionHandler::write()`, `exists = true`), więc
 * po skasowaniu wiersza trafia w zero wierszy. Wiersz wraca `INSERT`-em, gdy
 * żądanie w trakcie NADAJE NOWY identyfikator: logowanie z ciasteczka
 * „zapamiętaj mnie” (`SessionGuard::updateSession()` → `migrate(true)`),
 * logowanie hasłem, linkiem, 2FA. Dlatego wolne żądanie niesie tu SAMO
 * ciasteczko zapamiętania — tak wygląda obca przeglądarka, której sesja
 * wygasła, a recaller jeszcze nie.
 *
 * MACIERZ OPERACJI: wylogowanie innych urządzeń, zmiana i reset hasła, ban,
 * zawieszenie, zgłoszenie usunięcia, potwierdzenie zmiany e-maila, włączenie
 * 2FA (moderator bez 2FA — stawką jest `/admin/**`) oraz awans na moderatora
 * i admina z 2FA i bez. Przy 2FA wolnym żądaniem jest POST kodu zapasowego
 * (konto z 2FA nie ma recallera), więc odtworzona sesja niesie dowód 2FA.
 *
 * @bez-kontroli-dodatniej nie czyta źródeł — `base_path()` wskazuje skrypt procesu HTTP, a kontrola ujemna (strażnik jako przepust) jest testem w tej klasie.
 */
class SesjaPoUniewaznieniuNieWracaTest extends TestCase
{
    use DatabaseMigrations;

    private const HASLO = 'haslo-testowe-123';

    private const NOWE_HASLO = 'nowe-bezpieczne-haslo-1046';

    private const NOWY_ADRES = 'nowy.adres.1046@kuking.pl';

    /** Kody zapasowe kont z 2FA: jeden na logowanie właściciela, drugi na obcą przeglądarkę. */
    private const KODY_ZAPASOWE = ['ABCD-1234', 'EFGH-5678'];

    protected function tearDown(): void
    {
        // Cofnięcie migracji 2FA odmawia, dopóki konto ma ją potwierdzoną (D-238).
        User::query()->whereNotNull('two_factor_confirmed_at')->get()->each->disableTwoFactor();

        parent::tearDown();
    }

    private function konfiguracja(): array
    {
        return [
            'database.default' => 'pgsql', 'database.connections.pgsql' => config('database.connections.pgsql'),
            'app.key' => config('app.key'), 'app.debug' => false,
            'session.driver' => 'database', 'session.secure' => false,
            'cache.default' => 'array', 'mail.default' => 'array', 'queue.default' => 'sync',
            'kuking.turnstile.klucz_publiczny' => '', 'kuking.turnstile.sekret' => '',
        ];
    }

    private function proces(array $jar, string $method, string $path, array $data = [], array $dodatkowe = []): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/remember-request.php')], base_path(), null, null, 60);
        $process->setInput(json_encode([
            'cookies' => $jar, 'method' => $method, 'path' => $path, 'data' => $data,
            'config' => $this->konfiguracja(),
        ] + $dodatkowe, JSON_THROW_ON_ERROR));

        return $process;
    }

    private function odpowiedz(Process $process, array &$jar): array
    {
        // Nie wypisujemy odpowiedzi: zawiera cookies i token formularza.
        $this->assertSame(0, $process->getExitCode(), 'Proces HTTP zakończył się błędem.');
        $response = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $jar = array_filter(array_replace($jar, $response['cookies']), fn ($value) => $value !== null);

        return $response;
    }

    private function request(array &$jar, string $method, string $path, array $data = [], array $dodatkowe = []): array
    {
        $process = $this->proces($jar, $method, $path, $data, $dodatkowe);
        $process->run();

        return $this->odpowiedz($process, $jar);
    }

    /**
     * Start wolnego żądania: wraca dopiero, gdy proces wczytał sesję,
     * uwierzytelnił się i przeszedł kontroler — ale jeszcze niczego nie zapisał.
     *
     * @return array{Process, string}
     */
    private function wolneZadanie(array $jar, string $method = 'GET', string $path = '/ustawienia/bezpieczenstwo', array $data = []): array
    {
        $katalog = sys_get_temp_dir().'/kuking-1046-'.bin2hex(random_bytes(6));
        mkdir($katalog);
        $process = $this->proces($jar, $method, $path, $data, ['bariera' => $katalog]);
        $process->start();

        $koniec = microtime(true) + 30;
        while (! file_exists($katalog.'/wczytane') && $process->isRunning() && microtime(true) < $koniec) {
            usleep(20_000);
        }
        $this->assertFileExists($katalog.'/wczytane', 'Wolne żądanie nie dotarło do bariery.');

        return [$process, $katalog];
    }

    private function dokoncz(array $wolne, array &$jar): array
    {
        [$process, $katalog] = $wolne;
        touch($katalog.'/dalej');
        $process->wait();
        @unlink($katalog.'/wczytane');
        @unlink($katalog.'/dalej');
        @rmdir($katalog);

        return $this->odpowiedz($process, $jar);
    }

    private function token(array $response): string
    {
        $this->assertSame(200, $response['status']);
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', $response['body'], $matches));

        return $matches[1];
    }

    /** Przy koncie z 2FA drugi krok to kod zapasowy (TOTP nie przejdzie dwa razy w tym samym oknie). */
    private function login(User $user, ?string $kodZapasowy = null): array
    {
        $jar = [];
        $token = $this->token($this->request($jar, 'GET', '/login'));
        $odpowiedz = $this->request($jar, 'POST', '/login', ['_token' => $token, 'login' => $user->email, 'password' => self::HASLO]);
        $this->assertSame(302, $odpowiedz['status']);

        if ($kodZapasowy !== null) {
            $this->assertSame('http://localhost/logowanie/kod', $odpowiedz['location']);
            $token = $this->token($this->request($jar, 'GET', '/logowanie/kod'));
            $this->assertSame(302, $this->request($jar, 'POST', '/logowanie/kod', ['_token' => $token, 'backup_code' => $kodZapasowy])['status']);
        }

        $this->assertSame(200, $this->request($jar, 'GET', '/ustawienia/bezpieczenstwo')['status']);

        return $jar;
    }

    /** Sama „zapamiętana” przeglądarka: sesja wygasła, recaller jeszcze nie. */
    private function tylkoRecaller(array $jar): array
    {
        $remembered = array_filter($jar, fn ($name) => str_starts_with($name, 'remember_'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $remembered);

        return $remembered;
    }

    /**
     * Obca przeglądarka i jej wolne żądanie: [jar, metoda, ścieżka, dane].
     *
     * Konto bez 2FA: sam recaller, wolny GET (logowanie z „zapamiętaj mnie”).
     * Konto z 2FA nie dostaje recallera (`TwoFactorChallengeController`:
     * `remember: false`), więc tam wolnym żądaniem jest sam POST kodu
     * zapasowego — logowanie z dowodem 2FA w sesji, zapisane po operacji.
     * To najgroźniejszy wariant: odtworzona sesja niesie dowód drugiego składnika.
     *
     * @return array{array, string, string, array}
     */
    private function obcaPrzegladarka(User $user, bool $konto2fa): array
    {
        if (! $konto2fa) {
            return [$this->tylkoRecaller($this->login($user)), 'GET', '/ustawienia/bezpieczenstwo', []];
        }

        $jar = [];
        $token = $this->token($this->request($jar, 'GET', '/login'));
        $odpowiedz = $this->request($jar, 'POST', '/login', ['_token' => $token, 'login' => $user->email, 'password' => self::HASLO]);
        $this->assertSame('http://localhost/logowanie/kod', $odpowiedz['location']);
        $token = $this->token($this->request($jar, 'GET', '/logowanie/kod'));

        return [$jar, 'POST', '/logowanie/kod', ['_token' => $token, 'backup_code' => self::KODY_ZAPASOWE[1]]];
    }

    private function kodStrony(array &$jar, array $dodatkowe = []): int
    {
        return $this->request($jar, 'GET', '/ustawienia/bezpieczenstwo', [], $dodatkowe)['status'];
    }

    /** Operacja bezpieczeństwa wykonana w trakcie wolnego żądania. */
    private function odwolaj(string $operacja, User $user, array &$wlasciciel): void
    {
        switch ($operacja) {
            case 'wyloguj-inne':
                $token = $this->token($this->request($wlasciciel, 'GET', '/ustawienia/bezpieczenstwo'));
                $this->assertSame(302, $this->request($wlasciciel, 'POST', '/ustawienia/bezpieczenstwo/wyloguj-inne', ['_token' => $token, 'password' => self::HASLO])['status']);
                break;
            case 'zmiana-hasla':
                $token = $this->token($this->request($wlasciciel, 'GET', '/ustawienia/bezpieczenstwo'));
                $this->assertSame(302, $this->request($wlasciciel, 'PUT', '/ustawienia/bezpieczenstwo/haslo', ['_token' => $token, 'current_password' => self::HASLO, 'password' => self::NOWE_HASLO, 'password_confirmation' => self::NOWE_HASLO])['status']);
                break;
            case 'reset-hasla':
                $reset = Password::createToken($user);
                $gosc = [];
                $token = $this->token($this->request($gosc, 'GET', '/login'));
                $response = $this->request($gosc, 'POST', '/nowe-haslo', ['_token' => $token, 'token' => $reset, 'email' => $user->email, 'password' => self::NOWE_HASLO, 'password_confirmation' => self::NOWE_HASLO]);
                $this->assertSame('http://localhost/login', $response['location']);
                break;
            case 'zmiana-emaila':
                // Link z listu na NOWY adres, kliknięty w sesji właściciela
                // (`ConfirmEmailChange`). Żądanie powstaje wprost w bazie:
                // zamówienie przez formularz odmawia przy `mail.default=array`.
                $zmiana = new PendingEmailChange;
                $zmiana->user_id = $user->getKey();
                $zmiana->new_email = self::NOWY_ADRES;
                $zmiana->created_at = now();
                $zmiana->expires_at = now()->addHours(1);
                $zmiana->save();
                URL::forceRootUrl('http://localhost'); // host procesu HTTP, bez niego podpis nie pasuje
                $link = parse_url(URL::signedRoute('settings.email.confirm', ['zmiana' => $zmiana->getKey()]));
                $odpowiedz = $this->request($wlasciciel, 'GET', $link['path'].'?'.$link['query']);
                $this->assertSame('http://localhost/ustawienia/e-mail', $odpowiedz['location']);
                $this->assertSame(self::NOWY_ADRES, $user->fresh()->email, 'Potwierdzenie nie zmieniło adresu — test niczego nie zmierzył.');
                break;
            case 'wlacz-2fa':
                // Włączenie 2FA hasłem i kodem z aplikacji (`TwoFactorSettingsController::confirm`).
                $token = $this->token($this->request($wlasciciel, 'GET', '/ustawienia/2fa/wlacz'));
                $kod = (new Google2FA)->getCurrentOtp((string) $user->fresh()->two_factor_secret);
                $odpowiedz = $this->request($wlasciciel, 'POST', '/ustawienia/2fa/wlacz', ['_token' => $token, 'code' => $kod, 'password' => self::HASLO]);
                $this->assertSame('http://localhost/ustawienia/2fa/kody-zapasowe', $odpowiedz['location']);
                $this->assertTrue($user->fresh()->hasTwoFactorConfirmed(), '2FA nie została włączona — test niczego nie zmierzył.');
                break;
            case 'awans-moderator':
            case 'awans-admin':
                // Awans z powłoki (`ChangeUserRole`). Sesje są w bazie, jak na produkcji.
                config(['session.driver' => 'database']);
                $rola = $operacja === 'awans-admin' ? User::ROLE_ADMIN : User::ROLE_MODERATOR;
                $this->artisan('kuking:nadaj-role', ['login' => $user->email, 'rola' => $rola, '--tak' => true])->assertSuccessful();
                $this->assertSame($rola, $user->fresh()->role);
                break;
            default:
                // ban / suspend / markForDeletion — moderator albo automat, nie
                // właściciel. Konto wraca potem do `active`, żeby status nie
                // maskował powrotu sesji (ten sam chwyt co w #584).
                $user->{$operacja}();
                $operacja === 'markForDeletion' ? $user->cancelDeletion() : $user->reinstate();
        }
    }

    public static function operacje(): array
    {
        return [
            'wyloguj inne urządzenia' => ['wyloguj-inne', true],
            'zmiana hasła' => ['zmiana-hasla', true],
            'reset hasła' => ['reset-hasla', false],
            'ban' => ['ban', false],
            'zawieszenie' => ['suspend', false],
            'zgłoszenie usunięcia' => ['markForDeletion', false],
            'potwierdzenie zmiany e-maila' => ['zmiana-emaila', true],
            'włączenie 2FA (moderator)' => ['wlacz-2fa', true],
            'awans na moderatora, konto bez 2FA' => ['awans-moderator', false, false],
            'awans na moderatora, konto z 2FA' => ['awans-moderator', false, true],
            'awans na admina, konto bez 2FA' => ['awans-admin', false, false],
            'awans na admina, konto z 2FA' => ['awans-admin', false, true],
        ];
    }

    /** Operacje dopisane do macierzy po audycie 25.09 (e-mail, 2FA, awans roli) — ich kontrola ujemna. */
    public static function noweOperacje(): array
    {
        return array_slice(self::operacje(), 6, null, true);
    }

    /** Operacje, po których stara sesja mogłaby dostać panel `/admin/**`. */
    private static function dotykaPanelu(string $operacja): bool
    {
        return $operacja === 'wlacz-2fa' || str_starts_with($operacja, 'awans-');
    }

    /**
     * Konto do próby. Włączenie 2FA robi moderator bez 2FA — dla niego panel
     * jest stawką (#930); awans zaczyna od zwykłego konta, z 2FA lub bez.
     */
    private function konto(string $operacja, bool $konto2fa): User
    {
        $user = $this->user(null, $operacja === 'wlacz-2fa' ? ['role' => User::ROLE_MODERATOR] : []);

        if ($konto2fa) {
            $totp = app(TwoFactorAuthenticator::class);
            $user->beginTwoFactorSetup($totp->generateSecret());
            $user->confirmTwoFactor($totp->hashBackupCodes(self::KODY_ZAPASOWE));
        }

        return $user->refresh();
    }

    /** Osobna próba na KOPII ciasteczek: odrzucone żądanie czyści jar, a druga próba byłaby już gościem. */
    private function probaNaKopii(array $jar, string $path, array $dodatkowe = []): array
    {
        return $this->request($jar, 'GET', $path, [], $dodatkowe);
    }

    #[DataProvider('operacje')]
    public function test_wolne_zadanie_nie_odtwarza_odwolanej_sesji(string $operacja, bool $zachowujeBiezaca, bool $konto2fa = false): void
    {
        $user = $this->konto($operacja, $konto2fa);
        $wlasciciel = $this->login($user, $konto2fa ? self::KODY_ZAPASOWE[0] : null);
        [$obca, $metoda, $sciezka, $dane] = $this->obcaPrzegladarka($user, $konto2fa);
        $tokenPrzed = $user->fresh()->getRememberToken();

        $wolne = $this->wolneZadanie($obca, $metoda, $sciezka, $dane);
        $this->odwolaj($operacja, $user, $wlasciciel);
        $koniec = $this->dokoncz($wolne, $obca);

        // Kontrola dodatnia, że wyścig naprawdę zaszedł: wolne żądanie
        // przeszło kontroler zalogowane i ZAPISAŁO świeży wiersz sesji
        // z `user_id` po operacji, która sesje tego konta skasowała.
        $this->assertSame($konto2fa ? 302 : 200, $koniec['status']);
        $this->assertTrue(
            DB::table('sessions')->where('user_id', $user->getKey())->count() >= ($zachowujeBiezaca ? 2 : 1),
            'Wolne żądanie nie zapisało sesji — test niczego nie zmierzył.',
        );

        // Panel i zwykła trasa `auth` na kopiach jaru — przed próbami, które
        // czyszczą ciasteczka odrzuconej sesji. Ani awans, ani włączenie 2FA
        // nie mogą dać starej sesji `/admin/**` (ani ekranu „włącz 2FA” po awansie).
        if (self::dotykaPanelu($operacja)) {
            $panel = $this->probaNaKopii($obca, '/admin/zgloszenia');
            $this->assertSame(302, $panel['status'], 'Odtworzona stara sesja weszła do /admin/** albo do ekranu 2FA.');
            $this->assertSame('http://localhost/login', $panel['location']);
        }
        $zwykla = $this->probaNaKopii($obca, '/ustawienia/bezpieczenstwo');
        $this->assertSame(302, $zwykla['status'], 'Odwołana sesja weszła na trasę auth.');
        $this->assertSame('http://localhost/login', $zwykla['location']);

        $this->assertSame(302, $this->kodStrony($obca), 'Odwołana sesja wróciła po zapisie wolnego żądania.');
        $this->assertSame(302, $this->kodStrony($obca), 'Odwołana sesja wróciła przy kolejnym żądaniu.');
        $this->assertFalse(hash_equals($tokenPrzed, (string) $user->fresh()->getRememberToken()), 'Rotacja remember_token przestała działać.');

        if ($zachowujeBiezaca) {
            $this->assertSame(200, $this->kodStrony($wlasciciel), 'Bieżąca sesja właściciela nie przetrwała.');
            $this->assertSame(1, DB::table('sessions')->where('user_id', $user->getKey())->count(), 'Poza bieżącą przetrwała inna sesja.');

            if (self::dotykaPanelu($operacja)) {
                // Kod padł w sesji właściciela — do panelu wchodzi bez nowego logowania.
                $this->assertSame(200, $this->probaNaKopii($wlasciciel, '/admin/zgloszenia')['status'], 'Bieżąca sesja właściciela straciła panel.');
            }
        }
    }

    /**
     * Kontrola dodatnia: bez odwołania wolne i zwykłe żądania tej samej
     * osoby działają RÓWNOLEGLE — poprawka niczego nie serializuje.
     */
    public function test_bez_odwolania_rownolegle_zadania_dzialaja(): void
    {
        $user = $this->user();
        $wlasciciel = $this->login($user);
        $obca = $this->tylkoRecaller($this->login($user));

        $wolne = $this->wolneZadanie($obca);
        // Drugie żądanie tego samego konta kończy się, gdy pierwsze stoi.
        $this->assertSame(200, $this->kodStrony($wlasciciel));
        $this->assertTrue($wolne[0]->isRunning(), 'Wolne żądanie skończyło się przed czasem — nie było równoległości.');
        $this->assertSame(200, $this->dokoncz($wolne, $obca)['status']);

        $this->assertSame(200, $this->kodStrony($obca));
        $this->assertSame(200, $this->kodStrony($wlasciciel));
    }

    /**
     * Kontrola ujemna: ten sam przebieg ze strażnikiem generacji podmienionym
     * na przepust odtwarza powrót starej sesji — czyli test mierzy błąd, a nie
     * coś, co przechodziłoby zawsze.
     */
    public function test_bez_straznika_generacji_sesja_wraca(): void
    {
        $user = $this->user();
        $wlasciciel = $this->login($user);
        $obca = $this->tylkoRecaller($this->login($user));

        $wolne = $this->wolneZadanie($obca);
        $this->odwolaj('wyloguj-inne', $user, $wlasciciel);
        $this->assertSame(200, $this->dokoncz($wolne, $obca)['status']);

        $this->assertSame(200, $this->kodStrony($obca, ['bez_straznika_generacji' => true]), 'Kontrola ujemna nie odtworzyła błędu.');
    }

    /**
     * Kontrola ujemna dla nowych operacji: bez strażnika generacji ten sam
     * przebieg oddaje odtworzonej sesji zwykłą trasę `auth`. To dowodzi, że
     * asercje `302` w teście wyżej mierzą strażnika, a nie martwą trasę
     * albo sesję, która i tak by wygasła.
     */
    #[DataProvider('noweOperacje')]
    public function test_bez_straznika_generacji_sesja_wraca_po_kazdej_operacji(string $operacja, bool $zachowujeBiezaca, bool $konto2fa = false): void
    {
        $user = $this->konto($operacja, $konto2fa);
        $wlasciciel = $this->login($user, $konto2fa ? self::KODY_ZAPASOWE[0] : null);
        [$obca, $metoda, $sciezka, $dane] = $this->obcaPrzegladarka($user, $konto2fa);

        $wolne = $this->wolneZadanie($obca, $metoda, $sciezka, $dane);
        $this->odwolaj($operacja, $user, $wlasciciel);
        $this->assertSame($konto2fa ? 302 : 200, $this->dokoncz($wolne, $obca)['status']);

        $bezStraznika = ['bez_straznika_generacji' => true];
        $this->assertSame(200, $this->probaNaKopii($obca, '/ustawienia/bezpieczenstwo', $bezStraznika)['status'], 'Kontrola ujemna nie odtworzyła błędu.');

        if ($operacja === 'wlacz-2fa') {
            // Bez dowodu 2FA w odtworzonej sesji panel i tak odsyła na ekran 2FA (403) — ale sesja żyje, a strażnik ją zabija (302 wyżej).
            $this->assertSame(403, $this->probaNaKopii($obca, '/admin/zgloszenia', $bezStraznika)['status'], 'Kontrola ujemna: stara sesja moderatora miała dojść do ekranu 2FA.');
        }

        if ($konto2fa && self::dotykaPanelu($operacja)) {
            // Bez strażnika odtworzona sesja niesie dowód 2FA i nową rolę: panel stoi otworem.
            $this->assertSame(200, $this->probaNaKopii($obca, '/admin/zgloszenia', $bezStraznika)['status'], 'Kontrola ujemna nie otworzyła panelu.');
        }
    }
}
