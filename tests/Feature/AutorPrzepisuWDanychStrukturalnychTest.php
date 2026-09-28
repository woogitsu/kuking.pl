<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `author` w danych strukturalnych przepisu opisuje KONTO, KTÓRE PRZEPIS
 * OPUBLIKOWAŁO — a pochodzenie przepisu stoi w osobnym polu.
 *
 * ZGŁOSZENIE WŁAŚCICIELA. Blok JSON-LD na stronie przepisu składał obiekt
 * `Person` z dwóch różnych encji naraz:
 *
 *     'author' => [
 *         '@type' => 'Person',
 *         'name' => $recipe->source_person ?: $recipe->author->displayName(),
 *         'url'  => route('profile.show', $recipe->author->profile->username),
 *     ]
 *
 * czyli NAZWĘ brał z pochodzenia przepisu, a ADRES z profilu konta. Do tego
 * deklarował `@type: Person` dla wolnego tekstu, o którym nie wie nic — a
 * właściciel potwierdził, że wpisuje tam NAZWĘ GRUPY NA FACEBOOKU. Widoczny
 * tekst strony uznaje to od dawna (wartość idzie dosłownie, bez doklejanego
 * przyimka, patrz `Recipe::attributionLine()`); dane dla Google nie uznawały.
 *
 * DANE SĄ TU WROGIE, a nie wygodne. Sam „od babci Zofii" przechodziłby także
 * dla zepsutego kodu, bo wygląda jak osoba (docs/PULAPKI_TESTOW.md). Dlatego
 * w zestawie stoi nazwa grupy na Facebooku, nazwa własna bez człowieka
 * w środku, wzmianka o gazecie i wartość, która sama jest imieniem — ta
 * ostatnia jest najgroźniejsza, bo dla starego kodu wychodziła najbardziej
 * wiarygodnie.
 *
 * DWA STANY EKRANU. Cały blok JSON-LD stoi pod `@if($isPublic)`, więc test
 * musi renderować przepis PUBLICZNY I OPUBLIKOWANY — inaczej mierzyłby stan,
 * w którym sprawdzanego fragmentu w ogóle nie ma (D-099, D-106). Ostatni test
 * pilnuje drugiego stanu z drugiej strony: przy przepisie prywatnym danych
 * strukturalnych przepisu NIE MA WCALE.
 */
