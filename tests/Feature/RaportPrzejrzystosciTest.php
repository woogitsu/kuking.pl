<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * `kuking:raport-przejrzystosci` liczy odwrócenie decyzji i rodzaj nowego
 * działania (issue #1860, kryterium #989: „raportowanie przejrzystości
 * potrafi policzyć odwrócenie decyzji oraz rzeczywisty rodzaj nowego
 * działania").
 *
 * Dane powstają PRAWDZIWĄ drogą — formularz decyzji moderatora i formularz
 * rozpatrzenia odwołania — więc test łapie też rozjazd między tym, co
 * zapisuje `ResolveAppeal`, a tym, co czyta raport.
 */
class RaportPrzejrzystosciTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function zgloszenie(string $zrodlo, string $celId): Report
    {
        return Report::create([
            'reporter_id' => null,
            'source' => $zrodlo,
            'target_type' => 'post',
            'target_id' => $celId,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza moje prawa autorskie.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    private function zdecyduj(Report $zgloszenie, string $akcja): ModerationAction
    {
        $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), array_filter([
                'action' => $akcja,
                'reason_code' => $akcja === ModerationAction::ACTION_NONE ? 'brak_naruszenia' : 'niezgodne-z-prawem',
                'user_message' => $akcja === ModerationAction::ACTION_NONE ? null : 'Wpis zawierał cudze zdjęcia bez zgody autora.',
            ]))
            ->assertSessionHasNoErrors();

        return ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
    }

    /**
     * Jedna sprawa o znanym kształcie: zgłoszenie prawne → „Bez działania” →
     * odwołanie zgłaszającego → uznane z nową decyzją „Usuń treść”.
     */
    private function uznaneOdwolanieOdBezDzialania(): void
    {
        $wpis = Post::factory()->create(['author_id' => $this->user('basia')->getKey()]);
        $zgloszenie = $this->zgloszenie(Report::SOURCE_LEGAL_NOTICE, (string) $wpis->getKey());
        $pierwotna = $this->zdecyduj($zgloszenie, ModerationAction::ACTION_NONE);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $pierwotna->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'appellant' => Appeal::APPELLANT_REPORTER,
            'body' => 'Ta treść dalej narusza moje prawa, proszę o ponowne sprawdzenie.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        $this->actingAs($this->admin())
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Sprawdziliśmy jeszcze raz. Zgłoszenie było zasadne.',
                'nowa_decyzja' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'niezgodne-z-prawem',
                'user_message' => 'Wpis zawierał cudze zdjęcia bez zgody autora.',
            ])
            ->assertSessionHasNoErrors();

        // Kontrola, że sprawa ma kształt, który raport ma policzyć.
        $this->assertSame(1, ModerationAction::query()->whereNotNull('appeal_id')->where('action', ModerationAction::ACTION_REMOVE)->count());
    }

    /** @return array{0: int, 1: string} */
    private function raport(array $parametry = []): array
    {
        $kod = Artisan::call('kuking:raport-przejrzystosci', $parametry);

        return [$kod, Artisan::output()];
    }

    /** Wiersz tabeli Symfony: `| etykieta | liczba |` z dowolnymi odstępami. */
    private function assertWiersz(string $wyjscie, string $sekcja, string $etykieta, string $liczby): void
    {
        $poczatek = strpos($wyjscie, $sekcja);
        $this->assertNotFalse($poczatek, "Brak sekcji „{$sekcja}” w raporcie.");
        $nastepna = strpos($wyjscie, "\n\n", $poczatek + strlen($sekcja) + 1);
        $fragment = substr($wyjscie, $poczatek, $nastepna === false ? null : $nastepna - $poczatek);

        $wzor = '/\|\s*'.preg_quote($etykieta, '/').'\s*\|\s*'.implode('\s*\|\s*', array_map(fn ($l) => preg_quote($l, '/'), explode(' ', $liczby))).'\s*\|/u';
        $this->assertMatchesRegularExpression($wzor, $fragment, "Sekcja „{$sekcja}”: wiersz „{$etykieta}” nie ma liczb {$liczby}.\n{$fragment}");
    }

    public function test_liczy_odwrocenie_decyzji_i_rodzaj_nowego_dzialania(): void
    {
        $this->uznaneOdwolanieOdBezDzialania();

        // Druga sprawa: zgłoszenie społeczności → „Ukryj treść”, bez odwołania.
        $inny = Post::factory()->create(['author_id' => $this->user('marek')->getKey()]);
        $this->zdecyduj($this->zgloszenie(Report::SOURCE_COMMUNITY, (string) $inny->getKey()), ModerationAction::ACTION_HIDE);

        [$kod, $wyjscie] = $this->raport();

        $this->assertSame(0, $kod);
        $this->assertWiersz($wyjscie, '1. Zgłoszenia', 'zgłoszenie nielegalnej treści (DSA art. 16)', '1');
        $this->assertWiersz($wyjscie, '1. Zgłoszenia', 'społeczność (nasze zasady)', '1');
        $this->assertWiersz($wyjscie, '1. Zgłoszenia', 'RAZEM', '2');
        $this->assertWiersz($wyjscie, '2. Decyzje pierwszej instancji', 'Bez działania', '1');
        $this->assertWiersz($wyjscie, '2. Decyzje pierwszej instancji', 'Ukryj treść', '1');
        // Decyzja po odwołaniu NIE jest decyzją z urzędu, choć też nie ma report_id.
        $this->assertWiersz($wyjscie, '3. Decyzje z urzędu', 'RAZEM', '0');
        $this->assertWiersz($wyjscie, '5. Odwołania', 'zgłaszający (od decyzji)', '0 1');
        $this->assertWiersz($wyjscie, '5. Odwołania', 'autor treści (od sankcji)', '0 0');
        $this->assertWiersz($wyjscie, '6. Decyzje po uznaniu odwołania', 'Usuń treść', '1');
        $this->assertWiersz($wyjscie, '6. Decyzje po uznaniu odwołania', 'RAZEM', '1');
    }

    public function test_okno_poza_sprawami_daje_zera_a_nie_puste_tabele(): void
    {
        $this->uznaneOdwolanieOdBezDzialania();

        [$kod, $wyjscie] = $this->raport(['--od' => '2020-01-01', '--do' => '2020-12-31']);

        $this->assertSame(0, $kod);
        $this->assertWiersz($wyjscie, '6. Decyzje po uznaniu odwołania', 'Usuń treść', '0');
        $this->assertWiersz($wyjscie, '5. Odwołania', 'RAZEM', '0 0');
        $this->assertStringContainsString('ZANIŻONE', $wyjscie, 'Okno sprzed retencji musi o tym ostrzec.');
    }

    public function test_raport_nie_zawiera_identyfikatorow_ani_danych_zglaszajacego(): void
    {
        $this->uznaneOdwolanieOdBezDzialania();

        [, $wyjscie] = $this->raport();

        foreach (['jan@przyklad.test', 'Jan Zgłaszający', 'basia', 'Sprawdziliśmy jeszcze raz', 'cudze zdjęcia'] as $fraza) {
            $this->assertStringNotContainsString($fraza, $wyjscie);
        }

        $this->assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/', $wyjscie, 'W raporcie jest UUID.');
    }

    public function test_zla_data_konczy_sie_bledem_mowiacym_co_zrobic(): void
    {
        [$kod, $wyjscie] = $this->raport(['--od' => '2026-02-30']);
        $this->assertSame(1, $kod);
        $this->assertStringContainsString('Podaj ją jako RRRR-MM-DD', $wyjscie);

        [$kod, $wyjscie] = $this->raport(['--od' => '2026-05-01', '--do' => '2026-04-01']);
        $this->assertSame(1, $kod);
        $this->assertStringContainsString('późniejsza niż w --do', $wyjscie);
    }

    public function test_komenda_niczego_nie_zmienia(): void
    {
        $this->uznaneOdwolanieOdBezDzialania();
        $przed = [Report::count(), ModerationAction::count(), Appeal::count(), Appeal::query()->where('status', Appeal::STATUS_OVERTURNED)->count()];

        $this->raport();

        $this->assertSame($przed, [Report::count(), ModerationAction::count(), Appeal::count(), Appeal::query()->where('status', Appeal::STATUS_OVERTURNED)->count()]);
    }
}
