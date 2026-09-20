<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Contact\Actions\WyslijOdpowiedz;
use App\Domain\Security\DziennyBudzetListow;
use App\Domain\Security\WyslijPotwierdzenieAdresu;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Notifications\PotwierdzenieAdresu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * WSPÓLNY LICZNIK CAŁEJ POCZTY — kolejność wygaszania (decyzja właściciela
 * z 20 września 2026).
 *
 * PO CO TEN PLIK ISTNIEJE
 * Do 20 września każda funkcja wysyłająca wiele listów miała własny sufit
 * dobowy i widziała TYLKO swój, a listy bez sufitu — potwierdzenie
 * rejestracji i przypomnienie hasła — nie były liczone wcale. Rezerwa
 * transakcyjna (100 listów) istniała jako zdanie w komentarzu i nic jej nie
 * pilnowało.
 *
 * Zmierzone dziury tej samej rodziny:
 *
 *   1. `/nie-pamietam-hasla` — `limits.password_reset` to 5 próśb na
 *      10 minut z adresu IP, czyli 720 na dobę, KAŻDA na inny adres. Jeden
 *      sprawca opróżniał pulę 300/dobę w około 70 minut.
 *   2. „Wyślij wiadomość jeszcze raz" — 6 na minutę z konta, bez sufitu
 *      dobowego. Jedno niepotwierdzone konto wypalało pulę w około 50 minut.
 *
 * W obu przypadkach pierwszą rzeczą, która przestawała działać, było
 * POTWIERDZENIE REJESTRACJI i LOGOWANIE LINKIEM — czyli wejście dla nowych
 * ludzi. Najważniejszy test w tym pliku nazywa się dokładnie tak
 * (`test_wyczerpana_klasa_zwykla_nie_zabiera_listu_wpuszczajacego_na_konto`)
 * i sprawdza właśnie to: że zalana droga przypomnienia hasła nie zabiera ani
 * jednego listu klasie `wejscie`.
 *
 * DLACZEGO TESTY LICZĄ NA MAŁEJ PULI, A NIE NA 300
 * Bo sprawdzają MECHANIZM (kto gaśnie przed kim), a nie liczby z planu
 * taryfowego dostawcy. Liczby produkcyjne i rachunek między nimi pilnuje
 * `PodzialLimituPocztyTest` — i to jest podział celowy: test mechanizmu ma
 * nie oblewać przy przejściu na plan płatny, a test rachunku ma oblewać
 * zawsze, gdy ktoś ruszy jedną liczbę i nie zajrzy do pozostałych.
 */
class WspolnyLicznikPocztyTest extends TestCase
{
    use RefreshDatabase;

    /** Mała pula z progami w tych samych proporcjach co produkcyjne. */
    private function malaPula(int $limit = 10, int $podsumowanie = 8, int $zwykla = 4): void
    {
        config([
            'kuking.poczta.limit_dostawcy_dobowy' => $limit,
            'kuking.poczta.progi_wygaszania.podsumowanie' => $podsumowanie,
            'kuking.poczta.progi_wygaszania.zwykla' => $zwykla,
            'kuking.poczta.progi_wygaszania.wejscie' => 0,
            // Sufity własne poza drogą — w tym pliku mierzymy wspólny licznik.
            'kuking.login_link.dzienny_budzet' => 1000,
            'kuking.digest.dzienny_limit' => 1000,
        ]);
    }

