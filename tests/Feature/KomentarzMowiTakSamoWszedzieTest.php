<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Za długi komentarz brzmi tak samo pod wpisem, pod przepisem i pod
 * wykonaniem.
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * Trzy ekrany mają ten sam formularz komentarza (`x-comment-thread`), ale
 * trzy różne kontrolery — i tylko `PostController` miał własny komunikat
 * dla `body.max`. Sondą przez HTTP:
 *
 *   /wpisy/{uuid}      „Ten komentarz jest za długi. Zmieść się w 4000 znakach."
 *   /przepisy/{slug}   „Pole «treść» jest za długie — może mieć najwyżej 4000 znaków."
 *   /ugotowane/{uuid}  jak wyżej — tekst frameworka, nie głos Kuking
 *
 * `docs/brand/COPY_STYLE.md` i `AGENTS.md` §5 chcą komunikatu, który mówi
 * CO ZROBIĆ. „Zmieść się w 4000 znakach" to mówi; „pole jest za długie"
 * opisuje stan i jeszcze nazywa to pole „treścią", choć na ekranie stoi
 * przy nim napis „Napisz komentarz".
 *
 * CZEGO TU NIE MA, BO OKAZAŁO SIĘ NIEPRAWDĄ
 * Obchód strony zgłosił, że pod przepisem komunikat nie pokazuje się WCALE,
 * a wpisany tekst przepada. Sprawdziłem to sondą HTTP i jest inaczej:
 * `x-field` renderuje `field-error` i przywraca treść z `old()` na obu
 * ekranach. Zgłoszenie było przesadzone i nie naprawiam rzeczy, której nie
 * ma — ale zostawiam tu ślad pomiaru, żeby nikt nie wracał do tego drugi raz.
 * Test niżej sprawdza więc OBIE rzeczy: brzmienie i to, że tekst zostaje.
 */
class KomentarzMowiTakSamoWszedzieTest extends TestCase
{
    use RefreshDatabase;

    private const KOMUNIKAT = 'Ten komentarz jest za długi. Zmieść się w 4000 znakach.';

    private function tekstBledu(mixed $bledy): string
    {
        return match (true) {
            $bledy instanceof ViewErrorBag, $bledy instanceof MessageBag => (string) $bledy->first('body'),
            is_array($bledy) => (string) json_encode($bledy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => '',
        };
    }

    private function autorITresc(): array
    {
        $autor = $this->user('autortresci');
        $piszacy = $this->user('piszacy');

        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $autor->getKey(),
        ]);

        return [$piszacy, $przepis, $wpis, $wykonanie];
    }

    public function test_wszystkie_trzy_ekrany_mowia_to_samo_o_za_dlugim_komentarzu(): void
    {
        [$piszacy, $przepis, $wpis, $wykonanie] = $this->autorITresc();

        $ekrany = [
            'wpis' => route('posts.comment', $wpis),
            'przepis' => route('recipes.comment', $przepis->slug),
            'wykonanie' => route('cooked.comment', $wykonanie),
        ];

        foreach ($ekrany as $nazwa => $adres) {
            $odpowiedz = $this->actingAs($piszacy)->post($adres, ['body' => str_repeat('a', 5000)]);

            $this->assertStringContainsString(
                self::KOMUNIKAT,
                $this->tekstBledu(self::sesjaPrzekierowania($odpowiedz)->get('errors')),
                "Ekran „{$nazwa}” mówi o za długim komentarzu inaczej niż pozostałe.",
            );
        }
    }

    /**
     * Kontrola: komunikat o PUSTYM komentarzu też jest wszędzie ten sam —
     * ta linia stała w kontrolerach od początku i ma tak zostać.
     */
    public function test_kontrola_pusty_komentarz_tez_mowi_wszedzie_to_samo(): void
    {
        [$piszacy, $przepis, $wpis, $wykonanie] = $this->autorITresc();

        foreach ([
            route('posts.comment', $wpis),
            route('recipes.comment', $przepis->slug),
            route('cooked.comment', $wykonanie),
        ] as $adres) {
            $odpowiedz = $this->actingAs($piszacy)->post($adres, ['body' => '']);

            $this->assertStringContainsString(
                'Napisz coś, zanim wyślesz komentarz.',
                $this->tekstBledu(self::sesjaPrzekierowania($odpowiedz)->get('errors')),
            );
        }
    }

    /**
     * Poprawnie wpisany tekst NIE ZNIKA po odbiciu — `AGENTS.md` §5.
     *
     * To jest ta część, o której obchód strony powiedział nieprawdę.
     * Zostawiam ją zmierzoną, żeby następnym razem nie trzeba było
     * sprawdzać od zera.
     */
    public function test_odbity_komentarz_wraca_do_formularza_razem_z_komunikatem(): void
    {
        [$piszacy, $przepis] = $this->autorITresc();

        $dlugi = str_repeat('a', 5000);

        $this->actingAs($piszacy)
            ->from(route('recipes.show', $przepis->slug))
            ->post(route('recipes.comment', $przepis->slug), ['body' => $dlugi]);

        $strona = $this->actingAs($piszacy)->get(route('recipes.show', $przepis->slug))->getContent();

        $this->assertStringContainsString('field-error', (string) $strona, 'Na stronie przepisu nie widać komunikatu błędu.');
        $this->assertStringContainsString(self::KOMUNIKAT, (string) $strona);
        $this->assertStringContainsString(str_repeat('a', 500), (string) $strona, 'Wpisany komentarz przepadł po odbiciu.');
    }
}
