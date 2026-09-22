<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Domain\Users\Actions\CancelAccountDeletion;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\PotwierdzenieZadaniaRodo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * POTWIERDZENIE I SKUTEK ALBO SĄ OBA, ALBO NIE MA ŻADNEGO.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DLACZEGO TO JEST OSOBNY PLIK, A NIE ASERCJA DOKLEJONA DO TESTU AKCJI
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Bo mierzy coś, czego nie widać w szczęśliwym przebiegu. Test, który woła
 * `EraseAccountData::handle()` i sprawdza, że powstał wiersz `wykonane`,
 * przechodzi TAK SAMO wtedy, gdy potwierdzenie idzie osobną transakcją —
 * a wtedy rejestr potrafi kłamać w obie strony:
 *
 *  - **skutek bez wiersza**: dane wymazane, potwierdzenia brak. Dowód
 *    wykonania prawa z art. 17 nie istnieje, a odtworzyć go nie ma z czego,
 *    bo konto jest już zanonimizowane;
 *  - **wiersz bez skutku**: potwierdzenie twierdzi „wykonane", a wymazanie
 *    się nie odbyło. To jest gorsze: rejestr, który ma być dowodem, staje
 *    się dowodem czegoś, co się nie stało.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  JAK WYMUSZAM PORAŻKĘ — bo to jest cała wiarygodność tego pliku
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Nie przez mock, nie przez podmianę klasy i nie przez „odłączmy bazę".
 * Przez ZDARZENIE MODELU ELOQUENTA, które rzuca wyjątek, podpięte w dwóch
 * różnych momentach tej samej operacji:
 *
 *  - `eloquent.updating` / `eloquent.creating` — rzuca ZANIM zapis
 *    potwierdzenia dojdzie do bazy. Tak wygląda porażka „potwierdzenia nie
 *    da się zapisać", a wymazanie już się w tej transakcji odbyło;
 *  - `eloquent.updated` / `eloquent.created` — rzuca PO tym, jak `UPDATE`
 *    albo `INSERT` wykonał się w bazie, ale przed zatwierdzeniem transakcji.
 *    Ten wariant jest ważniejszy, bo w nim zapis NAPRAWDĘ SIĘ ODBYŁ:
 *    nasłuch odczytuje wiersz tym samym połączeniem i widzi już nową
 *    wartość. Test asertuje ten odczyt — inaczej „brak wiersza po porażce"
 *    dowodziłby tylko tego, że wiersza nigdy nie było.
 *
 * Wyjątkiem jest zwykły `RuntimeException`, czyli awaria dowolnej natury
 * (padł dysk, padło połączenie, padła kolejna instrukcja w tej samej
 * transakcji) — nie wyjątek bazodanowy, którego Laravel mógłby obsłużyć
 * po swojemu.
 */
class PotwierdzenieRodoIdzieWTejSamejTransakcjiTest extends TestCase
{
    use RefreshDatabase;

    // ───────────────────────────── WYMAZANIE ─────────────────────────────

    #[Test]
    public function test_wymazanie_danych_domyka_sprawe_w_rejestrze(): void
    {
        // Szczęśliwy przebieg — gdyby go nie było, wszystkie testy porażki
        // niżej przechodziłyby na kodzie, który nie robi nic.
        $basia = $this->zeZgloszonymUsunieciem('basia', User::DELETE_SCOPE_MINIMUM);

        $this->assertTrue(app(EraseAccountData::class)->handle($basia));

        $potwierdzenie = $this->jedyne();

        $this->assertSame(PotwierdzenieZadaniaRodo::WYNIK_WYKONANE, $potwierdzenie->wynik);
        $this->assertSame(User::DELETE_SCOPE_MINIMUM, $potwierdzenie->zakres);
        $this->assertNotNull($potwierdzenie->zakonczono);
        $this->assertNotNull($potwierdzenie->wyjatki, 'Potwierdzenie milczy o tym, czego NIE usunięto.');

        // DECYZJA WŁAŚCICIELA Z 21.09.2026: wskaźnik zostaje po wykonaniu.
        $this->assertSame($basia->getKey(), $potwierdzenie->konto_id);
    }

    #[Test]
    public function test_zakres_w_potwierdzeniu_jest_tym_ktory_wykonano_a_nie_domyslnym(): void
    {
        // `everything` to inny zakres niż domyślny `minimum` (D-022) — gdyby
        // kod wpisywał domysł zamiast faktu, ten test byłby jedynym miejscem,
        // które by to zauważyło.
        $czesiek = $this->zeZgloszonymUsunieciem('czesiek', User::DELETE_SCOPE_EVERYTHING);

        app(EraseAccountData::class)->handle($czesiek);

        $this->assertSame(User::DELETE_SCOPE_EVERYTHING, $this->jedyne()->zakres);
    }

