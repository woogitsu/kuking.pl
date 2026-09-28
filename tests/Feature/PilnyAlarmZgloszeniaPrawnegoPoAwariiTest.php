<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ZglosNielegalnaTresc;
use App\Domain\Security\DziennyBudzetListow;
use App\Models\Report;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * #2066 NA DRODZE BEZ KONTA (DSA art. 16): ponowienie formularza po awarii
 * zlecenia alarmu faktycznie budzi moderatora — raz.
 *
 * `KolejkaModeracjiStawiaPilneNaGorzeTest` sprawdza tę awarię na drodze
 * „Zgłoś" (`ReportContent`). Druga droga ponawia alarm osobnym wywołaniem
 * w gałęzi `$istniejace` (`ZglosNielegalnaTresc::handle()`), którego żaden
 * test nie pilnował: jego usunięcie zostawiało baterię zieloną, a pilna
 * sprawa od osoby bez konta — bez listu.
 *
 * KONTROLA UJEMNA (wykonana ręcznie): usunięcie `$this->alarm->handle($istniejace)`
 * w `ZglosNielegalnaTresc` oblewa ten test na liczbie zadań po ponowieniu;
 * zdjęcie transakcji w `AlarmujOPilnymZgloszeniu::handle()` oblewa go na
 * zadaniu, które zostaje w `jobs` po awarii; kod sprzed #2132 wypuszcza
 * wyjątek kolejki do wołającego.
 */
class PilnyAlarmZgloszeniaPrawnegoPoAwariiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ponowienie_formularza_po_awarii_kolejki_wysyla_jeden_alarm(): void
    {
        // Jak na produkcji: cache, budżet i kolejka w tej samej bazie
        // i w tej samej transakcji (`AlarmujOPilnymZgloszeniu::sprawdzWspolnaBaze()`).
        config([
            'kuking.moderation.model.alarm_email' => 'moderacja@kuking.test',
            'cache.default' => 'database',
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => false,
        ]);
        Cache::purge('database');

        // Bez adresu zgłaszającego — jedynym zadaniem w `jobs` jest alarm.
        $dane = [
            'imie' => null,
            'email' => null,
            'adres' => 'https://kuking.test/wpisy/wpis-po-awarii-alarmu',
            'uzasadnienie' => 'Uzasadnienie zgłoszenia dla potrzeb testu.',
            'powod' => 'minor',
            'kluczWyslania' => (string) Str::uuid7(),
        ];
        $wyslij = fn (): Report => app(ZglosNielegalnaTresc::class)->handle(...$dane);

        $pulaPrzed = DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte();
        $wstrzyknieto = false;
        DB::listen(static function (QueryExecuted $zapytanie) use (&$wstrzyknieto): void {
            if ($wstrzyknieto || preg_match('/^insert into ["`]?jobs["`]?\s/i', $zapytanie->sql) !== 1) {
                return;
            }

            $wstrzyknieto = true;
            throw new RuntimeException('Utracona odpowiedź po zapisie alarmu do jobs.');
        });

        $sprawa = $wyslij();

        $this->assertTrue($wstrzyknieto, 'Test nie doszedł do awarii po INSERT do jobs.');
        $this->assertSame(0, DB::table('jobs')->count(), 'Po awarii zostało zadanie alarmu.');
        $this->assertSame($pulaPrzed, DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(), 'Alarm, który nie wyszedł, zużył pulę poczty.');
        $this->assertSame(0, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
        $this->assertNull($sprawa->refresh()->alarm_czlowieka_obsluzony_at);

        // Człowiek wysyła ten sam formularz jeszcze raz.
        $ponowienie = $wyslij();

        $this->assertSame($sprawa->getKey(), $ponowienie->getKey(), 'Ponowienie założyło drugą sprawę.');
        $this->assertSame(1, DB::table('jobs')->count(), 'Ponowienie po awarii nie zleciło alarmu.');
        $this->assertSame($pulaPrzed + 1, DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte());
        $this->assertNotNull($ponowienie->refresh()->alarm_czlowieka_obsluzony_at);

        // Kolejne kliknięcia — także po wygaśnięciu okna celu — nie dokładają listu.
        $wyslij();
        $this->travel(7)->hours();
        $wyslij();

        $this->assertSame(1, Report::query()->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'Ponowienia tej samej sprawy zleciły drugi alarm.');
    }
}
