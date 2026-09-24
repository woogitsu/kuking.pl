<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportAutoInjectedAssets\SupportAutoInjectedAssets;
use Tests\TestCase;

/**
 * STRAŻNIK: STAN LIVEWIRE'A NIE MA PRZECIEKAĆ Z TESTU NA TEST.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO TU JEST PILNOWANE
 * ═══════════════════════════════════════════════════════════════════════
 *
 * `SupportAutoInjectedAssets` trzyma statyki KLASOWE — `$hasRenderedAComponentThisRequest`
 * i `$forceAssetInjection` — a statyka klasowa żyje tyle, co proces PHP.
 * Kontener aplikacji jest w testach budowany od nowa dla każdego testu, ale
 * klasy nie dotyka nikt. Livewire zeruje te flagi WYŁĄCZNIE na zdarzeniu
 * `flush-state`, którego zwykłe żądanie HTTP w teście nie wyzwala.
 *
 * `Tests\TestCase::setUp()` woła z tego powodu `Livewire::flushState()`.
 * Ten plik pada, gdy ktoś to wywołanie usunie — także przy aktualizacji
 * Livewire'a, gdyby zmienił się mechanizm zerowania.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DLACZEGO DWIE METODY, A NIE JEDNA
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Przeciek jest z definicji MIĘDZY testami — w jednej metodzie nie da się go
 * zobaczyć. PHPUnit puszcza metody w kolejności deklaracji i w JEDNYM
 * procesie (paratest dzieli pracę po klasach, więc ta para zostaje razem
 * także przy `--parallel`). Pierwsza metoda truje, druga sprawdza.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  KONTROLA DODATNIA WBUDOWANA W PIERWSZĄ METODĘ
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Asercja „na `/kontakt` nie ma `livewire.js`" byłaby bezwartościowa, gdyby
 * Livewire przestał doklejać skrypt z innego powodu (zmiana wersji, zmiana
 * `config('livewire.inject_assets')`, inny nagłówek odpowiedzi). Dlatego
 * metoda pierwsza NAJPIERW pokazuje, że przy podniesionej fladze skrypt
 * NAPRAWDĘ się dokleja. Jeżeli ta asercja przestanie przechodzić, strażnik
 * pada głośno zamiast zzielenieć na pustym miejscu.
 */
class StanLivewireNiePrzeciekaMiedzyTestamiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * KROK 1 — TRUCICIEL.
     *
     * Podnosi dokładnie tę flagę, którą podnosi `SupportAutoInjectedAssets::dehydrate()`
     * przy każdym wyrenderowanym komponencie Livewire'a, i pokazuje skutek:
     * następna zwykła odpowiedź HTML dostaje doklejony skrypt Livewire'a.
     *
     * Flaga jest ustawiana wprost, a nie przez wejście na stronę z kreatorem
     * (`/przepisy/{slug}/szczegoly`), bo strażnik ma pilnować MECHANIZMU,
     * a nie tego, która akurat strona używa dziś Livewire'a. Gdyby kreator
     * zniknął, ten plik dalej ma sens.
     */
    public function test_krok1_podniesiona_flaga_naprawde_dokleja_skrypt_do_zwyklej_strony(): void
    {
        $this->assertFalse(
            SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest,
            'Test zaczyna się z JUŻ podniesioną flagą — czyli `Tests\TestCase::setUp()` '
            .'przestał zerować stan Livewire\'a jeszcze zanim ten plik cokolwiek zrobił.',
        );

        SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest = true;

        $html = (string) $this->get(route('kontakt'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'livewire.js',
            $html,
            'KONTROLA DODATNIA PADŁA: przy podniesionej fladze `/kontakt` NIE dostaje '
            .'skryptu Livewire\'a. Skoro tak, to asercja w kroku 2 niczego już nie mierzy '
            .'— sprawdź, czy Livewire nie zmienił mechanizmu doklejania assetów '
            .'(`SupportAutoInjectedAssets::shouldInjectLivewireAssets()`) i napisz ten '
            .'strażnik od nowa, zamiast go kasować.',
        );
    }

    /**
     * KROK 2 — OFIARA.
     *
     * Ten test NIE robi nic z Livewire'em. Ma zastać czysty stan i czystą
     * stronę. Jeżeli pada, znaczy że flaga przeżyła koniec poprzedniego
     * testu — czyli wróciła usterka, przez którą
     * `NapiszDoNasTest::test_formularz_dziala_bez_javascriptu` bywał czerwony
     * zależnie od tego, co PHPUnit puścił wcześniej w tym samym procesie.
     */
    public function test_krok2_nastepny_test_zastaje_czysty_stan_i_strone_bez_skryptu(): void
    {
        $this->assertFalse(
            SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest,
            'Flaga `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest` przeżyła '
            .'poprzedni test. `Tests\TestCase::setUp()` ma wołać `Livewire::flushState()` '
            .'— bez tego każdy test puszczony po teście renderującym komponent Livewire\'a '
            .'dostaje doklejony skrypt do zwykłych stron HTML.',
        );

        $this->assertFalse(
            SupportAutoInjectedAssets::$forceAssetInjection,
            'Flaga `SupportAutoInjectedAssets::$forceAssetInjection` przeżyła poprzedni test '
            .'— ten sam mechanizm, to samo lekarstwo.',
        );

        $html = (string) $this->get(route('kontakt'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'livewire.js',
            $html,
            'Do `/kontakt` doklejony został skrypt Livewire\'a, choć ten test nie renderował '
            .'żadnego komponentu. To jest dokładnie ta czerwień, którą łapie '
            .'`NapiszDoNasTest::test_formularz_dziala_bez_javascriptu` — tyle że tam wychodzi '
            .'przypadkiem, zależnie od kolejności testów, a tutaj zawsze.',
        );
    }
}
