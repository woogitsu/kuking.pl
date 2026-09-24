<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Issue #1394: blokada autora przepisu zostawiała własne wykonanie na
 * profilu kucharza z przyciskiem prowadzącym do 403.
 *
 * ZMIERZONE PRZED POPRAWKĄ: zakładka „Ugotowane" na własnym profilu dalej
 * pokazywała kartę z tytułem i adresem przepisu oraz „Zobacz i skomentuj",
 * a `cooked.show` i zdjęcie wykonania odmawiały kucharzowi.
 *
 * ROZSTRZYGNIĘCIE: zdjęcie i notatka należą do kucharza, więc lista,
 * szczegół i zdjęcie odpowiadają mu „tak". Tytuł i adres przepisu są treścią
 * autora, z którym jest blokada — nie pokazują się nigdzie.
 */
class WykonaniePoBlokadzieAutoraPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Pierogi babci Hani';

    private const SLUG = 'pierogi-babci-hani';

    public static function kierunkiBlokady(): array
    {
        return [
            'kucharz blokuje autora' => [true],
            'autor blokuje kucharza' => [false],
        ];
    }

    #[DataProvider('kierunkiBlokady')]
    public function test_lista_szczegol_i_zdjecie_odpowiadaja_kucharzowi_tak_samo(bool $kucharzBlokuje): void
    {
        [$autor, $kucharz, $wykonanie, $zdjecie] = $this->scenariusz();

        // KONTROLA DODATNIA: przed blokadą tytuł przepisu jest na karcie.
        $this->zakladka($kucharz)->assertSee(self::TYTUL)->assertSee(self::SLUG);

        $kucharzBlokuje
            ? app(BlockUser::class)->handle($kucharz, $autor)
            : app(BlockUser::class)->handle($autor, $kucharz);
        $kucharz->refresh();

        $zakladka = $this->zakladka($kucharz);
        $naLiscie = str_contains($zakladka->getContent(), route('cooked.show', $wykonanie));

        $status = $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))->getStatusCode();
        $zdjecieDostepne = app(DostepDoZdjecia::class)->moze($kucharz, $zdjecie->fresh());

        $this->assertTrue($naLiscie, 'Własne wykonanie zniknęło z własnej zakładki „Ugotowane".');
        $this->assertSame(200, $status, 'Karta obiecuje „Zobacz i skomentuj", a adres wykonania odmawia.');
        $this->assertTrue($zdjecieDostepne, 'Kucharz stracił dostęp do własnego zdjęcia wykonania.');

        // Treść autora przepisu nie wraca bocznymi drzwiami — ani na liście,
        // ani na stronie wykonania (łącznie z tytułem strony).
        $zakladka->assertDontSee(self::TYTUL)->assertDontSee(self::SLUG)
            ->assertSee('Ten przepis nie jest dla Ciebie dostępny.');
        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))
            ->assertSee('Notatka po blokadzie.')
            ->assertDontSee(self::TYTUL)
            ->assertDontSee(self::SLUG);

        // Przepis dalej zamknięty — poprawka nie rozluźnia dostępu do niego.
        $this->actingAs($kucharz)->get(route('recipes.show', self::SLUG))->assertForbidden();
    }

    /**
     * KONTROLA UJEMNA: furtka jest wyłącznie dla kucharza. Autor przepisu,
     * z którym jest blokada, i jego zdjęcia nie widzi.
     */
    public function test_autor_przepisu_z_blokada_dalej_nie_widzi_wykonania(): void
    {
        [$autor, $kucharz, $wykonanie, $zdjecie] = $this->scenariusz();

        app(BlockUser::class)->handle($kucharz, $autor);
        $autor->refresh();

        $this->actingAs($autor)->get(route('cooked.show', $wykonanie))->assertForbidden();
        $this->assertFalse(app(DostepDoZdjecia::class)->moze($autor, $zdjecie->fresh()));
    }

    /** Bez blokady zakładka nie pokazuje komunikatu o niedostępnym przepisie. */
    public function test_bez_blokady_karta_pokazuje_przepis(): void
    {
        [, $kucharz] = $this->scenariusz();

        $this->zakladka($kucharz)
            ->assertSee(self::TYTUL)
            ->assertDontSee('Ten przepis nie jest dla Ciebie dostępny.');
    }

    /** @return array{User, User, CookedEvent, Media} */
    private function scenariusz(): array
    {
        $autor = $this->user('autorkaprzepisu');
        $kucharz = $this->user('kucharz');

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'title' => self::TYTUL,
            'slug' => self::SLUG,
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => 'Notatka po blokadzie.',
        ]);

        $zdjecie = Media::factory()->create([
            'owner_id' => $kucharz->getKey(),
            'status' => Media::STATUS_READY,
        ]);
        $wykonanie->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return [$autor, $kucharz, $wykonanie, $zdjecie];
    }

    private function zakladka(User $kucharz)
    {
        return $this->actingAs($kucharz)
            ->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane']))
            ->assertOk();
    }
}
