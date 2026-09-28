<?php

declare(strict_types=1);

namespace Tests\Feature\Zgody;

use App\Domain\Import\KlientLuna;
use App\Domain\Zgody\InformacjaOdczytuAi;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ta sama informacja przed zgodą „odczyt AI” w każdym miejscu, z którego da
 * się jej udzielić (issue #2033, D-296).
 *
 * Ekran „Przepisz z kartki” mówił, komu wysyłamy zdjęcie (OpenAI, USA), co
 * dokładnie wychodzi, gdzie trafia odczytany tekst i jak wycofać zgodę.
 * Ustawienia prywatności pokazywały przy tym samym przycisku jedno zdanie —
 * ta sama zgoda w dzienniku miała za sobą dwie różne informacje. Teraz obie
 * strony renderują jeden komponent (`x-zgoda-odczyt-ai`), a formularz niesie
 * wersję informacji, którą człowiek widział: nieaktualna wersja nie zapisuje
 * zgody.
 */
final class InformacjaPrzedZgodaOdczytuAiTest extends TestCase
{
    use RefreshDatabase;

    /** Fakty, bez których zgoda nie jest świadoma: odbiorca, zakres, skutek, odmowa. */
    private const FAKTY = [
        'OpenAI',
        'w USA',
        'prywatnego szkicu',
        'samo zdjęcie kartki',
        'bez Twojego imienia, adresu e-mail i danych z aparatu',
        'zasłoń je przed zrobieniem zdjęcia',
        'Nic się nie opublikuje',
        'Bez zgody nie wysyłamy żadnego zdjęcia',
        'wycofać',
    ];

    private const PRZYCISK = 'Zgadzam się, odczytujcie moje kartki';

    private User $osoba;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.wysilek.ocr' => 'medium',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
            'kuking.import.zrodla.zdjecie' => true,
        ]);

        $this->osoba = $this->user('kartki2033');
    }

    public function test_ustawienia_i_ekran_importu_pokazuja_te_sama_informacje_przed_przyciskiem_zgody(): void
    {
        $zUstawien = $this->actingAs($this->osoba)->get(route('settings.privacy'))->assertOk();
        $zImportu = $this->actingAs($this->osoba)->get(route('import.zdjecie'))->assertOk();

        foreach ([$zUstawien, $zImportu] as $strona) {
            $strona->assertSeeInOrder([...self::FAKTY, self::PRZYCISK]);
        }

        $this->assertSame(
            $this->informacja($zImportu->getContent()),
            $this->informacja($zUstawien->getContent()),
            'Ekran importu i ustawienia prywatności mają pokazać ten sam tekst informacji przed zgodą — z jednego komponentu x-zgoda-odczyt-ai.',
        );
    }

    public function test_zgoda_z_ustawien_zapisuje_sie_z_aktualna_wersja_informacji(): void
    {
        $this->actingAs($this->osoba)
            ->post(route('zgoda.odczyt-ai.udziel'), ['informacja' => InformacjaOdczytuAi::WERSJA])
            ->assertRedirect(route('settings.privacy'));

        $this->assertTrue(app(PrzestawZgodeNaOdczytAi::class)->udzielona($this->osoba));
        $this->assertSame(WpisZgody::ZRODLO_USTAWIENIA, WpisZgody::query()->where('user_id', $this->osoba->getKey())->value('zrodlo'));
    }

    public function test_zgoda_z_nieaktualnej_informacji_nie_zapisuje_sie_i_mowi_co_zrobic(): void
    {
        $przypadki = [
            [[], route('settings.privacy').'#odczyt-ai'],
            [['skad' => 'import'], route('import.zdjecie')],
        ];

        foreach ($przypadki as [$pola, $przekierowanie]) {
            $wyslanie = [...$pola, 'informacja' => 'stara-tresc'];

            $this->actingAs($this->osoba)
                ->post(route('zgoda.odczyt-ai.udziel'), $wyslanie)
                ->assertRedirect($przekierowanie)
                ->assertSessionHasErrors(['informacja'], null, InformacjaOdczytuAi::WOREK_BLEDOW);

            // Po powrocie człowiek widzi, co się stało, i aktualną informację z przyciskiem.
            $this->actingAs($this->osoba)->followingRedirects()
                ->post(route('zgoda.odczyt-ai.udziel'), $wyslanie)
                ->assertOk()
                ->assertSeeInOrder(['zgody nie zapisaliśmy', self::PRZYCISK]);
        }

        $this->assertSame(0, WpisZgody::query()->where('user_id', $this->osoba->getKey())->count());
    }

    /**
     * Karta ustawień otwarta przed wdrożeniem #2033 ma goły przycisk bez
     * informacji i bez pola wersji — taka zgoda nie może się zapisać.
     */
    public function test_zgoda_z_ustawien_bez_wersji_informacji_nie_zapisuje_sie(): void
    {
        $this->actingAs($this->osoba)
            ->post(route('zgoda.odczyt-ai.udziel'))
            ->assertRedirect(route('settings.privacy').'#odczyt-ai')
            ->assertSessionHasErrors(['informacja'], null, InformacjaOdczytuAi::WOREK_BLEDOW);

        $this->assertFalse(app(PrzestawZgodeNaOdczytAi::class)->udzielona($this->osoba));
        $this->assertSame(0, WpisZgody::query()->where('user_id', $this->osoba->getKey())->count());
    }

    /** Wnętrze bloku informacji — ten sam fragment HTML na obu stronach. */
    private function informacja(string $html): string
    {
        $this->assertSame(1, preg_match('~<div data-informacja-odczyt-ai="[^"]*"[^>]*>(.*?)</div>~s', $html, $m),
            'Brak bloku informacji o odczycie (x-zgoda-odczyt-ai) przed przyciskiem zgody.');

        return trim($m[1]);
    }
}
