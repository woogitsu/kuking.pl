<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ZglosNielegalnaTresc;
use App\Models\Report;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Ponowienie zgłoszenia prawnego po awarii (issue #1323) i tożsamość
 * wysłania razem z danymi kontaktowymi (issue #1325).
 *
 * KONTROLA UJEMNA (wykonana ręcznie przy poprawce):
 *  - przywrócenie wczesnego `return $istniejace;` bez `potwierdzOdbior()`
 *    oblewa `test_ponowienie_po_awarii_zlecenia_zleca_dokladnie_jedno_potwierdzenie`
 *    i `test_awaria_po_wpisaniu_zadania_nie_mnozy_listow`;
 *  - przywrócenie osobnego `save()` znacznika po `notify()` oblewa
 *    `test_awaria_po_wpisaniu_zadania_nie_mnozy_listow` (zadanie zostaje
 *    w `jobs`, a ponowienie dokłada drugie);
 *  - usunięcie porównania `notifier_name` / `notifier_email` oblewa oba
 *    testy z #1325.
 */
class ZgloszeniePrawnePonowienieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Żadnego prawdziwego listu, niezależnie od tego, co test podmienia.
        Mail::fake();
    }

    /** @return array<string, string|null> */
    private function zgloszenie(array $zmiany = []): array
    {
        return array_merge([
            'imie' => 'Anna Kowalska',
            'email' => 'anna@kancelaria.example',
            'adres' => 'https://kuking.pl/przepisy/rosol-babci',
            'uzasadnienie' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'powod' => 'copyright',
            'kluczWyslania' => (string) Str::uuid7(),
        ], $zmiany);
    }

    private function wyslij(array $dane): Report
    {
        return app(ZglosNielegalnaTresc::class)->handle(...$dane);
    }

    /**
     * Kolejka bazodanowa, jak na produkcji — w `phpunit.xml` jest `sync`,
     * który wysłałby list od razu i nie zostawiłby wiersza w `jobs`.
     */
    private function kolejkaBazodanowa(): void
    {
        config(['queue.default' => 'database']);
    }

    private function zadaniaWKolejce(): int
    {
        return DB::table('jobs')->count();
    }

    public function test_ponowienie_po_awarii_zlecenia_zleca_dokladnie_jedno_potwierdzenie(): void
    {
        $poczta = new class extends NotificationFake
        {
            public bool $awaria = true;

            public function send($notifiables, $notification)
            {
                if ($this->awaria) {
                    $this->awaria = false;

                    throw new RuntimeException('Symulowana awaria zlecenia potwierdzenia.');
                }

                parent::send($notifiables, $notification);
            }
        };
        Notification::swap($poczta);

        $dane = $this->zgloszenie();

        try {
            $this->wyslij($dane);
            $this->fail('Awaria zlecenia potwierdzenia nie dotarła do wołającego.');
        } catch (RuntimeException $e) {
            $this->assertSame('Symulowana awaria zlecenia potwierdzenia.', $e->getMessage());
        }

        // Sprawa zostaje — awaria listu nie wycofuje przyjętego zgłoszenia.
        $sprawa = Report::query()->sole();
        $this->assertNull($sprawa->receipt_sent_at, 'Znacznik został, choć list nie został zlecony.');
        $poczta->assertNothingSent();

        $ponowienie = $this->wyslij($dane);

        $this->assertSame($sprawa->getKey(), $ponowienie->getKey(), 'Ponowienie założyło drugą sprawę.');
        $this->assertSame(1, Report::query()->count());
        $poczta->assertSentOnDemandTimes(PotwierdzenieZgloszeniaNielegalnejTresci::class, 1);
        $this->assertNotNull($ponowienie->receipt_sent_at);

        // Trzecie wysłanie tego samego — już nic nowego.
        $this->wyslij($dane);
        $poczta->assertSentOnDemandTimes(PotwierdzenieZgloszeniaNielegalnejTresci::class, 1);
    }

    public function test_awaria_po_wpisaniu_zadania_nie_mnozy_listow(): void
    {
        $this->kolejkaBazodanowa();

        // Awaria PO tym, jak wiersz zadania jest już w `jobs` — to miejsce,
        // w którym stary kod zostawiał list w kolejce przy pustym znaczniku.
        $awaria = true;
        DB::listen(function (QueryExecuted $zapytanie) use (&$awaria): void {
            if ($awaria && str_starts_with($zapytanie->sql, 'insert into "jobs"')) {
                $awaria = false;

                throw new RuntimeException('Symulowana awaria po zleceniu potwierdzenia.');
            }
        });

        $dane = $this->zgloszenie();

        try {
            $this->wyslij($dane);
            $this->fail('Awaria po zleceniu potwierdzenia nie dotarła do wołającego.');
        } catch (RuntimeException) {
        }

        $sprawa = Report::query()->sole();
        $this->assertNull($sprawa->receipt_sent_at);
        $this->assertSame(0, $this->zadaniaWKolejce(), 'Zadanie zostało w kolejce, choć znacznik się cofnął — ponowienie wyśle drugi list.');

        $this->wyslij($dane);
        $this->wyslij($dane);

        $this->assertSame(1, Report::query()->count());
        $this->assertSame(1, $this->zadaniaWKolejce(), 'Ponowienia po awarii zleciły więcej niż jedno potwierdzenie.');
        $this->assertNotNull($sprawa->refresh()->receipt_sent_at);
    }

    public function test_dwa_klikniecia_to_jedno_zadanie_a_nowe_wyslanie_to_nowa_sprawa(): void
    {
        $this->kolejkaBazodanowa();

        $dane = $this->zgloszenie();
        $this->wyslij($dane);
        $this->wyslij($dane);

        $this->assertSame(1, Report::query()->count());
        $this->assertSame(1, $this->zadaniaWKolejce());

        // Kontrola dodatnia: nowe, niezależne wysłanie (nowy klucz) dalej
        // zakłada własną sprawę i dostaje własne potwierdzenie.
        $this->wyslij($this->zgloszenie());

        $this->assertSame(2, Report::query()->count());
        $this->assertSame(2, $this->zadaniaWKolejce());
    }

    public function test_ponowienie_bez_adresu_email_niczego_nie_wysyla(): void
    {
        Notification::fake();

        $dane = $this->zgloszenie(['imie' => null, 'email' => null, 'powod' => 'minor']);
        $pierwsza = $this->wyslij($dane);
        $druga = $this->wyslij($dane);

        $this->assertSame($pierwsza->getKey(), $druga->getKey(), 'Anonimowe zgłoszenie kliknięte dwa razy dało dwie sprawy.');
        $this->assertSame(1, Report::query()->count());
        $this->assertNull($druga->receipt_sent_at);
        Notification::assertNothingSent();
    }

    private function numerZPotwierdzenia(TestResponse $odpowiedz): string
    {
        $odpowiedz->assertSessionHasNoErrors()->assertRedirect();
        $this->withCookie((string) config('session.cookie'), session()->getId());
        $numer = $this->get($odpowiedz->headers->get('Location'))->assertOk()->viewData('numer');
        $this->assertIsString($numer);

        return $numer;
    }

    /** @return array<string, string> */
    private function formularz(string $klucz, array $zmiany = []): array
    {
        return array_merge([
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
            'klucz_wyslania' => $klucz,
        ], $zmiany);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function daneKontaktowe(): array
    {
        return [
            'inny e-mail' => ['notifier_email', 'jan@example.com'],
            'inne imię' => ['notifier_name', 'Jan Nowak'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('daneKontaktowe')]
    public function test_ten_sam_klucz_z_innymi_danymi_kontaktowymi_to_nowa_sprawa(string $pole, string $wartosc): void
    {
        Notification::fake();

        $klucz = (string) Str::uuid7();

        $numerPierwszy = $this->numerZPotwierdzenia($this->post(route('zglos.nielegalna.store'), $this->formularz($klucz)));
        $numerDrugi = $this->numerZPotwierdzenia($this->post(route('zglos.nielegalna.store'), $this->formularz($klucz, [$pole => $wartosc])));

        $this->assertSame(2, Report::query()->count(), 'Zmienione dane kontaktowe zostały uznane za to samo wysłanie i po cichu odrzucone.');
        $this->assertNotSame($numerPierwszy, $numerDrugi, 'Nowa osoba zobaczyła numer cudzej sprawy.');

        $pierwsza = Report::query()->where('numer_sprawy', $numerPierwszy)->sole();
        $druga = Report::query()->where('numer_sprawy', $numerDrugi)->sole();

        $this->assertSame($klucz, $pierwsza->klucz_wyslania);
        $this->assertSame('anna@kancelaria.example', $pierwsza->notifier_email);
        $this->assertSame('Anna Kowalska', $pierwsza->notifier_name);
        $this->assertSame($wartosc, $druga->{$pole}, 'Nowe dane kontaktowe nie trafiły do bazy.');
        $this->assertNull($druga->klucz_wyslania);

        Notification::assertSentOnDemandTimes(PotwierdzenieZgloszeniaNielegalnejTresci::class, 2);
    }

    public function test_identyczne_wyslanie_przez_formularz_to_jedna_sprawa(): void
    {
        // Kontrola dodatnia do testu wyżej: bez zmiany danych kontaktowych
        // drugie kliknięcie dalej jest tą samą sprawą z jednym listem.
        Notification::fake();

        $klucz = (string) Str::uuid7();

        $numerPierwszy = $this->numerZPotwierdzenia($this->post(route('zglos.nielegalna.store'), $this->formularz($klucz)));
        $numerDrugi = $this->numerZPotwierdzenia($this->post(route('zglos.nielegalna.store'), $this->formularz($klucz)));

        $this->assertSame(1, Report::query()->count());
        $this->assertSame($numerPierwszy, $numerDrugi);
        Notification::assertSentOnDemandTimes(PotwierdzenieZgloszeniaNielegalnejTresci::class, 1);
    }
}
