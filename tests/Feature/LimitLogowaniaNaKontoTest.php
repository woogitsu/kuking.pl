<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\KluczeLimitow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Atak rozproszony po wielu adresach na JEDNO konto (W7-01, R3 §5).
 *
 * CO BYŁO ZEPSUTE
 * Oba istniejące limitery logowania — middleware `throttle:5,1,login`
 * z `config/kuking.php` i ręczny `RateLimiter` w `LoginController` —
 * liczyły po kluczu zawierającym ADRES IP. Napastnik zmieniający adres
 * przy każdej próbie nie napotykał więc żadnego limitu: pięć prób z jednego
 * adresu było blokowane, ale pięćset prób z pięciuset adresów przechodziło
 * w całości.
 *
 * To nie jest luka „bo `trustProxies` ufa nagłówkowi". Zmiana adresu jest
 * dla napastnika tania także bez podszywania się pod proxy — botnet, sieć
 * mobilna, chmura. Limiter przywiązany WYŁĄCZNIE do adresu strukturalnie
 * nie widzi tego wzorca, niezależnie od tego, skąd bierze adres.
 *
 * DLACZEGO TO JEST NAPRAWIALNE TERAZ, MIMO ŻE W7-01 CZEKA NA PANEL
 * Pełna naprawa granicy zaufania (edge token w Cloudflare) wymaga zmian
 * w dwóch panelach i nie da się jej zrobić z kodu. Koszyk per KONTO nie
 * zależy od adresu wcale — działa niezależnie od tego, czy adres jest
 * prawdziwy, podrobiony, czy wspólny dla całego NAT-u.
 *
 * TRZY KOSZYKI, LICZBY Z R3 §5:
 *   A. para konto+adres — 5 prób / 1 min (tyle, ile jest dziś),
 *   B. samo konto       — 15 prób / 15 min  ← to zamyka tę lukę,
 *   C. sam adres        — 100 prób / 5 min.
 *
 * Koszyk B jest WYŻSZY niż suma kilku okien koszyka A i to jest celowe:
 * jego rolą nie jest chronić przed pomyłką człowieka, tylko przed atakiem,
 * którego koszyk A strukturalnie nie widzi. Osoba 50+ próbująca różnych
 * starych haseł przez kwadrans mieści się w nim z zapasem.
 */
class LimitLogowaniaNaKontoTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'zielonapietruszkarano';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('');
    }

    private function konto(): User
    {
        return $this->user('basia', ['email' => 'basia@example.com']);
    }

    /** Nieudana próba logowania z podanego adresu. */
    private function zlaProba(string $adres, string $login = 'basia@example.com'): int
    {
        return $this->post(route('login'), [
            'login' => $login,
            'password' => 'zle-haslo-'.$adres,
        ], ['X-Forwarded-For' => $adres])->getStatusCode();
    }

    /**
     * Czy serwis odmówił z powodu limitu.
     *
     * DWIE DROGI ODMOWY, obie liczą się jako blokada — i to nie jest
     * niechlujstwo testu, tylko stan faktyczny: middleware `throttle:` na
     * trasie odpowiada gołym **429**, a limitery w kontrolerze rzucają
     * `ValidationException` z komunikatem. Sprawdzanie tylko jednej z nich
     * dawało wynik zależny od tego, która bramka zadziałała pierwsza —
     * pierwsza wersja tego helpera patrzyła wyłącznie na komunikat i dlatego
     * asercja KONTROLNA padła, choć limiter działał poprawnie.
     */
    private function czyZablokowane(string $adres, string $login = 'basia@example.com'): bool
    {
        $odpowiedz = $this->from(route('login'))->post(route('login'), [
            'login' => $login,
            'password' => 'zle-haslo',
        ], ['X-Forwarded-For' => $adres]);

        if ($odpowiedz->getStatusCode() === 429) {
            return true;
        }

        // Błędy walidacji siedzą w sesji raz jako `ViewErrorBag`, raz jako
        // `MessageBag`, a raz jako zwykła tablica — zależnie od tego, czy
        // widok został już wyrenderowany. Obsługujemy wszystkie trzy
        // kształty, bo pierwsza wersja tego helpera zakładała tylko jeden:
        // na drugim wywalała się („Call to a member function first() on
        // array"), a na trzecim po cichu zwracała `false`, czyli raportowała
        // brak blokady tam, gdzie blokada była.
        $bledy = self::sesjaPrzekierowania($odpowiedz)->get('errors');

        $tekst = match (true) {
            $bledy instanceof ViewErrorBag, $bledy instanceof MessageBag => (string) $bledy->first('login'),
            is_array($bledy) => (string) json_encode($bledy, JSON_UNESCAPED_UNICODE),
            default => '',
        };

        return str_contains($tekst, 'Za dużo prób');
    }

    /**
     * KONTROLA. Bez niej test na atak rozproszony przechodziłby także wtedy,
     * gdyby limiter nie działał w ogóle — a wtedy nie mierzyłby luki, tylko
     * jej brak.
     */
    public function test_limit_z_jednego_adresu_dziala(): void
    {
        $this->konto();

        for ($i = 0; $i < 5; $i++) {
            $this->zlaProba('203.0.113.7');
        }

        $this->assertTrue(
            $this->czyZablokowane('203.0.113.7'),
            'Limiter nie zadziałał nawet dla powtórzonych prób z JEDNEGO adresu — test nie mierzy tego, co myśli.',
        );
    }

    /**
     * WŁAŚCIWY POMIAR. Ten sam login, każda próba z innego adresu.
     *
     * 20 prób to znacznie więcej niż 15 z koszyka B, więc po naprawie
     * blokada musi wystąpić. Przed naprawą nie występuje ani raz.
     */
    public function test_atak_rozproszony_po_adresach_zostaje_zatrzymany(): void
    {
        $this->konto();

        for ($i = 1; $i <= 20; $i++) {
            $this->zlaProba('198.51.100.'.$i);
        }

        $this->assertTrue(
            $this->czyZablokowane('198.51.100.200'),
            'Dwadzieścia nieudanych prób na to samo konto z dwudziestu różnych adresów nie wywołało żadnej blokady. '
            .'Limiter przywiązany do adresu strukturalnie nie widzi tego wzorca.',
        );
    }

    /**
     * Koszyk konta nie może karać SĄSIADA. Dwa różne konta z tego samego
     * adresu mają osobne koszyki B — inaczej jedna osoba w domu blokuje
     * logowanie drugiej.
     */
    public function test_koszyk_konta_nie_miesza_kont(): void
    {
        $this->konto();
        $this->user('marek', ['email' => 'marek@example.com']);

        for ($i = 1; $i <= 20; $i++) {
            $this->zlaProba('198.51.100.'.$i);
        }

        // Basia jest już zablokowana (test wyżej). Marek nie ma z tym nic
        // wspólnego i musi móc dalej próbować.
        $this->assertFalse(
            $this->czyZablokowane('203.0.113.9', 'marek@example.com'),
            'Blokada jednego konta zablokowała inne konto — koszyki się mieszają.',
        );
    }

    /**
     * Poprawne logowanie czyści koszyk pary i koszyk konta, ale NIE koszyk
     * adresu (R3 §5). Gdyby czyściło adres, napastnik zalogowałby się na
     * WŁASNE, jednorazowe konto z tego samego adresu, żeby zresetować
     * licznik adresowy i wrócić do ataku na cudze konto.
     */
    public function test_poprawne_logowanie_nie_czysci_koszyka_adresu(): void
    {
        $this->konto();
        $marek = $this->user('marek', ['email' => 'marek@example.com']);

        // Adres zbiera nieudane próby na RÓŻNE loginy — to jest wzorzec
        // rozpylania, który łapie koszyk C.
        for ($i = 1; $i <= 20; $i++) {
            $this->zlaProba('203.0.113.50', 'nieistniejacy'.$i.'@example.com');
        }

        $licznikPrzed = $this->licznikAdresu('203.0.113.50');

        // Marek loguje się poprawnie z TEGO SAMEGO adresu.
        $this->post(route('login'), [
            'login' => $marek->email,
            'password' => self::HASLO,
        ], ['X-Forwarded-For' => '203.0.113.50']);

        $this->assertGreaterThan(
            0,
            $licznikPrzed,
            'Koszyk adresu nie zebrał ani jednej próby — test nie mierzy tego, co myśli.',
        );

        $this->assertSame(
            $licznikPrzed,
            $this->licznikAdresu('203.0.113.50'),
            'Poprawne logowanie wyczyściło koszyk ADRESU. Napastnik może więc zalogować się na własne konto, '
            .'żeby zresetować licznik i wrócić do rozpylania po cudzych.',
        );
    }

    /**
     * Klucze limitera nie niosą adresu e-mail ani surowego IP (R3 §10).
     *
     * ZMIERZONE PRZED NAPRAWĄ, na store'rze `database` i na żywej bazie:
     * `cache.key => kuking-cache-jan.kowalski@example.com|203.0.113.7`.
     * `LoginController` sklejał klucz jako `login|ip`, a
     * `RateLimiter::cleanRateLimiterKey()` usuwa tylko kilka znaków —
     * NIE hashuje. Przy `CACHE_STORE=database` (produkcja) para adres
     * e-mail plus adres IP leżała więc w tabeli `cache` w postaci jawnej.
     *
     * DLACZEGO TEN TEST PATRZY NA KLUCZ, A NIE NA TABELĘ `cache`
     * `phpunit.xml` ustawia `CACHE_STORE=array`, więc tabela jest w testach
     * zawsze pusta — pierwsza wersja tego testu „przechodziła", nie
     * sprawdzając niczego, i dopiero asercja kontrolna („tabela jest pusta")
     * to pokazała. Przełączenie store'u w locie nie działa, bo `RateLimiter`
     * ma już rozwiązany sterownik z kontenera.
     *
     * Mierzymy więc WŁASNOŚĆ KLUCZA, bo to ona decyduje, co wyląduje
     * w cache'u niezależnie od sterownika. Że kontroler naprawdę używa tych
     * kluczy, dowodzi osobno `test_poprawne_logowanie_nie_czysci_koszyka_adresu`:
     * czyta licznik po kluczu z `KluczeLimitow` i gdyby kontroler liczył po
     * czymkolwiek innym, dostałby zero.
     */
    public function test_klucze_limitera_nie_niosa_adresu_email_ani_surowego_ip(): void
    {
        $klucze = app(KluczeLimitow::class);
        $email = 'jan.kowalski@example.com';
        $adres = '203.0.113.7';

        $wszystkie = implode(' ', [
            $klucze->para($email, $adres),
            $klucze->konto($email),
            $klucze->adres($adres),
        ]);

        $this->assertStringNotContainsString($email, $wszystkie, 'Adres e-mail siedzi w kluczu limitera w postaci jawnej.');
        $this->assertStringNotContainsString('jan.kowalski', $wszystkie, 'Część adresu e-mail siedzi w kluczu limitera.');
        $this->assertStringNotContainsString($adres, $wszystkie, 'Surowy adres IP siedzi w kluczu limitera w postaci jawnej.');

        // KONTROLA: klucze muszą się od siebie RÓŻNIĆ, inaczej trzy koszyki
        // byłyby jednym i test wyżej przechodziłby dla stałej.
        $this->assertNotSame($klucze->konto($email), $klucze->adres($adres));
        $this->assertNotSame($klucze->konto($email), $klucze->para($email, $adres));

        // KONTROLA: ten sam wsad daje ten sam klucz — bez tego licznik nie
        // mógłby się kumulować między żądaniami.
        $this->assertSame($klucze->konto($email), $klucze->konto($email));

        // Wielkość liter w loginie nie może tworzyć nowego koszyka.
        $this->assertSame($klucze->konto($email), $klucze->konto('Jan.Kowalski@Example.com'));

        // Ani białe znaki wokół loginu (SEC-01, punkt 3). `User::findByLogin()`
        // je obcina, więc „ jan.kowalski@example.com" to TO SAMO konto — a każdy
        // wariant zapisu z własnym koszykiem znaczyłby brak koszyka. Globalny
        // `TrimStrings` Laravela zamyka tę drogę od strony formularza, ale klucz
        // limitera nie ma prawa zależeć od middleware'u zrobionego do czego innego.
        $this->assertSame($klucze->konto($email), $klucze->konto('  '.$email.'  '));
        $this->assertSame($klucze->para($email, $adres), $klucze->para(' '.$email, $adres));
    }

    /** Ile prób zebrał koszyk adresu — czytane tak, jak liczy je kod. */
    private function licznikAdresu(string $adres): int
    {
        $klucz = app(KluczeLimitow::class)->adres($adres);

        return RateLimiter::attempts($klucz);
    }
}
