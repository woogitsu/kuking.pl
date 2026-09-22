<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionObject;
use Tests\TestCase;

/**
 * Każda migracja MA własny `down()` i nie jest on pusty (AGENTS.md §6:
 * „Zmiana schematu = migracja + test + docs/DATABASE.md + rollback”).
 *
 * PO CO TO, SKORO JEST `scripts/proba-wycofania.sh`
 * Bo tamten skrypt mierzy co innego i w innym momencie. Skrypt zakłada własną
 * bazę, podnosi schemat, schodzi do zera i wraca — to jest dowód, że
 * wycofania DZIAŁAJĄ, ale kosztuje kilka minut i wymaga PostgreSQL-a pod
 * ręką, więc nikt nie uruchomi go przy każdym commicie. Ten test kosztuje
 * ułamek sekundy, nie dotyka bazy i łapie jedną konkretną pomyłkę: migrację
 * dopisaną bez `down()` albo z `down()` „na potem”. Taka migracja przechodzi
 * w skrypcie na zielono — pusty `down()` niczego nie wywraca — a obietnicę
 * z AGENTS.md łamie.
 *
 * ŚWIADOMIE PUSTY `down()` TRZEBA ZADEKLAROWAĆ, NIE TYLKO OPISAĆ
 * Jeden `down()` w tym repozytorium naprawdę nie ma nic do roboty:
 * `0001_01_01_000000_enable_postgres_extensions` celowo nie zdejmuje
 * rozszerzeń. Test nie może go po prostu przepuścić po nazwie, bo wtedy
 * pierwszy nowy pusty `down()` też trafiłby na wyjątek „przecież to jak
 * tamten”. Dlatego świadoma pustka jest DEKLAROWANA w kodzie — stałą
 * `WYCOFANIE_NIC_NIE_ROBI` z uzasadnieniem po polsku. Komentarz nie
 * wystarcza: komentarz da się dopisać odruchowo, a stała wymaga napisania
 * ZDANIA o tym, dlaczego nie ma czego cofać, i to zdanie widać w przeglądzie
 * kodu.
 *
 * DLACZEGO REFLEKSJA, A NIE `preg_match` PO PLIKU
 * Bo wyrażenie regularne szukające „function down” trafia też w komentarz,
 * w napis i w metodę klasy pomocniczej, a przy zmianie formatowania
 * przestaje trafiać w cokolwiek — i wtedy test jest zielony, nie mierząc nic
 * (`PULAPKI_TESTOW.md` §2). Refleksja pyta o METODĘ, którą naprawdę wykona
 * `migrate:rollback`, i o klasę, która ją deklaruje.
 *
 * `PULAPKI_TESTOW.md` §2 — TEST SKANUJĄCY PLIKI
 * Skan, który nie znajdzie ŻADNEGO pliku, przechodzi. Dlatego jest tu próg
 * minimalnej liczby przeskanowanych migracji. Próg jest celowo NIŻSZY niż
 * stan dzisiejszy (76): ma łapać złą ścieżkę i przeniesiony katalog, a nie
 * oblewać się przy każdej nowej migracji.
 */
class KazdaMigracjaMaWycofanieTest extends TestCase
{
    /**
     * Liczba migracji, poniżej której uznajemy, że skan czyta nie ten katalog.
     */
    private const MINIMUM_PRZESKANOWANYCH = 60;

    /**
     * Najkrótsze uzasadnienie świadomie pustego `down()`, jakie przyjmujemy.
     * „Nie trzeba” ma 9 znaków i nie jest uzasadnieniem.
     */
    private const MINIMUM_UZASADNIENIA = 60;

    #[Test]
    public function test_kazda_migracja_deklaruje_wlasna_metode_down(): void
    {
        $bez = [];
        $przeskanowane = 0;

        foreach ($this->migracje() as $sciezka => $migracja) {
            $przeskanowane++;

            $klasa = new ReflectionObject($migracja);

            // `Migration` z frameworka nie ma `down()` wcale, ale gdyby
            // kiedyś dostała, dziedziczona metoda nie jest wycofaniem TEJ
            // migracji — pytamy więc o klasę deklarującą, nie o istnienie.
            if (! $klasa->hasMethod('down')
                || $klasa->getMethod('down')->getDeclaringClass()->getName() !== $klasa->getName()) {
                $bez[] = basename($sciezka);
            }
        }

        $this->assertGreaterThanOrEqual(
            self::MINIMUM_PRZESKANOWANYCH,
            $przeskanowane,
            'Skan przeczytał '.$przeskanowane.' migracji — to za mało jak na to repozytorium. '
            .'Zła ścieżka albo przeniesiony katalog `database/migrations`?',
        );

        $this->assertSame([], $bez, "Migracje bez własnej metody `down()`:\n  ".implode("\n  ", $bez));
    }

