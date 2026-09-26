<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Minutnik kroku i zdjęcie kroku — OBIE DROGI ZAPISU.
 *
 * POMIAR, KTÓRY OTWORZYŁ TO ZADANIE (audyt zewnętrzny T12/T24)
 * Tryb gotowania ma kompletny minutnik: interfejs, odliczanie w JS, komunikat
 * dla czytnika ekranu, `RecipeStep::timerLabel()`, render w eksporcie danych
 * i w migawce wersji. Kolumny `recipe_steps.timer_seconds` i
 * `recipe_steps.media_id` istnieją od migracji `create_recipes_tables`
 * (razem z CHECK-iem `timer_seconds IS NULL OR timer_seconds >= 0`).
 * A ŻADNA droga zapisu ich nie ustawiała: formularz nie miał pól, kontroler
 * nie przepuszczał wartości, więc każdy krok każdego przepisu miał
 * `timer_seconds = NULL` i `media_id = NULL` na zawsze.
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU nie sprawdza, czy zdjęcie się zapisuje —
 * sprawdza, czy zapisuje się PRZY SWOIM KROKU po zmianie kolejności i po
 * wyczyszczeniu innego wiersza. Zdjęcie przy złym kroku jest gorsze niż brak
 * zdjęcia: nie wygląda na awarię, więc nikt tego nie zgłosi.
 */
class MinutnikIZdjecieKrokuTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'recipe-wizard';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    // -----------------------------------------------------------------
    // 1. Droga bez JavaScriptu — formularz jednostronicowy
    // -----------------------------------------------------------------

    public function test_formularz_bez_javascriptu_zapisuje_minutnik_i_zdjecie_kroku(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Ziemniaki z piekarnika',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [
                ['text' => 'ziemniaki'],
            ],
            'steps' => [
                ['instruction' => 'Obierz ziemniaki.', 'timer_minutes' => '', 'photo' => UploadedFile::fake()->image('obieranie.jpg', 800, 600)],
                ['instruction' => 'Piecz w piekarniku.', 'timer_minutes' => '45'],
                ['instruction' => 'Wyjmij z piekarnika.', 'timer_minutes' => '2'],
            ],
        ]);

        $odpowiedz->assertSessionHasNoErrors();

        $kroki = $this->krokiPrzepisu('Ziemniaki z piekarnika');

        // POZYTYWNA: minutnik doszedł do bazy — w SEKUNDACH, bo tego czyta
        // widok trybu gotowania (`data-timer-sekundy`).
        $this->assertSame(45 * 60, $kroki[1]->timer_seconds);
        $this->assertSame(2 * 60, $kroki[2]->timer_seconds);

        // POZYTYWNA: zdjęcie kroku doszło do bazy i należy do tego, kto je wysłał.
        $this->assertNotNull($kroki[0]->media_id);
        $this->assertSame($basia->getKey(), Media::findOrFail($kroki[0]->media_id)->owner_id);

        // KONTROLNA: puste pole znaczy „bez minutnika", nie „zero".
        $this->assertNull($kroki[0]->timer_seconds);
        $this->assertNull($kroki[1]->media_id);

        // KONTROLNA: to, co czyta tryb gotowania, mówi po polsku.
        $this->assertSame('45 minut', $kroki[1]->timerLabel());
        $this->assertSame('2 minuty', $kroki[2]->timerLabel());
    }

    public function test_tryb_gotowania_pokazuje_minutnik_i_zdjecie_zapisane_formularzem(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Karkówka z piekarnika',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'karkówka']],
            'steps' => [
                ['instruction' => 'Piecz pod przykryciem.', 'timer_minutes' => '90'],
                ['instruction' => 'Odkryj i dopiecz.', 'timer_minutes' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $przepis = Recipe::where('title', 'Karkówka z piekarnika')->sole();

        // POZYTYWNA: to, co formularz zapisał, jest DOKŁADNIE tym, czym karmi
        // się widok trybu gotowania — atrybut startowy odliczania i zdanie
        // czytane bez JavaScriptu.
        $this->actingAs($basia)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertOk()
            ->assertSee('data-timer-sekundy="5400"', false)
            ->assertSee('Ustaw sobie kuchenny minutnik na 90 minut.');

        // NEGATYWNA: krok bez minutnika nie obiecuje odliczania.
        $this->actingAs($basia)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertOk()
            ->assertDontSee('data-timer-sekundy', false)
            ->assertDontSee('Ustaw sobie kuchenny minutnik');
    }

    // -----------------------------------------------------------------
    // 2. TOŻSAMOŚĆ KROKU — zmiana kolejności nie przestawia zdjęć
    // -----------------------------------------------------------------

    public function test_zmiana_kolejnosci_krokow_nie_przestawia_zdjec_ani_minutnikow(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepisZTrzemaZdjeciami($basia);

        $przed = $this->kroki($przepis);
        $zdjecia = $przed->mapWithKeys(
            static fn (RecipeStep $krok): array => [$krok->instruction => $krok->media_id],
        );

        // Wiersze wracają W ODWROTNEJ KOLEJNOŚCI — każdy ze swoim `id`, swoją
        // treścią i swoim minutnikiem, bez wybierania zdjęć od nowa. Tak
        // wygląda POST po przestawieniu kroków.
        $wiersze = $przed->reverse()->values()->map(static fn (RecipeStep $krok): array => [
            'id' => $krok->getKey(),
            'instruction' => $krok->instruction,
            'timer_minutes' => (string) StepTimer::minutesFromSeconds($krok->timer_seconds),
        ])->all();

        $this->actingAs($basia)->put(route('recipes.update', $przepis->slug), [
            'action' => 'publish',
            'title' => $przepis->title,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'ziemniaki']],
            'steps' => $wiersze,
        ])->assertSessionHasNoErrors();

        $po = $this->kroki($przepis);

        // KONTROLNA: kolejność naprawdę się zmieniła — inaczej ten test nie
        // sprawdzałby niczego.
        $this->assertSame(
            ['Wyjmij z piekarnika.', 'Piecz w piekarniku.', 'Obierz ziemniaki.'],
            $po->pluck('instruction')->all(),
        );
        $this->assertSame([0, 1, 2], $po->pluck('position')->all());

        // POZYTYWNA: KAŻDE zdjęcie zostało przy SWOJEJ treści kroku.
        foreach ($po as $krok) {
            $this->assertSame(
                $zdjecia[$krok->instruction],
                $krok->media_id,
                'Krok „'.$krok->instruction.'” dostał zdjęcie innego kroku.',
            );
        }

        // POZYTYWNA: minutniki też — „Piecz" nadal ma 45 minut, nie 2.
        $this->assertSame(2 * 60, $po[0]->timer_seconds);
        $this->assertSame(45 * 60, $po[1]->timer_seconds);
        $this->assertNull($po[2]->timer_seconds);

        // NEGATYWNA: żadne zdjęcie nie zginęło i żadne się nie zdublowało.
        $this->assertCount(3, $po->pluck('media_id')->filter()->unique());
    }

    public function test_wyczyszczenie_wiersza_nie_przesuwa_zdjec_na_sasiednie_kroki(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepisZTrzemaZdjeciami($basia);

        $przed = $this->kroki($przepis);

        // Człowiek kasuje treść PIERWSZEGO kroku. Pusty wiersz jest przy
        // zapisie pomijany, więc pozostałe dwa kroki zjeżdżają o jedną
        // POZYCJĘ w górę. Dopasowanie zdjęć po pozycji dałoby tu zdjęcie
        // „obierz ziemniaki" krokowi „piecz w piekarniku" — to jest dokładnie
        // ta pomyłka, której nikt by nie zgłosił.
        $wiersze = $przed->map(static fn (RecipeStep $krok, int $i): array => [
            'id' => $krok->getKey(),
            'instruction' => $i === 0 ? '' : $krok->instruction,
            'timer_minutes' => (string) StepTimer::minutesFromSeconds($krok->timer_seconds),
        ])->all();

        $this->actingAs($basia)->put(route('recipes.update', $przepis->slug), [
            'action' => 'publish',
            'title' => $przepis->title,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'ziemniaki']],
            'steps' => $wiersze,
        ])->assertSessionHasNoErrors();

        $po = $this->kroki($przepis);

        // KONTROLNA: krok naprawdę wypadł, a pozycje są ciągłe.
        $this->assertSame(['Piecz w piekarniku.', 'Wyjmij z piekarnika.'], $po->pluck('instruction')->all());
        $this->assertSame([0, 1], $po->pluck('position')->all());

        // POZYTYWNA: oba pozostałe kroki mają SWOJE zdjęcia, nie zdjęcie
        // wiersza, który zniknął.
        $this->assertSame($przed[1]->media_id, $po[0]->media_id);
        $this->assertSame($przed[2]->media_id, $po[1]->media_id);

        // NEGATYWNA: zdjęcie skasowanego kroku nie zostało nikomu podpięte.
        $this->assertNotContains($przed[0]->media_id, $po->pluck('media_id')->all());
    }

    public function test_zwykla_edycja_tytulu_nie_gubi_zdjec_ani_minutnikow_krokow(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepisZTrzemaZdjeciami($basia);

        $przed = $this->kroki($przepis);

        // Formularz edycji renderuje wiersze z bazy — z `id` i z minutami.
        // Tak wygląda POST z osoby, która poprawiła tylko literówkę w nazwie.
        $wiersze = $przed->map(static fn (RecipeStep $krok): array => [
            'id' => $krok->getKey(),
            'instruction' => $krok->instruction,
            'timer_minutes' => (string) StepTimer::minutesFromSeconds($krok->timer_seconds),
        ])->all();

        $this->actingAs($basia)->put(route('recipes.update', $przepis->slug), [
            'action' => 'publish',
            'title' => 'Ziemniaki z piekarnika po babci',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'ziemniaki']],
            'steps' => $wiersze,
        ])->assertSessionHasNoErrors();

        $po = $this->kroki($przepis);

        // POZYTYWNA: zdjęcia i minutniki przeżyły zapis, choć formularz nie
        // przysłał ani jednego pliku (przeglądarka nie umie tego zrobić).
        $this->assertSame($przed->pluck('media_id')->all(), $po->pluck('media_id')->all());
        $this->assertSame($przed->pluck('timer_seconds')->all(), $po->pluck('timer_seconds')->all());

        // KONTROLNA: zapis naprawdę się wykonał.
        $this->assertSame('Ziemniaki z piekarnika po babci', $przepis->refresh()->title);
    }

    public function test_zaznaczenie_usun_to_zdjecie_odpina_je_od_kroku_i_zostawia_krok(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepisZTrzemaZdjeciami($basia);

        $przed = $this->kroki($przepis);

        $wiersze = $przed->map(static fn (RecipeStep $krok, int $i): array => array_filter([
            'id' => $krok->getKey(),
            'instruction' => $krok->instruction,
            'timer_minutes' => (string) StepTimer::minutesFromSeconds($krok->timer_seconds),
            'remove_photo' => $i === 1 ? '1' : null,
        ], static fn ($v): bool => $v !== null))->all();

        $this->actingAs($basia)->put(route('recipes.update', $przepis->slug), [
            'action' => 'publish',
            'title' => $przepis->title,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'ziemniaki']],
            'steps' => $wiersze,
        ])->assertSessionHasNoErrors();

        $po = $this->kroki($przepis);

        // POZYTYWNA: zdjęcie odpięte od kroku, który o to poprosił.
        $this->assertNull($po[1]->media_id);

        // KONTROLNA: krok sam ZOSTAŁ, razem ze swoim minutnikiem.
        $this->assertSame('Piecz w piekarniku.', $po[1]->instruction);
        $this->assertSame(45 * 60, $po[1]->timer_seconds);

        // NEGATYWNA: sąsiedzi nie stracili swoich zdjęć.
        $this->assertSame($przed[0]->media_id, $po[0]->media_id);
        $this->assertSame($przed[2]->media_id, $po[2]->media_id);

        // Samo zdjęcie ZOSTAJE w `media` — właściciel ma je w eksporcie RODO,
        // odpięty został tylko krok.
        $this->assertNotNull(Media::find($przed[1]->media_id));
    }

    public function test_formularz_edycji_pokazuje_minutnik_zdjecie_i_ukryta_tozsamosc_kroku(): void
    {
        $basia = $this->user('basia');
        $przepis = $this->przepisZTrzemaZdjeciami($basia);

        // Zdjęcia pokazujemy WYŁĄCZNIE w stanie `ready` (AGENTS.md §7),
        // a w teście zadanie w tle nie chodzi — doprowadzamy je ręcznie.
        Media::whereIn('id', $this->kroki($przepis)->pluck('media_id'))
            ->update(['status' => Media::STATUS_READY]);

        $kroki = $this->kroki($przepis);

        $strona = $this->actingAs($basia)->get(route('recipes.edit', $przepis->slug))->assertOk();

        // POZYTYWNA: tożsamość każdego kroku wraca ukrytym polem. Bez niej
        // zdjęcie musiałoby być dopasowywane po pozycji.
        foreach ($kroki as $i => $krok) {
            $strona->assertSee('name="steps['.$i.'][id]" value="'.$krok->getKey().'"', false);
        }

        // POZYTYWNA: minutnik wraca w MINUTACH i pod nazwą z NAWIASAMI —
        // kropki w nazwie pola PHP zamienia na podkreślenia, więc
        // `steps.1.timer_minutes` nie trafiłoby do tablicy `steps`.
        $strona->assertSee('name="steps[1][timer_minutes]"', false);
        $strona->assertSee('Ile minut ma trwać ten krok?');
        $strona->assertSee('value="45"', false);

        // NEGATYWNA: sekundy z bazy nie wyciekają do pola dla człowieka.
        $strona->assertDontSee('value="2700"', false);

        // POZYTYWNA: zdjęcie, które krok już ma, jest widoczne — razem
        // z jawną opcją odpięcia go.
        $strona->assertSee('alt="Zdjęcie przy kroku 1"', false);
        $strona->assertSee('Usuń to zdjęcie');

        // NEGATYWNA: CSP jest wymuszające, więc żadnego atrybutu `style`.
        $strona->assertDontSee('style="', false);

        // KONTROLNA: etykieta zdjęcia mówi, że jest nieobowiązkowe.
        $strona->assertSee('Zdjęcie do tego kroku');
    }

    // -----------------------------------------------------------------
    // 3. Kreator Livewire — ta sama funkcja, druga droga
    // -----------------------------------------------------------------

    public function test_podglad_kreatora_odmienia_jedna_minute_po_na(): void
    {
        Livewire::actingAs($this->user('odmiana548'))
            ->test(self::COMPONENT)
            ->set('title', 'Zupa z minutnikiem')
            ->set('ingredients.0.text', 'pomidory')
            ->set('steps.0.instruction', 'Gotuj przez minutę.')
            ->set('steps.0.timer_minutes', '1')
            ->set('step', 4)
            ->assertSee('Ustaw sobie kuchenny minutnik na 1 minutę.')
            ->assertDontSee('Ustaw sobie kuchenny minutnik na 1 minuta.');
    }

    public function test_kreator_zapisuje_minutnik_i_zdjecie_kroku(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Zupa pomidorowa')
            ->set('ingredients.0.text', 'pomidory')
            ->set('steps.0.instruction', 'Ugotuj wywar.')
            ->set('steps.0.timer_minutes', '60')
            ->set('steps.0.photo', UploadedFile::fake()->image('wywar.jpg', 800, 600))
            ->set('steps.1.instruction', 'Dodaj przecier.')
            ->call('publish')
            ->assertHasNoErrors();

        $kroki = $this->krokiPrzepisu('Zupa pomidorowa');

        // POZYTYWNA: minuty z ekranu, sekundy w bazie — ten sam przelicznik
        // co w drodze bez JavaScriptu.
        $this->assertSame(60 * 60, $kroki[0]->timer_seconds);
        $this->assertNotNull($kroki[0]->media_id);
        $this->assertSame($basia->getKey(), Media::findOrFail($kroki[0]->media_id)->owner_id);

        // KONTROLNA: krok bez minutnika i bez zdjęcia zostaje bez nich.
        $this->assertNull($kroki[1]->timer_seconds);
        $this->assertNull($kroki[1]->media_id);
    }

    public function test_krok_trzeci_kreatora_pyta_o_minuty_i_o_zdjecie(): void
    {
        $basia = $this->user('basia');

        $ekran = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Rosół')
            ->set('step', 3);

        // POZYTYWNA: obie drogi zapisu pytają o TO SAMO i tymi samymi słowami.
        $ekran->assertSee('Ile minut ma trwać ten krok?')
            ->assertSee('Zdjęcie do tego kroku')
            ->assertSee('Krok 1: co się robi');

        // NEGATYWNA: CSP jest wymuszające, więc żadnego atrybutu `style`.
        $ekran->assertDontSee('style="', false);

        // KONTROLNA: dopóki kroku nie ma zdjęcia, nie ma też przycisku
        // usuwania — nie obiecujemy akcji, która nie ma czego zrobić.
        $ekran->assertDontSee('Usuń zdjęcie z tego kroku');
    }

    public function test_przeniesienie_kroku_w_kreatorze_przenosi_jego_zdjecie_i_minutnik(): void
    {
        $basia = $this->user('basia');

        $komponent = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Pierogi')
            ->set('ingredients.0.text', 'mąka')
            ->set('steps.0.instruction', 'Zagnieć ciasto.')
            ->set('steps.0.timer_minutes', '10')
            ->set('steps.0.photo', UploadedFile::fake()->image('ciasto.jpg', 800, 600))
            ->set('steps.1.instruction', 'Ugotuj pierogi.')
            ->set('steps.1.timer_minutes', '5');

        $zdjecieCiasta = $komponent->get('steps.0.mediaId');
        $this->assertNotNull($zdjecieCiasta, 'Zdjęcie kroku nie przyjęło się w kreatorze.');

        // Przenosimy „Zagnieć ciasto." w dół. Zdjęcie i minutnik siedzą
        // W WIERSZU, więc mają jechać razem z nim.
        $komponent->call('moveStepDown', 0)
            ->assertSet('steps.0.instruction', 'Ugotuj pierogi.')
            ->assertSet('steps.1.instruction', 'Zagnieć ciasto.')
            ->assertSet('steps.1.mediaId', $zdjecieCiasta)
            ->assertSet('steps.1.timer_minutes', '10')
            // NEGATYWNA: krok, który wjechał na pierwsze miejsce, NIE dostał
            // cudzego zdjęcia.
            ->assertSet('steps.0.mediaId', null)
            ->assertSet('steps.0.timer_minutes', '5')
            ->call('publish')
            ->assertHasNoErrors();

        $kroki = $this->krokiPrzepisu('Pierogi');

        // POZYTYWNA: w bazie kolejność z ekranu, a zdjęcie przy swoim kroku.
        $this->assertSame(['Ugotuj pierogi.', 'Zagnieć ciasto.'], $kroki->pluck('instruction')->all());
        $this->assertNull($kroki[0]->media_id);
        $this->assertSame($zdjecieCiasta, $kroki[1]->media_id);
        $this->assertSame(5 * 60, $kroki[0]->timer_seconds);
        $this->assertSame(10 * 60, $kroki[1]->timer_seconds);
    }

    public function test_kreator_wraca_do_szkicu_ze_zdjeciem_i_minutnikiem_kroku(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Bigos')
            ->set('steps.0.instruction', 'Duś kapustę.')
            ->set('steps.0.timer_minutes', '120')
            ->set('steps.0.photo', UploadedFile::fake()->image('kapusta.jpg', 800, 600));

        $szkic = Recipe::where('title', 'Bigos')->sole();
        $zdjecie = $szkic->steps()->orderBy('position')->firstOrFail()->media_id;
        $this->assertNotNull($zdjecie);

        // Zamknięcie karty i powrót = nowy komponent, ten sam szkic.
        Livewire::actingAs($basia)
            ->test(self::COMPONENT, ['recipeId' => $szkic->getKey()])
            ->assertSet('steps.0.timer_minutes', '120')
            ->assertSet('steps.0.mediaId', $zdjecie)
            // KONTROLNA: pusty wiersz dopisany na końcu nie udaje, że ma zdjęcie.
            ->assertSet('steps.1.mediaId', null);
    }

    public function test_kreator_usuwa_zdjecie_z_kroku_i_zostawia_sam_krok(): void
    {
        $basia = $this->user('basia');

        $komponent = Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Placki')
            ->set('ingredients.0.text', 'ziemniaki')
            ->set('steps.0.instruction', 'Zetrzyj ziemniaki.')
            ->set('steps.0.timer_minutes', '15')
            ->set('steps.0.photo', UploadedFile::fake()->image('tarka.jpg', 800, 600));

        $this->assertNotNull($komponent->get('steps.0.mediaId'));

        $komponent->call('removeStepPhoto', 0)
            ->assertSet('steps.0.mediaId', null)
            // KONTROLNA: krok i minutnik zostają.
            ->assertSet('steps.0.instruction', 'Zetrzyj ziemniaki.')
            ->assertSet('steps.0.timer_minutes', '15')
            ->call('publish')
            ->assertHasNoErrors();

        $kroki = $this->krokiPrzepisu('Placki');

        $this->assertNull($kroki[0]->media_id);
        $this->assertSame(15 * 60, $kroki[0]->timer_seconds);
    }

    // -----------------------------------------------------------------
    // 4. Granice minutnika — żadną drogą do bazy
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function bezsensowneMinutniki(): array
    {
        return [
            'ujemny' => ['-5'],
            'nierealnie duży' => ['20000'],
            'większy niż int' => ['99999999999999999999'],
            'tekst' => ['pół godziny'],
            'z przecinkiem' => ['45,5'],
        ];
    }

    #[DataProvider('bezsensowneMinutniki')]
    public function test_bezsensowny_minutnik_nie_dochodzi_do_bazy_formularzem(string $wartosc): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Przepis z dziwnym minutnikiem',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [['instruction' => 'Gotuj.', 'timer_minutes' => $wartosc]],
        ]);

        // NEGATYWNA: błąd przy POLU tego kroku, nie 500 i nie nad formularzem.
        $odpowiedz->assertSessionHasErrors('steps.0.timer_minutes');

        // NEGATYWNA: przepis w ogóle nie powstał, więc nie ma czego naprawiać
        // w bazie.
        $this->assertSame(0, Recipe::count());
        $this->assertSame(0, RecipeStep::count());
    }

    #[DataProvider('bezsensowneMinutniki')]
    public function test_bezsensowny_minutnik_nie_dochodzi_do_bazy_warstwa_domenowa(string $wartosc): void
    {
        // Formularz to nie jedyna droga: konsola, fabryka i przyszły import
        // wchodzą wprost w akcję domenową. Bramka musi stać TAM.
        $this->expectException(BladDlaCzlowieka::class);

        try {
            app(PublishRecipe::class)->handle(
                author: $this->user('basia'),
                attributes: ['title' => 'Przepis z dziwnym minutnikiem', 'visibility' => 'public', 'source_type' => 'own'],
                ingredients: [['text' => 'woda']],
                steps: [['instruction' => 'Gotuj.', 'timer_minutes' => $wartosc]],
                publish: true,
            );
        } finally {
            // NEGATYWNA: wyjątek poleciał PRZED zapisem, nie po nim.
            $this->assertSame(0, RecipeStep::count());
        }
    }

    public function test_komunikat_o_minutniku_jest_ten_sam_w_formularzu_i_w_warstwie_domenowej(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Przepis z ujemnym minutnikiem',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [['instruction' => 'Gotuj.', 'timer_minutes' => '-5']],
        ])->assertSessionHasErrors(['steps.0.timer_minutes' => StepTimer::KOMUNIKAT_UJEMNY]);

        // Ta sama wartość, druga droga, TO SAMO zdanie. Dwa różne tłumaczenia
        // jednej reguły to dwa razy „dlaczego raz mi mówi tak, a raz inaczej".
        try {
            StepTimer::secondsFromMinutes('-5');
            $this->fail('Ujemny minutnik przeszedł przez bramkę domenową.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(StepTimer::KOMUNIKAT_UJEMNY, $e->getMessage());
        }
    }

    public function test_baza_odrzuca_ujemny_minutnik_wpisany_wprost(): void
    {
        // CHECK w bazie to ostatnia linia obrony — dla drogi, która ominie
        // i formularz, i akcję domenową (seeder, konsola, ręczne SQL).
        $przepis = Recipe::factory()->create(['author_id' => $this->user('basia')->getKey()]);

        $this->expectException(QueryException::class);

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Gotuj.',
            'timer_seconds' => -60,
        ]);
    }

    public function test_kreator_odrzuca_bezsensowny_minutnik_przy_polu_kroku(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Przepis z dziwnym minutnikiem')
            ->set('ingredients.0.text', 'woda')
            ->set('steps.0.instruction', 'Gotuj.')
            ->set('steps.0.timer_minutes', 'pół godziny')
            ->call('publish')
            // NEGATYWNA: błąd przy polu, kreator wraca na krok 3.
            ->assertHasErrors('steps.0.timer_minutes')
            ->assertSet('step', 3)
            // KONTROLNA: wpisane dane NIE ZNIKNĘŁY.
            ->assertSet('steps.0.instruction', 'Gotuj.')
            ->assertSet('title', 'Przepis z dziwnym minutnikiem');

        // Szkic ISTNIEJE — autosave zapisał go, gdy minutnik był jeszcze
        // pusty, i tak ma być (docs/ROADMAP.md pkt 5: przerwanie kreatora nie
        // kasuje niczego, co człowiek wpisał).
        $przepis = Recipe::where('title', 'Przepis z dziwnym minutnikiem')->sole();

        // NEGATYWNA: bezsensowna wartość NIE DOSZŁA do bazy — krok stoi
        // z minutnikiem pustym, a nie z czymkolwiek doklejonym.
        $this->assertNull($this->kroki($przepis)->first()->timer_seconds);

        // NEGATYWNA: publikacja się nie odbyła.
        $this->assertSame(Recipe::STATUS_DRAFT, $przepis->status);
    }

    public function test_zero_minut_znaczy_bez_minutnika_a_nie_minutnik_na_zero(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Przepis z zerem',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [
                ['instruction' => 'Zagotuj.', 'timer_minutes' => '0'],
                ['instruction' => 'Odstaw.', 'timer_minutes' => '3'],
            ],
        ])->assertSessionHasNoErrors();

        $kroki = $this->krokiPrzepisu('Przepis z zerem');

        // POZYTYWNA: zero zapisane jako NULL, bo `timerLabel()` i tak nic dla
        // zera nie pokaże — wiersz z zerem byłby wartością, która wygląda jak
        // ustawiona i nie działa.
        $this->assertNull($kroki[0]->timer_seconds);
        $this->assertNull($kroki[0]->timerLabel());

        // KONTROLNA: prawdziwy minutnik obok nadal działa.
        $this->assertSame(3 * 60, $kroki[1]->timer_seconds);
    }

    // -----------------------------------------------------------------
    // 5. Bezpieczeństwo: UUID w POST-cie nie jest autoryzacją
    // -----------------------------------------------------------------

    public function test_nie_da_sie_podpiac_cudzego_zdjecia_do_wlasnego_kroku(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');

        $cudzeZdjecie = Media::factory()->create(['owner_id' => $zenek->getKey()]);

        // Kreator trzyma `mediaId` w publicznej właściwości `$steps`, a jej
        // klient umie podmienić (`#[Locked]` nie działa na element tablicy).
        // Bramka stoi więc w akcji domenowej.
        try {
            app(PublishRecipe::class)->handle(
                author: $basia,
                attributes: ['title' => 'Przepis z cudzym zdjęciem', 'visibility' => 'public', 'source_type' => 'own'],
                ingredients: [['text' => 'woda']],
                steps: [['instruction' => 'Gotuj.', 'media_id' => (string) $cudzeZdjecie->getKey()]],
                publish: true,
            );
            $this->fail('Cudze zdjęcie dało się podpiąć do własnego kroku.');
        } catch (BladDlaCzlowieka $e) {
            // NEGATYWNA: komunikat mówi, co zrobić, i nie zdradza, że ten
            // identyfikator istnieje.
            $this->assertStringContainsString('Wybierz je jeszcze raz', $e->getMessage());
        }

        // NEGATYWNA: nic nie zostało zapisane z cudzym zdjęciem.
        $this->assertSame(0, RecipeStep::where('media_id', $cudzeZdjecie->getKey())->count());

        // KONTROLNA: WŁASNE zdjęcie tą samą drogą przechodzi.
        $wlasne = Media::factory()->create(['owner_id' => $basia->getKey()]);

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => 'Przepis z własnym zdjęciem', 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: [['text' => 'woda']],
            steps: [['instruction' => 'Gotuj.', 'media_id' => (string) $wlasne->getKey()]],
            publish: true,
        );

        $this->assertSame($wlasne->getKey(), $przepis->steps()->firstOrFail()->media_id);
    }

    public function test_identyfikator_kroku_z_cudzego_przepisu_nic_nie_daje(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');

        $cudzy = $this->przepisZTrzemaZdjeciami($zenek);
        $cudzyKrok = $this->kroki($cudzy)->first();
        $this->assertNotNull($cudzyKrok->media_id);

        $moj = $this->przepisBezZdjec($basia);

        // Podajemy `id` kroku z CUDZEGO przepisu. Mapa tożsamości jest
        // budowana wyłącznie z kroków TEGO przepisu, więc nie ma czego
        // dopasować (AGENTS.md §7: UUID w POST-cie nie jest autoryzacją).
        $this->actingAs($basia)->put(route('recipes.update', $moj->slug), [
            'action' => 'publish',
            'title' => $moj->title,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [
                ['id' => (string) $cudzyKrok->getKey(), 'instruction' => 'Gotuj.', 'timer_minutes' => ''],
            ],
        ])->assertSessionHasNoErrors();

        // NEGATYWNA: żadne cudze zdjęcie nie wjechało do mojego przepisu.
        $this->assertNull($this->kroki($moj->refresh())->first()->media_id);

        // KONTROLNA: cudzy przepis nie stracił swojego zdjęcia.
        $this->assertSame($cudzyKrok->media_id, $this->kroki($cudzy->refresh())->first()->media_id);
    }

    public function test_identyfikator_kroku_ktory_nie_jest_uuid_daje_komunikat_a_nie_blad_bazy(): void
    {
        $basia = $this->user('basia');
        $moj = $this->przepisBezZdjec($basia);

        $this->actingAs($basia)->put(route('recipes.update', $moj->slug), [
            'action' => 'publish',
            'title' => $moj->title,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [['id' => 'nie-uuid; DROP TABLE recipe_steps', 'instruction' => 'Gotuj.']],
        ])->assertSessionHasErrors('steps.0.id');

        // KONTROLNA: tabela żyje, a przepis został nietknięty.
        $this->assertSame('Gotuj wodę.', $this->kroki($moj->refresh())->first()->instruction);
    }

    // -----------------------------------------------------------------
    // 6. Budżet zdjęć kroków na jeden zapis
    // -----------------------------------------------------------------

    public function test_za_duzo_zdjec_krokow_na_raz_konczy_sie_zdaniem_co_zrobic(): void
    {
        $basia = $this->user('basia');
        $limit = LimityZdjec::maksZdjecKrokowNaZapis();

        $kroki = [];

        for ($i = 0; $i <= $limit; $i++) {
            $kroki[] = [
                'instruction' => 'Krok numer '.($i + 1).'.',
                'photo' => UploadedFile::fake()->image("krok{$i}.jpg", 400, 300),
            ];
        }

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Przepis z za wieloma zdjęciami',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => $kroki,
            // NEGATYWNA: komunikat mówi, ile wolno i co zrobić z resztą.
        ])->assertSessionHasErrors(['steps' => LimityZdjec::komunikatZaDuzoZdjecKrokow()]);

        // NEGATYWNA: limit sprawdzony PRZED zapisem, więc nie ma osieroconych
        // wierszy w `media` ani połowy przepisu w bazie.
        $this->assertSame(0, Recipe::count());
        $this->assertSame(0, Media::count());

        // KONTROLNA: dokładnie tyle zdjęć, ile wolno, przechodzi.
        array_pop($kroki);

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Przepis w limicie',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => $kroki,
        ])->assertSessionHasNoErrors();

        $this->assertSame($limit, $this->krokiPrzepisu('Przepis w limicie')->pluck('media_id')->filter()->count());
    }

    public function test_zdjecie_dolaczone_do_pustego_wiersza_nie_zostawia_sieroty_w_media(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Przepis z pustym wierszem',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [
                ['instruction' => 'Gotuj.'],
                // Wiersz bez treści — przy zapisie pomijany W CAŁOŚCI.
                ['instruction' => '  ', 'timer_minutes' => '10', 'photo' => UploadedFile::fake()->image('nikogo.jpg', 400, 300)],
            ],
        ])->assertSessionHasNoErrors();

        $kroki = $this->krokiPrzepisu('Przepis z pustym wierszem');

        // POZYTYWNA: został jeden krok, ten z treścią.
        $this->assertCount(1, $kroki);
        $this->assertSame('Gotuj.', $kroki[0]->instruction);

        // NEGATYWNA: zdjęcie pustego wiersza nie zostało wgrane — nie ma
        // wiersza w `media`, do którego nic nie prowadzi.
        $this->assertSame(0, Media::count());
    }

    public function test_zdjecie_z_wiersza_o_nieciaglym_numerze_nie_gubi_sie(): void
    {
        // Numery wierszy w POST-cie nie muszą być ciągłe (klient wysyła to,
        // co wysyła). Gdyby kontroler przenumerował je PRZED szukaniem
        // plików, zdjęcie z wiersza „5" zniknęłoby bez słowa, a komunikat
        // o błędzie wylądowałby pod cudzym wierszem.
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Przepis z dziurą w numeracji',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [
                0 => ['instruction' => 'Zagotuj.', 'timer_minutes' => '5'],
                5 => ['instruction' => 'Odstaw.', 'timer_minutes' => '3', 'photo' => UploadedFile::fake()->image('odstaw.jpg', 400, 300)],
            ],
        ])->assertSessionHasNoErrors();

        $kroki = $this->krokiPrzepisu('Przepis z dziurą w numeracji');

        // KONTROLNA: kolejność i ciągłość pozycji w bazie.
        $this->assertSame(['Zagotuj.', 'Odstaw.'], $kroki->pluck('instruction')->all());
        $this->assertSame([0, 1], $kroki->pluck('position')->all());

        // POZYTYWNA: zdjęcie trafiło do kroku, który je przysłał.
        $this->assertNotNull($kroki[1]->media_id);

        // NEGATYWNA: i tylko do niego.
        $this->assertNull($kroki[0]->media_id);
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    /** @return Collection<int, RecipeStep> */
    private function kroki(Recipe $recipe): Collection
    {
        return $recipe->steps()->orderBy('position')->get()->values();
    }

    /** @return Collection<int, RecipeStep> */
    private function krokiPrzepisu(string $title): Collection
    {
        return $this->kroki(Recipe::where('title', $title)->sole());
    }

    /**
     * Opublikowany przepis o trzech krokach: każdy ma zdjęcie, dwa mają
     * minutnik. Zapisany PRAWDZIWYM formularzem, bo o niego tu chodzi.
     */
    private function przepisZTrzemaZdjeciami(User $autor): Recipe
    {
        $tytul = 'Ziemniaki z piekarnika '.Str::random(6);

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => $tytul,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'ziemniaki']],
            'steps' => [
                ['instruction' => 'Obierz ziemniaki.', 'timer_minutes' => '', 'photo' => UploadedFile::fake()->image('obieranie.jpg', 400, 300)],
                ['instruction' => 'Piecz w piekarniku.', 'timer_minutes' => '45', 'photo' => UploadedFile::fake()->image('pieczenie.jpg', 400, 300)],
                ['instruction' => 'Wyjmij z piekarnika.', 'timer_minutes' => '2', 'photo' => UploadedFile::fake()->image('wyjmowanie.jpg', 400, 300)],
            ],
        ])->assertSessionHasNoErrors();

        $przepis = Recipe::where('title', $tytul)->sole();

        // Założenie, na którym stoją testy tożsamości: każdy krok MA zdjęcie
        // i wszystkie trzy są różne.
        $this->assertCount(3, $this->kroki($przepis)->pluck('media_id')->filter()->unique());

        return $przepis;
    }

    private function przepisBezZdjec(User $autor): Recipe
    {
        $tytul = 'Woda '.Str::random(6);

        $this->actingAs($autor)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => $tytul,
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => 'woda']],
            'steps' => [['instruction' => 'Gotuj wodę.']],
        ])->assertSessionHasNoErrors();

        return Recipe::where('title', $tytul)->sole();
    }
}
