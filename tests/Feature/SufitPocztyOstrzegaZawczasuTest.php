<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\DziennyBudzetListow;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use Tests\TestCase;

/**
 * Dobowy sufit listów OSTRZEGA, ZANIM SIĘ SKOŃCZY (issue #234, D-062 §6).
 *
 * PO CO TO JEST
 * Sufit z D-057 chroni cudzy kawałek wiadra 300 listów, ale w dniu, w którym
 * się kończy, po prostu zamyka drzwi: logowanie linkiem przestaje wysyłać
 * listy i dla człowieka po drugiej stronie jest to nie do odróżnienia od
 * awarii. Issue #234 prosi wprost, żeby przejście na płatny plan dało się
 * zrobić DZIEŃ WCZEŚNIEJ, nie w dniu awarii — a jedynym sposobem, żeby ktoś
 * o tym wiedział dzień wcześniej, jest sygnał przy przekroczeniu progu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DWIE RZECZY, KTÓRE TEN PLIK ZMIERZYŁ PO SCALENIU Z D-076
 * ────────────────────────────────────────────────────────────────────────
 *
 * 1. OSTRZEŻENIE NIE WOŁA SIĘ Z `zajmij()`, TYLKO Z `sprobujZarezerwowac()`,
 *    PO ODDANIU BLOKADY. Pierwsza wersja tej poprawki (gałąź sprzed D-076)
 *    wołała je z `zajmij()` — wtedy ta metoda jeszcze nie chodziła pod
 *    blokadą. Dziś chodzi, jako drugi krok atomowej rezerwacji, więc wpis
 *    do dziennika i żądanie na webhook wykonywałyby się W SEKCJI KRYTYCZNEJ
 *    dobowego sufitu. Przy `CZEKANIE_SEKUND` = 2 i limicie 3 s na webhook
 *    jedno ostrzeżenie „zaraz zabraknie listów" ODMAWIAŁOBY WYSYŁKI
 *    wszystkim żądaniom czekającym w tym czasie na blokadę. Pilnuje tego
 *    `test_ostrzezenie_pada_dopiero_po_oddaniu_blokady_sufitu`.
 *
 * 2. SAM POZIOM `error` NIKOGO NIE BUDZI. Poprzednia wersja tego pliku
 *    twierdziła, że `error` „wychodzi na webhook błędów (D-041) i do
 *    Sentry". Sprawdzone w kodzie: kanał `blad_webhook` NIE jest częścią
 *    stosu domyślnego (`LOG_STACK=single`), a Sentry'ego nie ma
 *    w `composer.json` wcale. Zwykłe `Log::error()` zostawało więc w pliku
 *    na serwerze i nikt się o niczym nie dowiadywał — czyli ostrzeżenie było
 *    dokładnie tym cichym sygnałem, który issue #234 tępi. Dlatego wpis idzie
 *    teraz JAWNIE także na kanał `blad_webhook`, a dwa testy niżej pilnują
 *    i tego, że idzie, i tego, że nie niesie niczego poza liczbami.
 */
class SufitPocztyOstrzegaZawczasuTest extends TestCase
{
    private const ADRES_WEBHOOKA = 'https://discord.example.test/api/webhooks/sufit/slack';

    /** @var list<MessageLogged> */
    private array $wpisy = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpisy = [];

        Log::listen(function (MessageLogged $wpis): void {
            $this->wpisy[] = $wpis;
        });

