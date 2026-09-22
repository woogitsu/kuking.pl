<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\LiczbaKukingow;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Licznik społeczności w stopce (issue #38): „{n} kuKINGów".
 *
 * Sprawdza pięć rzeczy osobno, bo to pięć różnych sposobów, w jakie ten
 * licznik mógłby się zepsuć po cichu: KOGO liczy `przelicz()`, JAK się
 * odmienia, KIEDY jest w ogóle widoczny, że `liczba()` NIGDY nie dotyka
 * bazy (stopka jest na każdej stronie — patrz komentarz klasy o tym,
 * dlaczego to nie jest zwykłe `Cache::remember()`), i że stopka naprawdę
 * to renderuje.
 */
class LiczbaKukingowTest extends TestCase
{
    use RefreshDatabase;

    private function licznik(): LiczbaKukingow
    {
        return app(LiczbaKukingow::class);
    }

    private function wyczyscCache(): void
    {
        Cache::forget('community:liczba-kukingow');
    }

    // ---------------------------------------------------------------
    // Kogo liczymy
    // ---------------------------------------------------------------

    public function test_przelicz_liczy_tylko_realne_otwarte_konta(): void
    {
        // Trzy realne, aktywne konta — MAJĄ się policzyć.
        $this->user('realna1');
        $this->user('realna2');
        $this->user('realna3');

        // Konto zalążkowe (persona z pliku, D-025) — NIE MA się policzyć.
        $zalazek = $this->user('zalazek');
        $zalazek->forceFill(['is_seeded' => true])->save();

        // Zbanowane i w karencji — już nie są częścią serwisu.
        $this->user('zbanowana', ['status' => User::STATUS_BANNED]);
        $this->user('karencja', ['status' => User::STATUS_PENDING_DELETE]);
        // `erased` ma w bazie CHECK równoważności ze `data_erased_at`
        // (`users_data_erased_at_check`) — jedyna dopuszczalna droga tam
        // to metoda domenowa, nie `forceFill` samego statusu.
        $this->user('wymazana')->markDataErased();

        // Zawieszone konto WCIĄŻ jest członkiem społeczności (stan
        // tymczasowy, nie zamknięcie) — MA się policzyć.
        $this->user('zawieszona', ['status' => User::STATUS_SUSPENDED]);

        // Gospodarz — wykluczony z tego samego powodu co z WAC
        // (`CookEligibility`, patrz komentarz klasy): to konto istnieje,
        // żeby generować treść, nie żeby być dowodem, że dołączają obcy.
        $nazwaGospodarza = (string) config('kuking.community.host_username');
        $gospodarz = $this->user('gospodarz_test_'.uniqid());
        Profile::query()->where('user_id', $gospodarz->getKey())
            ->update(['username' => $nazwaGospodarza]);

        $this->assertSame(
            4,
            $this->licznik()->przelicz(),
            'Powinny się policzyć: 3 realne konta + 1 zawieszone. '.
            'Zalążkowe, zbanowane, w karencji, wymazane i gospodarz — nie.',
        );
    }

    // ---------------------------------------------------------------
    // Odmiana — wszystkie trzy formy plus zero
    // ---------------------------------------------------------------

    /** @return list<array{0: int, 1: string}> */
    public static function liczbyISufiksy(): array
    {
        return [
            // Zero bierze formę „wiele" („0 kuKINGów") — dokładnie tak samo
            // jak zero traktuje `Odmiana::rzeczownik()` wszędzie indziej
            // (`tests/Unit/OdmianaTest.php`: `[0, 'przepisów']`). Ten
            // przypadek i tak nigdy nie trafia na ekran — poniżej progu
            // widoczności (20) licznik jest ukryty (patrz testy niżej) —
            // ale sama funkcja ma dać poprawną formę, gdyby ktoś kiedyś
            // użył jej gdzie indziej bez progu.
            [0, 'ów'],
            [1, ''],
            [2, 'ów'],
            [3, 'ów'],
            [4, 'ów'],
            [5, 'ów'],
            [11, 'ów'],
            [20, 'ów'],
            [21, 'ów'],
            // Nie testujemy tu wielkich liczb (np. 2 431 z przykładu
            // w COPY_STYLE.md) — wymagałoby to tysięcy realnych wierszy
            // w bazie na jeden przypadek testowy. Sama odmiana dla dużych
            // liczb (w tym „nastek") jest już wyczerpująco sprawdzona
            // w `tests/Unit/OdmianaTest.php`; tu sprawdzamy tylko, że
            // `LiczbaKukingow` PRAWIDŁOWO WOŁA `Odmiana::rzeczownik()`
            // z argumentami kuKING/kuKINGów/kuKINGów.
            [50, 'ów'],
        ];
    }

    #[DataProvider('liczbyISufiksy')]
    public function test_odmiana_dla_kazdej_liczby(int $ile, string $oczekiwanySufiks): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        for ($i = 0; $i < $ile; $i++) {
            $this->user('kuking'.$i);
        }

        $licznik = $this->licznik();
        $policzone = $licznik->przelicz();

        $this->assertSame($ile, $policzone, 'Asercja kontrolna: w bazie musi być dokładnie tyle kont.');
        $this->assertSame(
            $oczekiwanySufiks,
            $licznik->sufiks(),
            "Zła forma dla {$ile}: kuKING{$licznik->sufiks()} zamiast kuKING{$oczekiwanySufiks}.",
        );

        // Nigdy „kuKINGi" (mianownik osobowy) — D-013/COPY_STYLE.md §2
        // rezerwują tę formę wyłącznie dla RZECZY („kuKINGi na dziś").
        $this->assertNotSame('i', $licznik->sufiks());
    }

    public function test_jeden_kuking_nie_dostaje_koncowki_dopelniacza(): void
    {
        User::query()->delete();
        $this->wyczyscCache();
        $this->user('jedynakuking');

        $licznik = $this->licznik();
        $licznik->przelicz();

        $this->assertSame('', $licznik->sufiks());
        $this->assertSame('1', $licznik->liczbaSformatowana());
    }

    // ---------------------------------------------------------------
    // Próg widoczności — „zero na starcie" nie może pokazać „0 kuKINGów"
    // ---------------------------------------------------------------

    public function test_licznik_jest_ukryty_ponizej_progu(): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        // Zero użytkowników — stan serwisu dziś. `przelicz()` nawet nie
        // trzeba wołać: cache pusty ma dawać to samo, bezpieczne „ukryty".
        $this->assertFalse($this->licznik()->widoczna(), 'Przy pustym cache (jeszcze nic nie przeliczone) licznik nie może się pokazać.');

        for ($i = 0; $i < 19; $i++) {
            $this->user('malo'.$i);
        }

        $licznik = $this->licznik();
        $this->assertSame(19, $licznik->przelicz());
        $this->assertFalse($licznik->widoczna(), 'Przy 19 kontach wciąż poniżej progu (20).');
    }

    public function test_licznik_pokazuje_sie_od_progu(): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        for ($i = 0; $i < 20; $i++) {
            $this->user('duzo'.$i);
        }

        $licznik = $this->licznik();
        $this->assertSame(20, $licznik->przelicz());
        $this->assertTrue($licznik->widoczna(), 'Przy dokładnie 20 kontach licznik ma być widoczny (Bramka A).');
    }

    // ---------------------------------------------------------------
    // Komenda i harmonogram
    // ---------------------------------------------------------------

    public function test_komenda_przelicza_i_wypisuje_wynik(): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        for ($i = 0; $i < 20; $i++) {
            $this->user('komenda'.$i);
        }

        $this->artisan('kuking:policz-kukingow')
            ->assertSuccessful()
            ->expectsOutputToContain('20');

        // Komenda ma zostawić wynik w cache, gotowy do czytania przez stopkę
        // bez ANI JEDNEGO zapytania — dokładnie to sprawdza kolejny test.
        $this->assertSame(20, $this->licznik()->liczba());
    }

    // ---------------------------------------------------------------
    // Wydajność — stopka jest na KAŻDEJ stronie
    // ---------------------------------------------------------------

    public function test_liczba_nigdy_nie_odpytuje_bazy(): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        for ($i = 0; $i < 25; $i++) {
            $this->user('cache'.$i);
        }

        // Przeliczenie samo w sobie ZAPYTUJE bazę — to normalne, robi to
        // komenda, poza ścieżką żądania. Liczymy zapytania dopiero PO nim.
        $this->licznik()->przelicz();

        $zapytania = 0;
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if (str_contains($zapytanie->sql, 'from "users"')) {
                $zapytania++;
            }
        });

        $licznik = $this->licznik();
        $licznik->liczba();
        $licznik->liczba();
        $licznik->widoczna();
        $licznik->liczbaSformatowana();

        // To jest właściwa asercja tej klasy, mocniejsza niż „cache
        // działa": widok NIGDY nie zapytuje `users` — tylko czyta cache.
        // Pierwszy szkic tej klasy cache'ował NA ŻĄDANIE (`Cache::remember`)
        // i to psuło `MiniaturyBezWachlarzaZapytanTest` (mierzy dwie odsłony
        // tej samej strony w jednym teście — pierwsza trafiała na zimny
        // cache, druga na ciepły, więc test widział fałszywy spadek liczby
        // zapytań). Zero zapytań, zawsze, usuwa ten problem u źródła.
        $this->assertSame(
            0,
            $zapytania,
            "Odczyt licznika wykonał {$zapytania} zapytań do users — powinien czytać wyłącznie cache.",
        );
    }

    // ---------------------------------------------------------------
    // Stopka — integracja
    // ---------------------------------------------------------------

    public function test_stopka_pokazuje_licznik_gdy_jest_dosc_osob(): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        for ($i = 0; $i < 25; $i++) {
            $this->user('widoczna'.$i);
        }

        $this->licznik()->przelicz();

        $odpowiedz = $this->get(route('about'));

        $odpowiedz->assertOk();
        // `assertSeeText`, nie `assertSee`: znacznik dostępności rozbija
        // „kuKINGów" na „ku" + `<strong>KING</strong>` + „ów" w źródle —
        // `assertSeeText` czyta to tak, jak przeczytałby to człowiek.
        $odpowiedz->assertSeeText('25');
        $odpowiedz->assertSeeText('kuKINGów');
    }

    public function test_stopka_nie_pokazuje_licznika_gdy_za_malo_osob(): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        $this->user('samotna1');
        $this->user('samotna2');
        $this->licznik()->przelicz();

        $odpowiedz = $this->get(route('about'));

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('site-footer-liczba', false);
    }

    public function test_stopka_nie_pokazuje_licznika_zanim_cokolwiek_przeliczono(): void
    {
        User::query()->delete();
        $this->wyczyscCache();

        // Dwadzieścia realnych kont w bazie — WYSTARCZY na próg — ale
        // komenda `kuking:policz-kukingow` jeszcze nigdy nie odpaliła
        // (świeże środowisko). Stopka MUSI zostać ukryta, nie policzyć
        // sama: to jest cały sens tej architektury.
        for ($i = 0; $i < 25; $i++) {
            $this->user('nieprzeliczona'.$i);
        }

        $odpowiedz = $this->get(route('about'));

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('site-footer-liczba', false);
    }
}
