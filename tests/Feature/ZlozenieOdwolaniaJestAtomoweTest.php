<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\FileAppeal;
use App\Domain\Moderation\Actions\FileReporterAppeal;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Złożone odwołanie nie zostaje bez zawiadomienia zespołu ani bez zlecenia
 * potwierdzenia (issue #1305).
 *
 * CO BYŁO ŹLE
 * `FileAppeal` i `FileReporterAppeal` zatwierdzały `Appeal::create()` osobno,
 * a dopiero potem zapisywały audyt, zlecenie listu i zawiadomienia
 * administratorów — każde osobno. Awaria przy drugim administratorze
 * zostawiała pismo złożone, jednego administratora zawiadomionego, drugiego
 * nie, a ponowienie odbijało się o „już do nas trafiło”.
 *
 * JAK MIERZYMY
 * `DB::beforeExecuting()` przerywa wybrany zapis PRZED jego wykonaniem —
 * prawdziwe klasy, bez atrap. Kolejka jest bazodanowa, jak na produkcji
 * (`config/queue.php`); przy `sync` z `phpunit.xml` nie byłoby wiersza
 * w `jobs`, więc nie byłoby czego mierzyć (`docs/PULAPKI_TESTOW.md` §2).
 *
 * Wpis `appeal.filed` w dzienniku jest pomocniczy (D-249, klasa 2): jego
 * awaria NIE cofa pisma — to też jest tu sprawdzone.
 *
 * Kontrola ujemna: `scripts/kontrole-negatywne-alfa08.py`, wpisy #1305 —
 * zdjęcie transakcji z którejkolwiek akcji oblewa test awarii przy drugim
 * administratorze.
 */
class ZlozenieOdwolaniaJestAtomoweTest extends TestCase
{
    use RefreshDatabase;

    private const TRESC = 'To jest mój własny przepis mojej babci, nie skopiowałam go z żadnego bloga.';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /** Przerywa n-ty zapis pasujący do początku zapytania, jeden raz. */
    private function zepsuj(string $poczatek, int $ktory, bool &$wlaczone): void
    {
        $licznik = 0;

        DB::beforeExecuting(function (string $zapytanie) use ($poczatek, $ktory, &$licznik, &$wlaczone): void {
            if (! $wlaczone || ! str_starts_with($zapytanie, $poczatek)) {
                return;
            }

            $licznik++;

            if ($licznik === $ktory) {
                throw new RuntimeException('awaria zapisu wymuszona testem');
            }
        });
    }

    /** @return array{0: User, 1: ModerationAction, 2: User, 3: User} */
    private function decyzjaAutora(): array
    {
        $admin = $this->admin();
        $drugiAdmin = $this->admin();
        $autor = $this->user('basia_1305');

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $zgloszenie = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'recipe',
            'target_id' => $przepis->getKey(),
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($admin)->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'sexual',
            'user_message' => 'Przepis wygląda na skopiowany z bloga.',
        ]);

        return [$autor, ModerationAction::firstOrFail(), $admin, $drugiAdmin];
    }

    /** @return array{0: Report, 1: User, 2: User} */
    private function decyzjaNaZgloszeniuPrawnym(): array
    {
        $admin = $this->admin();
        $drugiAdmin = $this->admin();
        $wpis = Post::factory()->create(['author_id' => $this->user()->getKey()]);

        $zgloszenie = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($admin)->post(route('admin.reports.decide', $zgloszenie), [
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'brak_naruszenia',
        ]);

        $this->assertNotNull(ModerationAction::query()->where('report_id', $zgloszenie->getKey())->first(),
            'Przygotowanie sceny nie utworzyło decyzji — test nie mierzyłby odwołania.');

        return [$zgloszenie->refresh(), $admin, $drugiAdmin];
    }

    private function zawiadomien(): int
    {
        return Notification::query()->where('type', Notification::TYPE_APPEAL_FILED)->count();
    }

    private function zlecenPotwierdzenia(): int
    {
        return DB::table('jobs')->where('payload', 'like', '%PotwierdzenieOdwolaniaZglaszajacego%')->count();
    }

    private function wpisowAudytu(): int
    {
        return DB::table('audit_log')->where('action', 'appeal.filed')->count();
    }

    public function test_awaria_przy_drugim_administratorze_nie_zostawia_pisma_autora(): void
    {
        [$autor, $decyzja] = $this->decyzjaAutora();

        $wlaczone = true;
        $this->zepsuj('insert into "notifications"', 2, $wlaczone);

        try {
            app(FileAppeal::class)->handle($autor, $decyzja, self::TRESC);
            $this->fail('Awaria zawiadomienia nie przerwała złożenia — test nie zmierzył tego, co miał.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('awaria zapisu wymuszona testem', $e->getMessage());
        }

        // CAŁE ZNALEZISKO #1305: przed poprawką stało tu 1 pismo i 1 zawiadomienie.
        $this->assertSame(0, Appeal::count(), 'Pismo zostało złożone mimo niepełnego zawiadomienia zespołu (#1305).');
        $this->assertSame(0, $this->zawiadomien(), 'Jeden administrator dostał alarm, drugi nie (#1305).');

        // Ponowienie jest bezpieczne i daje jeden komplet.
        $wlaczone = false;
        app(FileAppeal::class)->handle($autor, $decyzja->refresh(), self::TRESC);

        $this->assertSame(1, Appeal::count());
        $this->assertSame(2, $this->zawiadomien());
        $this->assertSame(1, $this->wpisowAudytu());
    }

    public function test_awaria_przy_drugim_administratorze_nie_zostawia_pisma_zglaszajacego(): void
    {
        [$zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym();
        config(['queue.default' => 'database']);

        $wlaczone = true;
        $this->zepsuj('insert into "notifications"', 2, $wlaczone);

        try {
            app(FileReporterAppeal::class)->handle($zgloszenie, self::TRESC);
            $this->fail('Awaria zawiadomienia nie przerwała złożenia — test nie zmierzył tego, co miał.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('awaria zapisu wymuszona testem', $e->getMessage());
        }

        $this->assertSame(0, Appeal::count(), 'Pismo zostało złożone mimo niepełnego zawiadomienia zespołu (#1305).');
        $this->assertSame(0, $this->zawiadomien(), 'Jeden administrator dostał alarm, drugi nie (#1305).');
        $this->assertSame(0, $this->zlecenPotwierdzenia(), 'Zostało zlecenie potwierdzenia pisma, którego nie ma.');

        $wlaczone = false;
        app(FileReporterAppeal::class)->handle($zgloszenie, self::TRESC);

        $this->assertSame(1, Appeal::count());
        $this->assertSame(2, $this->zawiadomien());
        $this->assertSame(1, $this->zlecenPotwierdzenia(), 'Jedno pismo = jedno zlecenie potwierdzenia.');
    }

    public function test_awaria_zapisu_zlecenia_potwierdzenia_nie_zostawia_pisma(): void
    {
        [$zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym();
        config(['queue.default' => 'database']);

        $wlaczone = true;
        $this->zepsuj('insert into "jobs"', 1, $wlaczone);

        try {
            app(FileReporterAppeal::class)->handle($zgloszenie, self::TRESC);
            $this->fail('Awaria kolejki nie przerwała złożenia — test nie zmierzył tego, co miał.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, Appeal::count(), 'Pismo zostało bez zlecenia jedynego potwierdzenia (#1305).');
        $this->assertSame(0, $this->zawiadomien());

        $wlaczone = false;
        app(FileReporterAppeal::class)->handle($zgloszenie, self::TRESC);

        $this->assertSame(1, Appeal::count());
        $this->assertSame(1, $this->zlecenPotwierdzenia());
    }

    /**
     * KONTROLA DODATNIA: zwykłe złożenie zostawia trwałe zlecenie listu
     * w `jobs` — do wykonania i ponowienia przez worker po zatwierdzeniu.
     * Drugie złożenie nie dokłada drugiego zlecenia.
     */
    public function test_zlozenie_zostawia_jedno_trwale_zlecenie_potwierdzenia(): void
    {
        [$zgloszenie] = $this->decyzjaNaZgloszeniuPrawnym();
        config(['queue.default' => 'database']);

        app(FileReporterAppeal::class)->handle($zgloszenie, self::TRESC);

        $this->assertSame(1, Appeal::count());
        $this->assertSame(2, $this->zawiadomien());
        $this->assertSame(1, $this->zlecenPotwierdzenia());
        $this->assertSame(1, $this->wpisowAudytu());

        try {
            app(FileReporterAppeal::class)->handle($zgloszenie, self::TRESC);
        } catch (BladDlaCzlowieka) {
        }

        $this->assertSame(1, $this->zlecenPotwierdzenia(), 'Drugie wysłanie dołożyło drugi list potwierdzenia.');
    }

    /**
     * Awaria dziennika audytu nie cofa pisma (D-249, klasa 2): pismo, jego
     * zawiadomienia i zlecenie listu zostają, brak wpisu idzie do `report()`.
     */
    public function test_awaria_audytu_nie_cofa_zlozonego_pisma(): void
    {
        [$autor, $decyzja] = $this->decyzjaAutora();

        $wlaczone = true;
        $this->zepsuj('insert into "audit_log"', 1, $wlaczone);

        app(FileAppeal::class)->handle($autor, $decyzja, self::TRESC);

        $this->assertSame(1, Appeal::count(), 'Awaria dziennika cofnęła pismo z biegnącym terminem — wbrew D-249.');
        $this->assertSame(2, $this->zawiadomien());
        $this->assertSame(0, $this->wpisowAudytu());
    }
}
