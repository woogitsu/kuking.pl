<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Krok „Kogo chcesz obserwować?”: ukryte pary nazwa–ID NIEZAZNACZONYCH
 * osób nie mogą blokować poprawnego wyboru (issue #1600).
 *
 * Formularz renderuje `oczekiwani[nazwa]` przy KAŻDEJ widocznej osobie —
 * wcześniej wybranych, wynikach szukania i polecanych. `saveFollows()`
 * trzymało sufit 20 na CAŁEJ tej tablicy, więc 8 zachowanych + 5 wyników
 * + 8 polecanych = 21 pól technicznych kończyło się `oczekiwani.max`, choć
 * zaznaczonych było mniej niż 20.
 *
 * Testy idą przez WYRENDEROWANY formularz (po dwóch wyszukiwaniach
 * z zachowanym wyborem), a nie przez ręcznie złożone żądanie — błąd żył
 * właśnie w tym, ile pól ekran sam produkuje.
 */
class OnboardingNiezaznaczeniNieBlokujaWyboruTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, User> */
    private array $ludzie = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Osiem osób do wcześniejszego wyboru (bez wpisów — nie trafią
        // do polecanych), pięć do wyników kolejnego szukania i osiem
        // polecanych autorów z wpisami.
        foreach ([...$this->nazwy('aldona', 5), ...$this->nazwy('bogna', 3), ...$this->nazwy('szukana', 5)] as $nazwa) {
            $this->ludzie[$nazwa] = $this->user($nazwa);
        }

        foreach ($this->nazwy('kucharz', 8) as $i => $nazwa) {
            $this->ludzie[$nazwa] = $this->user($nazwa);
            Post::factory()->create([
                'author_id' => $this->ludzie[$nazwa]->getKey(),
                'status' => Post::STATUS_PUBLISHED,
                'visibility' => 'public',
                'published_at' => now()->subDays($i + 1),
            ]);
        }
    }

    public function test_osiem_zachowanych_piec_wynikow_i_osiem_polecanych_da_sie_wyslac(): void
    {
        $widz = $this->user('widz');
        $pola = $this->formularzPoWyszukiwaniach($widz);

        // 8 zachowanych + 5 wyników + 8 polecanych = 21 ukrytych par.
        $this->assertCount(21, array_filter(array_keys($pola), fn ($k) => str_starts_with($k, 'oczekiwani[')));

        // Człowiek dozaznacza dwa wyniki: łącznie 10 osób, w limicie.
        $wybrane = [...$this->nazwy('aldona', 5), ...$this->nazwy('bogna', 3), 'szukana1', 'szukana2'];

        $this->actingAs($widz)->from(route('onboarding.people'))
            ->post(route('onboarding.people'), $this->zWyborem($pola, $wybrane))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('onboarding.done'));

        $this->assertEqualsCanonicalizing(
            array_map(fn ($n) => $this->ludzie[$n]->getKey(), $wybrane),
            $widz->following()->pluck('users.id')->all(),
        );
    }

    public function test_zmiana_wlasciciela_nazwy_nadal_pomija_tylko_te_osobe(): void
    {
        $widz = $this->user('widz');
        $pola = $this->formularzPoWyszukiwaniach($widz);

        Profile::where('user_id', $this->ludzie['aldona1']->getKey())->update(['username' => 'aldona_stara']);
        $nowa = $this->user('aldona1');

        $this->actingAs($widz)->from(route('onboarding.people'))
            ->post(route('onboarding.people'), $this->zWyborem($pola, [...$this->nazwy('aldona', 5), 'szukana1']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('onboarding.done'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'Nazwa „aldona1” należy teraz do innej osoby'));

        $obserwowani = $widz->following()->pluck('users.id')->all();
        $this->assertNotContains($nowa->getKey(), $obserwowani);
        $this->assertNotContains($this->ludzie['aldona1']->getKey(), $obserwowani);
        $this->assertCount(5, $obserwowani);
    }

    public function test_ponad_dwadziescia_zaznaczen_nadal_odrzucone_czytelnym_komunikatem(): void
    {
        $widz = $this->user('widz');
        $pola = $this->formularzPoWyszukiwaniach($widz);
        $wszyscy = array_keys($this->ludzie); // 21 osób widocznych na ekranie

        $this->actingAs($widz)->from(route('onboarding.people'))
            ->post(route('onboarding.people'), $this->zWyborem($pola, $wszyscy))
            ->assertRedirect(route('onboarding.people'))
            ->assertSessionHasErrors(['follow' => 'Zaznacz najwyżej 20 osób. Odznacz pozostałe i kliknij „Dalej”.']);

        $this->assertDatabaseCount('follows', 0);
    }

    /**
     * Dwa wyszukiwania z zachowanym wyborem, jak robi to człowiek:
     * „aldona” → zaznacz 5, „bogna” → zaznacz 3, „szukana” → ekran końcowy.
     *
     * @return array<string, string|list<string>> pola formularza tak, jak wysłałaby je przeglądarka bez zaznaczeń
     */
    private function formularzPoWyszukiwaniach(User $widz): array
    {
        $pola = $this->pola($this->actingAs($widz)->get(route('onboarding.people', ['q' => 'aldona']))->assertOk()->getContent());
        $pola = $this->pola($this->get(route('onboarding.people', [...$this->zWyborem($pola, $this->nazwy('aldona', 5)), 'q' => 'bogna']))->assertOk()->getContent());
        $wybor = [...$this->nazwy('aldona', 5), ...$this->nazwy('bogna', 3)];
        $html = $this->get(route('onboarding.people', [...$this->zWyborem($pola, $wybor), 'q' => 'szukana']))->assertOk()->getContent();

        $this->assertStringContainsString('Wcześniej wybrane osoby', $html);
        $pola = $this->pola($html);
        $this->assertSame($wybor, $pola['follow[]'], 'Wcześniejszy wybór powinien zostać zachowany.');

        return $pola;
    }

    /**
     * Pola formularza `#f-follow`: ukryte i zaznaczone checkboxy — to, co
     * przeglądarka wysłałaby bez dalszych kliknięć.
     *
     * @return array<string, string|list<string>>
     */
    private function pola(string $html): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $pola = ['follow[]' => []];
        /** @var DOMElement $input */
        foreach ((new DOMXPath($dom))->query('//form[@id="f-follow"]//input') as $input) {
            $nazwa = $input->getAttribute('name');
            if ($input->getAttribute('type') === 'checkbox') {
                if ($input->hasAttribute('checked')) {
                    $pola[$nazwa][] = $input->getAttribute('value');
                }
            } elseif ($input->getAttribute('type') === 'hidden') {
                $pola[$nazwa] = $input->getAttribute('value');
            }
        }

        return $pola;
    }

    /**
     * @param  array<string, string|list<string>>  $pola
     * @param  list<string>  $wybrane
     * @return array<string, mixed> dane żądania z `follow` = dokładnie `$wybrane`
     */
    private function zWyborem(array $pola, array $wybrane): array
    {
        $dane = ['follow' => array_values($wybrane), 'oczekiwani' => []];

        foreach ($pola as $nazwa => $wartosc) {
            if (preg_match('/^oczekiwani\[(.+)\]$/', $nazwa, $m)) {
                $dane['oczekiwani'][$m[1]] = $wartosc;
            } elseif ($nazwa !== 'follow[]' && $nazwa !== 'q') {
                $dane[$nazwa] = $wartosc;
            }
        }

        return $dane;
    }

    /** @return list<string> */
    private function nazwy(string $prefiks, int $ile): array
    {
        return array_map(fn ($i) => $prefiks.$i, range(1, $ile));
    }
}
