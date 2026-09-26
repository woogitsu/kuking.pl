<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresja issue #756, druga granica: odhaczenie kroku w trybie gotowania
 * szło po NUMERZE kroku z formularza (`krok=2`), a kontroler dobierał krok
 * z AKTUALNEJ listy po tej pozycji. Jeśli autor przestawił kroki między
 * otwarciem strony a kliknięciem „Oznacz krok jako zrobiony”, odhaczała
 * się inna czynność niż ta, którą gotujący widział na ekranie.
 *
 * Teraz formularz niesie `krok_id` (UUID kroku) i kontroler szuka kroku
 * po tożsamości wśród kroków TEGO przepisu. Krok, którego już nie ma,
 * albo formularz bez `krok_id` → jawna odmowa z komunikatem, nic nie
 * zostaje oznaczone. Zmieniona treść kroku przy tym samym `id` (edycja
 * w miejscu, #913) zachowuje odhaczenie — tożsamość wyznacza formularz
 * edycji, nie podobieństwo tekstu ani numer.
 */
class OdhaczanieKrokuPoTozsamosciTest extends TestCase
{
    use RefreshDatabase;

    private const ZROBIONE = 'Zrobione ✓';

    private const DO_ZROBIENIA = 'Oznacz krok jako zrobiony';

    private const ODMOWA = 'Przepis zmienił się, odkąd otworzono ten krok';

    /** @return array{0: User, 1: Recipe, 2: array<int, string>} autorka, przepis, id kroków A/B/C */
    private function przepisABC(string $nick): array
    {
        $autorka = $this->user($nick);

        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Zupa ogórkowa'],
            steps: [
                ['instruction' => 'Krok A: obierz ziemniaki.'],
                ['instruction' => 'Krok B: zetrzyj ogórki.'],
                ['instruction' => 'Krok C: zabiel śmietaną.'],
            ],
            publish: true,
        );

        return [$autorka, $przepis, $przepis->steps()->orderBy('position')->pluck('id')->all()];
    }

    /** @param array<int, array{id?: string, instruction: string}> $kroki */
    private function zapiszPonownie(User $autorka, Recipe $przepis, array $kroki, string $tytul = 'Zupa ogórkowa'): void
    {
        app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => $tytul],
            steps: $kroki,
            publish: true,
            existing: $przepis,
        );
    }

    private function kluczSesji(Recipe $przepis): string
    {
        return 'gotowanie.'.$przepis->getKey().'.zrobione';
    }

    public function test_formularz_odhaczenia_niesie_id_pokazanego_kroku(): void
    {
        [, $przepis, [, $b]] = $this->przepisABC('autorka756d');

        $this->actingAs($this->user('gotujacy756d'))
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertOk()
            ->assertSee('name="krok_id" value="'.$b.'"', false);
    }

    /**
     * Scenariusz 4 z issue: GET pokazuje B na pozycji 2, autorka zmienia
     * kolejność na B/A/C, potem przychodzi POST starego formularza
     * (`krok=2`, `krok_id=B`). Ma się odhaczyć B — nigdy A, które teraz
     * stoi na pozycji 2.
     */
    public function test_stary_formularz_po_zmianie_kolejnosci_odhacza_widziany_krok_nie_pozycje(): void
    {
        [$autorka, $przepis, [$a, $b, $c]] = $this->przepisABC('autorka756e');
        $gotujacy = $this->user('gotujacy756e');

        $this->actingAs($gotujacy)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertSee('Krok B: zetrzyj ogórki.');

        $this->zapiszPonownie($autorka, $przepis, [
            ['id' => $b, 'instruction' => 'Krok B: zetrzyj ogórki.'],
            ['id' => $a, 'instruction' => 'Krok A: obierz ziemniaki.'],
            ['id' => $c, 'instruction' => 'Krok C: zabiel śmietaną.'],
        ]);

        $this->actingAs($gotujacy)
            ->post(route('cooking.zaznacz', $przepis->slug), ['krok' => 2, 'krok_id' => $b, 'zrobiono' => 1])
            // Wracamy tam, gdzie B stoi TERAZ, żeby człowiek widział skutek swojego kliknięcia.
            ->assertRedirect(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertSessionHas($this->kluczSesji($przepis), [$b]);

        $this->actingAs($gotujacy)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertSee('Krok A: obierz ziemniaki.')
            ->assertSee(self::DO_ZROBIENIA)
            ->assertDontSee(self::ZROBIONE);

        $this->actingAs($gotujacy)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertSee('Krok B: zetrzyj ogórki.')
            ->assertSee(self::ZROBIONE);
    }

    /** Scenariusz 5: usunięty krok — jawna odmowa, nic nie przechodzi na krok, który zajął jego numer. */
    public function test_stary_formularz_usunietego_kroku_nic_nie_odhacza_i_mowi_co_zrobic(): void
    {
        [$autorka, $przepis, [$a, $b, $c]] = $this->przepisABC('autorka756f');
        $gotujacy = $this->user('gotujacy756f');

        $this->zapiszPonownie($autorka, $przepis, [
            ['id' => $a, 'instruction' => 'Krok A: obierz ziemniaki.'],
            ['id' => $c, 'instruction' => 'Krok C: zabiel śmietaną.'],
        ]);

        $this->actingAs($gotujacy)
            ->post(route('cooking.zaznacz', $przepis->slug), ['krok' => 2, 'krok_id' => $b, 'zrobiono' => 1])
            ->assertRedirect(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::ODMOWA))
            ->assertSessionMissing($this->kluczSesji($przepis));

        $this->actingAs($gotujacy)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))
            ->assertSee('Krok C: zabiel śmietaną.')
            ->assertDontSee(self::ZROBIONE);
    }

    /** Formularz otwarty przed wdrożeniem (bez `krok_id`) nie odhacza po samym numerze. */
    public function test_formularz_bez_id_kroku_nie_odhacza_po_samym_numerze(): void
    {
        [, $przepis] = $this->przepisABC('autorka756g');

        $this->actingAs($this->user('gotujacy756g'))
            ->post(route('cooking.zaznacz', $przepis->slug), ['krok' => 1, 'zrobiono' => 1])
            ->assertRedirect(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::ODMOWA))
            ->assertSessionMissing($this->kluczSesji($przepis));
    }

    /** Id kroku z INNEGO przepisu nie pasuje — przynależność kroku do przepisu zostaje sprawdzona. */
    public function test_id_kroku_z_innego_przepisu_nie_odhacza_niczego(): void
    {
        [, $przepis] = $this->przepisABC('autorka756h');
        [, , [$obcy]] = $this->przepisABC('autorka756i');

        $this->actingAs($this->user('gotujacy756h'))
            ->post(route('cooking.zaznacz', $przepis->slug), ['krok' => 1, 'krok_id' => $obcy, 'zrobiono' => 1])
            ->assertSessionHas('status', fn ($s) => str_contains($s, self::ODMOWA))
            ->assertSessionMissing($this->kluczSesji($przepis));
    }

    /**
     * Scenariusze 1–3: A i B odhaczone, autorka zmienia TYLKO tytuł,
     * kroki wracają z formularza z tymi samymi `id`. Id przed i po są
     * te same, a HTML trybu gotowania nadal pokazuje A i B jako zrobione.
     * Przy okazji jawne zachowanie ze scenariusza 5: krok C ze zmienioną
     * treścią, ale tym samym `id`, zachowuje swoją tożsamość.
     */
    public function test_zmiana_samego_tytulu_zachowuje_id_i_odhaczenia_w_html(): void
    {
        [$autorka, $przepis, [$a, $b, $c]] = $this->przepisABC('autorka756j');
        $gotujacy = $this->user('gotujacy756j');

        foreach ([[1, $a], [2, $b]] as [$numer, $id]) {
            $this->actingAs($gotujacy)
                ->post(route('cooking.zaznacz', $przepis->slug), ['krok' => $numer, 'krok_id' => $id, 'zrobiono' => 1])
                ->assertSessionHasNoErrors();
        }

        $this->zapiszPonownie($autorka, $przepis, [
            ['id' => $a, 'instruction' => 'Krok A: obierz ziemniaki.'],
            ['id' => $b, 'instruction' => 'Krok B: zetrzyj ogórki.'],
            ['id' => $c, 'instruction' => 'Krok C: zabiel śmietaną 18%.'],
        ], tytul: 'Zupa ogórkowa babci');

        $this->assertSame([$a, $b, $c], $przepis->steps()->orderBy('position')->pluck('id')->all());

        foreach ([1, 2] as $numer) {
            $this->actingAs($gotujacy)
                ->get(route('cooking.show', [$przepis->slug, 'krok' => $numer]))
                ->assertSee(self::ZROBIONE);
        }

        $this->actingAs($gotujacy)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 3]))
            ->assertSee('Krok C: zabiel śmietaną 18%.')
            ->assertSee(self::DO_ZROBIENIA)
            ->assertDontSee(self::ZROBIONE);
    }
}
