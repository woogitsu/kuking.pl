<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Zgody\PrzestawZgodeNaDigest;
use App\Domain\Zgody\WersjaDokumentu;
use App\Models\User;
use App\Models\WpisZgody;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Data publikacji osobno od daty wejścia w życie (D-327).
 *
 * Decyzja właściciela z 26.09.2026: przy ISTOTNEJ zmianie polityki albo
 * regulaminu pasek pokazuje się od publikacji, a nowa wersja obowiązuje
 * 14 dni później; drobna poprawka wchodzi od razu. Oznaczenie jest jawne
 * (`kuking.zgody.zmiana_*.istotna`), a zgoda zapisuje wersję obowiązującą.
 */
class WersjaDokumentuTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIKACJA = '2026-10-01';

    private const POPRZEDNIA = '2026-09-26';

    private function istotnaZmianaRegulaminu(): void
    {
        config([
            'kuking.zgody.wersja_regulaminu' => self::PUBLIKACJA,
            'kuking.zgody.zmiana_regulaminu' => ['istotna' => true, 'poprzednia' => self::POPRZEDNIA, 'obowiazuje_od' => null],
        ]);
    }

    private function drobnaZmianaRegulaminu(): void
    {
        config([
            'kuking.zgody.wersja_regulaminu' => self::PUBLIKACJA,
            'kuking.zgody.zmiana_regulaminu' => ['istotna' => false, 'poprzednia' => self::POPRZEDNIA, 'obowiazuje_od' => null],
        ]);
    }

    private function dzien(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data, Czas::strefa())->startOfDay();
    }

    private function kontoSprzedPublikacji(): User
    {
        $osoba = $this->user('stara_osoba');
        $osoba->forceFill(['created_at' => $this->dzien(self::PUBLIKACJA)->subDays(30)])->save();

        return $osoba->fresh();
    }

    public function test_istotna_zmiana_obowiazuje_czternascie_dni_po_publikacji(): void
    {
        $this->istotnaZmianaRegulaminu();
        $wersja = WersjaDokumentu::regulamin();

        $this->assertSame(14, WersjaDokumentu::okresIstotnejZmianyDni());
        $this->assertTrue($wersja->obowiazujeOd()->equalTo($this->dzien('2026-10-15')));

        // Granica: ostatnia sekunda przed dniem wejścia — jeszcze poprzednia.
        $this->assertSame(self::POPRZEDNIA, $wersja->obowiazujaca($this->dzien(self::PUBLIKACJA)));
        $this->assertSame(self::POPRZEDNIA, $wersja->obowiazujaca($this->dzien('2026-10-15')->subSecond()));
        $this->assertSame(self::PUBLIKACJA, $wersja->obowiazujaca($this->dzien('2026-10-15')));

        // Późniejszy termin wolno ustawić jawnie.
        config(['kuking.zgody.zmiana_regulaminu.obowiazuje_od' => '2026-11-01']);
        $this->assertTrue(WersjaDokumentu::regulamin()->obowiazujeOd()->equalTo($this->dzien('2026-11-01')));
    }

    /** Kontrola ujemna do poprzedniego: drobna nie ma okresu przejściowego. */
    public function test_drobna_zmiana_obowiazuje_od_dnia_publikacji(): void
    {
        $this->drobnaZmianaRegulaminu();
        $wersja = WersjaDokumentu::regulamin();

        $this->assertTrue($wersja->obowiazujeOd()->equalTo($this->dzien(self::PUBLIKACJA)));
        $this->assertFalse($wersja->wOkresiePrzejsciowym($this->dzien(self::PUBLIKACJA)));
        $this->assertSame(self::PUBLIKACJA, $wersja->obowiazujaca($this->dzien(self::PUBLIKACJA)));
    }

    public function test_pasek_przed_data_wejscia_mowi_od_kiedy_i_ze_obowiazuje_poprzednia(): void
    {
        Mail::fake();
        $this->istotnaZmianaRegulaminu();
        $osoba = $this->kontoSprzedPublikacji();
        $this->travelTo($this->dzien(self::PUBLIKACJA)->addDays(3));

        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertSee('data-pasek-zmiany-regulaminu', false)
            ->assertSee('Zmieniliśmy regulamin.')
            ->assertSee('Nowa wersja obowiązuje od 15 października 2026. Do tego dnia obowiązuje poprzednia.')
            ->assertSee(route('terms').'#co-sie-zmienilo', false);

        // Ostatnia chwila przed wejściem w życie — nadal okres przejściowy.
        $this->travelTo($this->dzien('2026-10-15')->subSecond());
        $this->actingAs($osoba)->get(route('discover'))
            ->assertSee('Do tego dnia obowiązuje poprzednia.');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_pasek_po_dacie_wejscia_nie_mowi_juz_o_poprzedniej(): void
    {
        $this->istotnaZmianaRegulaminu();
        $osoba = $this->kontoSprzedPublikacji();
        $this->travelTo($this->dzien('2026-10-15'));

        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertSee('data-pasek-zmiany-regulaminu', false)
            ->assertSee('Nowa wersja obowiązuje od 15 października 2026.')
            ->assertDontSee('Do tego dnia obowiązuje poprzednia.');
    }

    public function test_drobna_zmiana_pasek_bez_terminu(): void
    {
        $this->drobnaZmianaRegulaminu();
        $osoba = $this->kontoSprzedPublikacji();
        $this->travelTo($this->dzien(self::PUBLIKACJA)->addDays(3));

        $this->actingAs($osoba)->get(route('home'))->assertOk()
            ->assertSee('data-pasek-zmiany-regulaminu', false)
            ->assertSee('Zmieniliśmy regulamin.')
            ->assertDontSee('data-pasek-termin', false)
            ->assertDontSee('Nowa wersja obowiązuje od')
            ->assertDontSee('obowiązuje poprzednia');
    }

    /**
     * Zgoda zapisuje wersję OBOWIĄZUJĄCĄ. W okresie przejściowym to jest
     * poprzednia polityka, po nim — nowa.
     */
    public function test_zgoda_zapisuje_wersje_polityki_obowiazujaca_w_chwili_zgody(): void
    {
        config([
            'kuking.zgody.wersja_polityki' => self::PUBLIKACJA,
            'kuking.zgody.zmiana_polityki' => ['istotna' => true, 'poprzednia' => '2026-09-10', 'obowiazuje_od' => null],
        ]);
        $osoba = $this->user('zgoda_w_przejsciu', ['wants_weekly_digest' => false]);
        $akcja = app(PrzestawZgodeNaDigest::class);

        $this->travelTo($this->dzien(self::PUBLIKACJA)->addDays(5));
        $akcja->handle($osoba, true, WpisZgody::ZRODLO_USTAWIENIA);

        $this->travelTo($this->dzien('2026-10-15')->addHour());
        $akcja->handle($osoba->fresh(), false, WpisZgody::ZRODLO_USTAWIENIA);

        $wersje = WpisZgody::query()->where('user_id', $osoba->getKey())
            ->orderBy('wystapilo_at')->pluck('wersja_polityki')->all();

        $this->assertSame(['2026-09-10', self::PUBLIKACJA], $wersje);
    }

    /** Kontrola ujemna do poprzedniego: drobna zmiana polityki — od razu nowa. */
    public function test_zgoda_przy_drobnej_zmianie_polityki_zapisuje_nowa_od_razu(): void
    {
        config([
            'kuking.zgody.wersja_polityki' => self::PUBLIKACJA,
            'kuking.zgody.zmiana_polityki' => ['istotna' => false, 'poprzednia' => '2026-09-10', 'obowiazuje_od' => null],
        ]);
        $osoba = $this->user('zgoda_drobna', ['wants_weekly_digest' => false]);

        $this->travelTo($this->dzien(self::PUBLIKACJA)->addDays(5));
        app(PrzestawZgodeNaDigest::class)->handle($osoba, true, WpisZgody::ZRODLO_USTAWIENIA);

        $this->assertSame(self::PUBLIKACJA, WpisZgody::query()->where('user_id', $osoba->getKey())->value('wersja_polityki'));
    }

    /**
     * Oznaczenie musi być jawne. „Zapomniałem” nie może znaczyć „drobna”.
     *
     * @return array<string, array{0: array<string, mixed>|null, 1: string}>
     */
    public static function zleOznaczenia(): array
    {
        return [
            'brak wpisu' => [null, 'Oznacz zmianę jawnie'],
            'brak klucza istotna' => [['poprzednia' => '2026-09-07'], 'Oznacz zmianę jawnie'],
            'napis zamiast true/false' => [['istotna' => 'tak', 'poprzednia' => '2026-09-07'], 'Oznacz zmianę jawnie'],
            'istotna bez poprzedniej' => [['istotna' => true, 'poprzednia' => null], 'potrzebuje kuking.zgody.zmiana_regulaminu.poprzednia'],
            'drobna z terminem' => [['istotna' => false, 'obowiazuje_od' => '2026-12-01'], 'Drobna poprawka wchodzi w życie w dniu publikacji'],
        ];
    }

    /** @param  array<string, mixed>|null  $zmiana */
    #[DataProvider('zleOznaczenia')]
    public function test_zmiana_bez_jawnego_oznaczenia_nie_przejdzie(?array $zmiana, string $komunikat): void
    {
        config(['kuking.zgody.zmiana_regulaminu' => $zmiana]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($komunikat);

        WersjaDokumentu::regulamin();
    }

    public function test_istotna_nie_wejdzie_szybciej_niz_po_czternastu_dniach(): void
    {
        $this->istotnaZmianaRegulaminu();
        config(['kuking.zgody.zmiana_regulaminu.obowiazuje_od' => '2026-10-14']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Zmiana istotna nie może wejść w życie szybciej');

        WersjaDokumentu::regulamin()->obowiazujeOd();
    }

    /** Konfiguracja w repozytorium: oba dokumenty mają jawne oznaczenie. */
    public function test_obecna_konfiguracja_oznacza_oba_dokumenty(): void
    {
        foreach (['zmiana_polityki', 'zmiana_regulaminu'] as $klucz) {
            $this->assertIsBool(config("kuking.zgody.{$klucz}.istotna"), "kuking.zgody.{$klucz}.istotna nie jest oznaczone.");
        }

        WersjaDokumentu::polityka()->obowiazujeOd();
        WersjaDokumentu::regulamin()->obowiazujeOd();
    }

    /** Regulamin §11 obiecuje tyle dni, ile egzekwuje konfiguracja. */
    public function test_regulamin_obiecuje_ten_sam_okres_co_konfiguracja(): void
    {
        $tresc = (string) file_get_contents(resource_path('legal/regulamin.md'));
        $dni = WersjaDokumentu::okresIstotnejZmianyDni();

        $this->assertGreaterThanOrEqual(14, $dni);
        $this->assertStringContainsString("co najmniej **{$dni} dni** przed ich wejściem w życie", $tresc);
    }

    /**
     * Polityka §9 obiecuje przy zmianie istotnej „powiadomienie w serwisie”.
     * Pasek jest dziś tylko dla regulaminu — oznaczenie polityki jako
     * istotnej bez własnego paska to obietnica bez pokrycia (#1816).
     */
    public function test_istotna_zmiana_polityki_wymaga_paska_w_serwisie(): void
    {
        $tresc = (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
        $this->assertStringContainsString('O istotnych zmianach poinformujemy z wyprzedzeniem powiadomieniem w serwisie', $tresc);

        if (WersjaDokumentu::polityka()->istotna) {
            $this->assertTrue(view()->exists('components.pasek-zmiany-polityki'),
                'Zmiana polityki jest oznaczona jako istotna, a serwis nie ma paska o zmianie polityki. Dodaj go razem z podbiciem (D-327).');
        } else {
            $this->assertFalse(WersjaDokumentu::polityka()->wOkresiePrzejsciowym());
        }
    }
}
