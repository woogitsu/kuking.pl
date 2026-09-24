<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnionePowiadomienia;
use App\Models\ModerationAction;
use App\Models\Notification;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Częściowy błąd kasowania w retencji powiadomień NIE jest sukcesem (#1342).
 *
 * CO ROBIŁ KOD
 * `PrzedawnionePowiadomienia::posprzatajModeracyjne()` łapał wyjątek
 * z `delete()`, zapisywał go do logu i szedł dalej — bez licznika. Raport nie
 * miał pola na porażki, komenda zawsze zwracała `SUCCESS`, a harmonogram
 * (`Schedule::call(fn () => Artisan::call(...))`) i tak nie patrzył na kod
 * wyjścia. Przebieg, który zostawił wiersze po terminie, wyglądał jak udany.
 *
 * Awarię symuluje nasłuch `deleting`, który rzuca dla wybranych wierszy —
 * to ta sama ścieżka (`$powiadomienie->delete()`), którą idzie retencja.
 */
class RetencjaPowiadomienCzesciowaPorazkaTest extends TestCase
{
    use RefreshDatabase;

    private const TRESC = 'Poufna treść powiadomienia, która nie może trafić do logu';

    /** @var list<string> */
    private array $wadliwe = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.notifications.retention_months' => 3]);

        Notification::deleting(function (Notification $powiadomienie): void {
            if (in_array($powiadomienie->getKey(), $this->wadliwe, true)) {
                throw new RuntimeException(self::TRESC);
            }
        });
    }

    /** Powiadomienie moderacyjne, któremu termin odwołania już minął. */
    private function przedawnione(string $userId, string $moderatorId): Notification
    {
        $decyzja = ModerationAction::create([
            'moderator_id' => $moderatorId,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
        ]);
        DB::table('moderation_actions')->where('id', $decyzja->getKey())->update(['created_at' => now()->subMonths(7)]);

        $powiadomienie = Notification::create([
            'user_id' => $userId,
            'type' => Notification::TYPE_MODERATION,
            'data' => ['title' => 't', 'message' => self::TRESC, 'decision' => 'hide', 'appeal' => true, 'action_id' => $decyzja->getKey()],
        ]);
        DB::table('notifications')->where('id', $powiadomienie->getKey())->update(['created_at' => now()->subMonths(7)]);

        return $powiadomienie->refresh();
    }

    /** @return list<Notification> */
    private function piecPrzedawnionych(): array
    {
        $basia = $this->user('basia');
        $moderator = $this->moderator();

        return array_map(
            fn (): Notification => $this->przedawnione($basia->getKey(), $moderator->getKey()),
            range(1, 5),
        );
    }

    public function test_kilka_bledow_wsrod_sukcesow_jest_policzonych_a_reszta_skasowana(): void
    {
        $wszystkie = $this->piecPrzedawnionych();
        // Pierwszy, środkowy i ostatni w kolejności utworzenia.
        $this->wadliwe = [$wszystkie[0]->getKey(), $wszystkie[2]->getKey(), $wszystkie[4]->getKey()];

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertSame(3, $raport->nieudaneModeracyjne);
        $this->assertSame(2, $raport->usunieteModeracyjne);
        $this->assertTrue($raport->czesciowaPorazka());

        foreach ($wszystkie as $powiadomienie) {
            in_array($powiadomienie->getKey(), $this->wadliwe, true)
                ? $this->assertDatabaseHas('notifications', ['id' => $powiadomienie->getKey()])
                : $this->assertDatabaseMissing('notifications', ['id' => $powiadomienie->getKey()]);
        }
    }

    public function test_same_sukcesy_to_nie_porazka(): void
    {
        $this->piecPrzedawnionych();

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertSame(0, $raport->nieudaneModeracyjne);
        $this->assertSame(5, $raport->usunieteModeracyjne);
        $this->assertFalse($raport->czesciowaPorazka());
        $this->artisan('kuking:sprzataj-powiadomienia')->assertSuccessful();
    }

    public function test_komenda_konczy_sie_bledem_a_nastepny_przebieg_ponawia_wiersz(): void
    {
        $wszystkie = $this->piecPrzedawnionych();
        $this->wadliwe = [$wszystkie[1]->getKey()];

        $this->artisan('kuking:sprzataj-powiadomienia')
            ->expectsOutputToContain('Nie udało się skasować 1 powiadomień moderacyjnych')
            ->doesntExpectOutputToContain('bez ustalalnej decyzji')
            ->assertFailed();

        $this->assertDatabaseCount('notifications', 1);

        // Awaria ustąpiła — kolejny przebieg domyka to, co zostało.
        $this->wadliwe = [];
        $this->artisan('kuking:sprzataj-powiadomienia')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
    }

    /** Na sucho nic nie jest kasowane, więc nic nie może się nie udać. */
    public function test_na_sucho_nie_raportuje_bledow(): void
    {
        $wszystkie = $this->piecPrzedawnionych();
        $this->wadliwe = array_map(fn (Notification $n): string => $n->getKey(), $wszystkie);

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3, naSucho: true);

        $this->assertSame(0, $raport->nieudaneModeracyjne);
        $this->assertSame(5, $raport->usunieteModeracyjne);
        $this->artisan('kuking:sprzataj-powiadomienia', ['--na-sucho' => true])->assertSuccessful();
        $this->assertDatabaseCount('notifications', 5);
    }

    public function test_log_zawiera_identyfikator_i_klase_ale_nie_tresc(): void
    {
        $wszystkie = $this->piecPrzedawnionych();
        $this->wadliwe = [$wszystkie[0]->getKey()];
        $log = Log::spy();

        (new PrzedawnionePowiadomienia)->posprzataj(3);

        $log->shouldHaveReceived('error')->once()->withArgs(function (string $wiadomosc, array $kontekst) use ($wszystkie): bool {
            $this->assertSame($wszystkie[0]->getKey(), $kontekst['notification_id']);
            $this->assertSame(RuntimeException::class, $kontekst['error']['wyjatek']);
            $this->assertStringNotContainsString(self::TRESC, $wiadomosc.json_encode($kontekst));

            return true;
        });
    }

    /** Awaria samego logowania nie przerywa pętli ani nie zmienia liczb. */
    public function test_awaria_logowania_nie_przeslania_wyniku(): void
    {
        $wszystkie = $this->piecPrzedawnionych();
        $this->wadliwe = [$wszystkie[0]->getKey(), $wszystkie[3]->getKey()];
        Log::shouldReceive('error')->andThrow(new RuntimeException('log padł'));

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertSame(2, $raport->nieudaneModeracyjne);
        $this->assertSame(3, $raport->usunieteModeracyjne);
    }

    /**
     * `CallbackEvent` bierze za porażkę tylko wyjątek albo `false` — kod 1
     * z `Artisan::call()` przeszedłby jako sukces. Zadanie w harmonogramie
     * musi więc samo zamienić kod w wyjątek.
     */
    public function test_zadanie_w_harmonogramie_konczy_sie_wyjatkiem(): void
    {
        $wszystkie = $this->piecPrzedawnionych();
        $this->wadliwe = [$wszystkie[2]->getKey()];

        $zadanie = $this->zadanieRetencji();

        try {
            $zadanie->run(app());
            $this->fail('Zadanie z częściową porażką zakończyło się jak udane.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(kod wyjścia: 1)', $e->getMessage());
            $this->assertStringNotContainsString(self::TRESC, $e->getMessage());
        }
    }

    /**
     * Kontrola dodatnia do testu wyżej — osobno, bo `CallbackEvent` pamięta
     * wyjątek z poprzedniego `run()` i rzuciłby go ponownie.
     */
    public function test_zadanie_w_harmonogramie_bez_awarii_przechodzi(): void
    {
        $this->piecPrzedawnionych();

        $this->zadanieRetencji()->run(app());

        $this->assertDatabaseCount('notifications', 0);
    }

    private function zadanieRetencji(): CallbackEvent
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => $e->description === 'kuking:sprzataj-powiadomienia');
        $this->assertInstanceOf(CallbackEvent::class, $zadanie);

        return $zadanie;
    }
}
