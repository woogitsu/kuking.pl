<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Issue #86 — komunikaty walidacji dalej wychodzą po angielsku.
 *
 * W repozytorium NIE BYŁO `lang/pl/validation.php`. Każde pole bez własnego,
 * ręcznie napisanego komunikatu dostawało domyślny tekst Laravela. Przykład
 * z treści zgłoszenia, odtworzony niżej jako pierwszy test: człowiek myli się
 * o cyfrę w „Roku, od którego przepis jest w rodzinie" i ma dostać zdanie
 * mówiące, co zrobić — nie coś, czego nie rozumie.
 *
 * ZNALEZISKO PRZY POMIARZE „PRZED NAPRAWĄ" (patrz raport PR): to, co faktycznie
 * widać w TYM repozytorium, jest gorsze niż angielski tekst z przykładu
 * w zgłoszeniu. `config/app.php` ma `fallback_locale` ustawiony na `pl` —
 * TAKI SAM jak `locale` — więc bez `lang/pl/validation.php` Laravel nie miał
 * dokąd się cofnąć (nigdy nie sięgał po wbudowany, angielski plik z pakietu)
 * i zwracał SUROWY KLUCZ tłumaczenia, np. „validation.min.numeric", wprost
 * na ekranie. To wciąż dokładnie ten sam problem z AGENTS.md („błędy po
 * polsku, mówiące co zrobić") — tylko w przebraniu, które wygląda jak awaria
 * aplikacji, nie jak brakujące tłumaczenie. Test pilnuje obu wariantów
 * naraz — patrz `pierwszyBlad()`.
 *
 * BEZ NAPRAWY (bez `lang/pl/validation.php` i bez dopisanych komunikatów
 * w kontrolerach) 12 z 20 testów w tym pliku i w `OdmianaWalidacjiTest`
 * pada — zmierzone przez tymczasowe wyłączenie naprawy, patrz raport PR.
 */
final class WalidacjaPoPolskuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Frazy kotwiczące domyślnych, angielskich komunikatów Laravela.
     * Kryterium akceptacji z issue #86 — z jedną dodaną świadomie: samo
     * słowo „field" łapie też przypadki spoza listy z issue (np. „is
     * prohibited", „must not be greater than"), które i tak nie powinny
     * się pojawić, gdyby lang/pl/validation.php miał dziurę.
     */
    private const ANGIELSKIE_FRAZY = [
        'The ',
        ' field ',
        'must be',
        'is required',
        'is invalid',
        'may not be',
    ];

    // -----------------------------------------------------------------
    //  Dokładna reprodukcja z treści zgłoszenia #86
    // -----------------------------------------------------------------

    public function test_zly_rok_w_rodzinie_przepisu_mowi_po_polsku_i_nie_nazywa_pola_po_angielsku(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'family',
            // O CYFRĘ za wczesny — dokładnie ten scenariusz z issue.
            'family_since_year' => 1850 - 1,
        ]);

        $odpowiedz->assertSessionHasErrors('family_since_year');

        $komunikat = $this->pierwszyBlad($odpowiedz, 'family_since_year');

        // Najpierw dowód, że komunikat w ogóle się wyrenderował — inaczej
        // asercja „nie ma angielskiego" przechodzi też na pustej odpowiedzi
        // (uwaga z kryteriów akceptacji issue #86).
        $this->assertNotSame('', trim($komunikat), 'Komunikat dla family_since_year jest pusty.');

        $this->assertBezAngielskiego($komunikat);
        $this->assertStringNotContainsString('family_since_year', $komunikat);
        $this->assertStringContainsString('1850', $komunikat);
    }

    // -----------------------------------------------------------------
    //  Reguła `in` ma mówić CO WYBRAĆ, nie że „wartość jest nieprawidłowa"
    // -----------------------------------------------------------------

    public function test_zla_widocznosc_przepisu_mowi_co_wybrac(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('recipes.store'), [
            'title' => 'Rosół babci Zofii',
            'visibility' => 'nie-taka-opcja',
            'source_type' => 'own',
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'visibility');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
        // Generyczne „wybrana wartość jest nieprawidłowa" nie mówi nic —
        // komunikat ma wymienić opcje z ekranu (issue #86, sekcja „Zakres").
        $this->assertStringContainsString('wszyscy', mb_strtolower($komunikat));
    }

    public function test_zle_zrodlo_przepisu_mowi_co_wybrac(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('recipes.store'), [
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'nie-taka-opcja',
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'source_type');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
    }

    public function test_zla_trudnosc_przepisu_mowi_co_wybrac(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('recipes.store'), [
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'own',
            'difficulty' => 'bardzo-trudne',
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'difficulty');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
        $this->assertStringContainsString('trudn', mb_strtolower($komunikat));
    }

    public function test_zla_widocznosc_zeszytu_mowi_co_wybrac(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('collections.store'), [
            'name' => 'Na święta',
            'visibility' => 'nie-taka-opcja',
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'visibility');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
    }

    public function test_zla_widocznosc_wpisu_mowi_co_wybrac(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('posts.store'), [
            'visibility' => 'nie-taka-opcja',
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'visibility');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
    }

    public function test_zla_trudnosc_wykonania_mowi_co_wybrac(): void
    {
        $basia = $this->user('basia');
        $przepis = Recipe::factory()->create();

        $odpowiedz = $this->actingAs($basia)->post(route('cooked.store', $przepis->slug), [
            'perceived_difficulty' => 'bardzo-trudne',
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'perceived_difficulty');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
        $this->assertStringContainsString('trudn', mb_strtolower($komunikat));
    }

    // -----------------------------------------------------------------
    //  Sekcja `attributes` — pole nie nazywa się swoją techniczną nazwą
    // -----------------------------------------------------------------

    public function test_za_dlugi_opis_profilu_nie_nazywa_pola_technicznie(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->put('/ustawienia/profil', [
            'display_name' => 'Basia',
            'username' => 'basia',
            'bio' => str_repeat('a', 501),
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'bio');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
        $this->assertStringNotContainsString('bio', mb_strtolower($komunikat));
    }

    public function test_za_krotka_nazwa_uzytkownika_przy_rejestracji_nie_nazywa_pola_technicznie(): void
    {
        $odpowiedz = $this->post(route('register'), [
            'display_name' => 'Basia',
            // Minimum to 3 znaki — dwa nie przechodzą.
            'username' => 'ab',
            'email' => 'basia@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'username');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
        $this->assertStringNotContainsString('username', mb_strtolower($komunikat));
        // Odmiana: 3 to „kilka" (znaki), nie „wiele" (znaków) — patrz
        // OdmianaWalidacjiTest dla pełnego pokrycia liczb.
        $this->assertStringContainsString('znaki', $komunikat);
    }

    public function test_zly_rozmiar_tekstu_mowi_po_polsku(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->put('/ustawienia/czytelnosc', [
            'text_scale' => 999,
        ]);

        $komunikat = $this->pierwszyBlad($odpowiedz, 'text_scale');

        $this->assertNotSame('', trim($komunikat));
        $this->assertBezAngielskiego($komunikat);
        $this->assertStringNotContainsString('text_scale', mb_strtolower($komunikat));
    }

    // -----------------------------------------------------------------
    //  Pomoc
    // -----------------------------------------------------------------

    private function pierwszyBlad(TestResponse $odpowiedz, string $pole): string
    {
        $odpowiedz->assertSessionHasErrors($pole);

        /** @var MessageBag $bledy */
        $bledy = session('errors')->getBag('default');

        $komunikat = (string) $bledy->first($pole);

        // Odkryte przy pomiarze „przed naprawą" (raport PR): `config/app.php`
        // ma `fallback_locale` ustawione na `pl`, TAKIE SAMO jak `locale` —
        // więc bez `lang/pl/validation.php` Laravel nie miał do czego wrócić
        // i zwracał SUROWY KLUCZ tłumaczenia („validation.min.numeric"), nie
        // angielski tekst z przykładu w issue. To jest ten sam błąd (brak
        // polskiego komunikatu), tylko w gorszym przebraniu — wygląda jak
        // usterka aplikacji, nie jak nieprzetłumaczony tekst. Pilnujemy obu
        // wariantów w jednym miejscu.
        $this->assertStringNotContainsString(
            'validation.',
            $komunikat,
            "Komunikat jest surowym, nieprzetłumaczonym kluczem: „{$komunikat}”.",
        );

        return $komunikat;
    }

    private function assertBezAngielskiego(string $komunikat): void
    {
        foreach (self::ANGIELSKIE_FRAZY as $fraza) {
            $this->assertStringNotContainsString(
                $fraza,
                $komunikat,
                "Komunikat walidacji zawiera angielską frazę „{$fraza}” — treść: „{$komunikat}”.",
            );
        }
    }
}