    private function zajmijWspolne(int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zajmij();
        }
    }

    // -----------------------------------------------------------------
    //  Kolejność wygaszania
    // -----------------------------------------------------------------

    public function test_podsumowanie_gasnie_pierwsze(): void
    {
        $this->malaPula();
        $this->zajmijWspolne(3); // zostało 7, próg podsumowania to 8

        $this->assertFalse(
            DziennyBudzetListow::dlaPodsumowania()->jestMiejsce(),
            'Tygodniowe podsumowanie sięga po listy spod swojego progu — a ma gasnąć pierwsze.',
        );
        $this->assertTrue(DziennyBudzetListow::dlaOdzyskaniaHasla()->jestMiejsce());
        $this->assertTrue(DziennyBudzetListow::dlaPotwierdzeniaAdresu()->jestMiejsce());
    }

    public function test_klasa_zwykla_gasnie_przed_wejsciem(): void
    {
        $this->malaPula();
        $this->zajmijWspolne(6); // zostało 4, próg klasy `zwykla` to 4

        $this->assertFalse(
            DziennyBudzetListow::dlaOdzyskaniaHasla()->jestMiejsce(),
            'Przypomnienie hasła weszło w rezerwę, która ma zostać dla listów wpuszczających na konto.',
        );
        $this->assertTrue(
            DziennyBudzetListow::dlaPotwierdzeniaAdresu()->jestMiejsce(),
            'Rezerwa istnieje właśnie po to, żeby ta klasa wysyłała dalej.',
        );
    }

    public function test_wejscie_siega_po_ostatni_list_doby(): void
    {
        $this->malaPula();
        $this->zajmijWspolne(9); // zostaje JEDEN list

        $this->assertTrue(
            DziennyBudzetListow::dlaPotwierdzeniaAdresu()->sprobujZarezerwowac(),
            'Ostatni list doby należy do klasy `wejscie` — bez niego nikt tu nie wejdzie.',
        );
        $this->assertFalse(
            DziennyBudzetListow::dlaPotwierdzeniaAdresu()->sprobujZarezerwowac(),
            'Pula jest pusta, więc nawet ta klasa musi odmówić — dostawca i tak ten list odrzuci.',
        );
    }

    // -----------------------------------------------------------------
    //  Jeden list = jedno miejsce
    // -----------------------------------------------------------------

    public function test_rezerwacja_zajmuje_miejsce_w_obu_licznikach(): void
    {
        $this->malaPula(limit: 100);

        $this->assertTrue(DziennyBudzetListow::dlaLinkuLogowania()->sprobujZarezerwowac());

        $this->assertSame(1, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
        $this->assertSame(
            1,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            'List wysłany logowaniem linkiem nie policzył się we wspólnej puli.',
        );
    }

    public function test_odmowa_wlasnego_sufitu_oddaje_miejsce_we_wspolnej_puli(): void
    {
        $this->malaPula(limit: 100);
        config(['kuking.login_link.dzienny_budzet' => 0]);

        $this->assertFalse(DziennyBudzetListow::dlaLinkuLogowania()->sprobujZarezerwowac());

        $this->assertSame(
            0,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            'Wyczerpany sufit jednej funkcji zjada listy wszystkim pozostałym, nie wysławszy ani jednego.',
        );
    }

    public function test_oddane_miejsce_wraca_do_obu_licznikow(): void
    {
        $this->malaPula(limit: 100);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();
        $budzet->sprobujZarezerwowac();
        $budzet->zwolnij();

        $this->assertSame(0, DziennyBudzetListow::dlaLinkuLogowania()->zuzyte());
        $this->assertSame(
            0,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE)->zuzyte(),
            'Miejsce oddane w suficie funkcji zostało we wspólnej puli na zawsze.',
        );
    }

    // -----------------------------------------------------------------
    //  Drogi HTTP
    // -----------------------------------------------------------------

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU — sedno decyzji właściciela.
     *
     * Sprawca zalewa `/nie-pamietam-hasla` i wypala swoją klasę. List
     * potwierdzający rejestrację ma wyjść mimo to.
     */
    public function test_wyczerpana_klasa_zwykla_nie_zabiera_listu_wpuszczajacego_na_konto(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $this->malaPula();
        $this->zajmijWspolne(6); // zostało 4 — dokładnie próg klasy `zwykla`

        $basia = $this->user('basia', ['email' => 'basia@example.test']);

        $this->post(route('password.email'), ['email' => 'basia@example.test'])
            ->assertRedirect();

        $this->assertStringContainsString(
            'nie czekaj na niego',
            (string) session('status'),
            'Ekran przypomnienia hasła obiecuje list, który na pewno nie wyjdzie.',
        );

        $this->assertTrue(
            app(WyslijPotwierdzenieAdresu::class)->handle($basia),
            'Zalana droga przypomnienia hasła zabrała list, bez którego nikt tu nie wejdzie. '
            .'To jest dokładnie ta usterka, dla której wspólny licznik powstał.',
        );

        Notification::assertSentTo($basia, PotwierdzenieAdresu::class);
    }

    public function test_adres_bez_konta_nie_zjada_wspolnej_puli(): void
    {
        config(['mail.default' => 'smtp']);
        $this->malaPula(limit: 100);

        $this->post(route('password.email'), ['email' => 'nikogo-takiego@example.test'])
            ->assertRedirect();

        $this->assertSame(
            0,
            DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_ZWYKLA)->zuzyte(),
            'Automat wpisujący zmyślone adresy wyczerpuje pulę, nie wysławszy ani jednego listu.',
        );
    }

    public function test_ponowienie_potwierdzenia_mowi_prawde_gdy_pula_jest_pusta(): void
    {
        config(['mail.default' => 'smtp']);
        $this->malaPula();
        $this->zajmijWspolne(10); // pula pusta do zera

        $basia = $this->user('basia', ['email_verified_at' => null]);

        $this->actingAs($basia)->post(route('verification.send'))->assertRedirect();

        $status = (string) session('status');

        $this->assertStringNotContainsString(
            'Wysłaliśmy wiadomość jeszcze raz',
            $status,
            'Ekran twierdzi, że wysłał wiadomość, której nie wysłał — to jest ta sama usterka '
            .'co przed naprawą #234.',
        );
        $this->assertStringContainsString('nie czekaj na nią', $status);
        $this->assertStringContainsString((string) config('kuking.community.contact_email'), $status);
    }

    /**
     * ODPOWIEDŹ MODERATORA NIE PRZEPADA, GDY PULA ODMÓWI.
     *
     * Wpis przy `limits.kontakt_odpowiedz` zapowiadał, że gdyby powstał
     * wspólny licznik poczty, to ON ma być jednym miejscem tej decyzji.
     * Odpowiedź przechodzi więc przez licznik, ale treść napisana ręką
     * człowieka ZOSTAJE zapisana i panel mówi, co z nią zrobić — inaczej
     * sufit zjadałby pracę moderatora, a nie tylko list.
     */
    public function test_odpowiedz_moderatora_przy_pustej_puli_nie_przepada(): void
    {
        Mail::fake();
        $this->malaPula();
        $this->zajmijWspolne(6); // zostało 4 — dokładnie próg klasy `zwykla`

        $wiadomosc = ContactMessage::factory()->create(['contact_email' => 'basia@wp.pl']);

        $odpowiedz = app(WyslijOdpowiedz::class)->handle(
            $wiadomosc,
            $this->user('moderatorka'),
            'Przycisk poprawiliśmy dziś rano.',
        );

        Mail::assertNothingSent();

        $this->assertSame(ContactMessageReply::STATUS_NIEUDANA, $odpowiedz->status);
        $this->assertSame('Przycisk poprawiliśmy dziś rano.', $odpowiedz->body,
            'Treść napisana przez człowieka przepadła razem z listem.');
        $this->assertStringContainsString('wyślij ją jutro', (string) $odpowiedz->error);
    }

    public function test_udane_ponowienie_nadal_mowi_ze_wyslalo(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $this->malaPula(limit: 100);

        $basia = $this->user('basia', ['email_verified_at' => null]);

        $this->actingAs($basia)->post(route('verification.send'))->assertRedirect();

        $this->assertStringContainsString('Wysłaliśmy wiadomość jeszcze raz', (string) session('status'));
        Notification::assertSentTo($basia, PotwierdzenieAdresu::class);
    }
}