    #[Test]
    public function test_zaden_down_nie_jest_pusty_ani_samym_komentarzem(): void
    {
        $puste = [];
        $przeskanowane = 0;

        foreach ($this->migracje() as $sciezka => $migracja) {
            $przeskanowane++;

            $klasa = new ReflectionObject($migracja);

            if (! $klasa->hasMethod('down')) {
                continue; // Pilnuje tego test wyżej — tutaj nie ma czego mierzyć.
            }

            if ($this->liczbaInstrukcji($klasa->getMethod('down')) > 0) {
                continue;
            }

            // Pusto — ale czy ŚWIADOMIE? Deklaracja musi stać w tej samej
            // klasie (odziedziczona z innej migracji nic by tu nie znaczyła)
            // i musi nieść zdanie, nie samo „true”.
            $uzasadnienie = $this->deklaracjaSwiadomejPustki($klasa);

            if ($uzasadnienie === null || mb_strlen($uzasadnienie) < self::MINIMUM_UZASADNIENIA) {
                $puste[] = basename($sciezka);
            }
        }

        $this->assertGreaterThanOrEqual(self::MINIMUM_PRZESKANOWANYCH, $przeskanowane);

        $this->assertSame(
            [],
            $puste,
            "Migracje z pustym `down()` (albo z samym komentarzem) bez deklaracji świadomej pustki:\n  "
            .implode("\n  ", $puste)
            ."\n\nCO ZROBIĆ: napisz wycofanie, a jeśli naprawdę nie ma czego cofać — zadeklaruj to "
            .'w klasie migracji stałą `WYCOFANIE_NIC_NIE_ROBI` z uzasadnieniem po polsku '
            .'(co najmniej '.self::MINIMUM_UZASADNIENIA.' znaków), tak jak robi to '
            .'`0001_01_01_000000_enable_postgres_extensions`.',
        );
    }

    /**
     * Migracje repozytorium: ścieżka → obiekt migracji.
     *
     * Ścieżkę liczymy od `base_path()`, a nie od `__DIR__` — w worktree
     * z dowiązanym `vendor` te dwie rzeczy potrafią wskazywać różne
     * katalogi (AGENTS.md §10), a mierzyć chcemy migracje TEGO repozytorium.
     *
     * @return array<string, Migration>
     */
    private function migracje(): array
    {
        $pliki = glob(base_path('database/migrations').'/*.php');

        $migracje = [];

        foreach ($pliki === false ? [] : $pliki as $plik) {
            $obiekt = require $plik;

            // Plik migracji ma ZWRACAĆ obiekt migracji. Gdyby zwrócił `1`
            // (czyli nic), refleksja niżej pytałaby o klasę `int` i test
            // oblałby się z mylącym komunikatem.
            $this->assertInstanceOf(
                Migration::class,
                $obiekt,
                'Plik '.basename($plik).' nie zwraca obiektu migracji — brak `return new class extends Migration`?',
            );

            $migracje[$plik] = $obiekt;
        }

        return $migracje;
    }

    /**
     * Ile INSTRUKCJI stoi w ciele metody — komentarze, białe znaki i same
     * klamry się nie liczą.
     *
     * Liczymy tokenami PHP, a nie linijkami tekstu, bo tylko tokenizator
     * odróżnia `// Schema::drop(...)` (komentarz) od wywołania, a `/* … *\/`
     * rozciągnięte na pół ciała metody od kodu.
     */
    private function liczbaInstrukcji(ReflectionMethod $metoda): int
    {
        $plik = $metoda->getFileName();
        $od = $metoda->getStartLine();
        $do = $metoda->getEndLine();

        if ($plik === false || $od === false || $do === false) {
            return 0;
        }

        $linie = file($plik);

        if ($linie === false) {
            return 0;
        }

        $cialo = implode('', array_slice($linie, $od - 1, $do - $od + 1));

        $tokeny = token_get_all('<?php '.$cialo);

        // Sygnatura (`public function down(): void`) jest częścią wyciętego
        // tekstu, więc liczymy dopiero OD PIERWSZEJ KLAMRY — inaczej każdy
        // `down()` wyglądałby na niepusty, także ten całkiem pusty.
        $wCiele = false;
        $istotne = 0;

        foreach ($tokeny as $token) {
            if (! $wCiele) {
                $wCiele = $token === '{';

                continue;
            }

            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $istotne++;

                continue;
            }

            // Same klamry i średniki nie są instrukcjami: `{ ; }` to nadal
            // pusty `down()`.
            if (in_array($token, ['{', '}', ';'], true)) {
                continue;
            }

            $istotne++;
        }

        return $istotne;
    }

    /**
     * Uzasadnienie świadomie pustego `down()` zadeklarowane w TEJ klasie,
     * albo `null`, gdy klasa niczego nie deklaruje.
     *
     * @param  ReflectionObject<object>  $klasa
     */
    private function deklaracjaSwiadomejPustki(ReflectionObject $klasa): ?string
    {
        $stala = $klasa->getReflectionConstant('WYCOFANIE_NIC_NIE_ROBI');

        if ($stala === false || $stala->getDeclaringClass()->getName() !== $klasa->getName()) {
            return null;
        }

        $wartosc = $stala->getValue();

        return is_string($wartosc) ? $wartosc : null;
    }
}
