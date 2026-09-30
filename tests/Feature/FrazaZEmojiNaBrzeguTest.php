<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Domain\Tags\TagFollowWindow;
use App\Domain\Tags\TagSuggester;
use App\Http\Requests\Admin\ListaKontRequest;
use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\TagPromotion;
use App\Support\Czas;
use App\Support\FrazaWyszukiwania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Issue #2331 — fraza z emoji na brzegu („Basia 🍲") nie znajdowała osoby.
 *
 * Kontrolery przycinają frazę PRZED normalizacją, a emoji znika dopiero
 * w `Str::ascii()` (`FrazaWyszukiwania::normalizuj()`) i zostawiało po sobie
 * spację: „basia ", a `LIKE '%basia %'` nie pasuje do konta „Basia". Ta sama
 * reguła zasila każde pole szukania w serwisie, więc test idzie po każdej
 * ścieżce, która z niej korzysta: ludzie (`/szukaj`, onboarding), przepisy
 * (`/szukaj`, planer), zeszyty, własne wykonania, podpowiedzi tagów, filtr
 * obserwowanych tagów i lista kont w panelu moderacji.
 *
 * Każda scena ma obok kontrolę dodatnią: ta sama rzecz bez emoji JEST
 * znajdowana — inaczej zielony wynik mógłby znaczyć „nic tu nie ma".
 *
 * Kontrola ujemna (ręczna, izolowana baza PostgreSQL): po przywróceniu
 * `return mb_strtolower(Str::ascii($fraza));` w `FrazaWyszukiwania::normalizuj()`
 * oblewa każdy test z tego pliku poza testem odstępów wpisanych przez
 * człowieka (który pilnuje, że poprawka NIE zwija ich na siłę).
 */
class FrazaZEmojiNaBrzeguTest extends TestCase
{
    use RefreshDatabase;

    private int $pozycja = 1;

    public function test_normalizacja_nie_zostawia_odstepu_po_znikajacym_slowie(): void
    {
        // Fixture sprawdzana, nie zakładana: gdyby biblioteka zaczęła
        // przepisywać emoji na tekst, ten test ma to powiedzieć wprost.
        $this->assertSame('', Str::ascii('🍲'));

        foreach ([
            'Basia 🍲' => 'basia',
            '🍲 Basia' => 'basia',
            '🍲 Basia 🍲' => 'basia',
            'Helena 🍲 Nowak' => 'helena nowak',
            'Helena 🍲 🥟 Nowak' => 'helena nowak',
            "Żurek\u{200B}" => 'zurek',
            'Żurek' => 'zurek',
            'a🍲b' => 'ab',
            '🍲🍲' => '',
            "a\u{00A0}b" => 'a b',
        ] as $wejscie => $oczekiwane) {
            $this->assertSame($oczekiwane, FrazaWyszukiwania::normalizuj($wejscie), "Fraza „{$wejscie}”.");
        }
    }

    public function test_odstepy_wpisane_przez_czlowieka_zostaja(): void
    {
        // Kolumny `*_search` ich nie zwijają, więc nazwa skopiowana dosłownie
        // ma dalej znaleźć samą siebie. Zwijanie byłoby osobną decyzją.
        $this->assertSame('anna  nowak', FrazaWyszukiwania::normalizuj('Anna  Nowak'));

        $anna = $this->user('anna2331', ['display_name' => 'Anna  Nowak']);
        $this->assertSame([$anna->getKey()], app(SearchQuery::class)->people('Anna  Nowak')->pluck('user_id')->all());
    }

    public function test_ludzie_z_emoji_na_brzegu_i_w_srodku(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $helena = $this->user('hela2331', ['display_name' => 'Helena Nowak']);
        $szukaj = app(SearchQuery::class);

        // Kontrola dodatnia.
        $this->assertSame([$basia->getKey()], $szukaj->people('Basia')->pluck('user_id')->all());
        $this->assertSame([$helena->getKey()], $szukaj->people('Helena Nowak')->pluck('user_id')->all());

        foreach (['Basia 🍲', '🍲 Basia', '@Basia 🍲', 'Basia 🍲🍲'] as $fraza) {
            $this->assertSame([$basia->getKey()], $szukaj->people($fraza)->pluck('user_id')->all(), "Fraza „{$fraza}”.");
        }
        $this->assertSame([$helena->getKey()], $szukaj->people('Helena 🍲 Nowak')->pluck('user_id')->all());

        // Ten sam wynik na ekranie `/szukaj`, zakładka „Ludzie".
        $odpowiedz = $this->actingAs($this->user('widz2331'))
            ->get(route('search', ['q' => 'Basia 🍲', 'sekcja' => 'ludzie']))
            ->assertOk();
        $this->assertSame([$basia->getKey()], $odpowiedz->viewData('people')->pluck('user_id')->all());
    }

