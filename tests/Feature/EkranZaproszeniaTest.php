<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RegistrationInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ekran zaproszenia do założenia konta — to, co widać po kliknięciu w link
 * z wiadomości (D-085).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PLIK PILNUJE
 * ────────────────────────────────────────────────────────────────────────
 *
 *  1. GET NICZEGO NIE ZUŻYWA. Skanery odnośników w programach pocztowych
 *     i w bramkach antywirusowych otwierają każdy adres z wiadomości, ZANIM
 *     zrobi to człowiek — metodą GET. Gdyby GET cokolwiek zużywał, właściciel
 *     skrzynki dostawałby „ten link już nie działa" przy pierwszym własnym
 *     kliknięciu. Ta sama lekcja co przy logowaniu linkiem (D-056).
 *  2. TOKEN NIEZNANY, WYGASŁY I ZUŻYTY WYGLĄDAJĄ IDENTYCZNIE. Różnica choćby
 *     w kodzie odpowiedzi dałaby zgadywaniu tokenów wyrocznię „ten istniał".
 *  3. DROGA DZIAŁA BEZ JAVASCRIPTU — to zwykłe formularze POST.
 *  4. ODMOWA ZAWSZE MÓWI, CO ZROBIĆ (docs/UX_50_PLUS.md). Osoba, która
 *     właśnie nie zdążyła założyć konta, jest dokładnie tą, której nie wolno
 *     zostawić przed ślepą ścianą.
 */
class EkranZaproszeniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.login_link.zaproszenia.wlaczone' => true,
            'kuking.account.registration_open' => true,
        ]);
    }

    // ------------------------------------------------------------------
    //  Krok pierwszy: ekran, który niczego nie zmienia
    // ------------------------------------------------------------------

    public function test_wejscie_z_linku_pokazuje_pelny_adres_i_niczego_nie_zuzywa(): void
    {
        [$token] = $this->zaproszenie('basia@example.com');

        // Dwa wejścia pod rząd — tak jak zrobi to skaner poczty, a potem
        // człowiek. Oba mają wyglądać tak samo i oba mają zostawić wiersz.
        foreach ([1, 2] as $ktore) {
            $odpowiedz = $this->get(route('zaproszenie.pokaz', ['token' => $token]))
                ->assertOk();

            // ADRES W CAŁOŚCI, nie w skrócie: patrzy na to właściciel
            // skrzynki, na swój własny adres, i tylko pełny zapis pozwala
            // zobaczyć w nim cudzą literówkę.
            $odpowiedz->assertSee('basia@example.com');

            // „Załóż konto" MIERZONE W TREŚCI — z tego samego powodu, dla
            // którego niżej stoi `tresc()`: układ ma własny przycisk o tej
            // samej nazwie w belce, a strona ma ten napis również w `<title>`.
            // Zmierzone: po skasowaniu nagłówka i przycisku tego ekranu
            // asercja na całej odpowiedzi dalej przechodziła.
            $this->assertStringContainsString('Załóż konto', $this->tresc($odpowiedz));

            $this->assertDatabaseCount('registration_invites', 1);
        }

        // Po dwóch wejściach zaproszenie nadal działa.
        $this->przyjmij($token)->assertRedirect(route('register'));
    }

    /**
     * WAŻNE FUNKCJE DZIAŁAJĄ BEZ JAVASCRIPTU (AGENTS.md).
     *
     * Wycinamy KONKRETNY formularz, a nie szukamy słów w całym HTML —
     * asercja na całej stronie łapie to samo słowo skądinąd (układ strony,
     * stopka, menu). Sprawdzamy: formularz jest metodą POST, ma token CSRF,
     * niesie token zaproszenia w ukrytym polu i kończy się zwykłym
     * `<button type="submit">`, a nie czymś, co ożywa dopiero skryptem.
     */
    public function test_ekran_dziala_bez_javascriptu(): void
    {
        [$token] = $this->zaproszenie('basia@example.com');

        $html = $this->get(route('zaproszenie.pokaz', ['token' => $token]))
            ->assertOk()
            ->getContent();

        $formularz = $this->formularzZAkcja((string) $html, route('zaproszenie.przyjmij'));

        $this->assertStringContainsString('method="POST"', $formularz);
        $this->assertStringContainsString('name="_token"', $formularz);
        $this->assertStringContainsString('name="token"', $formularz);
        $this->assertStringContainsString('type="submit"', $formularz);

        // Ani jednego skryptu W FORMULARZU i żadnego `onclick` — przycisk
        // działa sam z siebie.
        $this->assertStringNotContainsString('<script', $formularz);
        $this->assertStringNotContainsString('onclick', $formularz);
    }

    // ------------------------------------------------------------------
    //  Token, który nie działa — i to, że wszystkie takie wyglądają tak samo
    // ------------------------------------------------------------------

    /**
     * TOKEN NIEZNANY, WYGASŁY I O ZŁYM KSZTAŁCIE DAJĄ TĘ SAMĄ ODPOWIEDŹ.
     *
     * Porównujemy kod HTTP i CAŁĄ treść strony. Gdyby różniły się choćby
     * nagłówkiem, zgadywanie tokenów dostałoby wyrocznię „ten istniał,
     * tamten nie" — a człowiekowi i tak nie zmienia to niczego, bo w każdym
     * z tych przypadków ma zrobić dokładnie to samo.
     */
    public function test_nieznany_wygasly_i_znieksztalcony_token_daja_ten_sam_ekran(): void
    {
        [$wygasly] = $this->zaproszenie('basia@example.com', wygasle: true);

        $nieznany = $this->get(route('zaproszenie.pokaz', ['token' => RegistrationInvite::nowyToken()]));
        $poTerminie = $this->get(route('zaproszenie.pokaz', ['token' => $wygasly]));
        $znieksztalcony = $this->get(route('zaproszenie.pokaz', ['token' => 'za-krotki']));

        // TREŚĆ STRONY, NIE CAŁY HTML. Układ wstawia w `<head>` adres bieżącego
        // żądania (`og:url`, `canonical`), więc pełne porównanie różniłoby się
        // zawsze — samym tokenem z adresu, a nie niczym, co serwis powiedział
        // o tokenie. Badamy `<main>`, czyli to, co człowiek dostaje.
        foreach ([$poTerminie, $znieksztalcony] as $inny) {
            $this->assertSame($nieznany->getStatusCode(), $inny->getStatusCode(),
                'Kod odpowiedzi zdradza, czy taki token kiedykolwiek istniał.');
            $this->assertSame($this->tresc($nieznany), $this->tresc($inny),
                'Treść ekranu zdradza, czy taki token kiedykolwiek istniał.');
        }
    }

    /**
     * ODMOWA MÓWI, CO ZROBIĆ, I ZAWSZE MA DROGĘ DALEJ.
     * Ślepa ściana jest tu gorsza niż gdziekolwiek indziej: przed nią stoi
     * osoba, która właśnie nie zdążyła założyć konta.
     */
    public function test_ekran_nieaktualnego_zaproszenia_mowi_co_zrobic(): void
    {
        $odpowiedz = $this->get(route('zaproszenie.pokaz', ['token' => RegistrationInvite::nowyToken()]))
            ->assertOk();

        $odpowiedz->assertSee('To zaproszenie już nie działa');
        // Co zrobić zamiast tego — konkret, nie samo „spróbuj ponownie".
        $odpowiedz->assertSee('konto założysz poniżej', false);
        $odpowiedz->assertSee(route('register'), false);
        $odpowiedz->assertSee(route('login'), false);
    }

    public function test_wygasle_zaproszenie_nie_przechodzi_dalej(): void
    {
        [$token] = $this->zaproszenie('basia@example.com', wygasle: true);

        $this->przyjmij($token)->assertRedirect(route('register'));

        // Do sesji nic nie weszło — a to jest jedyne, co decyduje o adresie
        // przy zakładaniu konta.
        $this->assertNull(session(RegistrationInvite::KLUCZ_SESJI));
    }

    /**
     * WYŁĄCZONA DROGA I ZAMKNIĘTA REJESTRACJA MAJĄ WŁASNY EKRAN PO POLSKU,
     * a nie 503 z `/register`. Zaproszenie mogło zostać wysłane, zanim
     * rejestracja się zamknęła — człowiek z ważnym linkiem w skrzynce ma
     * przeczytać zdanie, a nie zobaczyć stronę błędu serwera.
     */
    public function test_wylaczona_droga_daje_ekran_po_polsku_a_nie_blad_serwera(): void
    {
        [$token] = $this->zaproszenie('basia@example.com');

        config(['kuking.login_link.zaproszenia.wlaczone' => false]);

        $this->get(route('zaproszenie.pokaz', ['token' => $token]))
            ->assertOk()
            ->assertSee('Zakładanie konta z zaproszenia jest teraz wyłączone');

        $this->przyjmij($token)
            ->assertOk()
            ->assertSee('Zakładanie konta z zaproszenia jest teraz wyłączone');
    }

    public function test_zamknieta_rejestracja_nie_pokazuje_przycisku_zaloz_konto(): void
    {
        [$token] = $this->zaproszenie('basia@example.com');

        config(['kuking.account.registration_open' => false]);

        $tresc = $this->tresc(
            $this->get(route('zaproszenie.pokaz', ['token' => $token]))->assertOk(),
        );

        // BADAMY TREŚĆ STRONY, NIE CAŁY HTML: nagłówek układu ma własny,
        // stały przycisk „Załóż konto" i to nie o nim jest ten test.
        // Przycisk w treści prowadziłby na ekran 503 — droga dalej zostaje ta
        // jedna, która działa.
        $this->assertStringNotContainsString(route('register'), $tresc);
        $this->assertStringContainsString(route('login'), $tresc);
    }

    // ------------------------------------------------------------------
    //  Krok drugi: przyjęcie i porzucenie zaproszenia
    // ------------------------------------------------------------------

    public function test_przyjecie_zapamietuje_zaproszenie_w_sesji_i_nie_zuzywa_go(): void
    {
        [$token, $zaproszenie] = $this->zaproszenie('basia@example.com');

        $this->przyjmij($token)->assertRedirect(route('register'));

        $this->assertSame($zaproszenie->getKey(), session(RegistrationInvite::KLUCZ_SESJI));

        // ZAPROSZENIE JESZCZE ŻYJE. Za tym krokiem stoi cały formularz
        // rejestracji, o który ta osoba już raz się odbiła — gdyby link ginął
        // tutaj, pierwsza pomyłka w nazwie użytkownika odbierałaby go
        // bezpowrotnie.
        $this->assertDatabaseCount('registration_invites', 1);
    }

    /**
     * W SESJI LEŻY IDENTYFIKATOR WIERSZA, NIGDY ADRES ANI TOKEN.
     *
     * Adres w dwóch miejscach dałoby się rozjechać, a token nie ma po co
     * przeżywać drogi z wiadomości do formularza.
     */
    public function test_w_sesji_lezy_identyfikator_a_nie_adres_ani_token(): void
    {
        [$token, $zaproszenie] = $this->zaproszenie('basia@example.com');

        $this->przyjmij($token);

        // Badamy WŁASNY klucz tej funkcji. Całej sesji porównywać nie można:
        // `_previous.url` niesie adres poprzedniej strony, a więc i token
        // z niego — to zapis Laravela po stronie serwera, nie nasz nośnik.
        $nasze = json_encode(session('rejestracja'), JSON_UNESCAPED_UNICODE);

        $this->assertSame($zaproszenie->getKey(), session(RegistrationInvite::KLUCZ_SESJI));
        $this->assertStringNotContainsString($token, (string) $nasze);
        $this->assertStringNotContainsString('basia@example.com', (string) $nasze);
    }

    /**
     * „CHCĘ KONTO NA INNY ADRES" — bez tego przycisku droga jest pułapką.
     * Z jednej skrzynki korzysta czasem całe małżeństwo, a adres z zaproszenia
     * jest w formularzu niezmienny.
     */
    public function test_porzucenie_zaproszenia_czysci_sesje_i_mowi_co_dalej(): void
    {
        [$token] = $this->zaproszenie('basia@example.com');

        $this->przyjmij($token);
        $this->assertNotNull(session(RegistrationInvite::KLUCZ_SESJI));

        $odpowiedz = $this->post(route('zaproszenie.porzuc'));

        $odpowiedz->assertRedirect(route('register'));
        $this->assertNull(session(RegistrationInvite::KLUCZ_SESJI));

        // Komunikat mówi, CO ZROBIĆ, a nie samo „zrobione".
        $this->assertStringContainsString(
            'Wpisz adres e-mail',
            (string) $odpowiedz->getSession()->get('status', ''),
        );
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    /**
     * Zaproszenie wstawione wprost, z tokenem w ręku.
     *
     * @return array{0: string, 1: RegistrationInvite}
     */
    private function zaproszenie(string $adres, bool $wygasle = false): array
    {
        $token = RegistrationInvite::nowyToken();

        $wiersz = new RegistrationInvite;
        $wiersz->email = $adres;
        $wiersz->token_hash = RegistrationInvite::skrot($token);
        // CHECK w bazie wymusza `expires_at > created_at`, więc wygasłe
        // zaproszenie cofamy OBIEMA kolumnami naraz.
        $wiersz->created_at = $wygasle ? now()->subHours(30) : now();
        $wiersz->expires_at = $wygasle ? now()->subHours(6) : now()->addHours(24);
        $wiersz->save();

        return [$token, $wiersz];
    }

    /**
     * Treść strony — element `<main>`, bez nagłówka, stopki i `<head>`.
     *
     * Asercja na całym HTML łapie to samo słowo skądinąd: układ ma własny
     * przycisk „Załóż konto" w nagłówku i wstawia adres żądania w `<head>`.
     * Ten sam zabieg co `wycinek()` w testach onboardingu.
     */
    private function tresc(TestResponse $odpowiedz): string
    {
        $dokument = new \DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$odpowiedz->getContent());

        $main = (new \DOMXPath($dokument))->query('//main');

        $this->assertNotFalse($main);
        $this->assertGreaterThan(0, $main->length, 'Strona nie ma elementu <main> — test nie sprawdził niczego.');

        return (string) $dokument->saveHTML($main->item(0));
    }

    private function przyjmij(string $token): TestResponse
    {
        return $this->from(route('zaproszenie.pokaz', ['token' => $token]))
            ->post(route('zaproszenie.przyjmij'), ['token' => $token]);
    }

    /**
     * Wycina JEDEN formularz o podanej akcji.
     *
     * Asercja na całym HTML łapie to samo słowo skądinąd — układ strony ma
     * własne formularze (wyszukiwarka, wylogowanie), więc badamy dokładnie
     * ten element, o który chodzi.
     */
    private function formularzZAkcja(string $html, string $akcja): string
    {
        $dokument = new \DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $xpath = new \DOMXPath($dokument);
        $formularze = $xpath->query('//form[@action="'.$akcja.'"]');

        $this->assertNotFalse($formularze);
        $this->assertGreaterThan(0, $formularze->length,
            "Na stronie nie ma formularza o akcji {$akcja} — test nie sprawdził niczego.");

        return (string) $dokument->saveHTML($formularze->item(0));
    }
}
