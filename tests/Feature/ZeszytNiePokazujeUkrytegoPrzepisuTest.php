<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Publiczny zeszyt nie może zatrzymać tytułu przepisu, który autor schował.
 *
 * SIÓDME MIEJSCE Z RODZINY #368 i jedyne, w którym treść jest ODŁOŻONA —
 * a to zmienia charakter wycieku. Wszędzie indziej zapowiedź stoi w liście,
 * która sama się odświeża: przepis znika z bazy albo zmienia widoczność
 * i lista przestaje go pokazywać. Zeszyt trzyma WSKAZANIE na wpis, więc
 * zapowiedź zostaje w nim tak długo, aż ktoś ją stamtąd wyjmie — a nikt
 * tego nie zrobi, bo osoba, która zapisała, widziała wtedy przepis
 * całkowicie legalnie. Wyciek powstaje PÓŹNIEJ, bez niczyjego działania:
 * w chwili, w której autor schował przepis albo zdjęła go moderacja.
 *
 * `CollectionController::show()` filtrowało wpisy przez `widoczneDla()`
 * i `dostepnyJakoAutor()` — oba pytania o WPIS i o jego autora. Zapowiedź
 * przepisu ma `visibility = 'public'` na stałe
 * (`WpisWskazujacyPrzepis::dopisz()`), bo bramką ma być PRZEPIS, więc
 * przechodziła przez oba. Zeszyt renderuje `x-post-card`, czyli tę samą
 * kartę co feed: tytuł, zdjęcie główne i odnośnik, w którym slug niesie
 * ten sam tytuł zapisany inaczej.
 *
 * DLACZEGO NIE WYSTARCZY TU POLICY. `CollectionPolicy::view()` pilnuje
 * WEJŚCIA NA ADRES ZESZYTU i robi to dobrze. Nie ma jednak nic wspólnego
 * z tym, co w tym zeszycie wolno narysować — a wpis odłożony do cudzego
 * zeszytu jest cudzą treścią, oglądaną przez osobę, która o przepisie nie
 * wie nic. To ta sama różnica dwóch dróg, którą opisuje `tylkoWidoczne()`
 * w `ProfileController`.
 *
 * DOBÓR WIDZA JEST CZĘŚCIĄ TESTU. Zeszyt należy do OLI, a przepis do BASI,
 * i Ola Basi nie obserwuje. Gdyby zeszyt należał do autorki przepisu,
 * bramka przepuszczałaby wszystko zgodnie z regułą „własne widać zawsze"
 * i test przechodziłby z niewłaściwego powodu. Trasa `/zeszyt/{id}` leży
 * za `auth`, więc gościa bez konta w tym miejscu nie ma — najszerszym
 * możliwym widzem jest zalogowany obcy i to on tu patrzy.
 */
class ZeszytNiePokazujeUkrytegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const TYTUL = 'Bigos z kapusty kiszonej';

    private const SLUG = 'bigos-z-kapusty-kiszonej';

    /**
     * Basia publikuje przepis, Ola odkłada jego zapowiedź do swojego
     * publicznego zeszytu — obok zwykłego wpisu bez przepisu.
     *
     * @return array{0: User, 1: Recipe, 2: Collection, 3: Post}
     */
    private function zeszyt(string $widocznosc = 'public'): array
    {
        $basia = $this->user('basia');
        $ola = $this->user('ola');

        $this->assertFalse(
            $ola->isFollowing($basia),
            'Ola obserwuje Basię — wtedy przepis „tylko dla obserwujących" jest dla niej widoczny '
            .'zgodnie z ustawieniem i ten test nie mierzy wycieku.',
        );

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => self::TYTUL, 'visibility' => $widocznosc, 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        $przepis->forceFill([
            'hero_media_id' => Media::factory()->create(['owner_id' => $basia->getKey()])->getKey(),
        ])->save();

        /** @var Post $zapowiedz */
        $zapowiedz = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();

        $this->assertSame(
            Post::VISIBILITY_PUBLIC,
            $zapowiedz->visibility,
            'Zapowiedź przepisu nie jest publiczna — wtedy odciąłby ją `widoczneDla()` i te testy '
            .'przechodziłyby z niewłaściwego powodu.',
        );

        $zwykly = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'body' => 'Zwykly obiad, bez zadnego przepisu.',
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subMinute(),
        ]);

        $zeszyt = Collection::create([
            'owner_id' => $ola->getKey(),
            'name' => 'Na niedzielę',
            'visibility' => 'public',
            'is_default' => false,
        ]);

        $zeszyt->posts()->attach([
            $zapowiedz->getKey() => ['created_at' => now()],
            $zwykly->getKey() => ['created_at' => now()->subMinute()],
        ]);

        return [$ola, $przepis->fresh(), $zeszyt, $zapowiedz];
    }

    private function stronaObcego(Collection $zeszyt): string
    {
        // `actingAs()` z wcześniejszej asercji przecieka na kolejne żądanie
        // w tym samym teście — bez tego „obcy" bywa właścicielem zeszytu
        // albo autorką przepisu i pomiar mówi o czymś innym, niż się wydaje.
        Auth::logout();

        $celina = $this->user('celina');

        return $this->actingAs($celina)
            ->get(route('collections.show', $zeszyt))
            ->assertOk()
            ->getContent();
    }

    /**
     * KONTROLA DODATNIA PRZED NADGORLIWOŚCIĄ: wpis bez przepisu ma zostać.
     *
     * Odróżnia poprawną naprawę od `whereHas('recipe', …)` bez gałęzi na
     * `recipe_id IS NULL`, która skasowałaby z zeszytu wszystko, co nie jest
     * zapowiedzią przepisu — czyli niemal całą jego zawartość.
     */
    public function test_zwykly_wpis_bez_przepisu_zostaje_w_zeszycie(): void
    {
        [, , $zeszyt] = $this->zeszyt('followers');

        $this->assertStringContainsString(
            'Zwykly obiad, bez zadnego przepisu.',
            $this->stronaObcego($zeszyt),
            'Bramka przepisu skasowała z zeszytu zwykły wpis, który z żadnym przepisem nie ma nic '
            .'wspólnego.',
        );
    }

    /**
     * KONTROLA DODATNIA: zeszyt NAPRAWDĘ rysuje tytuł i slug zapowiedzi.
     *
     * Bez niej asercje „tytułu nie ma" przechodziłyby także wtedy, gdyby
     * zeszyt w ogóle nie pokazywał zapisanych wpisów — a wtedy cisza
     * o sekrecie nic nie znaczy.
     */
    public function test_publiczny_przepis_w_zeszycie_dalej_widac(): void
    {
        [, , $zeszyt] = $this->zeszyt('public');

        $html = $this->stronaObcego($zeszyt);

        $this->assertStringContainsString(
            self::TYTUL,
            $html,
            'Zeszyt nie wypisuje tytułu nawet przepisu w pełni publicznego — asercje o wycieku '
            .'nie mówiłyby wtedy o bramce, tylko o pustej stronie.',
        );

        $this->assertStringContainsString(
            self::SLUG,
            $html,
            'Zeszyt nie linkuje nawet do przepisu w pełni publicznego — asercja o slugu nie ma '
            .'wtedy czego pilnować.',
        );
    }

    public function test_przepis_schowany_po_zapisaniu_znika_z_zeszytu(): void
    {
        // Zapisany, gdy był publiczny — dokładnie tak, jak to się dzieje
        // naprawdę. Dopiero potem autorka go zawęża.
        [, $przepis, $zeszyt] = $this->zeszyt('public');

        $przepis->forceFill(['visibility' => 'followers'])->save();

        $html = $this->stronaObcego($zeszyt);

        $this->assertStringNotContainsString(
            self::TYTUL,
            $html,
            'Tytuł przepisu „tylko dla obserwujących" stoi w cudzym zeszycie. Zapowiedź została '
            .'w nim z czasów, gdy przepis był publiczny, i nikt jej stamtąd nie wyjmie.',
        );

        $this->assertStringNotContainsString(
            self::SLUG,
            $html,
            'Slug schowanego przepisu stoi w odnośniku w cudzym zeszycie — a slug niesie tytuł.',
        );
    }

    public function test_przepis_zdjety_przez_moderacje_znika_z_zeszytu(): void
    {
        [, $przepis, $zeszyt] = $this->zeszyt('public');

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $html = $this->stronaObcego($zeszyt);

        $this->assertStringNotContainsString(
            self::TYTUL,
            $html,
            'Tytuł przepisu zdjętego przez moderację stoi w cudzym zeszycie — i to przed osobą, '
            .'która pod adresem przepisu dostałaby 403.',
        );

        $this->assertStringNotContainsString(
            self::SLUG,
            $html,
            'Slug przepisu zdjętego przez moderację stoi w odnośniku w cudzym zeszycie.',
        );
    }

    /**
     * Autorka przepisu widzi go w cudzym zeszycie dalej — „poprawne dane
     * nigdy nie znikają", a dla niej te dane są poprawne.
     */
    public function test_autorka_swoj_schowany_przepis_w_zeszycie_dalej_widzi(): void
    {
        [, $przepis, $zeszyt, $zapowiedz] = $this->zeszyt('public');

        $przepis->forceFill(['visibility' => 'private'])->save();

        $basia = User::query()->whereKey($zapowiedz->author_id)->firstOrFail();

        Auth::logout();

        $this->assertStringContainsString(
            self::TYTUL,
            $this->actingAs($basia)
                ->get(route('collections.show', $zeszyt))
                ->assertOk()
                ->getContent(),
            'Poprawka odcięła autorce jej własny przepis odłożony do cudzego zeszytu.',
        );
    }
}
