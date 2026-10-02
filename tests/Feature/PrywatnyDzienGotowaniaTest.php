<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\CookActivity;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Gotowanie\DzienGotowania;
use App\Domain\UgotujmyRazem\TydzienGotowania;
use App\Domain\UgotujmyRazem\UgotujmyRazem;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\GenerateUserExport;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WeeklyRecipePick;
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
 * Prywatny dzień faktycznego gotowania przy „Ugotowałem" (issue #2583,
 * decyzja właściciela z 2.10.2026).
 *
 * Granice, które ten plik pilnuje:
 *  - dzień jest opcjonalny, domyślnie pusty, formularz zostaje prosty;
 *  - `cooked_at` i `created_at` zostają chwilą zgłoszenia — kolejność,
 *    aktywność kucharza i tydzień „Ugotujmy razem" się nie ruszają;
 *  - powiadomienie autora przepisu jak dotąd, jedno na jedno wysłanie;
 *  - dzień z przyszłości (wg Europe/Warsaw) i sprzed dolnej granicy jest
 *    odrzucany po polsku, a poprawne dane w formularzu zostają;
 *  - widzi go wyłącznie kucharz; eksport go niesie, wymazanie konta zeruje.
 */
class PrywatnyDzienGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_10_02_230000_add_dzien_gotowania_to_cooked_events.php';

    /** Wyróżniający się dzień, którego nie ma nigdzie indziej na stronach. */
    private const DZIEN = '2026-03-17';

    private const DZIEN_SLOWNIE = '17 marca 2026';

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-02 12:00:00', 'UTC'));

        $this->autor = $this->user('autorka2583');
        $this->kucharz = $this->user('kucharz2583');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'title' => 'Zupa dnia',
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

    public function test_bez_dnia_gotowania_wykonanie_zapisuje_sie_jak_dotad(): void
    {
        $this->wyslij(['note' => 'Wyszło.'])->assertRedirect()->assertSessionHasNoErrors();

        $wykonanie = CookedEvent::query()->sole();
        $this->assertNull($wykonanie->dzien_gotowania);
        $this->assertSame('2026-10-02 12:00:00', $wykonanie->cooked_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->powiadomienia());
    }

    public function test_pusty_napis_w_polu_dnia_znaczy_nie_podano(): void
    {
        $this->wyslij(['note' => 'Wyszło.', 'dzien_gotowania' => ''])->assertSessionHasNoErrors();

        $this->assertNull(CookedEvent::query()->sole()->dzien_gotowania);
    }

    public function test_formularz_ma_puste_pole_daty_z_etykieta_i_novalidate_oraz_zakresem(): void
    {
        $html = $this->actingAs($this->kucharz)->get(route('cooked.create', $this->przepis->slug))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('Dzień gotowania (tylko dla Ciebie)', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*novalidate/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*id="f-dzien_gotowania"[^>]*type="date"[^>]*value=""[^>]*max="2026-10-02"/s', $html.' ');
        $this->assertStringContainsString('min="2000-01-01"', $html);
    }

    public function test_wczesniejszy_dzien_zapisuje_sie_a_cooked_at_zostaje_chwila_zgloszenia(): void
    {
        $this->wyslij(['note' => 'Wyszło.', 'dzien_gotowania' => self::DZIEN])->assertSessionHasNoErrors();

        $wykonanie = CookedEvent::query()->sole();
        $this->assertSame(self::DZIEN, $wykonanie->dzien_gotowania?->format('Y-m-d'));
        $this->assertSame('2026-10-02 12:00:00', $wykonanie->cooked_at->format('Y-m-d H:i:s'), 'cooked_at ma zostać chwilą zgłoszenia');
        $this->assertSame('2026-10-02 12:00:00', $wykonanie->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->powiadomienia(), 'autor przepisu dostaje dotychczasowe powiadomienie, jedno');
    }

    public function test_dzien_nie_dopisuje_wykonania_do_dawnego_tygodnia_ani_do_aktywnosci(): void
    {
        $this->wyslij(['note' => 'Wyszło.', 'dzien_gotowania' => '2026-09-14'])->assertSessionHasNoErrors();

        // Tydzień „Ugotujmy razem" z poniedziałku 2026-09-14 NIE dostaje wykonania.
        $dawny = new WeeklyRecipePick;
        $dawny->forceFill(['week_starts_on' => '2026-09-14', 'recipe_id' => $this->przepis->getKey()])->save();
        $this->assertSame(0, app(UgotujmyRazem::class)->wykonania($dawny, null)->total());

        // Bieżący tydzień (poniedziałek 2026-09-28) je ma — liczy się chwila zgłoszenia.
        $this->assertSame('2026-09-28', TydzienGotowania::biezacy()->dzienStartu());
        $dawny->forceFill(['week_starts_on' => '2026-09-28'])->save();
        $this->assertSame(1, app(UgotujmyRazem::class)->wykonania($dawny, null)->total());

        // Aktywność kucharza (WAC, kohorty) stoi na chwili zgłoszenia.
        $aktywnosc = DB::query()->fromSub((new CookActivity)->unionQuery(), 'aktywnosc')
            ->where('user_id', $this->kucharz->getKey())->pluck('activity_at')->all();
        $this->assertCount(1, $aktywnosc);
        $this->assertStringStartsWith('2026-10-02', (string) $aktywnosc[0]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function bledneDni(): array
    {
        return [
            'jutro' => ['2026-10-03', DzienGotowania::KOMUNIKAT_Z_PRZYSZLOSCI],
            'za_rok' => ['2027-10-02', DzienGotowania::KOMUNIKAT_Z_PRZYSZLOSCI],
            'przed_dolna_granica' => ['1999-12-31', DzienGotowania::KOMUNIKAT_ZA_WCZESNY],
            'nie_data' => ['wczoraj', DzienGotowania::KOMUNIKAT_NIEZROZUMIALY],
            'nieistniejacy_dzien' => ['2026-02-30', DzienGotowania::KOMUNIKAT_NIEZROZUMIALY],
            'data_z_godzina' => ['2026-09-01 10:00', DzienGotowania::KOMUNIKAT_NIEZROZUMIALY],
            'bez_zer' => ['2026-9-1', DzienGotowania::KOMUNIKAT_NIEZROZUMIALY],
        ];
    }

    #[DataProvider('bledneDni')]
    public function test_bledny_dzien_nie_zapisuje_wykonania_i_zwraca_blad_przy_polu(string $dzien, string $komunikat): void
    {
        $this->wyslij(['note' => 'Moja ważna uwaga', 'dzien_gotowania' => $dzien])
            ->assertRedirect(route('cooked.create', $this->przepis->slug))
            ->assertSessionHasErrors(['dzien_gotowania' => $komunikat])
            ->assertSessionHasInput('note', 'Moja ważna uwaga');

        $this->assertSame(0, CookedEvent::query()->count(), 'błędny dzień nie może zapisać wykonania');
        $this->assertSame(0, $this->powiadomienia());
    }

    #[DataProvider('bledneDni')]
    public function test_ekran_po_bledzie_mowi_co_zrobic_przy_polu_i_w_podsumowaniu_a_dane_zostaja(string $dzien, string $komunikat): void
    {
        $html = $this->followingRedirects()->actingAs($this->kucharz)
            ->from(route('cooked.create', $this->przepis->slug))
            ->post(route('cooked.store', $this->przepis->slug), ['note' => 'Moja ważna uwaga', 'actual_minutes' => '45', 'dzien_gotowania' => $dzien])
            ->assertOk()->getContent();
        $this->followRedirects = false;

        $this->assertIsString($html);
        // Błąd przy polu ORAZ w podsumowaniu na górze.
        $this->assertGreaterThanOrEqual(2, substr_count($html, htmlspecialchars($komunikat, ENT_QUOTES)));
        $this->assertStringContainsString('id="f-dzien_gotowania-error"', $html);
        // Poprawnie wpisane pola nie znikają.
        $this->assertStringContainsString('Moja ważna uwaga', $html);
        $this->assertMatchesRegularExpression('/id="f-actual_minutes"[^>]*value="45"/s', $html);
    }

    /**
     * Każda para: chwila UTC, dzień ostatni dopuszczalny, pierwszy niedopuszczalny
     * — według doby w Europe/Warsaw, nie w UTC.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function granicePolnocyWarszawy(): array
    {
        return [
            'po_polnocy_przelom_roku' => ['2026-12-31 23:30:00', '2027-01-01', '2027-01-02'],
            'tuz_przed_polnoca_zima' => ['2026-12-31 22:30:00', '2026-12-31', '2027-01-01'],
            'po_polnocy_lato' => ['2026-06-30 22:30:00', '2026-07-01', '2026-07-02'],
            'zmiana_czasu_na_letni' => ['2026-03-28 23:30:00', '2026-03-29', '2026-03-30'],
            'zmiana_czasu_na_zimowy' => ['2026-10-24 22:30:00', '2026-10-25', '2026-10-26'],
        ];
    }

    #[DataProvider('granicePolnocyWarszawy')]
    public function test_dzis_liczy_sie_w_warszawie_a_nie_w_utc(string $teraz, string $ostatniDozwolony, string $pierwszyZabroniony): void
    {
        Carbon::setTestNow(Carbon::parse($teraz, 'UTC'));

        $this->wyslij(['dzien_gotowania' => $ostatniDozwolony])->assertSessionHasNoErrors();
        $this->assertSame($ostatniDozwolony, CookedEvent::query()->sole()->dzien_gotowania?->format('Y-m-d'));

        $this->wyslij(['dzien_gotowania' => $pierwszyZabroniony])
            ->assertSessionHasErrors(['dzien_gotowania' => DzienGotowania::KOMUNIKAT_Z_PRZYSZLOSCI]);
        $this->assertSame(1, CookedEvent::query()->count());
    }

    public function test_dolna_granica_jest_wlaczona_a_baza_pilnuje_jej_tez_poza_formularzem(): void
    {
        $this->wyslij(['dzien_gotowania' => DzienGotowania::NAJWCZESNIEJSZY])->assertSessionHasNoErrors();
        $this->assertSame('2000-01-01', CookedEvent::query()->sole()->dzien_gotowania?->format('Y-m-d'));

        $this->expectException(QueryException::class);
        DB::table('cooked_events')->where('id', CookedEvent::query()->sole()->getKey())->update(['dzien_gotowania' => '1999-12-31']);
    }

    public function test_akcja_odrzuca_dzien_z_przyszlosci_nawet_wywolana_wprost(): void
    {
        try {
            app(RecordCookedEvent::class)->handle(cook: $this->kucharz, recipe: $this->przepis, dzienGotowania: '2026-10-03');
            $this->fail('Akcja zapisała dzień z przyszłości.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(DzienGotowania::KOMUNIKAT_Z_PRZYSZLOSCI, $e->getMessage());
        }

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, $this->powiadomienia());
    }

    public function test_to_samo_wyslanie_dwa_razy_to_jedno_wykonanie_i_jedno_powiadomienie(): void
    {
        $klucz = '0197a6a6-1111-7000-8000-000000000001';

        $this->wyslij(['klucz_wyslania' => $klucz, 'dzien_gotowania' => self::DZIEN]);
        $this->wyslij(['klucz_wyslania' => $klucz, 'dzien_gotowania' => '2026-09-01']);

        $this->assertSame(1, CookedEvent::query()->count());
        $this->assertSame(self::DZIEN, CookedEvent::query()->sole()->dzien_gotowania?->format('Y-m-d'));
        $this->assertSame(1, $this->powiadomienia());
    }

    public function test_pole_jest_poza_fillable_i_nie_wchodzi_masowym_przypisaniem(): void
    {
        $this->assertFalse((new CookedEvent)->isFillable('dzien_gotowania'));
        $this->assertNotContains('dzien_gotowania', (new CookedEvent)->getFillable());
    }

    public function test_dzien_widzi_tylko_kucharz(): void
    {
        $this->wyslij(['note' => 'Wyszło.', 'dzien_gotowania' => self::DZIEN]);
        $wykonanie = CookedEvent::query()->sole();

        // Kucharz widzi dzień przy własnym wykonaniu i na swoim profilu.
        $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()->assertSee('Gotowane (widzisz tylko Ty)')->assertSee(self::DZIEN_SLOWNIE);
        $this->actingAs($this->kucharz)->get(route('profile.show', ['username' => 'kucharz2583', 'zakladka' => 'ugotowane']))
            ->assertOk()->assertSee(self::DZIEN_SLOWNIE);

        // Autor przepisu, obca osoba i gość — nie.
        $obca = $this->user('obca2583');
        $widzowie = ['autor' => $this->autor, 'obca' => $obca, 'gosc' => null];

        foreach ($widzowie as $widz) {
            $zadania = [
                route('cooked.show', $wykonanie),
                route('profile.show', ['username' => 'kucharz2583', 'zakladka' => 'ugotowane']),
                route('recipes.show', $this->przepis->slug),
            ];

            foreach ($zadania as $adres) {
                $odpowiedz = ($widz === null ? $this : $this->actingAs($widz))->get($adres)->assertOk();
                $odpowiedz->assertDontSee(self::DZIEN_SLOWNIE)
                    ->assertDontSee(self::DZIEN)
                    ->assertDontSee('widzisz tylko Ty');
            }
        }

        // Powiadomienie autora nie niesie dnia.
        $powiadomienie = Notification::query()->where('user_id', $this->autor->getKey())->where('type', Notification::TYPE_COOKED)->sole();
        $this->assertStringNotContainsString(self::DZIEN, (string) json_encode($powiadomienie->getAttributes()));
        $this->assertSame(1, $this->powiadomienia());
    }

    public function test_eksport_danych_konta_niesie_dzien_w_nazwanym_polu(): void
    {
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);

        $this->wyslij(['note' => 'Z dniem', 'dzien_gotowania' => self::DZIEN]);
        $this->wyslij(['note' => 'Bez dnia']);

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
        $poNotatce = array_column($dane['ugotowalem'], 'dzien_gotowania_podany_przeze_mnie', 'notatka');

        $this->assertSame(self::DZIEN, $poNotatce['Z dniem']);
        $this->assertNull($poNotatce['Bez dnia']);
        $this->assertTrue(class_exists(CollectUserExportData::class));
    }

    public function test_wymazanie_konta_zeruje_dzien_nawet_gdy_wykonanie_zostaje(): void
    {
        $this->wyslij(['note' => 'Zostaje', 'dzien_gotowania' => self::DZIEN]);
        $this->kucharz->markForDeletion();

        $this->assertTrue((new EraseAccountData)->handle($this->kucharz->refresh()));

        $wykonanie = CookedEvent::query()->sole();
        $this->assertSame('Zostaje', $wykonanie->note, 'przy zakresie minimum wykonanie zostaje (D-022)');
        $this->assertNull($wykonanie->dzien_gotowania);
    }

    public function test_wymazanie_konta_z_usunieciem_tresci_kasuje_wykonanie_razem_z_dniem(): void
    {
        $this->wyslij(['note' => 'Znika', 'dzien_gotowania' => self::DZIEN]);
        $this->kucharz->markForDeletion(User::DELETE_SCOPE_EVERYTHING);

        $this->assertTrue((new EraseAccountData)->handle($this->kucharz->refresh()));

        $this->assertSame(0, CookedEvent::query()->whereNotNull('dzien_gotowania')->count());
    }

    public function test_cofniecie_migracji_odmawia_gdy_zapisano_dzien(): void
    {
        $this->wyslij(['dzien_gotowania' => self::DZIEN]);

        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że wykonanie ma zapisany dzień.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(cooked_events.dzien_gotowania IS NOT NULL): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        $this->assertSame(1, $this->iloscKolumn());
        $this->assertSame(self::DZIEN, CookedEvent::query()->sole()->dzien_gotowania?->format('Y-m-d'));
    }

    public function test_cofniecie_migracji_przechodzi_gdy_nikt_nie_podal_dnia(): void
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
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'cooked_events' AND column_name = 'dzien_gotowania'",
        ));
    }
}
