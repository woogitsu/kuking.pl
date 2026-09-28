<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\FollowUser;
use App\Domain\Social\SkrotyObserwowania;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Issue #1809 — „Obserwuj tę osobę" i „Obserwuj tag: …" w menu trzech kropek,
 * z komunikatem, który mówi prawdę, i z „Cofnij".
 *
 * Kontrole ujemne (sprawdzone przy pisaniu):
 *  - `osobaDoObserwowania()` bez warunku blokad → macierz zgodności
 *    z `UserPolicy::follow` oblewa;
 *  - `->take(NAJWYZEJ_TAGOW)` usunięte → trzeci tag dostaje skrót;
 *  - komunikat bez `czyPowiadomiono()` → powtórne obserwowanie w oknie
 *    obiecuje powiadomienie, którego nie ma.
 */
class SkrotyObserwowaniaWMenuTest extends TestCase
{
    use RefreshDatabase;

    private function tag(string $slug, string $nazwa, string $status = Tag::STATUS_ACTIVE): Tag
    {
        $tag = Tag::create(['slug' => $slug, 'name' => $nazwa, 'normalized_name' => mb_strtolower($nazwa)]);
        $tag->forceFill(['status' => $status])->save();

        return $tag;
    }

    /** @param  list<Tag>  $tagi */
    private function wpis(User $autor, string $tresc, array $tagi = []): Post
    {
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => $tresc, 'published_at' => now()->subMinute()]);
        foreach ($tagi as $pozycja => $tag) {
            $post->tags()->attach($tag->getKey(), ['position' => $pozycja]);
        }

        return $post;
    }

    private function menuKarty(string $html, string $tresc): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query("//article[contains(concat(' ', normalize-space(@class), ' '), ' post-card ')]") as $karta) {
            if (str_contains($karta->textContent, $tresc)) {
                $menu = $xpath->query(".//div[contains(@class, 'post-card-menu-tresc')]", $karta)->item(0);

                return $menu === null ? '' : (string) $dom->saveHTML($menu);
            }
        }
        $this->fail("Nie ma karty z treścią „{$tresc}”.");
    }

    public function test_menu_pokazuje_skroty_tylko_tam_gdzie_maja_sens(): void
    {
        $widz = $this->user('widz');
        $obca = $this->user('obca');
        $znajoma = $this->user('znajoma');
        $zupy = $this->tag('zupy', 'Zupy');
        $ciasta = $this->tag('ciasta', 'Ciasta');
        $obiady = $this->tag('obiady', 'Obiady');
        $desery = $this->tag('desery', 'Desery');
        $ukryty = $this->tag('ukryty', 'Ukryty', Tag::STATUS_HIDDEN);
        $widz->followedTags()->attach($zupy->getKey(), ['created_at' => now()]);
        DB::table('follows')->insert(['follower_id' => $widz->id, 'followed_id' => $znajoma->id, 'created_at' => now()]);

        $this->wpis($obca, 'Wpis obcej', [$zupy, $ukryty, $ciasta, $obiady, $desery]);
        $this->wpis($znajoma, 'Wpis znajomej');
        $this->wpis($widz, 'Mój wpis', [$ciasta]);

        $html = (string) $this->actingAs($widz)->get(route('discover'))->assertOk()->getContent();

        $obcej = $this->menuKarty($html, 'Wpis obcej');
        $this->assertStringContainsString('Obserwuj tę osobę', $obcej);
        // Obserwowany (Zupy) i ukryty tag bez skrótu; z pozostałych trzech
        // dwa pierwsze w kolejności wpisu — Desery już nie.
        $this->assertStringContainsString('Obserwuj tag: Ciasta', $obcej);
        $this->assertStringContainsString('Obserwuj tag: Obiady', $obcej);
        $this->assertStringNotContainsString('Obserwuj tag: Zupy', $obcej);
        $this->assertStringNotContainsString('Obserwuj tag: Ukryty', $obcej);
        $this->assertStringNotContainsString('Obserwuj tag: Desery', $obcej);
        $this->assertSame(2, substr_count($obcej, 'data-skrot-obserwuj="tag"'));

        $this->assertStringNotContainsString('Obserwuj tę osobę', $this->menuKarty($html, 'Wpis znajomej'));
        $this->assertStringNotContainsString('data-skrot-obserwuj', $this->menuKarty($html, 'Mój wpis'));

        // Nigdzie „więcej takich", a kropki dalej bez widocznego napisu (D-172).
        $this->assertStringNotContainsStringIgnoringCase('więcej takich', $html);
    }

    public function test_obserwowanie_z_menu_mowi_prawde_i_daje_cofnij(): void
    {
        $widz = $this->user('widz');
        $obca = $this->user('obca');
        $this->wpis($obca, 'Wpis obcej');

        $odpowiedz = $this->actingAs($widz)->from(route('discover'))
            ->post(route('social.follow', $obca->profile->username), ['oczekiwany_id' => $obca->getKey()])
            ->assertRedirect(route('discover'))
            ->assertSessionHas('status', 'Obserwujesz. Ta osoba dostanie powiadomienie. Jej nowe wpisy zobaczysz na Starcie.');
        $this->assertTrue($widz->fresh()->isFollowing($obca));
        $this->assertSame(1, Notification::query()->where('user_id', $obca->id)->where('type', Notification::TYPE_FOLLOW)->count());

        $powrot = $odpowiedz->getSession()->get('status_powrot');
        $this->assertSame('Cofnij', $powrot['etykieta']);
        $this->assertSame('DELETE', $powrot['pola']['_method']);

        // Komunikat z „Cofnij" stoi na stronie, na którą wracamy.
        $this->get(route('discover'))->assertOk()
            ->assertSee('Obserwujesz. Ta osoba dostanie powiadomienie.')
            ->assertSee('data-rola="powrot-po-akcji"', false);

        // „Cofnij" to ten sam formularz, który wysyła przeglądarka.
        $this->from(route('discover'))->post($powrot['akcja'], $powrot['pola'])
            ->assertSessionHas('status', 'Nie obserwujesz już tej osoby.');
        $this->assertFalse($widz->fresh()->isFollowing($obca));

        // Ponowne obserwowanie w oknie wyciszenia nie tworzy powiadomienia —
        // i komunikat go nie obiecuje.
        $this->from(route('discover'))
            ->post(route('social.follow', $obca->profile->username), ['oczekiwany_id' => $obca->getKey()])
            ->assertSessionHas('status', 'Obserwujesz. Nowe wpisy tej osoby zobaczysz na Starcie.');
        $this->assertSame(1, Notification::query()->where('user_id', $obca->id)->where('type', Notification::TYPE_FOLLOW)->count());
    }

    public function test_obserwowanie_tagu_z_menu_daje_cofnij(): void
    {
        $widz = $this->user('widz');
        $ciasta = $this->tag('ciasta', 'Ciasta');

        $odpowiedz = $this->actingAs($widz)->from(route('discover'))
            ->post(route('tags.follow', $ciasta))
            ->assertSessionHas('status', 'Obserwujesz tag „Ciasta”. Nowe wpisy z tego tagu zobaczysz na Starcie.');
        $this->assertTrue($widz->fresh()->isFollowingTag($ciasta));

        $powrot = $odpowiedz->getSession()->get('status_powrot');
        $this->assertSame('Cofnij', $powrot['etykieta']);
        $this->from(route('discover'))->post($powrot['akcja'], $powrot['pola']);
        $this->assertFalse($widz->fresh()->isFollowingTag($ciasta));
    }

    public function test_regula_skrotu_zgodna_z_polityka_obserwowania(): void
    {
        $widz = $this->user('widz');
        $przypadki = [
            'zwykła' => $this->user('zwykla'),
            'obserwowana' => $this->user('obserwowana'),
            'blokowana' => $this->user('blokowana'),
            'blokująca' => $this->user('blokujaca'),
            'zawieszona' => $this->user('zawieszona'),
            'ja' => $widz,
        ];
        DB::table('follows')->insert(['follower_id' => $widz->id, 'followed_id' => $przypadki['obserwowana']->id, 'created_at' => now()]);
        DB::table('blocks')->insert([
            ['blocker_id' => $widz->id, 'blocked_id' => $przypadki['blokowana']->id, 'created_at' => now()],
            ['blocker_id' => $przypadki['blokująca']->id, 'blocked_id' => $widz->id, 'created_at' => now()],
        ]);
        $przypadki['zawieszona']->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        foreach ($przypadki as $nazwa => $autor) {
            $skroty = new SkrotyObserwowania(new Request);
            $oczekiwane = Gate::forUser($widz)->allows('follow', $autor) && ! $widz->isFollowing($autor);
            $this->assertSame($oczekiwane, $skroty->osobaDoObserwowania($widz, $autor->fresh()), "Rozjazd z UserPolicy::follow: {$nazwa}");
        }

        // Kontrola dodatnia: macierz ma oba wyniki.
        $this->assertTrue((new SkrotyObserwowania(new Request))->osobaDoObserwowania($widz, $przypadki['zwykła']));
    }

    public function test_skroty_nie_dokladaja_zapytan_na_karte(): void
    {
        $widz = $this->user('widz');
        $zupy = $this->tag('zupy', 'Zupy');
        $policz = function () use ($widz): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($widz)->get(route('discover'))->assertOk();
            $ile = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $ile;
        };

        foreach (range(1, 2) as $i) {
            $this->wpis($this->user("mala{$i}"), "Mała {$i}", [$zupy]);
        }
        $malo = $policz();

        foreach (range(1, 10) as $i) {
            $this->wpis($this->user("duza{$i}"), "Duża {$i}", [$zupy]);
        }
        $duzo = $policz();

        // `<=`, nie `===`: tablica na dziś w szynie ma własne zapytania zależne
        // od danych. Miarą jest to, że 10 kart więcej nie dokłada niczego.
        $this->assertLessThanOrEqual($malo, $duzo, "Skróty w menu dokładają zapytania na kartę: {$malo} przy 2 kartach, {$duzo} przy 12.");
    }

    public function test_akcja_obserwowania_nie_zostawia_flagi_z_poprzedniego_wywolania(): void
    {
        $a = $this->user('pierwsza');
        $b = $this->user('druga');
        $akcja = app(FollowUser::class);
        $akcja->handle($a, $b);
        $this->assertTrue($akcja->czyPowiadomiono());
        $this->assertFalse($akcja->handle($a, $b));
        $this->assertFalse($akcja->czyPowiadomiono());
    }
}
