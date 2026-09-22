<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Zakładki dodawania mają własne klasy w arkuszu (issue #366, wklejone przy #365).
 *
 * Blok CSS przygotował agent od #366, który nie miał prawa ruszać `app.css`;
 * ten test pilnuje, żeby wklejenie nie zginęło przy następnym scalaniu —
 * bez tych klas zakładki wracają na zastępcze `.chipsy`/`.chip`.
 *
 * KONTRAST ZMIERZONY PRZED WKLEJENIEM (liczony tak samo jak w
 * `docs/design/DESIGN_SYSTEM.md`):
 *   * zakładka bieżąca, `--color-ink-inverse` na `--color-brand-solid`:
 *     5,72:1 (jasny, #FFFFFF na #B3401F), 4,72:1 (ciemny, #FFFFFF na #C1502A)
 *     — próg 4,5:1 dla tekstu, przechodzi w obu motywach;
 *   * obwódka zakładki niebieżącej, `--color-border-strong` do tła karty:
 *     4,16:1 (jasny, #8A7A63 do #FFFFFF), 3,83:1 (ciemny, #8C7D68 do #2A241E)
 *     — próg 3:1 dla obwódki kontrolki (WCAG 1.4.11), przechodzi w obu.
 *     `--color-border` miałby tu 1,40:1, czyli NIE przechodzi — dlatego
 *     test pilnuje TEGO tokenu, a nie „jakiejkolwiek obwódki".
 */
class ZakladkiDodawaniaMajaStylTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    public function test_zakladka_ma_cel_dotykowy_w_pikselach(): void
    {
        $this->assertSame(
            1,
            preg_match('/\.zakladka-dodawania\s*\{([^}]*)\}/', $this->css(), $regula),
            'W `app.css` nie ma reguły `.zakladka-dodawania` — zakładki zostają bez wyglądu.',
        );

        $this->assertMatchesRegularExpression(
            '/min-height:\s*48px\s*;/',
            $regula[1],
            'Minimum celu dotykowego nie jest w pikselach. 48 px istnieje dla palca, a palec nie '
            .'rośnie, gdy ktoś powiększy czcionkę w przeglądarce (D-082, D-107).',
        );

        $this->assertMatchesRegularExpression(
            '/font-size:\s*var\(--text-body\)\s*;/',
            $regula[1],
            'Pismo zakładki przestało być typograficzne — nie urośnie ze skalą tekstu.',
        );
    }

    public function test_obwodka_zakladki_ma_kontrast_kontrolki(): void
    {
        $this->assertSame(
            1,
            preg_match('/\.zakladka-dodawania\s*\{([^}]*)\}/', $this->css(), $regula),
            'Brak reguły `.zakladka-dodawania`.',
        );

        $this->assertMatchesRegularExpression(
            '/border:\s*2px\s+solid\s+var\(--color-border-strong\)\s*;/',
            $regula[1],
            'Obwódka zakładki wzięła słabszy token. `--color-border` ma do tła karty 1,40:1, '
            .'czyli nie przechodzi progu 3:1 dla obwódki kontrolki (WCAG 1.4.11).',
        );
    }

    public function test_zakladka_biezaca_jest_oznaczona_stanem_a_nie_klasa(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.zakladka-dodawania\[aria-current="page"\]\s*\{[^}]*background-color:\s*var\(--color-brand-solid\)\s*;/s',
            $this->css(),
            'Zakładka bieżąca nie jest wyróżniana przez `aria-current="page"`. Wyróżnienie samą '
            .'klasą znaczy, że czytnik ekranu nie wie, na której zakładce człowiek stoi.',
        );
    }
}
