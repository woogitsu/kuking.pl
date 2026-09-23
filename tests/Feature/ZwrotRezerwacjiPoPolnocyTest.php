<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ZWROT REZERWACJI TRAFIA DO DOBY, W KTÓREJ MIEJSCE ZAJĘTO (issue #1061).
 *
 * PO CO TEN PLIK ISTNIEJE
 * `DziennyBudzetListow::zwolnij()` liczył klucz licznika z `now()` w chwili
 * ZWROTU, a nie w chwili rezerwacji. Rezerwacja zrobiona o 23:59:59 i oddana
 * po północy zdejmowała więc miejsce z NOWEJ doby — tej, w której ktoś inny
 * zdążył już zająć miejsce i wysłać list. Skutek: licznik nowej doby pokazuje
 * o jeden list mniej, niż naprawdę wyszło, i sufit przepuszcza jeden list
 * ponad limit, a stara doba zostaje zawyżona na zawsze.
 *
 * Warunek „licznik nie schodzi pod zero" chronił tylko PUSTY licznik nowej
 * doby. Ten plik sprawdza przypadek, którego tamten warunek nie widzi:
 * licznik nowej doby jest DODATNI, bo ktoś już w niej wysłał.
 *
 * DLACZEGO DWIE INSTANCJE, A NIE JEDNA
 * Bo tak wygląda produkcja: operacja A (żądanie, które zarezerwowało i nic
 * nie wysłało) i operacja B (żądanie z nowej doby) to dwa różne obiekty.
 * Test z jedną instancją przeszedłby także przy błędnym kodzie, gdyby zwrot
 * przypadkiem zdejmował „ostatnią rezerwację tego obiektu" z bieżącej doby.
 */
class ZwrotRezerwacjiPoPolnocyTest extends TestCase
{
    use RefreshDatabase;

    private const PRZED_POLNOCA = '2026-09-22 23:59:59';

    private const PO_POLNOCY = '2026-09-23 00:00:05';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.login_link.dzienny_budzet' => 100,
            'kuking.poczta.limit_dostawcy_dobowy' => 300,
            'kuking.poczta.progi_wygaszania.podsumowanie' => 240,
            'kuking.poczta.progi_wygaszania.zwykla' => 100,
            'kuking.poczta.progi_wygaszania.wejscie' => 0,
        ]);
    }

    /** Scenariusz wprost z issue #1061, na suficie własnym funkcji. */
    public function test_zwrot_starej_rezerwacji_nie_zdejmuje_listu_nowej_doby(): void
    {
        $this->travelTo(Carbon::parse(self::PRZED_POLNOCA));
        $a = DziennyBudzetListow::dlaLinkuLogowania();
        $this->assertTrue($a->sprobujZarezerwowac());

        $this->travelTo(Carbon::parse(self::PO_POLNOCY));
        $b = DziennyBudzetListow::dlaLinkuLogowania();
        $this->assertTrue($b->sprobujZarezerwowac());

        // A stwierdza, że nic nie wysłał (adres bez konta) i oddaje miejsce.
        $a->zwolnij();

        $this->assertSame(
            1,
            DziennyBudzetListow::dlaLinkuLogowania()->zuzyte(),
            'Zwrot rezerwacji z poprzedniej doby zdjął miejsce listu, który NAPRAWDĘ wyszedł dziś. '
            .'Licznik pokazuje mniej, niż wysłano, i sufit przepuści list ponad limit (#1061).',
        );

        $this->travelTo(Carbon::parse(self::PRZED_POLNOCA));
        $this->assertSame(
            0,
            DziennyBudzetListow::dlaLinkuLogowania()->zuzyte(),
            'Miejsce oddane przez A zostało policzone w dobie, w której je zajęto — na zawsze.',
        );
    }

    /**
     * To samo we WSPÓLNEJ puli całej poczty — zwrot idzie z dołu do góry
     * i u rodzica też musi trafić w dobę rezerwacji.
     */
    public function test_zwrot_po_polnocy_trafia_do_wlasciwej_doby_takze_we_wspolnej_puli(): void
    {
        $this->travelTo(Carbon::parse(self::PRZED_POLNOCA));
        $a = DziennyBudzetListow::dlaLinkuLogowania();
        $this->assertTrue($a->sprobujZarezerwowac());

        $this->travelTo(Carbon::parse(self::PO_POLNOCY));
        $this->assertTrue(DziennyBudzetListow::dlaPotwierdzeniaAdresu()->sprobujZarezerwowac());

        $a->zwolnij();

        $this->assertSame(
            1,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            'Zwrot z poprzedniej doby zdjął ze wspólnej puli list potwierdzenia, który wyszedł dziś.',
        );

        $this->travelTo(Carbon::parse(self::PRZED_POLNOCA));
        $this->assertSame(0, DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte());
        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
    }

    /** Zwrot w tej samej dobie nadal działa, a licznik nie schodzi pod zero. */
    public function test_zwrot_w_tej_samej_dobie_dziala_i_nie_schodzi_pod_zero(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        $this->assertTrue($budzet->sprobujZarezerwowac());
        $budzet->zwolnij();
        $budzet->zwolnij();

        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
        $this->assertSame(0, DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte());
    }

    /**
     * ODMOWA NIE DAJE PRAWA DO ZWROTU CUDZEGO MIEJSCA.
     *
     * Obiekt, któremu odmówiono, nic nie zajął — jego `zwolnij()` nie może
     * zdjąć miejsca zajętego przez kogoś innego.
     */
    public function test_odmowa_rezerwacji_nie_daje_prawa_do_zwrotu_cudzego_miejsca(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 1]);

        $this->assertTrue(DziennyBudzetListow::dlaLinkuLogowania()->sprobujZarezerwowac());

        $odrzucony = DziennyBudzetListow::dlaLinkuLogowania();
        $this->assertFalse($odrzucony->sprobujZarezerwowac());
        $odrzucony->zwolnij();

        $this->assertSame(
            1,
            DziennyBudzetListow::dlaLinkuLogowania()->zuzyte(),
            'Obiekt, któremu odmówiono rezerwacji, oddał miejsce zajęte przez kogoś innego.',
        );
        $this->assertSame(1, DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte());
    }

    /**
     * Komenda robi wiele rezerwacji jednym obiektem — zwrot oddaje ostatnią
     * z nich, w jej własnej dobie, a wcześniejsze zostają policzone.
     */
    public function test_wiele_rezerwacji_jednego_obiektu_przez_polnoc(): void
    {
        $this->travelTo(Carbon::parse(self::PRZED_POLNOCA));
        $komenda = DziennyBudzetListow::dlaLinkuLogowania();
        $this->assertTrue($komenda->sprobujZarezerwowac());

        $this->travelTo(Carbon::parse(self::PO_POLNOCY));
        $this->assertTrue($komenda->sprobujZarezerwowac());
        $komenda->zwolnij();

        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());

        $this->travelTo(Carbon::parse(self::PRZED_POLNOCA));
        $this->assertSame(
            1,
            DziennyBudzetListow::dlaLinkuLogowania()->zuzyte(),
            'Zwrot drugiej rezerwacji zdjął pierwszą, której list wyszedł poprzedniej doby.',
        );
    }

    /**
     * LIST ŚWIADOMIE PONAD SUFITEM (`zajmij()` wprost, `--tylko`) LICZY SIĘ
     * TAKŻE WE WSPÓLNEJ PULI.
     *
     * Komentarz w `kuking:wyslij-podsumowania` mówi, że list próbny „musi się
     * POLICZYĆ, żeby nie zniknął z rachunku wiadra". Po dołożeniu wspólnego
     * licznika `zajmij()` podnosiło wyłącznie sufit własny funkcji — list
     * wychodził, a wspólna pula o nim nie wiedziała.
     */
    public function test_zajecie_ponad_sufitem_liczy_sie_we_wspolnej_puli(): void
    {
        DziennyBudzetListow::dlaPodsumowania()->zajmij();

        $this->assertSame(1, DziennyBudzetListow::dlaPodsumowania()->zuzyte());
        $this->assertSame(
            1,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_PODSUMOWANIE)->zuzyte(),
            'List próbny wyszedł, a wspólna pula całej poczty go nie policzyła.',
        );
    }
}