    #[Test]
    public function test_porazka_zapisu_potwierdzenia_cofa_takze_wymazanie_danych(): void
    {
        // „SKUTEK BEZ WIERSZA" — niemożliwy.
        $basia = $this->zeZgloszonymUsunieciem('basia', User::DELETE_SCOPE_MINIMUM);
        $emailPrzed = $basia->email;

        $this->rzucajNa('eloquent.updating: '.PotwierdzenieZadaniaRodo::class);

        try {
            app(EraseAccountData::class)->handle($basia);
            $this->fail('Wymuszona awaria nie przerwała wymazywania danych.');
        } catch (RuntimeException $e) {
            $this->assertSame('AWARIA W SRODKU TRANSAKCJI', $e->getMessage());
        }

        $swiezy = $basia->fresh();

        $this->assertNotNull($swiezy);
        $this->assertNull($swiezy->data_erased_at, 'Dane wymazane, mimo że potwierdzenia nie udało się zapisać.');
        $this->assertSame($emailPrzed, $swiezy->email, 'Adres e-mail zanonimizowany bez potwierdzenia obsługi żądania.');
        $this->assertSame(
            PotwierdzenieZadaniaRodo::WYNIK_W_TOKU,
            $this->jedyne()->wynik,
            'Sprawa domknięta, mimo że transakcja została wycofana.',
        );
    }

    #[Test]
    public function test_porazka_po_zapisie_potwierdzenia_cofa_takze_potwierdzenie(): void
    {
        // „WIERSZ BEZ SKUTKU" — niemożliwy. Tu zapis potwierdzenia NAPRAWDĘ
        // dochodzi do bazy, co poniżej asertujemy, a mimo to nie zostaje po
        // nim ślad.
        $basia = $this->zeZgloszonymUsunieciem('basia', User::DELETE_SCOPE_MINIMUM);

        $widzianyWTrakcie = $this->rzucajNaPoOdczycie('eloquent.updated: '.PotwierdzenieZadaniaRodo::class);

        try {
            app(EraseAccountData::class)->handle($basia);
            $this->fail('Wymuszona awaria nie przerwała wymazywania danych.');
        } catch (RuntimeException) {
            // oczekiwane
        }

        $this->assertSame(
            PotwierdzenieZadaniaRodo::WYNIK_WYKONANE,
            $widzianyWTrakcie->wartosc,
            'Nasłuch nie zobaczył zapisanego `wykonane` — czyli test nie dowodzi wycofania zapisu, '
            .'tylko tego, że zapisu nigdy nie było.',
        );

        $this->assertSame(
            PotwierdzenieZadaniaRodo::WYNIK_W_TOKU,
            $this->jedyne()->wynik,
            'Potwierdzenie „wykonane" przeżyło wycofaną transakcję — rejestr twierdzi coś, czego nie było.',
        );

        $this->assertNull($basia->fresh()?->data_erased_at);
    }

    // ───────────────────────────── COFNIĘCIE ─────────────────────────────

    #[Test]
    public function test_cofniecie_usuniecia_domyka_sprawe_jako_cofnieta(): void
    {
        $basia = $this->zeZgloszonymUsunieciem('basia', User::DELETE_SCOPE_MINIMUM);

        app(CancelAccountDeletion::class)->handle($basia);

        $potwierdzenie = $this->jedyne();

        $this->assertSame(PotwierdzenieZadaniaRodo::WYNIK_COFNIETE, $potwierdzenie->wynik);
        $this->assertNull($potwierdzenie->zakres, 'Cofnięte żądanie nie wykonało żadnego zakresu.');
        $this->assertNotNull($potwierdzenie->zakonczono);
        $this->assertSame($basia->getKey(), $potwierdzenie->konto_id);

        $this->assertSame(User::STATUS_ACTIVE, $basia->fresh()?->status);
    }

    #[Test]
    public function test_porazka_zapisu_potwierdzenia_cofa_takze_odzyskanie_konta(): void
    {
        // `cancelDeletion()` ZERUJE `delete_requested_at` (ADR §3.1), więc
        // odzyskanie konta bez domkniętej sprawy zostawiłoby rejestr ze sprawą
        // wiecznie „w toku" i bez śladu w `users`, z czego dałoby się ją
        // domknąć.
        $basia = $this->zeZgloszonymUsunieciem('basia', User::DELETE_SCOPE_MINIMUM);

        $this->rzucajNa('eloquent.updating: '.PotwierdzenieZadaniaRodo::class);

        try {
            app(CancelAccountDeletion::class)->handle($basia);
            $this->fail('Wymuszona awaria nie przerwała cofania usunięcia.');
        } catch (RuntimeException) {
            // oczekiwane
        }

        $swiezy = $basia->fresh();

        $this->assertNotNull($swiezy);
        $this->assertSame(User::STATUS_PENDING_DELETE, $swiezy->status, 'Konto odzyskane bez domknięcia sprawy w rejestrze.');
        $this->assertNotNull($swiezy->delete_requested_at, 'Data zgłoszenia wyzerowana mimo wycofanej transakcji.');
        $this->assertSame(PotwierdzenieZadaniaRodo::WYNIK_W_TOKU, $this->jedyne()->wynik);
    }

