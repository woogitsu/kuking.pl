<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\ListaKont;
use App\Http\Requests\Admin\ListaKontRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lista kont w panelu moderacji po wyjęciu z kontrolera (issue #970):
 * wejście z adresu w `ListaKontRequest`, zapytania w `ListaKont`.
 *
 * Zachowanie ekranu pilnuje `PanelUzytkownicyTest` — przez prawdziwe żądania.
 * Tu są dwie rzeczy, których tamten plik nie mówi wprost:
 *
 *  1. kontrakt wejścia bez uruchamiania całego ekranu — każda wartość
 *     z adresu ma zdefiniowany wynik, także ta podrobiona;
 *  2. że przeniesienie do Form Requestu NIE WPROWADZIŁO odsyłania z błędem
 *     walidacji. Lista kont otwiera się przy każdym adresie; gdyby ktoś
 *     dopisał do `rules()` choćby `'od' => 'date'`, stary odnośnik z literówką
 *     zamieniłby się w przekierowanie — i to łapie pierwszy test.
 */
class ListaKontRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_bledne_parametry_nie_odsylaja_z_bledem_walidacji(): void
    {
        $this->user('halinka970', ['display_name' => 'Halina Kowalska']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', [
                'od' => 'jutro',
                'do' => '2026-13-45x',
                'status' => 'usuniete-na-zawsze',
                'sortuj' => 'users.password',
                'kierunek' => 'bokiem',
                'bez_wpisow' => 'tak',
            ]))
            ->assertOk()
            ->assertSessionHasNoErrors()
            // Każdy błędny parametr spadł do wartości domyślnej, więc lista
            // jest pełna — a nie pusta albo zawężona po cichu.
            ->assertSee('Halina Kowalska');
    }

    public function test_filtry_z_adresu_maja_zdefiniowany_wynik(): void
    {
        config(['kuking.strefa' => 'Europe/Warsaw']);

        $filtry = $this->zadanie([
            'szukaj' => '   Żaneta Kowalska   ',
            'status' => User::STATUS_SUSPENDED,
            'od' => '2026-09-01',
            'do' => '2026-09-09',
            'bez_wpisow' => '1',
        ])->filtry();

        $this->assertSame(User::STATUS_SUSPENDED, $filtry['status']);
        // Surowa fraza wraca do pola bez spacji z brzegów, znormalizowana
        // (bez polskich znaków, małymi literami) idzie do zapytania.
        $this->assertSame('Żaneta Kowalska', $filtry['szukaj']);
        $this->assertSame('zaneta kowalska', $filtry['fraza']);
        $this->assertTrue($filtry['bez_wpisow']);

        // Początek POLSKIEGO dnia zapisany w UTC (wrzesień = CEST, +2 h).
        $this->assertInstanceOf(CarbonImmutable::class, $filtry['od']);
        $this->assertSame('2026-08-31 22:00:00 UTC', $filtry['od']->format('Y-m-d H:i:s T'));
        // Górna granica obejmuje cały wskazany dzień: porównanie idzie do
        // początku dnia następnego.
        $this->assertInstanceOf(CarbonImmutable::class, $filtry['do']);
        $this->assertSame('2026-09-09 22:00:00 UTC', $filtry['do']->format('Y-m-d H:i:s T'));
    }

    public function test_podrobione_filtry_spadaja_do_wartosci_domyslnych(): void
    {
        $filtry = $this->zadanie([
            'szukaj' => str_repeat('a', 200),
            'status' => 'admin',
            'od' => 'jutro',
            'do' => '',
            'bez_wpisow' => 'tak',
        ])->filtry();

        $this->assertSame('wszystkie', $filtry['status']);
        $this->assertSame(ListaKontRequest::NAJDLUZSZA_FRAZA, mb_strlen($filtry['szukaj']));
        $this->assertNull($filtry['od']);
        $this->assertNull($filtry['do']);
        // Tylko dokładnie „1" włącza filtr — to wartość z pola wyboru.
        $this->assertFalse($filtry['bez_wpisow']);

        $puste = $this->zadanie([])->filtry();

        $this->assertSame('', $puste['szukaj']);
        $this->assertSame('', $puste['fraza']);
    }

    public function test_sortowanie_wylacznie_z_bialej_listy(): void
    {
        $this->assertSame(['wpisy', 'asc'], $this->zadanie(['sortuj' => 'wpisy', 'kierunek' => 'asc'])->sortowanie());
        $this->assertSame(['aktywnosc', 'desc'], $this->zadanie(['sortuj' => 'aktywnosc'])->sortowanie());

        // Domyślnie data rejestracji malejąco — nigdy ranking po wpisach
        // (AGENTS.md §12).
        $this->assertSame([ListaKont::SORTOWANIE_DOMYSLNE, 'desc'], $this->zadanie([])->sortowanie());
        $this->assertSame('rejestracja', ListaKont::SORTOWANIE_DOMYSLNE);

        // Nazwa kolumny wprost z adresu nie przechodzi, nawet prawdziwa.
        $this->assertSame(
            ['rejestracja', 'desc'],
            $this->zadanie(['sortuj' => 'users.created_at', 'kierunek' => 'ASC; drop table users'])->sortowanie(),
        );
    }

    /**
     * `ListaKont` nie zna żądania HTTP — i dalej się broni, gdy ktoś zawoła
     * ją z pominięciem `ListaKontRequest` (klauzula `ORDER BY` jest sklejana
     * z tekstu, więc nie może polegać na tym, że wejście przyszło właściwymi
     * drzwiami).
     */
    public function test_lista_kont_dziala_bez_zadania_i_nie_ufa_sortowaniu(): void
    {
        $this->user('halinka970', ['display_name' => 'Halina Kowalska']);
        $zawieszony = $this->user('zenek970', ['display_name' => 'Zenon Nowak']);
        $zawieszony->suspend();

        $lista = app(ListaKont::class);

        $wszystkie = $lista->strona($this->bezFiltrow(), 'users.email); drop table users --', 'bokiem');
        $this->assertSame(2, $wszystkie->total());

        $zawieszone = $lista->strona(['status' => User::STATUS_SUSPENDED] + $this->bezFiltrow(), 'rejestracja', 'desc');
        $this->assertSame(
            [$zawieszony->getKey()],
            array_map(fn (User $konto): string => (string) $konto->getKey(), $zawieszone->items()),
        );

        // „%" to znak wieloznaczny `LIKE` — po ucieczce nie oddaje nikogo.
        $procent = $lista->strona(['szukaj' => '%', 'fraza' => '%'] + $this->bezFiltrow(), 'rejestracja', 'desc');
        $this->assertSame(0, $procent->total());

        $liczniki = $lista->liczniki();
        $this->assertSame(2, $liczniki['wszystkie']);
        $this->assertSame(1, $liczniki[User::STATUS_SUSPENDED]);
    }

    /**
     * @param  array<string, string>  $parametry
     */
    private function zadanie(array $parametry): ListaKontRequest
    {
        return ListaKontRequest::create('/admin/uzytkownicy', 'GET', $parametry);
    }

    /**
     * @return array{status: string, od: ?CarbonImmutable, do: ?CarbonImmutable, bez_wpisow: bool, szukaj: string, fraza: string}
     */
    private function bezFiltrow(): array
    {
        return [
            'status' => 'wszystkie',
            'od' => null,
            'do' => null,
            'bez_wpisow' => false,
            'szukaj' => '',
            'fraza' => '',
        ];
    }
}
