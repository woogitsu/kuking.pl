<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Jeden bardzo aktywny autor nie może wypchnąć wszystkich innych z tablicy
 * dnia (audyt zewnętrzny U04).
 *
 * CO BYŁO ZEPSUTE
 * `DailyBoard::automaticPosts()` pobierało 24 najnowsze wpisy (`POSTS * 6`)
 * i DOPIERO POTEM odsiewało powtórzonych autorów przez `unique('author_id')`.
 * Komentarz w kodzie nazywał to świadomym kompromisem — „pobieramy z zapasem
 * i dopiero potem odsiewamy" — ale zapas 6× jest ZGADYWANY, nie
 * gwarantowany.
 *
 * Skutek: jeśli jedna osoba opublikuje 24 najnowsze wpisy, odsiew zostawia
 * z nich JEDEN, a tablica pokazuje jedną kartę zamiast czterech — mimo że
 * inne osoby mają dostępne, tylko starsze wpisy. Przy społeczności liczonej
 * w dziesiątkach osób i jednym gospodarzu publikującym codziennie
 * (`docs/product/COLD_START.md` §4.2 każe mu to robić) to nie jest przypadek
 * teoretyczny — to jest wzorzec wpisany w plan startu.
 *
 * DLACZEGO TO BOLI WŁAŚNIE TUTAJ
 * Tablica dnia jest tym, co nowa osoba widzi na stronie głównej, zanim
 * kogokolwiek obserwuje. Pusta albo jednoautorska tablica mówi jej „nic tu
 * się nie dzieje" dokładnie w momencie, w którym `COLD_START.md` §6.1
 * próbuje ją przekonać, że dzieje się.
 */
class TablicaDniaNieChowaAutorowTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, Carbon $kiedy): Post
    {
        return Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Obiad '.$kiedy->toDateTimeString(),
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $kiedy,
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: Collection} */
    private function tablica(): array
    {
        $wynik = app(DailyBoard::class)->forViewer(null);

        return [$wynik, $wynik['posts']];
    }

    /**
     * KONTROLA. Przy równym rozkładzie tablica pokazuje cztery wpisy od
     * czterech osób — bez tego test niżej mógłby przechodzić dlatego, że
     * tablica jest z innego powodu pusta.
     */
    public function test_przy_rownym_rozkladzie_tablica_ma_cztery_wpisy(): void
    {
        foreach (['ala', 'bela', 'cela', 'dela', 'ela'] as $i => $nazwa) {
            $this->wpis($this->user($nazwa), now()->subHours($i + 1));
        }

        [, $wpisy] = $this->tablica();

        $this->assertCount(4, $wpisy, 'Tablica nie pokazała czterech wpisów przy pięciu dostępnych autorach.');
        $this->assertSame(4, $wpisy->pluck('author_id')->unique()->count(), 'Tablica powtórzyła autora.');
    }

    /**
     * WŁAŚCIWY POMIAR. Jeden autor zajmuje wszystkie najnowsze pozycje.
     *
     * 30 wpisów, czyli więcej niż zapas `POSTS * 6 = 24`. Przed naprawą
     * odsiew zostawiał z nich jeden i tablica miała JEDEN wpis. Po naprawie
     * musi mieć cztery, od czterech różnych osób.
     */
    public function test_jeden_aktywny_autor_nie_wypycha_pozostalych(): void
    {
        $gadula = $this->user('gadula');

        for ($i = 0; $i < 30; $i++) {
            $this->wpis($gadula, now()->subMinutes($i + 1));
        }

        // Trzy inne osoby ze starszymi wpisami — dostępnymi, tylko dalej
        // w kolejce.
        foreach (['basia', 'marek', 'ania'] as $i => $nazwa) {
            $this->wpis($this->user($nazwa), now()->subDays($i + 2));
        }

        [, $wpisy] = $this->tablica();

        $this->assertCount(
            4,
            $wpisy,
            'Tablica pokazała mniej niż cztery wpisy, choć czterech autorów miało dostępne treści. '
            .'Jeden aktywny autor wypchnął pozostałych z zapasu, na którym stoi odsiew.',
        );

        $this->assertSame(
            4,
            $wpisy->pluck('author_id')->unique()->count(),
            'Tablica powtórzyła autora.',
        );

        // Gaduła MA prawo być na tablicy — chodzi o to, żeby był tam RAZ,
        // nie żeby wypadł.
        $this->assertTrue(
            $wpisy->pluck('author_id')->contains($gadula->getKey()),
            'Najaktywniejszy autor wypadł z tablicy zamiast zostać na niej raz.',
        );
    }

    /**
     * Z każdego autora bierzemy jego NAJNOWSZY wpis, nie dowolny. Inaczej
     * tablica pokazywałaby stare dania osób, które publikowały dziś.
     */
    public function test_z_kazdego_autora_najnowszy_wpis(): void
    {
        $basia = $this->user('basia');

        $stary = $this->wpis($basia, now()->subDays(5));
        $nowy = $this->wpis($basia, now()->subMinutes(5));

        $this->user('marek');

        [, $wpisy] = $this->tablica();

        $this->assertTrue($wpisy->contains('id', $nowy->getKey()), 'Tablica wzięła stary wpis autora zamiast najnowszego.');
        $this->assertFalse($wpisy->contains('id', $stary->getKey()));
    }

    /**
     * Kolejność tablicy zostaje chronologiczna — malejąco po dacie
     * publikacji. To nie jest ranking popularności i ma nim nie być
     * (`docs/product/COLD_START.md` §6.2: „kolejność chronologiczna, nie
     * »popularne«").
     */
    public function test_kolejnosc_zostaje_chronologiczna(): void
    {
        $this->wpis($this->user('najstarsza'), now()->subDays(3));
        $this->wpis($this->user('srednia'), now()->subDays(2));
        $this->wpis($this->user('najnowsza'), now()->subHour());

        [, $wpisy] = $this->tablica();

        $daty = $wpisy->pluck('published_at')->map(fn ($d) => $d->timestamp)->all();
        $posortowane = $daty;
        rsort($posortowane);

        $this->assertSame($posortowane, $daty, 'Tablica nie jest uporządkowana od najnowszego wpisu.');
    }

    /**
     * Wpisy niepubliczne i od kont nieaktywnych nie mogą wejść na tablicę —
     * to jest powierzchnia PROMUJĄCA treść nieznajomym, więc próg jest
     * surowszy niż wejście na adres wpisu (audyt A5).
     */
    public function test_tablica_nie_promuje_tresci_ktorej_nie_wolno(): void
    {
        $zbanowany = $this->user('zbanowany');
        $this->wpis($zbanowany, now()->subMinutes(1));
        $zbanowany->forceFill(['status' => User::STATUS_BANNED])->save();

        $prywatna = $this->user('prywatna');
        Post::create([
            'author_id' => $prywatna->getKey(),
            'body' => 'Tylko dla mnie',
            'visibility' => Post::VISIBILITY_PRIVATE,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subMinutes(2),
        ]);

        $jawna = $this->user('jawna');
        $widoczny = $this->wpis($jawna, now()->subMinutes(3));

        [, $wpisy] = $this->tablica();

        $this->assertSame([$widoczny->getKey()], $wpisy->pluck('id')->all(), 'Na tablicę weszła treść, której nie wolno promować.');
    }
}
