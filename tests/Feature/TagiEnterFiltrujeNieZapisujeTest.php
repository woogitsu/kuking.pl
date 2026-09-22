<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TagPromotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decyzja właściciela z 20.09.2026 do #858, punkt 3.
 *
 * Ekran „Twoje tagi" ma jeden `<form>` z kilkoma przyciskami `submit`
 * („Pokaż pasujące", „Pokaż wszystkie tagi", „Pokaż kolejne…", „Zapisz").
 * Enter naciśnięty w polu tekstowym wyzwala PIERWSZY przycisk `submit`
 * formularza W KOLEJNOŚCI DOKUMENTU — to zachowanie przeglądarki, nie
 * czegoś, co da się ustawić atrybutem. Dziś „Pokaż pasujące" stoi przed
 * „Zapisz", więc Enter filtruje. Ale nic tego nie PILNUJE — to przypadek
 * układu HTML, nie wybór potwierdzony testem.
 *
 * Ten test sprawdza kolejność w wyrenderowanym HTML-u: pierwszy przycisk
 * `submit` na stronie nie może być „Zapisz". Złapie każde przestawienie
 * przycisków, które zamieniłoby filtrowanie w zapis pod Enterem —
 * czyli zapisywałoby zmiany, których człowiek nie potwierdził.
 */
class TagiEnterFiltrujeNieZapisujeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sam formularz „Twoje tagi", bez reszty strony (topbar i boczna
     * nawigacja ustawień mają WŁASNE przyciski `submit` — Enter w polu
     * filtra ich nie dotyczy, bo są w innym `<form>`).
     */
    private function samFormularz(string $html): string
    {
        $poczatek = strpos($html, 'action="'.route('settings.tags.update').'"');
        $this->assertNotFalse($poczatek, 'Formularz „Twoje tagi" nie wysyła się na settings.tags.update.');
        $koniec = strpos($html, '</form>', $poczatek);
        $this->assertNotFalse($koniec, 'Formularz „Twoje tagi" nie ma zamykającego </form>.');

        return substr($html, $poczatek, $koniec - $poczatek);
    }

    /** Wartości `name="akcja"` przycisków `submit`, w kolejności dokumentu. */
    private function kolejnoscPrzyciskowAkcji(string $html): array
    {
        preg_match_all(
            '/<button\b[^>]*type="submit"[^>]*>/',
            $this->samFormularz($html),
            $przyciski,
        );

        $kolejnosc = [];
        foreach ($przyciski[0] as $przycisk) {
            if (preg_match('/\bname="akcja"[^>]*\bvalue="([^"]*)"/', $przycisk, $m)) {
                $kolejnosc[] = $m[1];
            }
        }

        return $kolejnosc;
    }

    /**
     * Czerwień tego testu wymuszona ręcznie: przestaw w
     * `resources/views/pages/settings/tags.blade.php` blok `form-actions`
     * (z przyciskiem „Zapisz") przed blok filtra i uruchom ten test — musi
     * spaść. To jest dowód, że test naprawdę pilnuje kolejności, a nie
     * przechodzi z przypadku. Po dowodzie przywróć oryginalną kolejność.
     */
    public function test_przycisk_zapisz_nie_jest_pierwszym_submitem_formularza(): void
    {
        $user = $this->user();
        $tag = Tag::factory()->create([
            'name' => 'Zupa pomidorowa',
            'normalized_name' => Tag::znormalizujNazwe('Zupa pomidorowa'),
        ]);
        TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => 0]);

        $strona = $this->actingAs($user)->get(route('settings.tags'))->assertOk()->getContent();

        $kolejnosc = $this->kolejnoscPrzyciskowAkcji($strona);

        $this->assertNotEmpty($kolejnosc, 'Formularz nie ma ani jednego przycisku submit z polem akcja — test nic nie sprawdził.');
        $this->assertContains('zapisz', $kolejnosc, 'Przycisk „Zapisz" zniknął z formularza.');
        $this->assertNotSame(
            'zapisz',
            $kolejnosc[0],
            'Enter w polu tekstowym wyzwala PIERWSZY submit formularza. Jeśli nim jest '
            .'„Zapisz", Enter po wpisaniu frazy filtra zapisuje niepotwierdzone zmiany '
            .'zamiast filtrować.',
        );
    }
}