    public function test_przepisy_na_szukaj_i_w_planerze(): void
    {
        // Dopasowanie tylko przez opis — tytuł nie ratuje wyniku
        // podobieństwem trigramowym.
        $przepis = $this->przepis('Obiad niedzielny', ['summary' => 'Z twarogiem']);
        $szukaj = app(SearchQuery::class);

        $this->assertSame([$przepis->getKey()], $szukaj->recipes('twarogiem')->pluck('id')->all());
        $this->assertSame([$przepis->getKey()], $szukaj->recipes('twarogiem 🍲')->pluck('id')->all());
        $this->assertSame([$przepis->getKey()], $szukaj->recipes('🍲 twarogiem')->pluck('id')->all());

        // Planer: ta sama wyszukiwarka pod polem „Jakiego przepisu szukasz?".
        $dzien = Czas::dzisiajData();
        $wyniki = $this->actingAs($this->user('planuje2331'))
            ->get(route('planer.show', ['dzien' => $dzien, 'q' => 'twarogiem 🍲']))
            ->assertOk()
            ->viewData('wyniki');
        $this->assertSame([$przepis->getKey()], $wyniki->pluck('id')->all());
    }

    public function test_szukaj_w_moich_zeszytach(): void
    {
        $basia = $this->user('basia');
        $zurek = $this->przepis('Żurek');
        Collection::create(['owner_id' => $basia->getKey(), 'name' => 'Obiady', 'visibility' => 'private'])
            ->recipes()->attach($zurek->getKey());

        foreach (['Żurek', 'Żurek 🍲', '🍲 żurek'] as $fraza) {
            $wyniki = $this->actingAs($basia)
                ->get(route('collections.index', ['szukaj' => $fraza]))
                ->assertOk()
                ->viewData('wynikiSzukania');
            $this->assertSame([$zurek->getKey()], $wyniki->pluck('id')->all(), "Fraza „{$fraza}”.");
        }
    }

    public function test_szukaj_w_moich_wykonaniach(): void
    {
        $kucharz = $this->user('kucharz');
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $this->przepis('Żurek')->getKey(),
            'cooked_at' => now()->subDay(),
        ]);

        foreach (['Żurek', 'Żurek 🍲', '🍲 żurek'] as $fraza) {
            $html = $this->actingAs($kucharz)
                ->get(route('profile.show', ['username' => 'kucharz', 'zakladka' => 'ugotowane', 'szukaj' => $fraza]))
                ->assertOk()
                ->getContent();
            preg_match_all('/data-klucz="wykonanie-([0-9a-f-]+)"/', $html, $m);
            $this->assertSame([$wykonanie->getKey()], $m[1], "Fraza „{$fraza}”.");
        }
    }

    public function test_podpowiedzi_tagow(): void
    {
        $zurek = $this->tag('Żurek');
        $this->tag('Zupa pomidorowa');
        $podpowiedzi = fn (string $fraza): array => app(TagSuggester::class)->sugeruj($fraza)->pluck('id')->all();

        $this->assertSame($zurek->getKey(), $podpowiedzi('żurek')[0] ?? null);
        $this->assertSame($zurek->getKey(), $podpowiedzi('żurek 🍲')[0] ?? null);
        $this->assertSame($zurek->getKey(), $podpowiedzi('🍲 żurek')[0] ?? null);
    }

    public function test_filtr_obserwowanych_tagow(): void
    {
        $user = $this->user('tagi2331');
        $zurek = $this->tag('Żurek', promowany: true);
        $this->tag('Zupa pomidorowa', promowany: true);
        $okno = app(TagFollowWindow::class);

        foreach (['żurek', 'żurek 🍲', '🍲 żurek'] as $fraza) {
            $this->assertSame(
                [$zurek->getKey()],
                $okno->zloz($user, $fraza, null, null, null)['tags']->pluck('id')->all(),
                "Fraza „{$fraza}”.",
            );
        }
    }

    public function test_lista_kont_w_panelu_moderacji(): void
    {
        $filtry = ListaKontRequest::create('/admin/uzytkownicy', 'GET', ['szukaj' => 'Zięba 🍲'])->filtry();
        // Pole formularza dostaje to, co człowiek wpisał; zapytanie — frazę bez odstępu po emoji.
        $this->assertSame('Zięba 🍲', $filtry['szukaj']);
        $this->assertSame('zieba', $filtry['fraza']);

        $this->user('barbara2331', ['display_name' => 'Barbara Zięba']);
        $this->user('zenek2331', ['display_name' => 'Zenon Nowak']);

        $this->actingAs($this->moderator())
            ->get(route('admin.users', ['szukaj' => 'Zięba 🍲']))
            ->assertOk()
            ->assertSee('Barbara Zięba')
            ->assertDontSee('Zenon Nowak');
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function przepis(string $tytul, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create($atrybuty + [
            'title' => $tytul,
            'slug' => Str::slug($tytul).'-'.Str::lower(Str::random(6)),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);
    }

    private function tag(string $nazwa, bool $promowany = false): Tag
    {
        $tag = Tag::factory()->create([
            'name' => $nazwa,
            'normalized_name' => Tag::znormalizujNazwe($nazwa),
        ]);
        if ($promowany) {
            TagPromotion::create(['tag_id' => $tag->getKey(), 'position' => $this->pozycja++]);
        }

        return $tag;
    }
}
