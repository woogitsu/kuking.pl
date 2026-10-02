<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\PrzypomnienieDobowe;
use App\Domain\Pantry\OdnosnikWypisaniaZPrzypomnienia;
use App\Domain\Pantry\PriorytetZuzycia;
use App\Domain\Zgody\PrzestawZgodeNaPrzypomnienieSpizarni;
use App\Mail\PrzypomnienieOProduktach;
use App\Models\User;
use App\Models\WpisZgody;
use App\Support\Czas;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sobotnie przypomnienie o produktach do zużycia (#1903, D-333): osobna,
 * domyślnie WYŁĄCZONA zgoda z dowodem w dzienniku zgód, jeden list tygodniowo,
 * pusty nie wychodzi, własny sufit, deduplikacja, wypisanie bez logowania.
 * Każdy test z `Mail::fake()`: żaden list nie wychodzi naprawdę.
 *
 * Sobota 10 października 2026, 09:00 UTC (10:00/11:00 w Polsce) — pora z harmonogramu.
 */
class SobotniePrzypomnienieOProduktachTest extends TestCase
{
    use RefreshDatabase;

    private const SOBOTA = '2026-10-10 09:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        config([
            'kuking.pantry.przypomnienie.wlaczone' => true,
            'kuking.pantry.przypomnienie.dzienny_sufit' => 20,
        ]);
        $this->travelTo(Carbon::parse(self::SOBOTA, 'UTC'));
    }

    private function osoba(string $nazwa, bool $zgoda = true, ?string $termin = '2026-10-12', array $atrybuty = []): User
    {
        $osoba = $this->user($nazwa, $atrybuty);
        DB::table('users')->where('id', $osoba->getKey())->update(['wants_pantry_reminder' => $zgoda]);

        if ($termin !== null) {
            $this->produkt($osoba, 'mleko', $termin);
        }

        return $osoba->fresh();
    }

    private function produkt(User $osoba, string $nazwa, ?string $termin, bool $mrozone = false): void
    {
        $produkt = $osoba->pantryItems()->create(['name' => $nazwa]);
        DB::table('pantry_items')->where('id', $produkt->getKey())->update([
            'expires_on' => $termin,
            'expiry_kind' => $termin === null ? null : 'use_by',
            'frozen' => $mrozone,
        ]);
    }

    private function wyslij(string ...$opcje): string
    {
        Artisan::call('kuking:wyslij-przypomnienia-spizarni');

        return Artisan::output();
    }

    public function test_zgoda_jest_domyslnie_wylaczona_a_bez_niej_list_nie_wychodzi(): void
    {
        $this->assertFalse((bool) $this->user()->fresh()->wants_pantry_reminder);

        $this->osoba('bezzgody', zgoda: false);
        $this->wyslij();

        Mail::assertNothingQueued();
    }

    public function test_ustawienie_terminu_nie_daje_zgody_na_list(): void
    {
        $ja = $this->user();
        $produkt = $ja->pantryItems()->create(['name' => 'mleko']);

        $this->actingAs($ja)->put(route('pantry.update', $produkt), ['rodzaj' => 'use_by', 'za' => '3'])->assertRedirect();

        $this->assertFalse((bool) $ja->fresh()->wants_pantry_reminder);
        $this->assertSame(0, WpisZgody::query()->where('cel', WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI)->count());
        $this->wyslij();
        Mail::assertNothingQueued();
    }

    public function test_list_wychodzi_tylko_do_osoby_ze_zgoda_i_pilnym_produktem(): void
    {
        $basia = $this->osoba('basia');
        $bezZgody = $this->osoba('marek', zgoda: false);
        $bezPilnych = $this->osoba('zofia', termin: '2026-12-01');
        $bezTerminow = $this->osoba('halina', termin: null);
        $mrozone = $this->osoba('ewa', termin: null);
        $this->produkt($mrozone, 'kurczak', '2026-10-09', mrozone: true);
        $niepotwierdzona = $this->osoba('jan', atrybuty: ['email_verified_at' => null]);
        $zbanowana = $this->osoba('ola', atrybuty: ['status' => User::STATUS_BANNED]);

        $this->wyslij();

        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);
        Mail::assertQueued(PrzypomnienieOProduktach::class, fn (PrzypomnienieOProduktach $m) => $m->hasTo($basia->email));

        foreach ([$bezZgody, $bezPilnych, $bezTerminow, $mrozone, $niepotwierdzona, $zbanowana] as $nie) {
            Mail::assertNotQueued(PrzypomnienieOProduktach::class, fn (PrzypomnienieOProduktach $m) => $m->hasTo($nie->email));
        }
    }

    public function test_pusty_list_nie_wychodzi_i_komenda_mowi_to_wprost(): void
    {
        $this->osoba('zofia', termin: '2026-12-01');

        $wynik = $this->wyslij();

        Mail::assertNothingQueued();
        $this->assertStringContainsString('Nikt nie czeka dziś na przypomnienie o produktach.', $wynik);
    }

    public function test_produkt_po_terminie_nalezy_zuzyc_do_nie_wywoluje_listu(): void
    {
        $osoba = $this->osoba('basia', termin: '2026-09-01');

        $this->wyslij();

        Mail::assertNothingQueued();
        $this->assertSame(1, $osoba->pantryItems()->count(), 'Produkt pozostaje do poprawienia lub usunięcia.');
    }

    public function test_po_terminie_najlepiej_spozyc_przed_nadal_jest_w_liscie(): void
    {
        $osoba = $this->osoba('basia', termin: '2026-09-01');
        $osoba->pantryItems()->where('name', 'mleko')->update(['expiry_kind' => 'best_before']);

        $this->wyslij();

        Mail::assertQueued(PrzypomnienieOProduktach::class, fn (PrzypomnienieOProduktach $m) => $m->hasTo($osoba->email));
    }

    public function test_poza_sobota_nic_nie_wychodzi(): void
    {
        $this->osoba('basia');

        foreach (['2026-10-09 09:00:00', '2026-10-11 09:00:00', '2026-10-07 09:00:00'] as $dzien) {
            $this->travelTo(Carbon::parse($dzien, 'UTC'));
            $wynik = $this->wyslij();
            $this->assertStringContainsString('tylko w sobotę', $wynik);
        }

        Mail::assertNothingQueued();
    }

    public function test_sobota_liczy_sie_w_czasie_polskim_nie_utc(): void
    {
        $this->osoba('basia');

        // Piątek 23:30 UTC = sobota 01:30 w Warszawie (UTC+2): to już sobota.
        $this->travelTo(Carbon::parse('2026-10-09 23:30:00', 'UTC'));
        $this->wyslij();
        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);
    }

    public function test_drugi_przebieg_tego_samego_dnia_nie_wysyla_drugiego_listu(): void
    {
        $this->osoba('basia');

        $this->wyslij();
        $wynik = $this->wyslij();

        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);
        $this->assertStringContainsString('już obsłużone dziś: 1', $wynik);
    }

    /**
     * #2364: klucz deduplikacji to DZIEŃ W POLSCE, nie data UTC. Polska sobota
     * obejmuje dwie daty UTC (piątek po 22:00/23:00 i sobotę), więc klucz z UTC
     * pozwalał na dwa listy: jeden tuż po polskiej północy, drugi z harmonogramu.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function granicePolskiejSoboty(): array
    {
        return [
            'lato: sobota 00:30 czasu polskiego jest jeszcze w piątek UTC' => ['2026-10-09 22:30:00', '2026-10-10 09:00:00'],
            'zima: sobota 00:30 czasu polskiego jest jeszcze w piątek UTC' => ['2027-01-08 23:30:00', '2027-01-09 10:00:00'],
            'sobota przed zmianą czasu jesienią' => ['2026-10-23 22:30:00', '2026-10-24 09:00:00'],
            'sobota przed zmianą czasu wiosną' => ['2026-03-27 23:30:00', '2026-03-28 09:00:00'],
            'sobota od rana do 23:59 w jednej dacie UTC' => ['2026-10-10 06:00:00', '2026-10-10 21:59:00'],
        ];
    }

    #[DataProvider('granicePolskiejSoboty')]
    public function test_jedna_polska_sobota_to_jeden_list_mimo_dwoch_dat_utc(string $pierwszy, string $drugi): void
    {
        $this->travelTo(Carbon::parse($pierwszy, 'UTC'));
        $basia = $this->osoba('basia', termin: null);
        $this->produkt($basia, 'mleko', Carbon::parse($pierwszy, 'UTC')->addDays(2)->toDateString());

        $this->wyslij();
        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);

        $this->travelTo(Carbon::parse($drugi, 'UTC'));
        $wynik = $this->wyslij();

        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);
        $this->assertStringContainsString('już obsłużone dziś: 1', $wynik);
        // Jeden wiersz deduplikacji, z datą polską — nie dwa z różnymi datami UTC.
        $this->assertSame(1, DB::table('przypomnienia_dobowe')->where('rodzaj', 'przypomnienie-spizarni')->count());
        $this->assertSame(
            Czas::lokalnie(Carbon::parse($pierwszy, 'UTC'))->toDateString(),
            (string) DB::table('przypomnienia_dobowe')->where('rodzaj', 'przypomnienie-spizarni')->value('doba'),
        );
    }

    public function test_kolejna_sobota_w_polsce_to_nowa_doba_i_nowy_list(): void
    {
        $this->travelTo(Carbon::parse('2026-10-09 22:30:00', 'UTC'));
        $basia = $this->osoba('basia', termin: null);
        $this->produkt($basia, 'mleko', '2026-10-12');

        $this->wyslij();
        $this->travelTo(Carbon::parse('2026-10-16 22:30:00', 'UTC')); // sobota 17.10, 00:30
        $this->wyslij();

        Mail::assertQueued(PrzypomnienieOProduktach::class, 2);
    }

    public function test_przypomnienie_dobowe_bez_podanej_doby_liczy_utc_jak_dawniej_a_z_podana_uzywa_jej(): void
    {
        $dedup = app(PrzypomnienieDobowe::class);
        $this->travelTo(Carbon::parse('2026-10-09 22:30:00', 'UTC'));

        // Bez doby: data UTC (piątek) — zachowanie innych użytkowników klasy bez zmian.
        $this->assertTrue($dedup->zarezerwuj('inny-rodzaj', 'ktos@example.com'));
        $this->assertFalse($dedup->zarezerwuj('inny-rodzaj', 'ktos@example.com'));
        $this->assertSame('2026-10-09', (string) DB::table('przypomnienia_dobowe')->where('rodzaj', 'inny-rodzaj')->value('doba'));

        // Z dobą polską (sobota): osobny klucz, a zwolnienie dotyczy tej samej doby.
        $this->assertTrue($dedup->zarezerwuj('inny-rodzaj', 'ktos@example.com', '2026-10-10'));
        $this->assertFalse($dedup->zarezerwuj('inny-rodzaj', 'ktos@example.com', '2026-10-10'));
        $dedup->zwolnij('inny-rodzaj', 'ktos@example.com', '2026-10-10');
        $this->assertTrue($dedup->zarezerwuj('inny-rodzaj', 'ktos@example.com', '2026-10-10'));
        $this->assertFalse($dedup->zarezerwuj('inny-rodzaj', 'ktos@example.com'), 'Zwolnienie polskiej doby nie zdjęło klucza UTC.');

        $this->expectException(\InvalidArgumentException::class);
        $dedup->zarezerwuj('inny-rodzaj', 'ktos@example.com', 'jutro');
    }

    public function test_za_tydzien_list_wychodzi_znowu(): void
    {
        $this->osoba('basia');
        $this->wyslij();

        $this->travelTo(Carbon::parse('2026-10-17 09:00:00', 'UTC'));
        $this->wyslij();

        Mail::assertQueued(PrzypomnienieOProduktach::class, 2);
    }

    public function test_sufit_dobowy_zatrzymuje_nadmiar_i_mowi_o_tym(): void
    {
        config(['kuking.pantry.przypomnienie.dzienny_sufit' => 1]);
        $this->osoba('basia');
        $this->osoba('marek');

        $wynik = $this->wyslij();

        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);
        $this->assertStringContainsString('bez listu zostało dziś: 1', $wynik);
    }

    public function test_przy_suficie_mniejszym_niz_liczba_zgod_kazdy_dostaje_list_w_kolejnych_sobotach(): void
    {
        config(['kuking.pantry.przypomnienie.dzienny_sufit' => 2]);
        $osoby = [$this->osoba('basia'), $this->osoba('marek'), $this->osoba('zofia')];

        $this->wyslij();
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00', 'UTC'));
        $this->wyslij();

        foreach ($osoby as $osoba) {
            Mail::assertQueued(PrzypomnienieOProduktach::class, fn (PrzypomnienieOProduktach $m) => $m->hasTo($osoba->email));
        }
    }

    public function test_wylacznik_awaryjny_nic_nie_wysyla(): void
    {
        config(['kuking.pantry.przypomnienie.wlaczone' => false]);
        $this->osoba('basia');

        $this->wyslij();

        Mail::assertNothingQueued();
    }

    public function test_na_sucho_liczy_ale_niczego_nie_rezerwuje_ani_nie_wysyla(): void
    {
        $this->osoba('basia');

        Artisan::call('kuking:wyslij-przypomnienia-spizarni', ['--na-sucho' => true]);

        Mail::assertNothingQueued();
        $this->assertStringContainsString('Do wysłania: 1.', Artisan::output());
        $this->assertSame(0, DB::table('przypomnienia_dobowe')->count());
    }

    public function test_przypomnienie_to_tylko_list_bez_push_i_bez_powiadomienia_w_serwisie(): void
    {
        Notification::fake();
        $this->osoba('basia');

        $this->wyslij();

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_zgoda_z_ustawien_zapisuje_dowod_w_dzienniku_tylko_przy_zmianie(): void
    {
        $ja = $this->user();

        $this->actingAs($ja)->post(route('pantry.reminder'), [
            'original_pantry_reminder' => '0',
            'wants_pantry_reminder' => '1',
        ])->assertRedirect(route('pantry.index'))->assertSessionHas('status', 'Włączono sobotnie przypomnienie. List przyjdzie w sobotę rano, jeśli będzie co na nim wymienić.');

        $this->assertTrue((bool) $ja->fresh()->wants_pantry_reminder);
        $wpis = WpisZgody::query()->where('user_id', $ja->getKey())->where('cel', WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI)->sole();
        $this->assertSame(WpisZgody::UDZIELONA, $wpis->czynnosc);
        $this->assertSame(WpisZgody::ZRODLO_USTAWIENIA, $wpis->zrodlo);
        $this->assertNotEmpty($wpis->wersja_polityki);

        // Ten sam stan jeszcze raz: brak zmiany = brak nowego wiersza.
        $this->actingAs($ja)->post(route('pantry.reminder'), ['original_pantry_reminder' => '1', 'wants_pantry_reminder' => '1']);
        $this->assertSame(1, WpisZgody::query()->where('cel', WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI)->count());

        // Wycofanie zapisuje drugi wiersz.
        $this->actingAs($ja)->post(route('pantry.reminder'), ['original_pantry_reminder' => '1']);
        $this->assertFalse((bool) $ja->fresh()->wants_pantry_reminder);
        $czynnosci = WpisZgody::query()->where('cel', WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI)->pluck('czynnosc')->sort()->values()->all();
        $this->assertSame([WpisZgody::UDZIELONA, WpisZgody::WYCOFANA], $czynnosci);
    }

    public function test_stary_formularz_nie_zapisze_nikogo_z_powrotem_na_list(): void
    {
        $ja = $this->osoba('basia');
        // Formularz otwarty, gdy zgoda była włączona; w międzyczasie wypisanie odnośnikiem.
        app(PrzestawZgodeNaPrzypomnienieSpizarni::class)->handle($ja, false, WpisZgody::ZRODLO_LINK_WYPISANIA);

        $this->actingAs($ja)->post(route('pantry.reminder'), ['original_pantry_reminder' => '1', 'wants_pantry_reminder' => '1'])
            ->assertRedirect(route('pantry.index'));

        $this->assertFalse((bool) $ja->fresh()->wants_pantry_reminder);
    }

    public function test_formularz_na_liscie_ma_pole_domyslnie_nie_zaznaczone_i_opis_bez_obietnic(): void
    {
        $ja = $this->user();

        $html = $this->actingAs($ja)->get(route('pantry.index'))->assertOk()
            ->assertSee('Chcę dostawać w sobotę e-mail o produktach do zużycia')
            ->assertSee('Jeden list tygodniowo, rano, i tylko wtedy, gdy na liście jest produkt')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="wants_pantry_reminder"[^>]*checked/', $html);
    }

    public function test_wypisanie_get_pyta_post_wycofuje_z_dowodem_a_powrot_wlacza(): void
    {
        $basia = $this->osoba('basia');
        $wypisz = OdnosnikWypisaniaZPrzypomnienia::dla($basia);

        $this->get($wypisz)->assertOk()->assertSee('Tak, nie wysyłajcie mi go');
        $this->assertTrue((bool) $basia->fresh()->wants_pantry_reminder, 'GET niczego nie zmienia.');

        $this->post($wypisz)->assertOk()->assertSee('Nie wyślemy już sobotniego przypomnienia');
        $this->assertFalse((bool) $basia->fresh()->wants_pantry_reminder);
        $wpis = WpisZgody::query()->where('user_id', $basia->getKey())->where('cel', WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI)->sole();
        $this->assertSame(WpisZgody::WYCOFANA, $wpis->czynnosc);
        $this->assertSame(WpisZgody::ZRODLO_LINK_WYPISANIA, $wpis->zrodlo);

        $this->wyslij();
        Mail::assertNothingQueued();

        $this->post(OdnosnikWypisaniaZPrzypomnienia::powrotDla($basia))->assertOk()->assertSee('Sobotnie przypomnienie przyjdzie');
        $this->assertTrue((bool) $basia->fresh()->wants_pantry_reminder);
        $zrodla = WpisZgody::query()->where('cel', WpisZgody::CEL_PRZYPOMNIENIE_SPIZARNI)->pluck('zrodlo')->sort()->values()->all();
        $this->assertSame([WpisZgody::ZRODLO_LINK_POWROTNY, WpisZgody::ZRODLO_LINK_WYPISANIA], $zrodla);
    }

    public function test_wygasly_link_powrotu_nie_wlacza_zgody_a_link_wypisania_z_listu_dziala_po_miesiacu(): void
    {
        $basia = $this->osoba('basia');
        $wypisz = OdnosnikWypisaniaZPrzypomnienia::dla($basia);

        $this->post($wypisz)->assertOk();
        $powrot = OdnosnikWypisaniaZPrzypomnienia::powrotDla($basia);

        $this->travelTo(now()->addMinutes(61));
        $this->post($powrot)->assertOk()->assertSee('Ten link wygasł')->assertSee('Zaloguj się')
            ->assertSee('na stronie „Co mam w domu” (jest tam pole „Sobotnie przypomnienie”)', false)
            ->assertDontSee('w Ustawieniach');
        $this->assertFalse((bool) $basia->fresh()->wants_pantry_reminder, 'Wygasły link nie może włączyć zgody.');
        $this->assertSame(1, WpisZgody::query()->where('user_id', $basia->getKey())->count());

        // Link z listu jest bezterminowy: po miesiącu wypisanie nadal działa.
        $this->travelTo(now()->addDays(30));
        $this->get($wypisz)->assertOk()->assertSee('Tak, nie wysyłajcie mi go');
        $this->post($wypisz)->assertOk()->assertSee('Nie wyślemy już');
    }

    public function test_link_powrotu_dziala_w_ciagu_godziny_a_podrobiony_dostaje_403(): void
    {
        $basia = $this->osoba('basia', zgoda: false);
        $powrot = OdnosnikWypisaniaZPrzypomnienia::powrotDla($basia);

        $this->post($powrot.'x')->assertForbidden();
        $this->assertFalse((bool) $basia->fresh()->wants_pantry_reminder);

        $this->travelTo(now()->addMinutes(59));
        $this->post($powrot)->assertOk()->assertSee('Sobotnie przypomnienie przyjdzie');
        $this->assertTrue((bool) $basia->fresh()->wants_pantry_reminder);
    }

    public function test_konto_wymazane_nie_zapisuje_nic_do_dziennika_zgod_przez_wracam_ani_wypisz(): void
    {
        $basia = $this->osoba('basia');
        $wypisz = OdnosnikWypisaniaZPrzypomnienia::dla($basia);
        $powrot = OdnosnikWypisaniaZPrzypomnienia::powrotDla($basia);
        DB::table('users')->where('id', $basia->getKey())->update([
            'status' => User::STATUS_ERASED,
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
            'delete_requested_at' => now(),
            'data_erased_at' => now(),
            'wants_pantry_reminder' => false,
        ]);

        $this->post($powrot)->assertNotFound();
        $this->post($wypisz)->assertOk();

        $this->assertSame(0, WpisZgody::query()->where('user_id', $basia->getKey())->count());
        $this->assertFalse((bool) $basia->fresh()->wants_pantry_reminder);
        $this->assertFalse(app(PrzestawZgodeNaPrzypomnienieSpizarni::class)->handle($basia->fresh(), true, WpisZgody::ZRODLO_LINK_POWROTNY));
        $this->assertSame(0, WpisZgody::query()->where('user_id', $basia->getKey())->count());
    }

    public function test_wypisanie_bez_podpisu_jest_zabronione(): void
    {
        $basia = $this->osoba('basia');

        $this->get(route('spizarnia.wypisz', $basia))->assertForbidden();
        $this->post(route('spizarnia.wypisz', $basia))->assertForbidden();
        $this->post(route('spizarnia.wracam', $basia))->assertForbidden();
        $this->assertTrue((bool) $basia->fresh()->wants_pantry_reminder);
    }

    public function test_list_ma_tresc_bez_slow_o_swiezosci_naglowek_wypisania_i_zarezerwowany_znacznik(): void
    {
        $basia = $this->osoba('basia');
        $this->produkt($basia, 'szynka', '2026-10-10');
        $mail = new PrzypomnienieOProduktach($basia);

        $html = $mail->render();
        $naglowki = $mail->headers()->text;

        $this->assertStringContainsString('mleko', $html);
        $this->assertStringContainsString('szynka', $html);
        $this->assertStringContainsString('Termin to Twoja notatka z opakowania', $html);
        $this->assertStringContainsString(OdnosnikWypisaniaZPrzypomnienia::dla($basia), html_entity_decode($html));
        $this->assertStringContainsString('najpierw=termin', $html);
        $this->assertDoesNotMatchRegularExpression('/śwież|bezpiecz|zepsut|marnuj|uratuj/iu', strip_tags($html));
        $this->assertStringContainsString('<'.OdnosnikWypisaniaZPrzypomnienia::dla($basia).'>', $naglowki['List-Unsubscribe']);
        $this->assertArrayHasKey('X-Kuking-Budzet', $naglowki);
        // Szynka z terminem dziś przed mlekiem z terminem za 2 dni.
        $this->assertLessThan(strpos($html, 'mleko'), strpos($html, 'szynka'));
    }

    public function test_wersja_tekstowa_listu_nie_zamienia_znakow_na_encje_html(): void
    {
        $basia = $this->osoba('basia', termin: null);
        $this->produkt($basia, "ser 'Gouda' & szynka", '2026-10-10');
        $basia->pantryItems()->where('name', "ser 'Gouda' & szynka")->update(['quantity_note' => 'pół & pół']);

        $tresc = (new PrzypomnienieOProduktach($basia))->content();
        $tekst = view($tresc->text, $tresc->with)->render();

        $this->assertStringContainsString("- ser 'Gouda' & szynka (pół & pół)", $tekst);
        $this->assertStringNotContainsString('&amp;', $tekst);
        $this->assertStringNotContainsString('&#039;', $tekst);
    }

    public function test_list_wymienia_najwyzej_dziesiec_produktow_a_reszte_liczy(): void
    {
        $basia = $this->osoba('basia', termin: null);
        foreach (['jogurt', 'kefir', 'maslo', 'twarog', 'smietana', 'szynka', 'pasztet', 'kielbasa', 'salata', 'pomidory', 'ogorki', 'papryka', 'rzodkiewka'] as $nazwa) {
            $this->produkt($basia, $nazwa, '2026-10-11');
        }

        $html = strip_tags((new PrzypomnienieOProduktach($basia))->render());

        $this->assertStringContainsString('I jeszcze 3 produkty na liście.', $html);
        $wymienione = array_filter(
            ['jogurt', 'kefir', 'maslo', 'twarog', 'smietana', 'szynka', 'pasztet', 'kielbasa', 'salata', 'pomidory', 'ogorki', 'papryka', 'rzodkiewka'],
            fn (string $nazwa): bool => str_contains($html, $nazwa),
        );
        $this->assertCount(10, $wymienione);
    }

    public function test_w_chwili_wysylki_wycofana_zgoda_albo_zniknieta_lista_zatrzymuja_list(): void
    {
        $basia = $this->osoba('basia');
        $mail = new PrzypomnienieOProduktach($basia);

        // Kontrola dodatnia: zgoda jest i jest co wymienić — transport dostaje list.
        $transport = \Mockery::mock(Mailer::class);
        $transport->shouldReceive('send')->once()->andReturn(null);
        $mail->send($transport);

        // Zgoda wycofana między kolejką a wysyłką — transport nie jest dotykany.
        DB::table('users')->where('id', $basia->getKey())->update(['wants_pantry_reminder' => false]);
        $zablokowany = \Mockery::mock(Mailer::class);
        $zablokowany->shouldReceive('send')->never();
        $this->assertNull((new PrzypomnienieOProduktach($basia))->send($zablokowany));

        // Nie ma już czego wymieniać: pusty list nie wychodzi.
        DB::table('users')->where('id', $basia->getKey())->update(['wants_pantry_reminder' => true]);
        DB::table('pantry_items')->where('user_id', $basia->getKey())->delete();
        $pusty = \Mockery::mock(Mailer::class);
        $pusty->shouldReceive('send')->never();
        $this->assertNull((new PrzypomnienieOProduktach($basia))->send($pusty));

        // Konto zamknięte albo adres niepotwierdzony — też nie.
        $this->produkt($basia, 'mleko', '2026-10-11');
        DB::table('users')->where('id', $basia->getKey())->update(['email_verified_at' => null]);
        $niepotwierdzony = \Mockery::mock(Mailer::class);
        $niepotwierdzony->shouldReceive('send')->never();
        $this->assertNull((new PrzypomnienieOProduktach($basia))->send($niepotwierdzony));
    }

    public function test_granica_pilnych_w_liscie_to_ta_sama_regula_co_na_ekranie(): void
    {
        $basia = $this->osoba('basia', termin: null);
        $this->produkt($basia, 'sernik', '2026-10-13');
        $this->produkt($basia, 'pasztet', '2026-10-14');

        $this->assertSame(['sernik'], PriorytetZuzycia::pilneDla($basia)->pluck('name')->all());

        $this->wyslij();
        Mail::assertQueued(PrzypomnienieOProduktach::class, 1);
    }
}
