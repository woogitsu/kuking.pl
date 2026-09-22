<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\RejestrPotwierdzenRodo;
use App\Models\PotwierdzenieZadaniaRodo;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * REJESTR POTWIERDZEŃ RODO NIE MA — I NIE DOSTANIE — EKRANU.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO TEN PLIK PILNUJE I DLACZEGO POWSTAŁ RAZEM Z DECYZJĄ WŁAŚCICIELA
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Projekt (`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §3.2) zakładał, że
 * `konto_id` znika z chwilą wykonania żądania, i pilnował tego CHECK w bazie.
 * **Decyzja właściciela z 21.09.2026 ten CHECK zdjęła**: wskaźnik zostaje,
 * żeby dało się odpowiedzieć regulatorowi o konkretną osobę bez pytania jej
 * o numer sprawy.
 *
 * Cena tej decyzji jest nazwana w §3.3 punkcie 7: rejestr UMIE teraz
 * odpowiedzieć na pytanie „czy ta osoba usunęła konto", jeżeli ktoś ma dostęp
 * do bazy. A przy zakresie `minimum` treści tej osoby zostają pod tym samym
 * `user_id` (D-022), więc wskaźnik prowadzi od potwierdzenia wprost do
 * zachowanego dorobku kogoś, kto prosił o usunięcie.
 *
 * Decyzja brzmi „MA SIĘ DAĆ ODPOWIEDZIEĆ REGULATOROWI", a nie „ma być
 * wyszukiwarka". Różnica jest cała w tym, ilu ludzi i jak łatwo może zadać to
 * pytanie. Odczyt ręczny przy sprawie od regulatora zostawia ślad, ma powód
 * i ma autora; ekran w panelu nie ma żadnej z tych trzech rzeczy. Dlatego:
 *
 *  1. żadna trasa HTTP nie prowadzi do kodu, który zna ten rejestr;
 *  2. warstwa HTTP i widoki nie znają ani modelu, ani nazwy tabeli;
 *  3. klasa pisząca do rejestru nie ma publicznej metody, która zwracałaby
 *     wiersze — wyszukanie po koncie jest tam prywatne i służy wyłącznie
 *     domknięciu sprawy, którą sami otworzyliśmy.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  KONTROLE DODATNIE (`PULAPKI_TESTOW.md` §2)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Skan, który nie znajduje żadnego pliku, przechodzi — a skan, który szuka
 * nazwy z literówką, przechodzi zawsze. Każdy skan niżej ma więc dwie
 * kontrole: liczbę faktycznie przeczytanych plików (czy skan w ogóle czyta)
 * oraz przepuszczenie przez TEN SAM wykrywacz tekstu, który naruszeniem
 * jest (czy wykrywacz w ogóle wykrywa).
 */
class RejestrPotwierdzenRodoNieMaEkranuTest extends TestCase
{
    /**
     * Czego szukamy. Sama nazwa tabeli to za mało — zapytanie da się napisać
     * modelem, relacją albo gołym `DB::table()`.
     *
     * @var list<string>
     */
    private const SLADY = [
        'potwierdzenia_zadan_rodo',
        'PotwierdzenieZadaniaRodo',
        'RejestrPotwierdzenRodo',
    ];

    /**
     * Jedyne miejsca w warstwie HTTP, którym wolno WOŁAĆ rejestr — i tylko
     * po to, żeby OTWORZYĆ sprawę przy zgłoszeniu żądania. Żadne z nich nie
     * ma prawa niczego z rejestru odczytać.
     *
     * Lista jest wymieniona z nazwy, a nie zgadywana wzorcem: dopisanie do
     * niej czegokolwiek ma być świadomą zmianą w recenzji kodu, a nie
     * skutkiem ubocznym nazwania pliku „jakoś podobnie".
     *
     * @var array<string, string>
     */
    private const WOLNO_PISAC = [
        'Settings/DataSettingsController.php' => 'otwiera sprawę przy zgłoszeniu żądania z /ustawienia/twoje-dane',
    ];

    #[Test]
    public function test_zadna_trasa_nie_prowadzi_do_kodu_znajacego_rejestr(): void
    {
        $winne = [];
        $sprawdzone = 0;

        foreach (Route::getRoutes() as $trasa) {
            $plik = $this->plikKontrolera($trasa->getActionName());

            if ($plik === null) {
                continue;
            }

            $sprawdzone++;

            if (! $this->zawieraSlad((string) file_get_contents($plik)) || $this->wolnoPisac($plik)) {
                continue;
            }

            $winne[] = $trasa->methods()[0].' '.$trasa->uri().' → '.$trasa->getActionName();
        }

        // KONTROLA DODATNIA: skan, który nie otworzył ani jednego kontrolera,
        // „udowodniłby" brak dowolnego kodu w serwisie.
        $this->assertGreaterThan(
            50,
            $sprawdzone,
            'Skan tras otworzył tylko '.$sprawdzone.' kontrolerów — nie mierzy tego, co obiecuje.',
        );

        $this->assertSame([], $winne, $this->komunikat(
            "Do rejestru potwierdzeń RODO prowadzi trasa HTTP:\n  ".implode("\n  ", $winne),
        ));
    }

    #[Test]
    public function test_warstwa_http_i_widoki_nie_znaja_rejestru(): void
    {
        $winne = [];
        $sprawdzone = 0;

        foreach ($this->plikiWarstwyWidocznej() as $plik) {
            $sprawdzone++;

            if (! $this->zawieraSlad($plik->getContents()) || $this->wolnoPisac($plik->getPathname())) {
                continue;
            }

            $winne[] = $plik->getRelativePathname();
        }

        // KONTROLA DODATNIA numer jeden: skan naprawdę czyta pliki.
        $this->assertGreaterThan(
            200,
            $sprawdzone,
            'Skan warstwy widocznej przeczytał tylko '.$sprawdzone.' plików — zła ścieżka albo zły filtr.',
        );

        // KONTROLA DODATNIA numer dwa: TEN SAM wykrywacz na tekście, który
        // naruszeniem JEST. Bez niej test przechodziłby także z literówką
        // w nazwie modelu.
        $this->assertTrue(
            $this->zawieraSlad("PotwierdzenieZadaniaRodo::query()->where('konto_id', \$user->id)->get();"),
            'Wykrywacz nie rozpoznaje odczytu rejestru po koncie — cały skan wyżej nic nie znaczy.',
        );
        $this->assertFalse(
            $this->zawieraSlad("return view('pages.settings.data', ['exports' => \$exports]);"),
            'Wykrywacz zgłasza naruszenie na kodzie, który rejestru nie dotyka.',
        );

        $this->assertSame([], $winne, $this->komunikat(
            "Rejestr potwierdzeń RODO pojawił się w warstwie HTTP albo w widoku:\n  ".implode("\n  ", $winne),
        ));
    }

    #[Test]
    public function test_klasa_piszaca_nie_udostepnia_zadnego_odczytu(): void
    {
        $publiczne = array_map(
            static fn (ReflectionMethod $metoda): string => $metoda->getName(),
            (new ReflectionClass(RejestrPotwierdzenRodo::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($publiczne);

        // Zamknięta lista, nie „czy nie ma metody o nazwie zawierającej
        // znajdz". Nazwa metody jest dowolna, więc wzorzec po nazwie
        // przepuściłby `poKoncie()` przy pierwszej próbie.
        $this->assertSame(
            ['domknijJakoCofniete', 'domknijJakoWykonane', 'przyjmijZadanieUsunieciaKonta'],
            $publiczne,
            $this->komunikat(
                'Rejestr potwierdzeń RODO dostał nową publiczną metodę. Trzy dotychczasowe PISZĄ; '
                .'każda czwarta jest kandydatem na odczyt po `konto_id`.',
            ),
        );
    }

    #[Test]
    public function test_model_nie_ma_wiazania_trasy_ani_polityki(): void
    {
        // `UUID w adresie to nie autoryzacja` (AGENTS.md §7) idzie tu o krok
        // dalej: ten model nie ma mieć adresu w ogóle. Wiązanie modelu
        // z trasy jest pierwszym krokiem do „wpisz numer, zobacz sprawę",
        // przed którym ostrzega `App\Support\NumerZadaniaRodo`.
        $wiazane = [];

        foreach (Route::getRoutes() as $trasa) {
            foreach ($trasa->signatureParameters() as $parametr) {
                $typ = $parametr->getType();

                if ($typ instanceof \ReflectionNamedType && $typ->getName() === PotwierdzenieZadaniaRodo::class) {
                    $wiazane[] = $trasa->uri();
                }
            }
        }

        $this->assertSame([], $wiazane, $this->komunikat(
            'Potwierdzenie RODO jest wiązane z adresu: '.implode(', ', $wiazane),
        ));
    }

    // ──────────────────────────── POMOCNIKI ──────────────────────────────

    private function zawieraSlad(string $tresc): bool
    {
        foreach (self::SLADY as $slad) {
            if (str_contains($tresc, $slad)) {
                return true;
            }
        }

        return false;
    }

    private function wolnoPisac(string $sciezka): bool
    {
        $znormalizowana = str_replace('\\', '/', $sciezka);

        foreach (array_keys(self::WOLNO_PISAC) as $dozwolony) {
            if (str_ends_with($znormalizowana, $dozwolony)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Plik klasy kontrolera obsługującego trasę — `null` dla domknięć
     * i tras bez kontrolera.
     */
    private function plikKontrolera(string $akcja): ?string
    {
        if ($akcja === 'Closure' || ! str_contains($akcja, '@')) {
            return null;
        }

        $klasa = explode('@', $akcja)[0];

        if (! class_exists($klasa)) {
            return null;
        }

        $plik = (new ReflectionClass($klasa))->getFileName();

        return $plik === false ? null : $plik;
    }

    /**
     * Wszystko, co człowiek może zobaczyć albo wywołać żądaniem: kontrolery,
     * middleware i widoki Blade.
     *
     * @return iterable<SplFileInfo>
     */
    private function plikiWarstwyWidocznej(): iterable
    {
        return Finder::create()
            ->files()
            ->in([app_path('Http'), resource_path('views')])
            ->name(['*.php', '*.blade.php']);
    }

    private function komunikat(string $co): string
    {
        return $co."\n\n"
            .'Decyzja właściciela z 21.09.2026 zostawiła `konto_id` w potwierdzeniu po wykonaniu '
            .'żądania, ale brzmiała „ma się dać odpowiedzieć regulatorowi", a NIE „ma być '
            .'wyszukiwarka". Rejestr, do którego da się zajrzeć ekranem, odpowiada na pytanie '
            .'„czy ta osoba usunęła konto" każdemu, kto ma dostęp do panelu — a przy zakresie '
            .'`minimum` prowadzi stamtąd wprost do treści, które po tej osobie zostały '
            .'(`docs/decyzje/PROJEKT_POTWIERDZENIA_RODO.md` §3.3 punkt 7). Odczyt tego rejestru '
            .'jest ODCZYTEM RĘCZNYM przy sprawie od regulatora — tryb opisuje `docs/DATABASE.md`. '
            .'Jeśli naprawdę potrzeba tu ekranu, to jest decyzja właściciela, nie zmiana w kodzie.';
    }
}
