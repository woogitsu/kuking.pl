<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KANAŁ = TO, CO GOŚĆ WIDZI NA STRONIE (#2227).
 *
 * Zakresy widoczności profilu i tagu są prywatnymi metodami kontrolerów,
 * więc `TresciKanalu` ma ich drugą kopię. Druga kopia reguły to w tym
 * repozytorium najczęstsza przyczyna wycieku (komentarz przy
 * `DostepDoZdjecia`), dlatego ten test nie sprawdza reguł po kolei, tylko
 * porównuje WYNIK: dla jednej macierzy treści identyfikatory i kolejność
 * pozycji w kanale mają być dokładnie te, które gość widzi na stronie
 * (`data-klucz` kart). Zmiana zakresu po jednej stronie bez drugiej zapala
 * ten test w obie strony — i wtedy, gdy kanał pokaże za dużo, i wtedy, gdy
 * za mało.
 *
 * Macierz mieści się na pierwszej stronie listy (12 kart), więc paginacja
 * strony nie ucina niczego, co kanał pokazuje.
 */
class KanalyAtomZgodneZeStronaTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function zeStrony(string $adres): array
    {
        $html = (string) $this->get($adres)->assertOk()->getContent();
        preg_match_all('/data-klucz="(?:wpis|przepis)-([0-9a-f-]{36})"/', $html, $trafienia);

        return array_values(array_unique($trafienia[1]));
    }

    /** @return list<string> */
    private function zKanalu(string $adres): array
    {
        $dom = new DOMDocument;
        $this->assertTrue($dom->loadXML((string) $this->get($adres)->assertOk()->getContent()));
        $x = new DOMXPath($dom);
        $x->registerNamespace('a', 'http://www.w3.org/2005/Atom');

        $id = [];
        foreach ($x->query('/a:feed/a:entry/a:id') as $wezel) {
            $id[] = Str::after($wezel->textContent, 'urn:uuid:');
        }

        return $id;
    }

    private int $minuty = 0;

    /** Każda kolejna treść o minutę starsza — kolejność jest jawna. */
    private function wpis(User $autor, array $atrybuty = []): Post
    {
        return Post::factory()->create([
            'author_id' => $autor->getKey(),
            'published_at' => now()->subMinutes(++$this->minuty),
            ...$atrybuty,
        ]);
    }

    private function przepis(User $autor, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'published_at' => now()->subMinutes(++$this->minuty),
            ...$atrybuty,
        ]);
    }

    /**
     * Wpisy jednej osoby we wszystkich stanach, które strony rozróżniają.
     *
     * @return list<Post>
     */
    private function macierzWpisow(User $autor, User $zbanowanyAutorPrzepisu): array
    {
        $ukryty = $this->wpis($autor);
        $ukryty->forceFill(['status' => Post::STATUS_HIDDEN])->save();
        $ukrytyPrzepis = $this->przepis($autor);
        $ukrytyPrzepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        return [
            $this->wpis($autor),
            $this->wpis($autor, ['visibility' => Post::VISIBILITY_FOLLOWERS]),
            $this->wpis($autor, ['visibility' => Post::VISIBILITY_PRIVATE]),
            Post::factory()->draft()->create(['author_id' => $autor->getKey()]),
            $ukryty,
            // Zapowiedzi przepisu (wpis `public`, bez własnej treści).
            $this->wpis($autor, ['body' => null, 'recipe_id' => $this->przepis($autor)->getKey()]),
            $this->wpis($autor, ['body' => null, 'recipe_id' => $this->przepis($autor, ['visibility' => 'private'])->getKey()]),
            $this->wpis($autor, ['body' => null, 'recipe_id' => $ukrytyPrzepis->getKey()]),
            $this->wpis($autor, ['body' => null, 'recipe_id' => $this->przepis($zbanowanyAutorPrzepisu)->getKey()]),
            // Własna treść przy przepisie niewidocznym — wpis zostaje (#1377).
            $this->wpis($autor, ['recipe_id' => $this->przepis($autor, ['visibility' => 'private'])->getKey()]),
            $this->wpis($autor),
        ];
    }

    public function test_kanal_profilu_zgodny_z_profilem_dla_goscia(): void
    {
        $autorka = $this->user('zgodnoscprofil');
        $zbanowany = $this->user('zgodnoscprofilban');
        $this->macierzWpisow($autorka, $zbanowany);
        $zbanowany->ban();

        $strona = $this->zeStrony(route('profile.show', 'zgodnoscprofil'));
        $kanal = $this->zKanalu(route('kanaly.profil', 'zgodnoscprofil'));

        // Kontrola dodatnia: macierz naprawdę ma coś widocznego i coś ukrytego.
        $this->assertGreaterThanOrEqual(3, count($strona));
        $this->assertLessThan(11, count($strona));
        $this->assertSame($strona, $kanal);
    }

    public function test_kanal_tagu_zgodny_ze_strona_tagu_dla_goscia(): void
    {
        $tag = Tag::factory()->create(['name' => 'Zgodność']);
        $aktywna = $this->user('zgodnosctag');
        $zawieszona = $this->user('zgodnosctagzaw');
        $zbanowany = $this->user('zgodnosctagban');

        $wpisy = [
            ...$this->macierzWpisow($aktywna, $zbanowany),
            $this->wpis($zawieszona),
        ];
        foreach ($wpisy as $wpis) {
            $wpis->tags()->attach($tag->getKey());
        }
        $zbanowany->ban();
        $zawieszona->suspend(now()->addWeek());

        $strona = $this->zeStrony(route('tags.show', $tag));
        $kanal = $this->zKanalu(route('kanaly.tag', $tag->slug));

        $this->assertGreaterThanOrEqual(3, count($strona));
        $this->assertLessThan(count($wpisy), count($strona));
        $this->assertSame($strona, $kanal);
    }

    public function test_kanal_zeszytu_zgodny_z_zeszytem_dla_goscia(): void
    {
        $wlascicielka = $this->user('zgodnosczeszyt');
        $inna = $this->user('zgodnosczeszytinna');
        $zbanowany = $this->user('zgodnosczeszytban');
        $zeszyt = Collection::create(['owner_id' => $wlascicielka->getKey(), 'name' => 'Zgodność', 'visibility' => 'public']);

        $przepisy = [
            $this->przepis($inna),
            $this->przepis($inna, ['visibility' => 'private']),
            $this->przepis($inna, ['visibility' => 'followers']),
            $this->przepis($zbanowany),
            $this->przepis($wlascicielka),
        ];
        $wpisy = [
            $this->wpis($inna),
            $this->wpis($inna, ['visibility' => Post::VISIBILITY_PRIVATE]),
            $this->wpis($zbanowany),
            $this->wpis($inna, ['body' => null, 'recipe_id' => $this->przepis($inna, ['visibility' => 'private'])->getKey()]),
        ];
        foreach ($przepisy as $i => $przepis) {
            $zeszyt->recipes()->attach($przepis->getKey(), ['created_at' => now()->subMinutes(2 * $i)]);
        }
        foreach ($wpisy as $i => $wpis) {
            $zeszyt->posts()->attach($wpis->getKey(), ['created_at' => now()->subMinutes(2 * $i + 1)]);
        }
        $zbanowany->ban();

        $strona = $this->zeStrony(route('collections.show', $zeszyt));
        $kanal = $this->zKanalu(route('kanaly.zeszyt', $zeszyt));

        $this->assertGreaterThanOrEqual(3, count($strona));
        // Strona ma dwie listy (przepisy, potem wpisy), kanał jedną oś czasu:
        // porównujemy ZBIÓR, a kolejność kanału sprawdza `KanalyAtomTest`.
        sort($strona);
        sort($kanal);
        $this->assertSame($strona, $kanal);
    }
}
