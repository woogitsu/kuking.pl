<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnionePowiadomienia;
use App\Domain\Compliance\PrzedawnioneWpisyAudytu;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A6-04: pod koniec miesiąca retencja kasowała dane przed czasem.
 *
 * `now()->subMonths(3)` PRZEPEŁNIA datę, gdy dzień nie istnieje w miesiącu
 * docelowym, i przesuwa próg w stronę NOWSZYCH wierszy:
 *
 *     now()                 subMonths(3)   subMonthsNoOverflow(3)
 *     2026-05-31 12:00      2026-03-03     2026-02-28
 *
 * Powiadomienie z 1 marca wypadało więc 31 maja — po dwóch miesiącach
 * i trzydziestu dniach, a nie po trzech miesiącach, które obiecuje polityka
 * prywatności. To nie dotyczy wszystkich dat w roku, dlatego zwykły test
 * „stare znika, nowe zostaje" tego nie widział: przy typowym dniu miesiąca
 * obie funkcje dają ten sam wynik.
 *
 * KIERUNEK POMYŁKI JEST TU CAŁĄ SPRAWĄ. Wariant bez przepełnienia myli się
 * wyłącznie w stronę „zostaje dłużej". Przy retencji to jedyny dopuszczalny
 * kierunek: dane skasowane za wcześnie znikają na zawsze, dane trzymane dzień
 * dłużej — nie.
 *
 * OSTATNI PRZYPADEK PILNUJE ASYMETRII, którą przy tej poprawce łatwo
 * przeoczyć: `ModerationAction::appealDeadline()` DODAJE miesiące i tam
 * przepełnienie WYDŁUŻA termin odwołania, czyli działa na korzyść człowieka.
 * Zamiana na wariant bez przepełnienia byłaby tam SKRÓCENIEM obiecanego
 * terminu — i właśnie dlatego ten przypadek istnieje.
 */
