<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pasek panelu, tytuł karty i nagłówek mówią o TYM SAMYM ekranie (#581).
 *
 * SKĄD SIĘ WZIĘŁA USTERKA
 * `pages/admin/bez-odpowiedzi-inne.blade.php` obsługuje DWA rodzaje treści:
 * przepisy i wykonania („Ugotowałem”). Nagłówek `<h1>` nazywał rodzaj wprost,
 * ale `<x-layout title="…">` i `<x-panel-moderacji ekran="…">` miały wpisane
 * na sztywno ogólne „Bez odpowiedzi”. Pasek panelu stał więc tuż nad
 * nagłówkiem i mówił co innego niż on.
 *
 * Dwa pozostałe ekrany tej samej rodziny (wpisy, pytania) nazywają rodzaj
 * w obu miejscach — niespójny był wyłącznie ten jeden.
 *
 * DLACZEGO TO NIE JEST KOSMETYKA
 * Pasek panelu powstał po to, żeby człowiek wiedział, GDZIE JEST, gdy wejdzie
 * na ekran z odnośnika w treści, a nie z menu (patrz `components/panel-moderacji`).
 * Pasek, który nazywa ekran inaczej niż nagłówek, odbiera sobie tę jedną
 * funkcję. Tytuł karty przeglądarki jest dodatkowo jedyną etykietą przy
 * kilku otwartych kartach panelu.
 */
class PanelNazywaEkranTakSamoWszedzieTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rodzaje treści z rodziny „bez odpowiedzi” i nazwa, jaką ekran ma nosić.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rodzajeTresci(): array
    {
        return [
            'wpisy' => ['wpisy', 'Wpisy bez odpowiedzi'],
            'przepisy' => ['przepisy', 'Przepisy bez odpowiedzi'],
            'ugotowane' => ['ugotowane', 'Ugotowałem bez odpowiedzi'],
        ];
    }

    #[DataProvider('rodzajeTresci')]
    public function test_trzy_miejsca_nazywaja_ekran_tak_samo(string $typ, string $nazwa): void
    {
        $odpowiedz = $this->actingAs($this->moderator())->get('/admin/bez-odpowiedzi?typ='.$typ);

        $odpowiedz->assertOk();

        $html = (string) $odpowiedz->getContent();

        // 1. Nagłówek ekranu.
        $this->assertMatchesRegularExpression(
            '~<h1[^>]*>\s*'.preg_quote($nazwa, '~').'\s*</h1>~u',
            $html,
            "Nagłówek ekranu „{$typ}” nie brzmi „{$nazwa}”.",
        );

        // 2. Tytuł karty przeglądarki — z dopiskiem panelu, jak na pozostałych ekranach.
        $this->assertMatchesRegularExpression(
            '~<title>\s*'.preg_quote($nazwa.' — Panel moderacji', '~').'~u',
            $html,
            "Tytuł karty dla „{$typ}” nie nazywa ekranu tak samo co nagłówek.",
        );

        // 3. Pasek panelu — ta sama nazwa, w elemencie, który ją niesie.
        $this->assertMatchesRegularExpression(
            '~class="panel-pasek-ekran"[^>]*>\s*'.preg_quote($nazwa, '~').'\s*<~u',
            $html,
            "Pasek panelu dla „{$typ}” nazywa ekran inaczej niż nagłówek nad treścią.",
        );

        // Kontrola ujemna wbudowana w scenę: dawna ogólna nazwa nie może wracać
        // do paska ani do tytułu. Sam nagłówek jej nigdy nie miał, więc asercja
        // „nie ma napisu” bez tego zawężenia przechodziłaby także po cofnięciu
        // poprawki na innym ekranie rodziny.
        if ($typ !== 'wpisy') {
            $this->assertStringNotContainsString(
                '<title>Bez odpowiedzi — Panel moderacji',
                $html,
                "Tytuł karty dla „{$typ}” wrócił do ogólnej nazwy rodziny.",
            );
        }
    }

    public function test_pasek_panelu_jest_na_kazdym_ekranie_rodziny(): void
    {
        /*
         * Kontrola dodatnia (pułapka 4): asercje wyżej pilnują TREŚCI paska.
         * Gdyby pasek zniknął z widoku w całości, wzorzec z `panel-pasek-ekran`
         * przestałby pasować — i test oblewałby z powodu, który brzmi jak zła
         * nazwa, a nie jak brak paska. Ta asercja rozdziela te dwa przypadki.
         */
        foreach (['wpisy', 'przepisy', 'ugotowane'] as $typ) {
            $html = (string) $this->actingAs($this->moderator())
                ->get('/admin/bez-odpowiedzi?typ='.$typ)
                ->getContent();

            $this->assertStringContainsString('panel-pasek', $html,
                "Ekran „{$typ}” stracił pasek panelu.");
            $this->assertStringContainsString('Panel moderacji', $html,
                "Ekran „{$typ}” nie mówi, że jest w panelu moderacji.");
        }
    }
}
