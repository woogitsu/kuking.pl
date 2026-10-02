<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Gotowanie\PorcjeWykonania;
use App\Domain\Users\Actions\EraseAccountData;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\GenerateUserExport;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;
use ZipArchive;

/**
 * Prywatna liczba faktycznie ugotowanych porcji przy własnym „Ugotowałem"
 * (issue #2540, decyzja właściciela z 2.10.2026).
 *
 * Granice, które ten plik pilnuje:
 *  - liczba jest opcjonalna, domyślnie pusta, nigdy nie wynika z przepisu;
 *  - zakres 0,5–100, najwyżej dwie cyfry po przecinku, błąd po polsku
 *    mówi co zrobić, a poprawne dane w formularzu zostają;
 *  - widzi ją wyłącznie kucharz (karta, strona wykonania, profil, przepis);
 *  - poprawa i usunięcie nie tworzą nowego wykonania ani powiadomienia;
 *  - powiadomienie autora przepisu jak dotąd, bez liczby;
 *  - eksport ją niesie, wymazanie konta zeruje, rollback migracji odmawia.
 */
class PrywatneFaktycznePorcjeTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_10_03_180000_add_faktyczne_porcje_to_cooked_events.php';

    /** Napis, którego nie ma nigdzie indziej na stronach. */
    private const ETYKIETA = '7,25 porcji';

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 12:00:00', 'UTC'));

        $this->autor = $this->user('autorka2540');
        $this->kucharz = $this->user('kucharz2540');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'title' => 'Zupa dnia',
            'servings' => 4,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $pola
     */
    private function wyslij(array $pola, ?User $kto = null): TestResponse
    {
        return $this->actingAs($kto ?? $this->kucharz)
            ->from(route('cooked.create', $this->przepis->slug))
            ->post(route('cooked.store', $this->przepis->slug), $pola);
    }

    private function powiadomienia(): int
    {
        return Notification::query()
            ->where('user_id', $this->autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->count();
    }

    private function wykonanieZPorcjami(string $porcje = '7,25'): CookedEvent
    {
        $this->wyslij(['note' => 'Wyszło.', 'faktyczne_porcje' => $porcje])->assertSessionHasNoErrors();

        return CookedEvent::query()->sole();
    }

    public function test_bez_liczby_porcji_wykonanie_zapisuje_sie_jak_dotad_a_pole_zostaje_puste(): void
    {
        $this->wyslij(['note' => 'Wyszło.'])->assertRedirect()->assertSessionHasNoErrors();

        $wykonanie = CookedEvent::query()->sole();
        $this->assertNull($wykonanie->faktyczne_porcje, 'brak danych to „nie podano”, nie porcje autora');
        $this->assertSame(1, $this->powiadomienia());
    }

    public function test_pusty_napis_znaczy_nie_podano(): void
    {
        $this->wyslij(['faktyczne_porcje' => '  '])->assertSessionHasNoErrors();

        $this->assertNull(CookedEvent::query()->sole()->faktyczne_porcje);
    }

    public function test_formularz_ma_puste_pole_z_widoczna_etykieta_i_novalidate(): void
    {
        $html = $this->actingAs($this->kucharz)->get(route('cooked.create', $this->przepis->slug).'?porcje=8')->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('Ile porcji wyszło (tylko dla Ciebie)', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*novalidate/', $html);
        // Pole jest puste mimo liczby porcji przepisu i wyboru z adresu.
        $this->assertMatchesRegularExpression('/<input[^>]*id="f-faktyczne_porcje"[^>]*value=""/s', $html.' ');
    }

    /**
     * @return array<string, array{0: string, 1: float}>
     */
    public static function poprawneLiczby(): array
    {
        return [
            'calkowita' => ['8', 8.0],
            'przecinek' => ['2,5', 2.5],
            'kropka' => ['2.5', 2.5],
            'dwa_miejsca' => ['0,75', 0.75],
            'dolna_granica' => ['0,5', 0.5],
            'gorna_granica' => ['100', 100.0],
            'ze_spacjami' => [' 12 ', 12.0],
        ];
    }

    #[DataProvider('poprawneLiczby')]
    public function test_poprawna_liczba_zapisuje_sie_z_zachowana_precyzja(string $wpisane, float $oczekiwana): void
    {
        $this->wyslij(['note' => 'Wyszło.', 'faktyczne_porcje' => $wpisane])->assertSessionHasNoErrors();

        $wykonanie = CookedEvent::query()->sole();
        $this->assertSame($oczekiwana, $wykonanie->faktyczne_porcje);
        $this->assertSame('2026-10-03 12:00:00', $wykonanie->cooked_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->powiadomienia(), 'autor przepisu dostaje dotychczasowe powiadomienie, jedno');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bledneLiczby(): array
    {
        return [
            'tekst' => ['osiem', PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY],
            'trzy_miejsca' => ['2,555', PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY],
            'ulamek_zwykly' => ['1/2', PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY],
            'ujemna' => ['-3', PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY],
            'dwa_przecinki' => ['1,2,3', PorcjeWykonania::KOMUNIKAT_NIEZROZUMIALY],
            'zero' => ['0', PorcjeWykonania::KOMUNIKAT_ZA_MALO],
            'ponizej_polowy' => ['0,49', PorcjeWykonania::KOMUNIKAT_ZA_MALO],
            'ponad_sto' => ['100,01', PorcjeWykonania::KOMUNIKAT_ZA_DUZO],
            'tysiac' => ['1000', PorcjeWykonania::KOMUNIKAT_ZA_DUZO],
        ];
    }

    #[DataProvider('bledneLiczby')]
    public function test_bledna_liczba_nie_zapisuje_wykonania_i_zwraca_blad_przy_polu(string $wpisane, string $komunikat): void
    {
        $this->wyslij(['note' => 'Moja ważna uwaga', 'faktyczne_porcje' => $wpisane])
            ->assertRedirect(route('cooked.create', $this->przepis->slug))
            ->assertSessionHasErrors(['faktyczne_porcje' => $komunikat])
            ->assertSessionHasInput('note', 'Moja ważna uwaga');

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, $this->powiadomienia());
    }

    #[DataProvider('bledneLiczby')]
    public function test_ekran_po_bledzie_mowi_co_zrobic_przy_polu_i_w_podsumowaniu_a_dane_zostaja(string $wpisane, string $komunikat): void
    {
        $html = $this->followingRedirects()->actingAs($this->kucharz)
            ->from(route('cooked.create', $this->przepis->slug))
            ->post(route('cooked.store', $this->przepis->slug), ['note' => 'Moja ważna uwaga', 'actual_minutes' => '45', 'faktyczne_porcje' => $wpisane])
            ->assertOk()->getContent();
        $this->followRedirects = false;

        $this->assertIsString($html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, htmlspecialchars($komunikat, ENT_QUOTES)), 'błąd przy polu ORAZ w podsumowaniu');
        $this->assertStringContainsString('id="f-faktyczne_porcje-error"', $html);
        // Wpisana wartość i pozostałe poprawne pola nie znikają.
        $this->assertMatchesRegularExpression('/id="f-faktyczne_porcje"[^>]*value="'.preg_quote(htmlspecialchars($wpisane, ENT_QUOTES), '/').'"/s', $html);
        $this->assertStringContainsString('Moja ważna uwaga', $html);
        $this->assertMatchesRegularExpression('/id="f-actual_minutes"[^>]*value="45"/s', $html);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function liczbyPozaZakresemBazy(): array
    {
        return [
            'ponizej_polowy' => ['0.49'],
            'ponad_sto' => ['100.01'],
            'ujemna' => ['-1'],
        ];
    }

    #[DataProvider('liczbyPozaZakresemBazy')]
    public function test_baza_pilnuje_zakresu_tez_poza_formularzem(string $zla): void
    {
        $wykonanie = $this->wykonanieZPorcjami('8');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('cooked_events_faktyczne_porcje_check');
        DB::table('cooked_events')->where('id', $wykonanie->getKey())->update(['faktyczne_porcje' => $zla]);
    }

    public function test_akcja_odrzuca_zla_liczbe_nawet_wywolana_wprost(): void
    {
        try {
            app(RecordCookedEvent::class)->handle(cook: $this->kucharz, recipe: $this->przepis, faktycznePorcje: '101');
            $this->fail('Akcja zapisała liczbę poza zakresem.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(PorcjeWykonania::KOMUNIKAT_ZA_DUZO, $e->getMessage());
        }

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, $this->powiadomienia());
    }

    public function test_pole_jest_poza_fillable_i_nie_wchodzi_masowym_przypisaniem(): void
    {
        $this->assertFalse((new CookedEvent)->isFillable('faktyczne_porcje'));
        $this->assertNotContains('faktyczne_porcje', (new CookedEvent)->getFillable());
    }

    public function test_liczba_nie_zmienia_przepisu_ani_publicznego_czasu(): void
    {
        $this->wyslij(['faktyczne_porcje' => '8', 'actual_minutes' => '40'])->assertSessionHasNoErrors();

        $przepis = $this->przepis->fresh();
        $this->assertNotNull($przepis);
        $this->assertSame(4.0, (float) $przepis->servings);
        $this->assertSame(40, CookedEvent::query()->sole()->actual_minutes);
    }

    public function test_liczbe_widzi_tylko_kucharz(): void
    {
        $wykonanie = $this->wykonanieZPorcjami();

        $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()->assertSee('Ugotowano (widzisz tylko Ty)')->assertSee(self::ETYKIETA);
        $this->actingAs($this->kucharz)->get(route('profile.show', ['username' => 'kucharz2540', 'zakladka' => 'ugotowane']))
            ->assertOk()->assertSee(self::ETYKIETA);

        $obca = $this->user('obca2540');

        foreach (['autor' => $this->autor, 'obca' => $obca, 'gosc' => null] as $widz) {
            $adresy = [
                route('cooked.show', $wykonanie),
                route('profile.show', ['username' => 'kucharz2540', 'zakladka' => 'ugotowane']),
                route('recipes.show', $this->przepis->slug),
            ];

            foreach ($adresy as $adres) {
                ($widz === null ? $this : $this->actingAs($widz))->get($adres)->assertOk()
                    ->assertDontSee(self::ETYKIETA)
                    ->assertDontSee('7,25')
                    ->assertDontSee('widzisz tylko Ty');
            }
        }

        $powiadomienie = Notification::query()->where('user_id', $this->autor->getKey())->where('type', Notification::TYPE_COOKED)->sole();
        $this->assertStringNotContainsString('7,25', (string) json_encode($powiadomienie->getAttributes()));
        $this->assertStringNotContainsString('7.25', (string) json_encode($powiadomienie->getAttributes()));
        $this->assertSame(1, $this->powiadomienia(), 'brak dodatkowego powiadomienia');
    }

    public function test_ekran_poprawy_jest_wypelniony_a_obcy_dostaje_odmowe(): void
    {
        $wykonanie = $this->wykonanieZPorcjami();

        $html = $this->actingAs($this->kucharz)->get(route('cooked.porcje.edit', $wykonanie))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/<form[^>]*novalidate/', $html);
        $this->assertMatchesRegularExpression('/id="f-faktyczne_porcje"[^>]*value="7,25"/s', $html);

        $this->actingAs($this->autor)->get(route('cooked.porcje.edit', $wykonanie))->assertForbidden();
        $this->actingAs($this->user('obca2540b'))->get(route('cooked.porcje.edit', $wykonanie))->assertForbidden();
        $this->actingAs($this->user('obca2540c'))->put(route('cooked.porcje.update', $wykonanie), ['faktyczne_porcje' => '1'])->assertForbidden();
        $this->assertSame(7.25, $wykonanie->fresh()?->faktyczne_porcje, 'obcy nie zmienia cudzej liczby');

        auth()->logout();
        $this->get(route('cooked.porcje.edit', $wykonanie))->assertRedirect();
    }

    public function test_poprawa_zmienia_tylko_liczbe_bez_nowego_wykonania_i_powiadomienia(): void
    {
        $wykonanie = $this->wykonanieZPorcjami();
        $przedPoprawa = $wykonanie->fresh();
        $this->assertNotNull($przedPoprawa);
        Carbon::setTestNow(Carbon::parse('2026-11-20 08:00:00', 'UTC'));

        $this->actingAs($this->kucharz)->put(route('cooked.porcje.update', $wykonanie), ['faktyczne_porcje' => '12'])
            ->assertRedirect(route('cooked.show', $wykonanie))
            ->assertSessionHasNoErrors();

        $po = CookedEvent::query()->sole();
        $this->assertSame(12.0, $po->faktyczne_porcje);
        $this->assertSame($przedPoprawa->cooked_at->toIso8601String(), $po->cooked_at->toIso8601String(), 'data wykonania bez zmian');
        $this->assertSame('Wyszło.', $po->note);
        $this->assertSame(1, $this->powiadomienia(), 'poprawa nie wysyła powiadomienia');
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_wyczyszczenie_usuwa_liczbe_bez_usuwania_wykonania(): void
    {
        $wykonanie = $this->wykonanieZPorcjami();

        $this->actingAs($this->kucharz)->put(route('cooked.porcje.update', $wykonanie), ['faktyczne_porcje' => ''])
            ->assertRedirect(route('cooked.show', $wykonanie))
            ->assertSessionHasNoErrors();

        $po = CookedEvent::query()->sole();
        $this->assertNull($po->faktyczne_porcje);
        $this->assertSame('Wyszło.', $po->note);
        $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))->assertOk()->assertDontSee('Ugotowano (widzisz tylko Ty)');
    }

    public function test_zla_poprawa_zostawia_stara_liczbe_a_wpisana_wartosc_wraca_w_polu(): void
    {
        $wykonanie = $this->wykonanieZPorcjami();

        $this->actingAs($this->kucharz)->from(route('cooked.porcje.edit', $wykonanie))
            ->put(route('cooked.porcje.update', $wykonanie), ['faktyczne_porcje' => '500'])
            ->assertRedirect(route('cooked.porcje.edit', $wykonanie))
            ->assertSessionHasErrors(['faktyczne_porcje' => PorcjeWykonania::KOMUNIKAT_ZA_DUZO]);

        $this->assertSame(7.25, $wykonanie->fresh()?->faktyczne_porcje);

        $html = $this->actingAs($this->kucharz)->followingRedirects()->from(route('cooked.porcje.edit', $wykonanie))
            ->put(route('cooked.porcje.update', $wykonanie), ['faktyczne_porcje' => '500'])->getContent();
        $this->assertIsString($html);
        $this->assertGreaterThanOrEqual(2, substr_count($html, htmlspecialchars(PorcjeWykonania::KOMUNIKAT_ZA_DUZO, ENT_QUOTES)));
        $this->assertMatchesRegularExpression('/id="f-faktyczne_porcje"[^>]*value="500"/s', $html);
    }

    public function test_kucharz_poprawia_liczbe_takze_gdy_przepis_przestal_byc_dostepny(): void
    {
        $wykonanie = $this->wykonanieZPorcjami();
        $this->przepis->forceFill(['visibility' => 'private'])->save();

        $this->actingAs($this->kucharz)->get(route('cooked.porcje.edit', $wykonanie))->assertOk();
        $this->actingAs($this->kucharz)->put(route('cooked.porcje.update', $wykonanie), ['faktyczne_porcje' => '3'])->assertSessionHasNoErrors();
        $this->assertSame(3.0, $wykonanie->fresh()?->faktyczne_porcje);

        // Obcy nadal nie widzi niczego, także gdy przepis przestał być publiczny.
        $this->actingAs($this->user('obca2540d'))->get(route('cooked.porcje.edit', $wykonanie))->assertForbidden();
    }

    public function test_eksport_danych_konta_niesie_liczbe_w_nazwanym_polu(): void
    {
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);

        $this->wyslij(['note' => 'Z liczbą', 'faktyczne_porcje' => '7,25']);
        $this->wyslij(['note' => 'Bez liczby']);

        $export = DataExport::create(['user_id' => $this->kucharz->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        (new GenerateUserExport((string) $export->getKey()))->handle();
        $export->refresh();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true);
        $json = $zip->getFromName('dane.json');
        $this->assertIsString($json);
        $zip->close();

        /** @var array{ugotowalem: list<array<string, mixed>>} $dane */
        $dane = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $poNotatce = array_column($dane['ugotowalem'], 'faktyczne_porcje_podane_przeze_mnie', 'notatka');

        $this->assertSame(7.25, $poNotatce['Z liczbą']);
        $this->assertNull($poNotatce['Bez liczby']);
    }

    public function test_wymazanie_konta_zeruje_liczbe_nawet_gdy_wykonanie_zostaje(): void
    {
        $this->wykonanieZPorcjami();
        $this->kucharz->markForDeletion();

        $this->assertTrue((new EraseAccountData)->handle($this->kucharz->refresh()));

        $wykonanie = CookedEvent::query()->sole();
        $this->assertSame('Wyszło.', $wykonanie->note, 'przy zakresie minimum wykonanie zostaje (D-022)');
        $this->assertNull($wykonanie->faktyczne_porcje);
    }

    public function test_wymazanie_konta_z_usunieciem_tresci_kasuje_wykonanie_razem_z_liczba(): void
    {
        $this->wykonanieZPorcjami();
        $this->kucharz->markForDeletion(User::DELETE_SCOPE_EVERYTHING);

        $this->assertTrue((new EraseAccountData)->handle($this->kucharz->refresh()));

        $this->assertSame(0, CookedEvent::query()->whereNotNull('faktyczne_porcje')->count());
    }

    public function test_cofniecie_migracji_odmawia_gdy_zapisano_liczbe(): void
    {
        $this->wykonanieZPorcjami();

        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że wykonanie ma zapisaną liczbę porcji.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(cooked_events.faktyczne_porcje IS NOT NULL): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        $this->assertSame(1, $this->iloscKolumn());
        $this->assertSame(7.25, CookedEvent::query()->sole()->faktyczne_porcje);
    }

    public function test_cofniecie_migracji_przechodzi_gdy_nikt_nie_podal_liczby(): void
    {
        CookedEvent::factory()->create();

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn());

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn());
    }

    private function iloscKolumn(): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'cooked_events' AND column_name = 'faktyczne_porcje'",
        ));
    }
}
