<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as TrasaFrameworku;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Panel moderacji: KAŻDA trasa `admin/*` bez identyfikatora, KAŻDĄ metodą,
 * sprawdzona ŻĄDANIEM jako zwykłe konto i jako gość (audyt B7-17, etap 1;
 * B7-25).
 *
 * Audyt B7-17 zwrócił uwagę, że regułę „UUID w adresie to nie autoryzacja”
 * pilnuje po części skan tekstu (`authorize(` w ciele metody —
 * `AutoryzacjaTrasZWiazaniemModeluTest`). Trasy Z identyfikatorem mają już
 * macierz behawioralną pięciu ról (`KazdaTrasaZIdentyfikatoremPodPolicyTest`).
 * Poza nią zostawała cała rodzina tras panelu BEZ identyfikatora: zapisy
 * „Kuking na dziś”, kolażu powitalnego, tagu tygodnia, tagów promowanych
 * i zbiorczego odrzucenia sygnałów automatu były sprawdzane wyłącznie jako
 * moderator, a przegląd trybu panelu (`TrybPaneluWMenuTest`) chodzi tylko
 * po GET-ach. Zdjęcie `moderator` z grupy tras jednego z tych zapisów nie
 * zapalało niczego.
 *
 * Tu lista tras pochodzi z ROUTERA, nie z tabeli przepisanej ręcznie:
 * nowy ekran albo nowy zapis panelu wchodzi do pomiaru sam, bez niczyjej
 * pamięci. Trasy z parametrem zostają w macierzy pięciu ról — tu adres
 * z wymyślonym identyfikatorem dałby 404 z wiązania modelu, nie z bramki,
 * i test mierzyłby nieistniejący wiersz zamiast autoryzacji.
 *
 * Trzy warstwy w jednym przebiegu:
 *  1. zwykłe konto → 404 (panel celowo nie potwierdza, że istnieje),
 *  2. gość → przekierowanie na logowanie,
 *  3. baza po wszystkich żądaniach 1–2 jest bajt w bajt taka sama
 *     (odcisk każdej tabeli) — kod odmowy bez tej kontroli przepuściłby
 *     zapis wykonany PRZED odmową;
 *  i KONTROLA DODATNIA: ta sama trasa tą samą metodą u administratora nie
 *  daje 403/404/405 ani 5xx — więc 404 zwykłego konta pochodzi z bramki,
 *  a nie z tego, że trasa albo metoda nie istnieje.
 */
class PanelNieWpuszczaZwyklegoKontaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kolumny, które KAŻDE zalogowane żądanie ma prawo zmienić — wyjęte
     * z odcisku bazy. Każda z powodem; lista ma zostać krótka.
     *
     * @var array<string, list<string>>
     */
    private const ZAPIS_KAZDEGO_ZADANIA = [
        // Znacznik ostatniej wizyty (#1044) — pisany przy każdym zalogowanym
        // żądaniu, także odrzuconym. To obecność osoby w serwisie, nie zapis
        // panelu.
        'users' => ['ostatnio_widziany_at'],
    ];

    public function test_kazda_trasa_panelu_bez_identyfikatora_odmawia_zwyklemu_kontu_i_gosciowi(): void
    {
        // Limity zapytań mierzą osobne testy; tu dwadzieścia kilka żądań
        // z jednego konta nie może zamienić 404 w 429.
        $this->withoutMiddleware(ThrottleRequests::class);

        // Funkcje panelu za flagą odpowiadają 404 WSZYSTKIM, gdy flaga jest
        // wyłączona — wtedy 404 zwykłego konta nie mówiłoby nic o bramce.
        // Włączamy je, a kontrola dodatnia niżej pilnuje, że żadna nie została.
        config(['kuking.tag_tygodnia.wlaczony' => true]);

        $zwykly = $this->user('zwyklaosoba');
        $admin = $this->admin();

        $pary = $this->trasyPanelu();
        $zapisy = array_filter($pary, fn (array $para): bool => $para[0] !== 'GET');

        // Pułapka 2 (`docs/PULAPKI_TESTOW.md`): zero tras wygląda jak komplet.
        // Stan z 25.09.2026: 17 par metoda+adres, w tym 7 zapisów.
        $this->assertGreaterThanOrEqual(17, count($pary), 'Router oddał za mało tras panelu — czytam złe trasy? Par: '.count($pary));
        $this->assertGreaterThanOrEqual(7, count($zapisy), 'Pomiar nie widzi zapisów panelu (PUT/POST/DELETE). Zapisów: '.count($zapisy));

        $odciskPrzed = $this->odciskBazy();
        $przepuszczone = [];

        foreach ($pary as [$metoda, $uri]) {
            $this->app['auth']->forgetGuards();
            $kod = $this->actingAs($zwykly)->call($metoda, '/'.$uri)->getStatusCode();

            if ($kod !== 404) {
                $przepuszczone[] = "zwykłe konto: {$metoda} /{$uri} → {$kod}";
            }

            $this->app['auth']->forgetGuards();
            $gosc = $this->call($metoda, '/'.$uri);

            if (! $gosc->isRedirect(route('login'))) {
                $przepuszczone[] = "gość: {$metoda} /{$uri} → {$gosc->getStatusCode()}";
            }
        }

        $this->assertSame([], $przepuszczone,
            "Trasa panelu nie odmówiła osobie bez roli moderatora.\n"
            ."Oczekiwane: zwykłe konto 404 (EnsureUserIsModerator), gość przekierowanie na logowanie.\n"
            .implode("\n", $przepuszczone));

        $this->assertSame($odciskPrzed, $this->odciskBazy(),
            'Odmowa przyszła, ale baza się zmieniła — któraś trasa panelu zapisała coś, zanim odmówiła.');

        // KONTROLA DODATNIA — po porównaniu odcisku, bo te żądania mogą pisać.
        $zamkniete = [];

        foreach ($pary as [$metoda, $uri]) {
            $this->app['auth']->forgetGuards();
            $kod = $this->actingAs($admin)->call($metoda, '/'.$uri)->getStatusCode();

            if (in_array($kod, [403, 404, 405], true) || $kod >= 500) {
                $zamkniete[] = "{$metoda} /{$uri} → {$kod}";
            }
        }

        $this->assertSame([], $zamkniete,
            "Administrator dostał odmowę albo błąd na trasie panelu — wtedy 404 zwykłego konta wyżej nie dowodzi bramki.\n"
            .implode("\n", $zamkniete));
    }

    /**
     * Pary [metoda HTTP, adres] dla każdej trasy `admin` / `admin/*` bez
     * parametru. HEAD pomijamy — to GET bez ciała, obsłużony tą samą trasą.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function trasyPanelu(): array
    {
        $pary = [];

        /** @var TrasaFrameworku $trasa */
        foreach (Route::getRoutes()->getRoutes() as $trasa) {
            $uri = $trasa->uri();

            if (($uri !== 'admin' && ! str_starts_with($uri, 'admin/')) || str_contains($uri, '{')) {
                continue;
            }

            foreach ($trasa->methods() as $metoda) {
                if ($metoda !== 'HEAD') {
                    $pary[] = [$metoda, $uri];
                }
            }
        }

        return $pary;
    }

    /**
     * Odcisk treści każdej tabeli schematu: md5 z posortowanych wierszy.
     * Łapie wstawienie, zmianę i usunięcie — także UPDATE, którego nie
     * widać w liczbie wierszy (np. podmiana „Kuking na dziś”).
     *
     * @return array<string, string>
     */
    private function odciskBazy(): array
    {
        $tabele = DB::table('pg_tables')
            ->where('schemaname', DB::raw('current_schema()'))
            ->orderBy('tablename')
            ->pluck('tablename');

        $odcisk = [];

        foreach ($tabele as $tabela) {
            // `to_jsonb(t) - text[]` zdejmuje z wiersza kolumny z listy
            // ZAPIS_KAZDEGO_ZADANIA; przy pustej liście wiersz zostaje cały.
            $wiersz = '(to_jsonb(t) - ?::text[])::text';
            $pominiete = '{'.implode(',', self::ZAPIS_KAZDEGO_ZADANIA[$tabela] ?? []).'}';

            $odcisk[$tabela] = (string) DB::selectOne(
                "SELECT md5(coalesce(string_agg({$wiersz}, '|' ORDER BY {$wiersz}), '')) AS h FROM \"{$tabela}\" t",
                [$pominiete, $pominiete],
            )->h;
        }

        $this->assertArrayHasKey('users', $odcisk, 'Odcisk bazy nie widzi tabeli users — czytam zły schemat.');

        return $odcisk;
    }
}
