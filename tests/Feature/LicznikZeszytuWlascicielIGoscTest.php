<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Licznik karty zeszytu = to, co widać po otwarciu zeszytu (issue #774).
 *
 * `LicznikKartyZeszytuSpojnyTest` sprawdza dwa przypadki (prywatny i usunięty
 * miękko przepis). Tutaj pełna macierz z kryteriów issue, przez OBA ekrany:
 * przepisy i wpisy osobno, w jednym mieszanym zeszycie, każdy rodzaj
 * niedostępności (prywatny, ukryty przez moderację, usunięty miękko, blokada,
 * zablokowane konto autora), przywrócenie dostępu — oraz widok GOŚCIA, który
 * przechodzi przez tę samą bramkę widoczności, ale nie dostaje liczby
 * niedostępnych zapisów (#1297).
 */
final class LicznikZeszytuWlascicielIGoscTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{
     *     wlasciciel: User, zeszyt: Collection, usunietyPrzepis: Recipe,
     *     widocznyPrzepis: Recipe, widocznyWpis: Post, ukryteTytuly: list<string>
     * }
     */
    private function zeszytZKazdymRodzajemNiedostepnosci(string $widocznoscZeszytu): array
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zablokowanyPrzezWlasciciela = $this->user('zablokowany');
        $zbanowany = $this->user('zbanowany');

        DB::table('blocks')->insert([
            'blocker_id' => $wlasciciel->getKey(),
            'blocked_id' => $zablokowanyPrzezWlasciciela->getKey(),
            'created_at' => now(),
        ]);

        $widocznyPrzepis = $this->przepis($autor, 'Widoczny barszcz');
        $prywatnyPrzepis = $this->przepis($autor, 'Prywatny bigos', ['visibility' => 'private']);
        $ukrytyPrzepis = $this->przepis($autor, 'Ukryty żurek', ['status' => Recipe::STATUS_HIDDEN]);
        $usunietyPrzepis = $this->przepis($autor, 'Usunięty kapuśniak');
        $przepisZablokowanego = $this->przepis($zablokowanyPrzezWlasciciela, 'Zablokowana zupa');
        $przepisZbanowanego = $this->przepis($zbanowany, 'Zbanowany rosół');

        $widocznyWpis = $this->wpis($autor, 'Widoczne pierogi z serem');
        $prywatnyWpis = $this->wpis($autor, 'Prywatne naleśniki', ['visibility' => Post::VISIBILITY_PRIVATE]);
        $ukrytyWpis = $this->wpis($autor, 'Ukryte placki', ['status' => Post::STATUS_HIDDEN]);
        $usunietyWpis = $this->wpis($autor, 'Usunięte kopytka');
        $wpisZbanowanego = $this->wpis($zbanowany, 'Zbanowane gołąbki');

        $zeszyt = Collection::create([
            'owner_id' => $wlasciciel->getKey(),
            'name' => 'Macierz',
            'visibility' => $widocznoscZeszytu,
        ]);
        $zeszyt->recipes()->attach(collect([
            $widocznyPrzepis, $prywatnyPrzepis, $ukrytyPrzepis,
            $usunietyPrzepis, $przepisZablokowanego, $przepisZbanowanego,
        ])->map->getKey()->all());
        $zeszyt->posts()->attach(collect([
            $widocznyWpis, $prywatnyWpis, $ukrytyWpis, $usunietyWpis, $wpisZbanowanego,
        ])->map->getKey()->all());

        $usunietyPrzepis->delete();
        $usunietyWpis->delete();
        $zbanowany->forceFill(['status' => User::STATUS_BANNED])->save();

        return [
            'wlasciciel' => $wlasciciel,
            'zeszyt' => $zeszyt,
            'usunietyPrzepis' => $usunietyPrzepis,
            'widocznyPrzepis' => $widocznyPrzepis,
            'widocznyWpis' => $widocznyWpis,
            'ukryteTytuly' => [
                'Prywatny bigos', 'Ukryty żurek', 'Usunięty kapuśniak', 'Zablokowana zupa', 'Zbanowany rosół',
                'Prywatne naleśniki', 'Ukryte placki', 'Usunięte kopytka', 'Zbanowane gołąbki',
            ],
        ];
    }

    public function test_wlasciciel_widzi_na_karcie_i_we_wnetrzu_te_same_liczby(): void
    {
        $s = $this->zeszytZKazdymRodzajemNiedostepnosci('private');

        $karta = $this->tekst($this->actingAs($s['wlasciciel'])->get(route('collections.index'))->assertOk()->getContent());
        $wnetrze = $this->tekst($this->actingAs($s['wlasciciel'])->get(route('collections.show', $s['zeszyt']))->assertOk()->getContent());

        // Kontrola dodatnia: widoczne pozycje NAPRAWDĘ są we wnętrzu — inaczej
        // „1 przepis" na karcie mógłby się zgadzać z pustym ekranem.
        $this->assertStringContainsString('Widoczny barszcz', $wnetrze);
        $this->assertStringContainsString('Widoczne pierogi z serem', $wnetrze);

        // Karta: tyle widocznych, ile we wnętrzu — przepisy i wpisy osobno.
        $this->assertStringContainsString('1 przepis · 1 wpis ·', $karta);

        // Dziewięć niedostępnych (5 przepisów + 4 wpisy) — ta sama liczba
        // na obu ekranach, niezależnie od RODZAJU niedostępności.
        $this->assertStringContainsString('9 zapisów nie jest dla Ciebie dostępnych', $karta);
        $this->assertStringContainsString('9 zapisów nie jest dla Ciebie dostępnych', $wnetrze);

        // Ochrona treści: żadnego tytułu niedostępnej pozycji na żadnym ekranie.
        foreach ($s['ukryteTytuly'] as $tytul) {
            $this->assertStringNotContainsString($tytul, $karta);
            $this->assertStringNotContainsString($tytul, $wnetrze);
        }
    }

    public function test_przywrocenie_dostepu_przenosi_zapis_z_niedostepnych_do_widocznych_na_obu_ekranach(): void
    {
        $s = $this->zeszytZKazdymRodzajemNiedostepnosci('private');

        $s['usunietyPrzepis']->restore();

        $karta = $this->tekst($this->actingAs($s['wlasciciel'])->get(route('collections.index'))->assertOk()->getContent());
        $wnetrze = $this->tekst($this->actingAs($s['wlasciciel'])->get(route('collections.show', $s['zeszyt']))->assertOk()->getContent());

        $this->assertStringContainsString('2 przepisy · 1 wpis ·', $karta);
        $this->assertStringContainsString('Usunięty kapuśniak', $wnetrze);
        $this->assertStringContainsString('8 zapisów nie jest dla Ciebie dostępnych', $karta);
        $this->assertStringContainsString('8 zapisów nie jest dla Ciebie dostępnych', $wnetrze);
    }

    public function test_gosc_widzi_te_same_widoczne_pozycje_bez_liczby_niedostepnych(): void
    {
        $s = $this->zeszytZKazdymRodzajemNiedostepnosci('public');
        $gosc = $this->user('gosc');

        // Zeszyty wymagają konta (anonimowy dostaje przekierowanie do
        // logowania), więc gość to zalogowana osoba spoza zeszytu.
        $wnetrze = $this->tekst($this->actingAs($gosc)->get(route('collections.show', $s['zeszyt']))->assertOk()->getContent());

        // Kontrola dodatnia: gość widzi to, co wolno mu otworzyć.
        $this->assertStringContainsString('Widoczny barszcz', $wnetrze);
        $this->assertStringContainsString('Widoczne pierogi z serem', $wnetrze);

        // Bramka liczona DLA OGLĄDAJĄCEGO: blokada łączy właściciela z autorem,
        // nie gościa — więc gość ten przepis widzi, a właściciel nie.
        $this->assertStringContainsString('Zablokowana zupa', $wnetrze);

        // Reszta niedostępnych pozycji — niedostępna także dla gościa…
        foreach (array_diff($s['ukryteTytuly'], ['Zablokowana zupa']) as $tytul) {
            $this->assertStringNotContainsString($tytul, $wnetrze);
        }
        // …i żadnej metadanej o prywatnych zapisach właściciela (#1297).
        $this->assertStringNotContainsString('dla Ciebie dostępn', $wnetrze);

        // Karta zeszytu istnieje tylko na liście WŁASNYCH zeszytów — cudzy
        // zeszyt nie pojawia się na liście gościa ani z nazwą, ani z liczbą.
        $listaGoscia = $this->tekst($this->actingAs($gosc)->get(route('collections.index'))->assertOk()->getContent());
        $this->assertStringNotContainsString('Macierz', $listaGoscia);
    }

    public function test_gosc_zeszytu_z_samymi_niedostepnymi_widzi_pusty_stan_a_wlasciciel_liczbe(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $autor = $this->user('autor');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Same ukryte', 'visibility' => 'public']);
        $zeszyt->recipes()->attach($this->przepis($autor, 'Prywatny bigos', ['visibility' => 'private'])->getKey());
        $zeszyt->posts()->attach($this->wpis($autor, 'Ukryte placki', ['status' => Post::STATUS_HIDDEN])->getKey());

        $gosc = $this->tekst($this->actingAs($this->user('gosc'))->get(route('collections.show', $zeszyt))->assertOk()->getContent());
        $this->assertStringContainsString('W tym zeszycie nic jeszcze nie ma', $gosc);

        $karta = $this->tekst($this->actingAs($wlasciciel)->get(route('collections.index'))->assertOk()->getContent());
        $wnetrze = $this->tekst($this->actingAs($wlasciciel)->get(route('collections.show', $zeszyt))->assertOk()->getContent());
        $this->assertStringContainsString('0 przepisów ·', $karta);
        $this->assertStringContainsString('2 zapisy nie są dla Ciebie dostępne', $karta);
        $this->assertStringContainsString('2 zapisy nie są dla Ciebie dostępne', $wnetrze);
        $this->assertStringNotContainsString('W tym zeszycie nic jeszcze nie ma', $wnetrze);
    }

    public function test_prawdziwie_pusty_zeszyt_nie_ma_liczby_niedostepnych_na_zadnym_ekranie(): void
    {
        $wlasciciel = $this->user('wlasciciel');
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Pusty', 'visibility' => 'private']);

        $karta = $this->tekst($this->actingAs($wlasciciel)->get(route('collections.index'))->assertOk()->getContent());
        $wnetrze = $this->tekst($this->actingAs($wlasciciel)->get(route('collections.show', $zeszyt))->assertOk()->getContent());

        $this->assertStringContainsString('0 przepisów ·', $karta);
        $this->assertStringNotContainsString('dla Ciebie dostępn', $karta);
        $this->assertStringContainsString('W tym zeszycie nic jeszcze nie ma', $wnetrze);
        $this->assertStringNotContainsString('dla Ciebie dostępn', $wnetrze);
    }

    /** @param  array<string, mixed>  $sterujace  pola spoza `$fillable` (D-006) */
    private function przepis(User $autor, string $tytul, array $sterujace = []): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => $tytul, 'visibility' => 'public']);
        if ($sterujace !== []) {
            $przepis->forceFill($sterujace)->save();
        }

        return $przepis;
    }

    /** @param  array<string, mixed>  $sterujace */
    private function wpis(User $autor, string $tresc, array $sterujace = []): Post
    {
        $wpis = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => $tresc]);
        if ($sterujace !== []) {
            $wpis->forceFill($sterujace)->save();
        }

        return $wpis;
    }

    private function tekst(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', $html));
    }
}