class AutorPrzepisuWDanychStrukturalnychTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pochodzenia, których stary kod nie miał prawa nazwać osobą.
     *
     * @var list<string>
     */
    private const WROGIE_POCHODZENIA = [
        'Nasze smaki - grupa na Facebooku',
        'Nasze smaki',
        'z gazety Przyjaciółka',
        'od mamy',
        'Halina',
    ];

    private const NAZWA_KONTA = 'Żaneta Kowalska';

    private const LOGIN_KONTA = 'zaneta';

    private ?User $autor = null;

    // -----------------------------------------------------------------
    // Twierdzenie 1: `author.name` to nazwa konta publikującego
    // -----------------------------------------------------------------

    public function test_author_name_to_nazwa_konta_publikujacego_takze_gdy_pochodzenie_jest_ustawione(): void
    {
        foreach (self::WROGIE_POCHODZENIA as $pochodzenie) {
            $przepis = $this->publicznyPrzepis($pochodzenie);

            $dane = $this->daneStrukturalnePrzepisu($przepis);

            $this->assertSame(
                self::NAZWA_KONTA,
                $dane['author']['name'],
                "Dla pochodzenia „{$pochodzenie}” jako autor wyszło coś innego niż konto publikujące.",
            );
            $this->assertSame('Person', $dane['author']['@type']);

            // I to jest sedno błędu: pochodzenie NIE jest nazwą autora.
            $this->assertNotSame($pochodzenie, $dane['author']['name']);
        }
    }

    // -----------------------------------------------------------------
    // Twierdzenie 2: `author.url` wskazuje profil TEGO SAMEGO konta
    // -----------------------------------------------------------------

    public function test_author_url_wskazuje_profil_tego_samego_konta_co_nazwa(): void
    {
        foreach (self::WROGIE_POCHODZENIA as $pochodzenie) {
            $przepis = $this->publicznyPrzepis($pochodzenie);

            $dane = $this->daneStrukturalnePrzepisu($przepis);

            $this->assertSame(
                route('profile.show', self::LOGIN_KONTA),
                $dane['author']['url'],
                "Dla pochodzenia „{$pochodzenie}” adres autora nie prowadzi do profilu konta publikującego.",
            );
        }
    }

    // -----------------------------------------------------------------
    // Twierdzenie 3: pochodzenie nigdzie nie udaje osoby, a stoi w `citation`
    // -----------------------------------------------------------------

    public function test_pochodzenie_nie_udaje_osoby_i_trafia_do_citation(): void
    {
        foreach (self::WROGIE_POCHODZENIA as $pochodzenie) {
            $przepis = $this->publicznyPrzepis($pochodzenie);

            $bloki = $this->blokiJsonLd($przepis);

            // `citation` przyjmuje `Text` (schema.org V30.0), więc nie zmusza
            // nas do zadeklarowania typu encji, którego nie znamy.
            $this->assertSame(
                $pochodzenie,
                $bloki[0]['citation'] ?? null,
                "Pochodzenie „{$pochodzenie}” nie trafiło do `citation`.",
            );

            // I nigdzie w ŻADNYM bloku JSON-LD tej strony nie stoi jako nazwa
            // encji typu `Person` ani `Organization`.
            foreach ($this->wszystkieEncjeNazwane($bloki) as $sciezka => $encja) {
                $this->assertNotSame(
                    $pochodzenie,
                    $encja['name'],
                    "Pochodzenie „{$pochodzenie}” wyszło jako nazwa encji `{$encja['@type']}` w `{$sciezka}`.",
                );
            }
        }
    }

    public function test_puste_pochodzenie_nie_zostawia_pustego_citation(): void
    {
        // `array_filter` w widoku wyrzuca `null`, ale przepuszcza `''` —
        // dlatego widok normalizuje pusty napis do `null`.
        foreach ([null, ''] as $puste) {
            $przepis = $this->publicznyPrzepis($puste);

            $dane = $this->daneStrukturalnePrzepisu($przepis);

            $this->assertArrayNotHasKey('citation', $dane);
            $this->assertSame(self::NAZWA_KONTA, $dane['author']['name']);
        }
    }

    // -----------------------------------------------------------------
    // Drugi stan ekranu: przepis prywatny nie oddaje danych strukturalnych
    // -----------------------------------------------------------------

    public function test_przepis_prywatny_nie_oddaje_zadnych_danych_strukturalnych_przepisu(): void
    {
        $autor = $this->user(self::LOGIN_KONTA, ['display_name' => self::NAZWA_KONTA]);

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'private',
            'published_at' => now(),
            'source_person' => 'Nasze smaki - grupa na Facebooku',
        ]);

        // Prywatny przepis widzi tylko jego autor — i właśnie dlatego trzeba
        // tu być zalogowanym: inaczej odpowiedź byłaby pusta z innego powodu
        // niż ten, którego pilnujemy.
        $html = $this->actingAs($autor)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertNotSame('', $html);
        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('Nasze smaki - grupa na Facebooku', $this->tylkoSkrypty($html));
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function publicznyPrzepis(?string $pochodzenie): Recipe
    {
        // JEDNO konto na cały test, nie jedno na obieg pętli: `username` jest
        // w bazie unikalny, a twierdzenie o adresie potrzebuje loginu znanego
        // z góry. Zmienia się tylko pochodzenie przepisu — bo o nie tu idzie.
        $autor = $this->autor ??= $this->user(
            self::LOGIN_KONTA,
            ['display_name' => self::NAZWA_KONTA],
        );

        return Recipe::factory()->zeZdjeciem()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
            'source_person' => $pochodzenie,
            'source_note' => null,
        ]);
    }

    /**
     * Wszystkie bloki `application/ld+json` ze strony przepisu, zdekodowane.
     *
     * @return list<array<string, mixed>>
     */
    private function blokiJsonLd(Recipe $przepis): array
    {
        $html = $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        preg_match_all(
            '#<script[^>]*type="application/ld\+json"[^>]*>(.*?)</script>#s',
            $html,
            $trafienia,
        );

        $this->assertNotEmpty(
            $trafienia[1],
            'Strona publicznego przepisu nie oddała ani jednego bloku JSON-LD — '
            .'test mierzy zły stan ekranu (D-099, D-106), a nie zawartość danych.',
        );

        return array_map(
            fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $trafienia[1],
        );
    }

    /**
     * Blok `Recipe` — ten, w którym stoi `author`.
     *
     * @return array<string, mixed>
     */
    private function daneStrukturalnePrzepisu(Recipe $przepis): array
    {
        foreach ($this->blokiJsonLd($przepis) as $blok) {
            if (($blok['@type'] ?? null) === 'Recipe') {
                return $blok;
            }
        }

        $this->fail('Na stronie publicznego przepisu nie ma bloku JSON-LD typu `Recipe`.');
    }

    /**
     * Każda encja z nazwą i typem, jaka stoi gdziekolwiek w podanych blokach.
     *
     * Szukamy REKURENCYJNIE i po CAŁEJ stronie, a nie tylko w `author`:
     * twierdzenie brzmi „pochodzenie nigdzie nie udaje osoby", więc test nie
     * może pilnować wyłącznie tego jednego miejsca, w którym błąd stał.
     *
     * @param  list<array<string, mixed>>  $bloki
     * @return array<string, array{'@type': string, name: string}>
     */
    private function wszystkieEncjeNazwane(array $bloki): array
    {
        $znalezione = [];

        $chodz = function (mixed $wezel, string $sciezka) use (&$chodz, &$znalezione): void {
            if (! is_array($wezel)) {
                return;
            }

            $typ = $wezel['@type'] ?? null;
            $nazwa = $wezel['name'] ?? null;

            if (is_string($typ) && is_string($nazwa)) {
                $znalezione[$sciezka] = ['@type' => $typ, 'name' => $nazwa];
            }

            foreach ($wezel as $klucz => $dziecko) {
                $chodz($dziecko, $sciezka === '' ? (string) $klucz : $sciezka.'.'.$klucz);
            }
        };

        foreach ($bloki as $i => $blok) {
            $chodz($blok, 'blok'.$i);
        }

        return $znalezione;
    }

    /**
     * Sama treść elementów `<script>` — żeby twierdzenie o przepisie
     * prywatnym nie łapało wartości pokazanej autorowi w widocznej treści
     * strony (tam ma prawo stać).
     */
    private function tylkoSkrypty(string $html): string
    {
        preg_match_all('#<script[^>]*>(.*?)</script>#s', $html, $trafienia);

        return implode("\n", $trafienia[1]);
    }
}
