<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Dodawanie przepisu: sześć kontrolek, reszta po opublikowaniu (issue #364).
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Właściciel zmierzył ekran dodawania na sobie: przepis o ośmiu składnikach
 * i trzech krokach pokazywał około 89 kontrolek (9 pól u góry, 9 kółek
 * wyboru, SIEDEM pól na każdy składnik, pięć na każdy krok). Dowodem, że
 * formularz nie słuchał, było to, co zrobił: wkleił listę składników do pola
 * „Krótko o przepisie". Nie z niezrozumienia podpisu — tylko dlatego, że
 * formularz kazał rozstrzygnąć strukturę przepisu, zanim pozwolił cokolwiek
 * napisać. Człowiek OBCHODZI wtedy formularz, zamiast go wypełniać.
 *
 * CZEGO PILNUJE TEN PLIK — I DLACZEGO AKURAT TEGO
 *
 *  1. Przepis z samym zdjęciem, tytułem i tekstem DA SIĘ opublikować.
 *     To jest zgoda właściciela z 11.09.2026, udzielona wprost i świadomie:
 *     „przepis wolno opublikować bez ani jednego składnika". Za pół roku
 *     nikt nie będzie pamiętał, że była świadoma, i ktoś życzliwy dopisze
 *     `required` z powrotem. Ten test jest jedynym śladem tej decyzji,
 *     który nie wymaga niczyjej pamięci.
 *
 *  2. Baza się NIE zmieniła. Wklejona lista daje tyle wierszy
 *     w `recipe_ingredients`, ile było niepustych linijek, W TEJ SAMEJ
 *     KOLEJNOŚCI. Szukanie po składnikach nadal czyta te same wiersze;
 *     skalowanie porcji pozostaje niewdrożonym planem V2. Znika wpisywanie
 *     po jednym w siedmiu polach, a nie struktura danych.
 *
 *  3. Liczba kontrolek na ekranie dodawania. ASERCJA NA LICZBĘ, nie na
 *     „wygląda prosto": bez niej za miesiąc znów będzie ich dwadzieścia,
 *     dokładana po jednej, z których każda osobno wygląda na uzasadnioną.
 */
class DodawaniePrzepisuSzescKontrolekTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ile kontrolek wolno pokazać na ekranie dodawania.
     *
     * Sześć rzeczy z #364: zdjęcie, tytuł, składniki, przygotowanie,
     * kto ma widzieć, Opublikuj.
     */
    private const LIMIT_KONTROLEK = 6;

    // =================================================================
    // 1. Zgoda właściciela: przepis bez ani jednego składnika
    // =================================================================

    public function test_przepis_z_samym_zdjeciem_tytulem_i_tekstem_da_sie_opublikowac(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        $this->actingAs($basia)
            ->post(route('recipes.store'), [
                'title' => 'Rosół babci Zofii',
                'hero_photo' => UploadedFile::fake()->image('rosol.jpg', 800, 600),
                'skladniki_tekst' => '',
                'przygotowanie_tekst' => 'Kurczaka zalej zimną wodą i gotuj trzy godziny.',
                'visibility' => 'public',
                'action' => 'publish',
            ])
            ->assertSessionHasNoErrors();

        $recipe = Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();

        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertNotNull($recipe->published_at);
        $this->assertCount(0, $recipe->ingredients, 'Przepis bez składników nie ma prawa ich sobie dorobić.');
        $this->assertCount(1, $recipe->steps);
        $this->assertNotNull($recipe->hero_media_id);
    }

    // =================================================================
    // 2. Baza się nie zmienia: tekst → wiersze
    // =================================================================

    public function test_wklejona_lista_skladnikow_daje_tyle_wierszy_ile_niepustych_linii(): void
    {
        $basia = $this->user('basia');

        // Pomiędzy składnikami stoją: pusta linijka, linijka z samymi
        // spacjami i enter na końcu — wszystkie trzy człowiek zostawia
        // odruchowo i żadna nie ma prawa zrobić pustego składnika.
        $wklejone = implode("\n", [
            '1 kurczak, najlepiej zagrodowy',
            '2 marchewki',
            '',
            'pietruszka',
            '   ',
            'kawałek selera',
            'sól do smaku',
            '',
        ]);

        $this->actingAs($basia)
            ->post(route('recipes.store'), [
                'title' => 'Rosół z wklejonej listy',
                'skladniki_tekst' => $wklejone,
                'przygotowanie_tekst' => 'Gotuj.',
                'visibility' => 'public',
                'action' => 'publish',
            ])
            ->assertSessionHasNoErrors();

        $recipe = Recipe::where('title', 'Rosół z wklejonej listy')->firstOrFail();

        $this->assertSame(
            [
                '1 kurczak, najlepiej zagrodowy',
                '2 marchewki',
                'pietruszka',
                'kawałek selera',
                'sól do smaku',
            ],
            $recipe->ingredients->pluck('ingredient_text')->all(),
            'Wiersze w `recipe_ingredients` muszą być tymi samymi wierszami i w tej samej kolejności.',
        );

        // Kolejność jest zapisana W BAZIE, a nie tylko w kolejności odczytu.
        $this->assertSame([0, 1, 2, 3, 4], $recipe->ingredients->pluck('position')->all());
    }

    public function test_pusta_linia_w_przygotowaniu_rozdziela_kroki(): void
    {
        $basia = $this->user('basia');

        // Krok drugi ma W ŚRODKU zwykły enter — to dalej JEDNA czynność,
        // tylko zapisana w dwóch linijkach. Rozbicie jej na dwa kroki byłoby
        // poprawieniem człowieka, o które nie prosił.
        $przygotowanie = "Kurczaka zalej zimną wodą i zagotuj.\n\n"
            ."Wrzuć warzywa.\nGotuj na małym ogniu trzy godziny.\n\n\n"
            .'Posól na końcu.';

        $this->actingAs($basia)
            ->post(route('recipes.store'), [
                'title' => 'Rosół w trzech krokach',
                'skladniki_tekst' => 'kurczak',
                'przygotowanie_tekst' => $przygotowanie,
                'visibility' => 'public',
                'action' => 'publish',
            ])
            ->assertSessionHasNoErrors();

        $recipe = Recipe::where('title', 'Rosół w trzech krokach')->firstOrFail();

        $this->assertSame(
            [
                'Kurczaka zalej zimną wodą i zagotuj.',
                "Wrzuć warzywa.\nGotuj na małym ogniu trzy godziny.",
                'Posól na końcu.',
            ],
            $recipe->steps->pluck('instruction')->all(),
        );
    }

    // =================================================================
    // 3. „Dopisz szczegóły" nie gubi tego, co już jest
    // =================================================================

    public function test_cztery_skladniki_po_dopisaniu_szczegolow_dalej_sa_czterema_i_w_tej_samej_kolejnosci(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'title' => 'Placki ziemniaczane',
            'skladniki_tekst' => "1 kg ziemniaków\n1 cebula\n1 jajko\nsól do smaku",
            'przygotowanie_tekst' => 'Zetrzyj i usmaż.',
            'visibility' => 'public',
            'action' => 'publish',
        ])->assertSessionHasNoErrors();

        $recipe = Recipe::where('title', 'Placki ziemniaczane')->firstOrFail();
        $this->assertCount(4, $recipe->ingredients);

        $kolejnosc = $recipe->ingredients->pluck('ingredient_text')->all();

        // Ekran „Dopisz szczegóły" — te same cztery wiersze wracają POST-em
        // razem z tym, co człowiek właśnie dopisał.
        $this->actingAs($basia)->put(route('recipes.update', $recipe->slug), [
            'title' => 'Placki ziemniaczane',
            'summary' => 'Takie, jakie robiła babcia w piątki.',
            'servings' => 4,
            'prep_minutes' => 20,
            'visibility' => 'public',
            'source_type' => 'family',
            'ingredients' => array_map(
                static fn (string $tekst): array => ['text' => $tekst],
                $kolejnosc,
            ),
            'steps' => [
                ['id' => $recipe->steps->first()->getKey(), 'instruction' => 'Zetrzyj i usmaż.'],
            ],
            'action' => 'publish',
        ])->assertSessionHasNoErrors();

        $recipe->refresh()->load('ingredients');

        $this->assertCount(4, $recipe->ingredients, 'Dopisanie szczegółów nie ma prawa zgubić składnika.');
        $this->assertSame($kolejnosc, $recipe->ingredients->pluck('ingredient_text')->all());
        $this->assertSame('Takie, jakie robiła babcia w piątki.', $recipe->summary);
        $this->assertSame(4.0, $recipe->servings);
    }

    public function test_pusty_opis_przygotowania_mowi_o_tym_przy_swoim_polu(): void
    {
        $basia = $this->user('basia');

        // Błąd ma stać PRZY POLU, w którym jest robota do zrobienia
        // (AGENTS.md §5). Zdanie „opisz krok" przy poprawnie wypełnionej
        // NAZWIE przepisu każe człowiekowi szukać usterki tam, gdzie jej nie
        // ma — a przy grupie 50+ to koniec wypełniania formularza.
        $this->actingAs($basia)
            ->post(route('recipes.store'), [
                'title' => 'Przepis bez przygotowania',
                'skladniki_tekst' => 'kurczak',
                'przygotowanie_tekst' => "   \n\n  ",
                'visibility' => 'public',
                'action' => 'publish',
            ])
            ->assertSessionHasErrors('przygotowanie_tekst');

        $this->assertNull(Recipe::where('title', 'Przepis bez przygotowania')->first());
    }

    // =================================================================
    // 4. Bez martwego przycisku (D-053)
    // =================================================================

    public function test_zaproszenie_do_dopisania_szczegolow_pojawia_sie_tylko_wtedy_gdy_jest_co_dopisac(): void
    {
        Storage::fake('public');
        $basia = $this->user('basia');

        // Przepis z ekranu sześciu rzeczy: kilkanaście pól stoi pustych,
        // więc zaproszenie jest prawdziwe.
        $this->actingAs($basia)->post(route('recipes.store'), [
            'title' => 'Rosół na szybko',
            'skladniki_tekst' => 'kurczak',
            'przygotowanie_tekst' => 'Gotuj.',
            'visibility' => 'public',
            'action' => 'publish',
        ])->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'dopisać szczegóły'));

        // Przepis wysłany z pełnego formularza, w którym wypełniono wszystko.
        // Zaproszenie prowadziłoby tu do ekranu bez ani jednego pustego pola,
        // czyli byłoby przyciskiem, który po kliknięciu nic nie daje.
        $this->actingAs($basia)->post(route('recipes.store'), [
            'title' => 'Rosół opisany do końca',
            'summary' => 'Na niedzielę.',
            'servings' => 6,
            'prep_minutes' => 20,
            'cook_minutes' => 180,
            'difficulty' => 'easy',
            'visibility' => 'public',
            'source_type' => 'family',
            'source_person' => 'od babci Zofii',
            'source_note' => 'Gotowała go w każdą niedzielę.',
            'source_url' => 'https://example.com/rosol',
            'family_since_year' => 1974,
            'hero_photo' => UploadedFile::fake()->image('rosol.jpg', 800, 600),
            'source_scan' => UploadedFile::fake()->image('kartka.jpg', 800, 600),
            'ingredients' => [['text' => 'kurczak']],
            'steps' => [['instruction' => 'Gotuj trzy godziny.']],
            'action' => 'publish',
        ])->assertSessionHas('status', fn (string $status): bool => ! str_contains($status, 'dopisać szczegóły'));
    }

    // =================================================================
    // 5. Liczba kontrolek — asercja na liczbę, nie na wrażenie
    // =================================================================

    public function test_ekran_dodawania_ma_nie_wiecej_niz_szesc_kontrolek(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('recipes.create'))
            ->assertOk()
            ->getContent();

        $kontrolki = $this->kontrolkiFormularza($html);

        // KONTROLA DODATNIA: to naprawdę jest ten formularz, a nie pusta
        // strona, na której zliczanie dałoby zero i test przeszedłby sam
        // z siebie (docs/PULAPKI_TESTOW.md — test bez kontroli dodatniej
        // przechodzi także wtedy, gdy ekranu w ogóle nie ma).
        $this->assertContains('file:hero_photo', $kontrolki);
        $this->assertContains('text:title', $kontrolki);
        $this->assertContains('textarea:skladniki_tekst', $kontrolki);
        $this->assertContains('textarea:przygotowanie_tekst', $kontrolki);
        $this->assertContains('radiogrupa:visibility', $kontrolki);

        $this->assertLessThanOrEqual(
            self::LIMIT_KONTROLEK,
            count($kontrolki),
            'Ekran dodawania przepisu znów rośnie. Widać na nim: '.implode(', ', $kontrolki)
            .'. Sześć rzeczy z #364 to: zdjęcie, tytuł, składniki, przygotowanie, kto ma widzieć, Opublikuj. '
            .'Wszystko inne idzie do „Dopisz szczegóły" PO opublikowaniu.',
        );
    }

    public function test_ekran_dodawania_nie_pyta_o_to_co_poszlo_do_szczegolow(): void
    {
        $html = $this->actingAs($this->user('basia'))
            ->get(route('recipes.create'))
            ->assertOk()
            ->getContent();

        $kontrolki = $this->kontrolkiFormularza($html);

        // Dziewięć pól u góry i dziewięć kółek wyboru z pomiaru w #364.
        // Wymienione z nazwy, bo „mniej niż siedem kontrolek" spełniłby też
        // ekran, na którym zostały złe sześć.
        foreach (['summary', 'servings', 'prep_minutes', 'cook_minutes', 'difficulty',
            'source_type', 'source_person', 'source_note', 'source_url',
            'family_since_year', 'source_scan'] as $pole) {
            foreach ($kontrolki as $kontrolka) {
                $this->assertStringNotContainsString(
                    ':'.$pole,
                    $kontrolka,
                    'Pole „'.$pole.'” wróciło na ekran dodawania. Jego miejsce jest w „Dopisz szczegóły”.',
                );
            }
        }
    }

    // =================================================================
    // Narzędzie
    // =================================================================

    /**
     * Kontrolki formularza przepisu — po jednej na DECYZJĘ człowieka.
     *
     * Grupa kółek wyboru o jednej nazwie liczy się RAZ, bo jest jednym
     * pytaniem z kilkoma odpowiedziami („kto ma widzieć ten przepis?").
     * Tak samo liczył pomiar w #364, który dał 89. Pola ukryte (`@csrf`,
     * `_method`, tożsamość kroku) nie liczą się wcale — człowiek ich nie
     * widzi i o niczym przy nich nie decyduje.
     *
     * @return list<string>
     */
    private function kontrolkiFormularza(string $html, string $id = 'formularz-przepisu'): array
    {
        $dom = new DOMDocument;
        $poprzednie = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzednie);

        $xpath = new DOMXPath($dom);
        $form = $xpath->query("//*[@id='{$id}']")->item(0);

        $this->assertInstanceOf(
            DOMElement::class,
            $form,
            'Na stronie nie ma formularza o identyfikatorze „'.$id.'” — bez niego zliczanie kontrolek nie mierzy niczego.',
        );

        $kontrolki = [];
        $licznik = 0;

        foreach ($xpath->query('.//input | .//textarea | .//select | .//button', $form) as $element) {
            /** @var DOMElement $element */
            $znacznik = strtolower($element->nodeName);
            $typ = strtolower($element->getAttribute('type'));
            $nazwa = $element->getAttribute('name');

            if ($znacznik === 'input' && $typ === 'hidden') {
                continue;
            }

            $klucz = match (true) {
                $znacznik === 'input' && $typ === 'radio' => 'radiogrupa:'.$nazwa,
                $znacznik === 'input' => ($typ === '' ? 'text' : $typ).':'.$nazwa,
                $znacznik === 'button' => 'przycisk:'.($nazwa !== '' ? $nazwa : ++$licznik),
                default => $znacznik.':'.$nazwa,
            };

            $kontrolki[$klucz] = true;
        }

        return array_keys($kontrolki);
    }
}
