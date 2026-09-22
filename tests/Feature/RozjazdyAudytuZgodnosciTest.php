<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Trzy rozjazdy z audytu zgodności dokumentów z kodem (#8, 19 września 2026):
 * R2, R3 i R5. Każdy polegał na tym samym: dokument albo ekran mówił coś,
 * czego kod nie robi — i nic tego nie porównywało.
 *
 * DLACZEGO TO JEST TEST, A NIE POPRAWKA TEKSTU
 * Bo sama poprawka tekstu nie przeżyje następnej zmiany kodu. R3 jest tu
 * dowodem: Turnstile dołożono na siódmym formularzu 10 września, polityka
 * nosi datę 11 września — zdanie rozjechało się NASTĘPNEGO DNIA po zmianie
 * i przeżyło dziewięć dni, bo żaden test nie porównywał listy z dokumentu
 * z listą z konfiguracji.
 *
 * Każde sprawdzenie niżej pyta KOD o prawdę i dopiero potem patrzy
 * w dokument — nigdy odwrotnie. Dzięki temu dołożenie ósmego formularza
 * albo tabeli wersji dla wpisów samo zapala czerwone światło.
 */
class RozjazdyAudytuZgodnosciTest extends TestCase
{
    use RefreshDatabase;

    private function polityka(): string
    {
        return (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
    }

    /**
     * R3. Polityka wymienia SZEŚĆ formularzy za Turnstile, a kod ma SIEDEM.
     *
     * Nazwy ludzkie stoją tutaj, nie w konfiguracji, i to jest świadome:
     * konfiguracja ma klucze techniczne, a polityka mówi do człowieka.
     * Gdy dojdzie ósme miejsce, ten test padnie na brakującym tłumaczeniu —
     * i o to chodzi, bo wtedy ktoś musi zdecydować, jak je nazwać w polityce.
     */
    public function test_polityka_wymienia_kazdy_formularz_za_turnstile(): void
    {
        $nazwy = [
            'rejestracja' => 'rejestracji',
            'logowanie' => 'logowaniu',
            'logowanie_linkiem' => 'wysłaniu linku do zalogowania',
            'odzyskanie_hasla' => 'odzyskiwaniu hasła',
            'cofniecie_usuniecia' => 'cofnięciu usunięcia konta',
            'kontakt' => 'Napisz do nas',
            'zgloszenie_nielegalnej_tresci' => 'zgłoszenia nielegalnej treści',
        ];

        $miejsca = (array) config('kuking.turnstile.miejsca');
        $polityka = $this->polityka();

        $this->assertNotEmpty($miejsca, 'Konfiguracja Turnstile jest pusta — ten test przestał mierzyć.');

        foreach (array_keys($miejsca) as $klucz) {
            $this->assertArrayHasKey(
                $klucz,
                $nazwy,
                "Konfiguracja ma miejsce Turnstile „{$klucz}”, dla którego ten test nie zna nazwy w polityce. "
                .'Dopisz tłumaczenie TUTAJ i sprawdź, czy polityka rzeczywiście o tym formularzu mówi — '
                .'to jest dokładnie ten moment, w którym R3 powstało.',
            );

            $this->assertStringContainsString(
                $nazwy[$klucz],
                $polityka,
                "Polityka prywatności nie wymienia formularza „{$klucz}”, a Turnstile na nim stoi. "
                .'Człowiek dowiaduje się z polityki, kiedy jego adres IP trafia do Cloudflare — '
                .'lista musi być pełna.',
            );
        }

        // KONTROLA METODY. Gdyby konfiguracja skurczyła się do jednego
        // miejsca, pętla wyżej nadal byłaby zielona, nie sprawdzając nic
        // istotnego (`docs/PULAPKI_TESTOW.md` §2).
        $this->assertGreaterThanOrEqual(
            6,
            count($miejsca),
            'Miejsc Turnstile jest mniej niż sześć — albo konfiguracja się zmieniła, albo test patrzy nie tam.',
        );
    }

    /**
     * R5. Polityka deklarowała „historię edycji" wpisów i komentarzy.
     * Historia wersji istnieje WYŁĄCZNIE dla przepisów (`recipe_versions`).
     *
     * Test pyta schemat, nie dokument: dopóki nie ma tabeli wersji dla wpisów
     * ani komentarzy, polityka nie ma prawa ich obiecywać. Gdyby taka tabela
     * kiedyś powstała, sprawdzenie samo się wyłączy — i wtedy zdanie
     * w polityce będzie można rozszerzyć zgodnie z prawdą.
     */
    public function test_polityka_nie_obiecuje_historii_edycji_ktorej_nie_ma(): void
    {
        $this->assertTrue(
            Schema::hasTable('recipe_versions'),
            'Nie ma tabeli `recipe_versions` — ten test opiera się na niej jako na jedynej historii wersji.',
        );

        $historiaWpisow = Schema::hasTable('post_versions') || Schema::hasColumn('posts', 'edited_at');
        $historiaKomentarzy = Schema::hasTable('comment_versions') || Schema::hasColumn('comments', 'edited_at');

        if ($historiaWpisow && $historiaKomentarzy) {
            $this->markTestSkipped('Historia edycji wpisów i komentarzy istnieje — polityka może o niej mówić.');
        }

        $polityka = $this->polityka();

        $this->assertStringNotContainsString(
            'komentarze i ich historia edycji',
            $polityka,
            'Polityka obiecuje historię edycji komentarzy, a w bazie jej nie ma. '
            .'To jest naddeklarowanie zbierania danych: człowiek myśli, że trzymamy o nim więcej, niż trzymamy.',
        );

        $this->assertStringNotContainsString(
            'wpisy, komentarze i ich historia',
            $polityka,
            'Polityka obiecuje historię edycji wpisów, a w bazie jej nie ma.',
        );
    }

    /**
     * R2. Ekran zamawiania paczki obiecywał „wszystkie zdjęcia", a zdjęcia
     * odrzucone i skasowane nie wchodzą do żadnej paczki — sama paczka mówi
     * to wprost w polu `czego_nie_zawiera`, tylko ekran milczał.
     *
     * Test porównuje ekran z REGUŁĄ z kodu eksportu, nie z zapamiętanym
     * brzmieniem zdania.
     */
    public function test_ekran_paczki_nie_obiecuje_wszystkich_zdjec(): void
    {
        $kolektor = (string) file_get_contents(
            app_path('Domain/Users/Exports/CollectUserExportData.php'),
        );

        // Najpierw upewniamy się, że reguła w kodzie NADAL obowiązuje —
        // inaczej sprawdzalibyśmy ekran pod kątem nieistniejącego ograniczenia.
        $this->assertStringContainsString(
            'zdjec_odrzuconych_przy_przygotowaniu',
            $kolektor,
            'Eksport nie liczy już zdjęć odrzuconych — sprawdź, czy przypadkiem nie zaczął ich załączać, '
            .'i dopiero wtedy popraw ekran.',
        );
        $this->assertStringContainsString('zdjec_skasowanych', $kolektor);

        $widok = (string) file_get_contents(
            resource_path('views/pages/settings/data.blade.php'),
        );

        $this->assertStringNotContainsString(
            'wszystkimi Twoimi wpisami',
            $widok,
            'Ekran znowu obiecuje paczkę ze WSZYSTKIMI danymi, a zdjęcia odrzucone i skasowane '
            .'do żadnej paczki nie wchodzą. To zdanie człowiek czyta pierwsze i jedyne, zanim kliknie.',
        );

        $this->assertStringContainsString(
            'nie wejdą do żadnej paczki',
            $widok,
            'Ekran nie mówi o granicy, którą sama paczka opisuje w `czego_nie_zawiera`.',
        );
    }

    /**
     * KONTROLA DODATNIA do testu wyżej: strona naprawdę się renderuje
     * i naprawdę pokazuje to zdanie zalogowanej osobie. Bez tego sprawdzalibyśmy
     * plik, którego nikt nie ogląda (`docs/PULAPKI_TESTOW.md` §2).
     */
    public function test_ekran_paczki_pokazuje_te_granice_czlowiekowi(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user->fresh() ?? $user)
            ->get('/ustawienia/twoje-dane')
            ->assertOk()
            ->assertSee('nie wejdą do żadnej paczki', escape: false);
    }
}