final class RetencjaNieGubiMiesiacaNaKoncuMiesiacaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    /**
     * Eloquent nadpisuje `created_at` przy zapisie, więc datę wstawiamy
     * osobnym `UPDATE`. Bez tego wszystkie wiersze miałyby czas z `now()`
     * i cały ten plik badałby jeden punkt na osi czasu.
     */
    private function zDataUtworzenia(string $tabela, string|int $id, string $kiedy): void
    {
        // Same `created_at` — ani `notifications`, ani `audit_log` nie mają
        // kolumny `updated_at`, i to jest zamierzone: te wiersze się nie
        // zmieniają, a retencja i tak liczy wiek od utworzenia.
        DB::table($tabela)->where('id', $id)->update(['created_at' => $kiedy]);

        $this->assertSame(
            $kiedy,
            CarbonImmutable::parse(DB::table($tabela)->where('id', $id)->value('created_at'))->toDateTimeString(),
            "Nie udało się cofnąć daty utworzenia w tabeli {$tabela} — bez tego ten przypadek nie bada niczego.",
        );
    }

    #[Test]
    public function zwykle_powiadomienie_nie_znika_przed_uplywem_trzech_miesiecy(): void
    {
        $teraz = CarbonImmutable::parse('2026-05-31 12:00:00', 'UTC');
        Date::setTestNow($teraz);

        // Kontrola metody pomiaru: wybrana data MUSI wywoływać przepełnienie,
        // inaczej stary i nowy kod dają ten sam próg i nie ma czego badać.
        $this->assertSame('2026-03-03 12:00:00', $teraz->subMonths(3)->toDateTimeString(),
            'Wybrana data przestała wywoływać przepełnienie. Popraw datę, nie asercję.');
        $this->assertSame('2026-02-28 12:00:00', $teraz->subMonthsNoOverflow(3)->toDateTimeString());

        $basia = $this->user('basia');

        // 1 marca leży MIĘDZY progami: stary kod je kasował, nowy zostawia.
        // Trzy miesiące od 1 marca mijają 1 czerwca, czyli dzień po próbie.
        $mlodsze = Notification::query()->create([
            'user_id' => $basia->getKey(),
            'type' => Notification::TYPE_COOKED,
        ]);
        $this->zDataUtworzenia('notifications', $mlodsze->getKey(), '2026-03-01 12:00:00');

        // Kontrola w drugą stronę: coś naprawdę starego MUSI zniknąć. Bez
        // tego „nic nie skasowano" przechodziłoby także wtedy, gdyby
        // sprzątanie w ogóle przestało działać.
        $starsze = Notification::query()->create([
            'user_id' => $basia->getKey(),
            'type' => Notification::TYPE_COOKED,
        ]);
        $this->zDataUtworzenia('notifications', $starsze->getKey(), '2025-12-01 12:00:00');

        app(PrzedawnionePowiadomienia::class)->posprzataj(3);

        $this->assertDatabaseHas('notifications', ['id' => $mlodsze->getKey()]);
        $this->assertDatabaseMissing('notifications', ['id' => $starsze->getKey()]);
    }

    /**
     * Ten sam błąd siedział w dwóch pozostałych klasach retencji. Audyt
     * zmierzył tylko powiadomienia, ale wzorzec był identyczny — sprawdzamy
     * więc także dziennik audytu, na jego własnym okresie (12 miesięcy).
     */
    #[Test]
    public function wpis_audytu_nie_znika_przed_uplywem_dwunastu_miesiecy(): void
    {
        // Przy dwunastu miesiącach przepełnienie wychodzi wtedy, gdy rok
        // docelowy różni się przestępnością: 29 lutego 2028 minus 12 miesięcy
        // to 29 lutego 2027, którego nie ma.
        $teraz = CarbonImmutable::parse('2028-02-29 12:00:00', 'UTC');
        Date::setTestNow($teraz);

        $this->assertSame('2027-03-01 12:00:00', $teraz->subMonths(12)->toDateTimeString(),
            'Wybrana data przestała wywoływać przepełnienie. Popraw datę, nie asercję.');
        $this->assertSame('2027-02-28 12:00:00', $teraz->subMonthsNoOverflow(12)->toDateTimeString());

        $basia = $this->user('basia');

        // 28 lutego 2027 o 18:00 leży DOKŁADNIE między progami.
        $mlodszy = AuditLogEntry::query()->create([
            'actor_id' => $basia->getKey(),
            'action' => 'test.retencja',
        ]);
        $this->zDataUtworzenia('audit_log', $mlodszy->getKey(), '2027-02-28 18:00:00');

        app(PrzedawnioneWpisyAudytu::class)->posprzataj(12);

        $this->assertDatabaseHas('audit_log', ['id' => $mlodszy->getKey()]);
    }

    /**
     * KIERUNEK ODWROTNY — i dlatego `addMonths` tam ZOSTAJE.
     *
     * Przy dodawaniu miesięcy przepełnienie wydłuża termin, czyli działa na
     * korzyść człowieka, który liczy sześć miesięcy z kalendarza. Zamiana na
     * wariant bez przepełnienia skróciłaby obiecany termin odwołania —
     * i byłaby błędem, mimo że wygląda na „dokończenie tej samej poprawki".
     */
    #[Test]
    public function termin_odwolania_nie_zostaje_skrocony_przy_okazji(): void
    {
        $decyzja = new ModerationAction;
        $decyzja->created_at = Carbon::parse('2026-08-31 12:00:00', 'UTC');

        $termin = $decyzja->appealDeadline();
        $bezPrzepelnienia = CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC')->addMonthsNoOverflow(6);

        $this->assertTrue($termin->greaterThanOrEqualTo($bezPrzepelnienia),
            'Termin odwołania wypada wcześniej niż sześć miesięcy liczonych bez przepełnienia. '
            .'Przy DODAWANIU miesięcy przepełnienie działa na korzyść człowieka i ma zostać — '
            .'poprawka A6-04 dotyczy wyłącznie ODEJMOWANIA w klasach retencji.');

        $this->assertTrue($termin->greaterThanOrEqualTo(CarbonImmutable::parse('2027-02-28 12:00:00', 'UTC')),
            'Sześć miesięcy od 31 sierpnia nie może wypaść przed końcem lutego.');
    }
}