        config([
            'kuking.login_link.dzienny_budzet' => 10,
            'kuking.poczta.prog_ostrzezenia_procent' => 80,
        ]);
    }

    public function test_ostrzezenie_pada_po_przekroczeniu_progu_a_nie_wczesniej(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 7; $list++) {
            $this->assertTrue($budzet->sprobujZarezerwowac());
        }

        $this->assertFalse(
            $this->ostrzegano(),
            'Siedem listów z dziesięciu to 70% — ostrzegać przed progiem znaczy uczyć ignorowania alarmu.',
        );

        $this->assertTrue($budzet->sprobujZarezerwowac()); // ósmy, czyli 80%

        $this->assertTrue(
            $this->ostrzegano(),
            'Przy 80% sufitu właściciel ma się dowiedzieć, że pula się kończy — ZANIM listy zaczną odbijać.',
        );
    }

    /**
     * ODMOWA REZERWACJI NIE OSTRZEGA — bo nie zajęła miejsca.
     *
     * To jest osobna gałąź warunku (`if ($zajete)`), więc ma własny test
     * i własny sabotaż (`docs/PULAPKI_TESTOW.md` §3b: dwa testy trafiające
     * w tę samą gałąź znaczą, że drugi nie sprawdza niczego).
     *
     * SCENARIUSZ JEST DOBRANY TAK, ŻEBY TA GAŁĄŹ BYŁA W OGÓLE WIDOCZNA,
     * i to nie było oczywiste. Pierwsza wersja tego testu ustawiała sufit na
     * zero — i przechodziła także po usunięciu `if ($zajete)`, bo przy sufcie
     * zero `ostrzezZawczasu()` wychodzi wcześniej, na `$budzet < 1`. Sabotaż
     * po prostu nie dochodził do sprawdzanego miejsca.
     *
     * Dlatego licznik dobijamy tu do sufitu przez `zajmij()`, czyli drogą
     * listu próbnego `--tylko` — ta metoda świadomie NIE ostrzega (D-076,
     * list ponad sufitem). Klucz „już dziś ostrzegałem" zostaje więc wolny,
     * a odmowa rezerwacji jest jedyną rzeczą, która mogłaby go zająć. Bez
     * `if ($zajete)` to zdanie by padło — i byłoby myląco uprzejme: pula nie
     * „prawie się kończy", ona się już skończyła, a to jest inna czynność
     * człowieka.
     */
    public function test_odmowa_rezerwacji_nie_ostrzega_bo_nic_nie_zajela(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 10; $list++) {
            $budzet->zajmij();
        }

        $this->assertFalse($this->ostrzegano(), 'Droga `--tylko` (`zajmij()`) ostrzegać nie ma — patrz jej opis.');
        $this->assertFalse($budzet->sprobujZarezerwowac(), 'Wyczerpany sufit ma odmawiać.');
        $this->assertFalse($this->ostrzegano());
    }

    /** Sufit zerowy („ta funkcja nie wysyła dziś nic") to konfiguracja, nie awaria — nie ma o czym ostrzegać. */
    public function test_sufit_zerowy_nie_ostrzega(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 0]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        $this->assertFalse($budzet->sprobujZarezerwowac(), 'Sufit zerowy ma odmawiać.');
        $this->assertFalse($this->ostrzegano());
    }

    /**
     * SEDNO SPOTKANIA #253 Z D-076: gdy pada ostrzeżenie, blokada dobowego
     * sufitu jest już ODDANA.
     *
     * Sprawdzamy to w chwili, która jest tu jedyną prawdziwą: wewnątrz
     * słuchacza dziennika, czyli w trakcie ostrzegania. Gdyby ostrzeżenie
     * wołało się spod blokady (tak było przed scaleniem, bo stało
     * w `zajmij()`), zdobycie tej samej blokady zwróciłoby `false` — a na
     * produkcji znaczyłoby to, że sygnał o kończącej się puli sam wstrzymuje
     * wysyłkę listów na czas zapisu do dziennika i żądania na webhook.
     */
    public function test_ostrzezenie_pada_dopiero_po_oddaniu_blokady_sufitu(): void
    {
        $klucz = $this->kluczBlokady('link-logowania');

        $blokadaByla = null;

        Log::listen(function (MessageLogged $wpis) use ($klucz, &$blokadaByla): void {
            if (! str_contains($wpis->message, 'dobowy sufit listów jest prawie wyczerpany')) {
                return;
            }

            $blokada = Cache::lock($klucz, 5);
            $blokadaByla = $blokada->get();

            if ($blokadaByla === true) {
                $blokada->release();
            }
        });

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 8; $list++) {
            $this->assertTrue($budzet->sprobujZarezerwowac());
        }

        $this->assertTrue($this->ostrzegano(), 'Bez ostrzeżenia ten test nie zmierzyłby niczego.');
        $this->assertTrue(
            $blokadaByla,
            'Ostrzeżenie padło POD blokadą dobowego sufitu. Zapis do dziennika i żądanie na webhook '
            .'trwają dłużej niż cała rezerwacja, a `DziennyBudzetListow::CZEKANIE_SEKUND` to 2 sekundy — '
            .'czyli sygnał „zaraz zabraknie listów" sam zabierałby listy równoległym żądaniom.',
        );
    }

    /**
     * Ostrzeżenie leci RAZ NA DOBĘ NA FUNKCJĘ. Przy sufitcie 120 listów
     * powtarzanie go przy każdym kolejnym liście dałoby dwadzieścia cztery
     * identyczne wpisy — a alarm, który się powtarza, uczy się ignorować.
     */
    public function test_ostrzezenie_nie_powtarza_sie_przy_kazdym_liscie(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 10; $list++) {
            $budzet->sprobujZarezerwowac();
        }

        $this->assertSame(1, $this->ileOstrzezen());
    }

    /** Ostrzeżenie nie niesie ani adresu, ani nazwy konta — sam wpis idzie dalej (AGENTS.md §7). */
    public function test_ostrzezenie_niesie_wylacznie_liczby(): void
    {
        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 9; $list++) {
            $budzet->sprobujZarezerwowac();
        }

        $wpis = $this->pierwszeOstrzezenie();

        $this->assertNotNull($wpis);
        $this->assertSame('link-logowania', $wpis->context['funkcja'] ?? null);
        // Ostrzeżenie pada przy ÓSMYM liście (80% z dziesięciu) i jest jedno
        // na dobę, więc liczby opisują tę chwilę, a nie koniec pętli.
        $this->assertSame(8, $wpis->context['zuzyte'] ?? null);
        $this->assertSame(10, $wpis->context['budzet'] ?? null);
        $this->assertSame(2, $wpis->context['zostalo'] ?? null);
        $this->assertSame(
            'error',
            $wpis->level,
            'Poziom `error` jest progiem kanału `blad_webhook` (`config/logging.php`) — niżej wpis '
            .'nie przeszedłby przez handler, nawet wołany na ten kanał wprost.',
        );
    }

    /**
     * OSTRZEŻENIE NAPRAWDĘ DZWONI, gdy właściciel ustawił adres webhooka.
     *
     * Bez tego testu „poziom `error`" był całą obroną — a sprawdzenie
     * w kodzie pokazało, że `Log::error()` nie wychodzi na ten kanał wcale
     * (`LOG_STACK=single`, Sentry'ego nie ma w `composer.json`). Asercja
     * jest DODATNIA: na webhook poszło żądanie i jest w nim to jedno zdanie.
     */
    public function test_ostrzezenie_dzwoni_na_webhook_bledow_gdy_adres_jest_ustawiony(): void
    {
        config(['logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA]);
        Log::forgetChannel('blad_webhook');

        Http::fake([self::ADRES_WEBHOOKA => Http::response('', 204)]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 8; $list++) {
            $budzet->sprobujZarezerwowac();
        }

        Http::assertSent(function (Request $zadanie): bool {
            if ($zadanie->url() !== self::ADRES_WEBHOOKA) {
                return false;
            }

            $tekst = (string) ($zadanie->data()['text'] ?? '');

            return str_contains($tekst, 'sufit „link-logowania"')
                && str_contains($tekst, '80%')
                && str_contains($tekst, '8 z 10');
        });
    }

    /**
     * Bez `LOG_BLAD_WEBHOOK_URL` kanał jest martwy i NIE WOŁA się w ogóle —
     * ten sam warunek co w `bootstrap/app.php`. Kontrolą dodatnią do tej
     * asercji ujemnej jest test wyżej (`…dzwoni_na_webhook…`): dopiero para
     * „z adresem poszło, bez adresu nie poszło" dowodzi, że mechanizm
     * pracował (`docs/PULAPKI_TESTOW.md` §4).
     */
    public function test_bez_adresu_webhooka_ostrzezenie_nie_wychodzi_z_serwera(): void
    {
        config(['logging.channels.blad_webhook.url' => null]);
        Log::forgetChannel('blad_webhook');

        Http::fake();

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 8; $list++) {
            $budzet->sprobujZarezerwowac();
        }

        $this->assertTrue($this->ostrzegano(), 'Wpis w dzienniku serwera ma zostać niezależnie od webhooka.');
        Http::assertNothingSent();
    }

    /** Wyłącznik: próg 100 albo więcej znaczy „nie ostrzegaj" — sufit i tak zatrzyma wysyłkę. */
    public function test_prog_setny_wylacza_ostrzeganie(): void
    {
        config(['kuking.poczta.prog_ostrzezenia_procent' => 100]);

        $budzet = DziennyBudzetListow::dlaLinkuLogowania();

        for ($list = 1; $list <= 10; $list++) {
            $budzet->sprobujZarezerwowac();
        }

        $this->assertFalse($this->ostrzegano());
    }

    /**
     * Klucz blokady dobowego sufitu — czytany z klasy przez refleksję, a nie
     * wpisany tu z ręki. Gdyby ktoś zmienił prefiks, test ma OBLAĆ na
     * asercji niżej, a nie po cichu sprawdzać nieistniejący klucz (czyli
     * zawsze zdobywać wolną blokadę i zawsze przechodzić).
     */
    private function kluczBlokady(string $funkcja): string
    {
        $prefiks = (new ReflectionClass(DziennyBudzetListow::class))->getConstant('PREFIKS_BLOKADY');

        $this->assertIsString($prefiks, 'Zniknął `PREFIKS_BLOKADY` — ten test przestał mierzyć blokadę.');

        return $prefiks.$funkcja;
    }

    private function ostrzegano(): bool
    {
        return $this->ileOstrzezen() > 0;
    }

    private function ileOstrzezen(): int
    {
        return count(array_filter(
            $this->wpisy,
            static fn (MessageLogged $wpis): bool => str_contains($wpis->message, 'dobowy sufit listów jest prawie wyczerpany'),
        ));
    }

    private function pierwszeOstrzezenie(): ?MessageLogged
    {
        foreach ($this->wpisy as $wpis) {
            if (str_contains($wpis->message, 'dobowy sufit listów jest prawie wyczerpany')) {
                return $wpis;
            }
        }

        return null;
    }
}
