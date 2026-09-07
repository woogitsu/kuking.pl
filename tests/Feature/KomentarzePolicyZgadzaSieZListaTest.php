<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * `CommentPolicy::view()` musi odpowiadać tak samo jak zapytanie budujące
 * listę komentarzy (audyt komentarzy).
 *
 * CO BYŁO ZEPSUTE
 * Polityka miała jedną linijkę treści: „widoczność komentarza to widoczność
 * jego rodzica". Rodzic jest jednak tylko JEDNĄ z granic. `Comment::
 * scopeWidoczneDla()` liczy dwie kolejne — blokadę widz↔autor komentarza
 * i status konta autora — a relacje `comments()` trzecią, status samego
 * komentarza. Polityka nie znała żadnej z tych trzech.
 *
 * DLACZEGO TO MA ZNACZENIE, MIMO ŻE KOMENTARZ NIE MA WŁASNEGO ADRESU
 * Bo pyta o nią bramka zgłoszeń (`ReportContent::authorize()`, audyt W7-05),
 * której cała racja istnienia brzmi: „zgłoszenie samo w sobie jest
 * przeciekiem — wskazuje istnienie i typ treści, do której nie ma dostępu",
 * a odmowa MUSI być 404 nieodróżnialnym od nieistniejącego celu.
 *
 * ZMIERZONE PRZED POPRAWKĄ: `GET /zglos/comment/{uuid}` zwracało 200 dla
 * komentarza ukrytego przed tym widzem blokadą ORAZ dla komentarza ukrytego
 * przez moderację. Oczekiwane w obu przypadkach 404.
 */
class KomentarzePolicyZgadzaSieZListaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bramka_zgloszen_odmawia_komentarza_ukrytego_blokada(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $czytelnik = $this->user('czytelniczka');
        $natret = $this->user('natret');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $zaczepka = Comment::create([
            'author_id' => $natret->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Zaczepka od zablokowanej osoby.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $zwykly = Comment::create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Zwykly komentarz kontrolny.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        app(BlockUser::class)->handle($czytelnik, $natret);
        $czytelnik->refresh();

        // Punkt odniesienia: lista tego komentarza NIE pokazuje.
        $this->assertFalse(
            $wpis->comments()->widoczneDla($czytelnik)->whereKey($zaczepka->getKey())->exists(),
            'Test zakłada, że lista ukrywa ten komentarz — jeśli nie ukrywa, sprawdza co innego.',
        );

        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $zaczepka->getKey()]))
            ->assertNotFound();

        // KONTROLA: formularz zgłoszenia komentarza, który ten sam widz
        // NAPRAWDĘ widzi, dalej działa. Bez tej asercji test przechodziłby
        // także wtedy, gdyby bramka zaczęła odmawiać wszystkiego.
        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $zwykly->getKey()]))
            ->assertOk();
    }

    public function test_bramka_zgloszen_odmawia_komentarza_ukrytego_przez_moderacje(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $czytelnik = $this->user('czytelniczka');
        $pisarz = $this->user('pisarz');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $ukryty = Comment::create([
            'author_id' => $pisarz->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz zdjety przez moderacje.',
            'status' => Comment::STATUS_HIDDEN,
        ]);

        $widoczny = Comment::create([
            'author_id' => $pisarz->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Drugi komentarz tej samej osoby, nietkniety.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $ukryty->getKey()]))
            ->assertNotFound();

        // KONTROLA: ten sam autor, ten sam wpis, komentarz nieukryty — 200.
        // To odcina „naprawę", która po prostu wyłącza zgłaszanie komentarzy.
        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $widoczny->getKey()]))
            ->assertOk();
    }

    /**
     * Ukryty komentarz zostaje dostępny dla SWOJEGO autora i dla moderatora.
     *
     * Bez tego „naprawa" odbierałaby człowiekowi możliwość odwołania się od
     * decyzji o zdjęciu jego własnej wypowiedzi (DSA art. 20) — a to jest
     * dokładnie ten sam wyjątek, jaki `RecipePolicy::view()` ma dla
     * nieopublikowanego przepisu.
     */
    public function test_autor_i_moderator_widza_wlasny_ukryty_komentarz(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $pisarz = $this->user('pisarz');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $ukryty = Comment::create([
            'author_id' => $pisarz->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Moja wypowiedz zdjeta przez moderacje.',
            'status' => Comment::STATUS_HIDDEN,
        ]);

        $this->assertTrue(
            Gate::forUser($pisarz)->allows('view', $ukryty),
            'Autor stracił dostęp do własnej, ukrytej wypowiedzi — nie ma jak się odwołać.',
        );

        $this->assertTrue(
            Gate::forUser($this->moderator())->allows('view', $ukryty),
            'Moderator nie widzi treści, o której ma decydować.',
        );
    }

    /**
     * Blokada nie ma furtki ANI dla moderatora, ANI dla autora treści.
     *
     * To nie jest kwestia uprawnień, tylko relacji dwóch osób — dokładnie
     * tak samo jak w `Comment::scopeWidoczneDla()` i w `CookedEventPolicy`.
     */
    public function test_blokada_obowiazuje_takze_moderatora(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $natret = $this->user('natret');
        $moderator = $this->moderator();

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $komentarz = Comment::create([
            'author_id' => $natret->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz osoby w relacji blokady z moderatorem.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // KONTROLA: przed blokadą moderator widzi ten komentarz.
        $this->assertTrue(Gate::forUser($moderator)->allows('view', $komentarz));

        app(BlockUser::class)->handle($moderator, $natret);

        $this->assertFalse(
            Gate::forUser($moderator->refresh())->allows('view', $komentarz),
            'Blokada dała się obejść rolą — a blokada jest relacją dwóch osób, nie uprawnieniem.',
        );
    }

    /**
     * Blokada w DRUGĄ stronę: to autor komentarza zablokował widza.
     *
     * `AGENTS.md` §4 mówi „blokada działa w obie strony", a `Comment::
     * scopeWidoczneDla()` liczyła tak od początku. Bramka zgłoszeń musi
     * odpowiadać identycznie, inaczej ta granica ma dziurę z jednej strony.
     */
    public function test_blokada_w_druga_strone_tez_zamyka_bramke_zgloszen(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $czytelnik = $this->user('czytelniczka');
        $blokujacy = $this->user('blokujacy');

        $wpis = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        $jego = Comment::create([
            'author_id' => $blokujacy->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz osoby, ktora zablokowala czytelnika.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $czyjs = Comment::create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $wpis->getKey(),
            'body' => 'Komentarz kontrolny, bez zadnej blokady.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        // To KOMENTUJĄCY blokuje czytelnika, nie odwrotnie.
        app(BlockUser::class)->handle($blokujacy, $czytelnik);
        $czytelnik->refresh();

        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $jego->getKey()]))
            ->assertNotFound();

        // KONTROLA: inny komentarz pod tym samym wpisem dalej daje 200.
        $this->actingAs($czytelnik)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $czyjs->getKey()]))
            ->assertOk();
    }

    /**
     * Granica RODZICA nie mogła zniknąć przy przebudowie tej metody.
     *
     * `CommentPolicy::view()` dostała trzy warunki PRZED delegacją do polityki
     * rodzica. Gdyby delegacja przy tym wypadła, komentarz pod prywatnym
     * wpisem stałby się zgłaszalny przez obcą osobę — czyli naprawa jednej
     * połowy zepsułaby drugą.
     */
    public function test_komentarz_pod_prywatnym_wpisem_dalej_jest_niedostepny(): void
    {
        $autorWpisu = $this->user('autorkawpisu');
        $obca = $this->user('obca');

        $prywatny = Post::factory()->create([
            'author_id' => $autorWpisu->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);

        $komentarz = Comment::create([
            'author_id' => $autorWpisu->getKey(),
            'post_id' => $prywatny->getKey(),
            'body' => 'Tajna uwaga pod prywatnym wpisem.',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->actingAs($obca)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]))
            ->assertNotFound();

        // KONTROLA: autor wpisu, czyli osoba uprawniona, dalej wchodzi.
        $this->actingAs($autorWpisu)
            ->get(route('reports.create', ['type' => 'comment', 'id' => $komentarz->getKey()]))
            ->assertOk();
    }
}