    // ─────────────────────────── PRZYJĘCIE ŻĄDANIA ───────────────────────

    #[Test]
    public function test_zgloszenie_z_ustawien_otwiera_sprawe_w_rejestrze(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->from(route('settings.data'))
            ->post(route('settings.data.delete'), [
                'password' => 'haslo-testowe-123',
                'confirm' => '1',
            ])
            ->assertRedirect(route('landing'));

        $potwierdzenie = $this->jedyne();

        $this->assertSame(PotwierdzenieZadaniaRodo::WYNIK_W_TOKU, $potwierdzenie->wynik);
        $this->assertSame($basia->getKey(), $potwierdzenie->konto_id);
        $this->assertNull($potwierdzenie->zakonczono, 'Sprawa w toku dostała datę zakończenia.');
        $this->assertNull($potwierdzenie->zakres, 'Sprawa w toku dostała zakres wykonania, choć nic nie wykonano.');
        $this->assertMatchesRegularExpression('/^RODO-/', (string) $potwierdzenie->numer);
    }

    #[Test]
    public function test_porazka_otwarcia_sprawy_cofa_takze_oznaczenie_konta(): void
    {
        $basia = $this->user('basia');

        $this->rzucajNa('eloquent.creating: '.PotwierdzenieZadaniaRodo::class);

        try {
            $this->withoutExceptionHandling()
                ->actingAs($basia)
                ->post(route('settings.data.delete'), [
                    'password' => 'haslo-testowe-123',
                    'confirm' => '1',
                ]);
            $this->fail('Wymuszona awaria nie przerwała zgłoszenia usunięcia.');
        } catch (RuntimeException) {
            // oczekiwane
        }

        $swiezy = $basia->fresh();

        $this->assertNotNull($swiezy);
        $this->assertSame(User::STATUS_ACTIVE, $swiezy->status, 'Konto oznaczone do usunięcia bez sprawy w rejestrze.');
        $this->assertNull($swiezy->delete_requested_at);
        $this->assertSame(0, PotwierdzenieZadaniaRodo::query()->count());
    }

    // ──────────────────────────── POMOCNIKI ──────────────────────────────

    /**
     * Konto z żądaniem usunięcia sprzed 31 dni i z otwartą sprawą
     * w rejestrze — czyli stan, w jakim zastaje je egzekutor karencji.
     */
    private function zeZgloszonymUsunieciem(string $nazwa, string $zakres): User
    {
        $user = $this->user($nazwa);

        DB::transaction(function () use ($user, $zakres): void {
            $user->markForDeletion($zakres);
            $user->forceFill(['delete_requested_at' => now()->subDays(31)])->save();

            app(RejestrPotwierdzenRodo::class)->przyjmijZadanieUsunieciaKonta($user);
        });

        return $user->refresh();
    }

    /**
     * Jedyne potwierdzenie w bazie — z asercją, że jest DOKŁADNIE jedno.
     *
     * Jedno żądanie to jeden wiersz, nie jeden wiersz na zdarzenie (§C oceny:
     * „przechowuj właściwy stan końcowy, nie trzy niekasowalne kopie").
     * Gdyby domknięcie dokładało drugi wiersz zamiast zamykać istniejący,
     * asercje o `wynik` niżej trafiałyby losowo w jeden z dwóch.
     */
    private function jedyne(): PotwierdzenieZadaniaRodo
    {
        $wszystkie = PotwierdzenieZadaniaRodo::query()->get();

        $this->assertCount(1, $wszystkie, 'Na jedno żądanie ma przypadać dokładnie jedno potwierdzenie.');

        return $wszystkie->first();
    }

    /**
     * Wymusza awarię w chwili, gdy Eloquent zaczyna zapisywać potwierdzenie.
     */
    private function rzucajNa(string $zdarzenie): void
    {
        Event::listen($zdarzenie, static function (): void {
            throw new RuntimeException('AWARIA W SRODKU TRANSAKCJI');
        });
    }

    /**
     * Wymusza awarię DOPIERO PO tym, jak zapis dojdzie do bazy — i po drodze
     * odczytuje wiersz tym samym połączeniem, żeby test miał dowód, że zapis
     * naprawdę się odbył.
     */
    private function rzucajNaPoOdczycie(string $zdarzenie): object
    {
        $swiadek = new class
        {
            public ?string $wartosc = null;
        };

        Event::listen($zdarzenie, static function (PotwierdzenieZadaniaRodo $model) use ($swiadek): void {
            $swiadek->wartosc = DB::table('potwierdzenia_zadan_rodo')
                ->where('id', $model->getKey())
                ->value('wynik');

            throw new RuntimeException('AWARIA W SRODKU TRANSAKCJI');
        });

        return $swiadek;
    }
}
