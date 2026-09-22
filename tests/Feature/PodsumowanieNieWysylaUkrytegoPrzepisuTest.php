<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\OdnosnikWypisania;
use App\Domain\Digest\TrescDigestu;
use App\Domain\Digest\ZbierzTresciDigestu;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Mail\PodsumowanieTygodnia;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tygodniowy list nie może wynieść tytułu przepisu, którego adresat nie
 * zobaczyłby na stronie.
 *
 * TO MIEJSCE JEST INNE NIŻ POZOSTAŁE Z TEJ RODZINY (#368) I DLATEGO MA
 * WŁASNY PLIK. Wszędzie indziej wyciek kończy się na ekranie: da się go
 * zamknąć i następne wejście pokazuje już stan poprawiony. Tutaj treść
 * wychodzi POZA SERWIS, na cudzą skrzynkę, i listu raz wysłanego nie da się
 * cofnąć — poprawka po fakcie nie odbiera tego, co już przeczytano.
 *
 * WPIS ZAPOWIADAJĄCY PRZEPIS JEST NA STAŁE `public`
 * (`WpisWskazujacyPrzepis::dopisz()`), bo bramką ma być PRZEPIS, nie jego
 * zapowiedź. Filtr `whereIn('visibility', [public, followers])`, który
 * `ZbierzTresciDigestu::wpisyObserwowanych()` nakłada na WPIS, przepuszcza
 * więc zapowiedź przepisu w każdym stanie — również wtedy, gdy przepis
 * został schowany po publikacji.
 *
 * CO DOKŁADNIE WYCIEKA. Zapowiedź nie ma własnego `body`, więc szablon
 * listu wchodzi w gałąź `@elseif($wpis->recipe !== null)` i wypisuje
 * `{{ $wpis->recipe->title }}` — w obu wariantach listu, HTML i tekstowym.
 * Tytuł jest całą treścią, jaką przepis pokazuje z zewnątrz.
 *
 * DOBÓR PRZYPADKÓW JEST CZĘŚCIĄ TESTU — I RÓŻNI SIĘ OD RESZTY RODZINY.
 * W strumieniach wyciek widać na przepisie „tylko dla obserwujących"
 * oglądanym przez kogoś, kto autora NIE obserwuje. W liście taki widz nie
 * istnieje: wpis trafia do adresata WYŁĄCZNIE dlatego, że adresat obserwuje
 * autora. Przepis „tylko dla obserwujących" jest dla niego widoczny zgodnie
 * z ustawieniem i odcięcie go byłoby błędem — pilnuje tego
 * `test_przepis_tylko_dla_obserwujacych_zostaje_w_liscie_obserwujacego`.
 * Zostają dwa stany, w których adresat naprawdę nie ma prawa nic zobaczyć:
 * przepis schowany do prywatnego i przepis zdjęty przez moderację.
 */
class PodsumowanieNieWysylaUkrytegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Basia publikuje przepis, Ola ją obserwuje.
     *
     * @return array{0: User, 1: User, 2: Recipe, 3: Post}
     */
    private function zapowiedz(string $tytul = 'Bigos z kapusty kiszonej'): array
    {
        $basia = $this->user('basia');
        $ola = $this->user('ola');

        $ola->following()->attach($basia->getKey(), ['created_at' => now()->subMonth()]);

        $przepis = app(PublishRecipe::class)->handle(
            author: $basia,
            attributes: ['title' => $tytul, 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: [['text' => 'kapusta kiszona']],
            steps: [['instruction' => 'Gotuj powoli, przez trzy godziny.']],
            publish: true,
        );

        /** @var Post $zapowiedz */
        $zapowiedz = Post::query()->where('recipe_id', $przepis->getKey())->firstOrFail();

        $this->assertSame(
            Post::VISIBILITY_PUBLIC,
            $zapowiedz->visibility,
            'Zapowiedź przepisu nie jest publiczna — wtedy odciąłby ją zwykły filtr widoczności '
            .'wpisu i te testy przechodziłyby z niewłaściwego powodu.',
        );

        $this->assertNull(
            $zapowiedz->body,
            'Zapowiedź ma własną treść — wtedy szablon listu wchodzi w gałąź `filled($wpis->body)` '
            .'i w ogóle nie dotyka tytułu przepisu, czyli nie mierzymy tego, co trzeba.',
        );

        return [$basia, $ola, $przepis, $zapowiedz];
    }

    private function zbierz(User $odbiorca): TrescDigestu
    {
        return app(ZbierzTresciDigestu::class)->dlaJednej($odbiorca->fresh());
    }

    /**
     * Oba warianty listu naraz — HTML i tekstowy.
     *
     * Wersja tekstowa NIE jest ozdobą i ma tę samą gałąź z tytułem
     * (`podsumowanie-tygodnia-tekst.blade.php`). Naprawa sprawdzona wyłącznie
     * na HTML-u zostawiłaby wyciek w wariancie, który u części odbiorców jest
     * jedynym czytanym.
     */
    private function listy(TrescDigestu $tresc): string
    {
        $list = new PodsumowanieTygodnia($tresc);

        // `render()` oddaje wariant HTML; wersję tekstową składamy osobno,
        // przez ten sam widok i te same zmienne, których używa `Content::text`.
        return $list->render()."\n".view('mail.podsumowanie-tygodnia-tekst', [
            'tresc' => $tresc,
            'imie' => $tresc->odbiorca->displayName(),
            'gospodarz' => config('kuking.community.host_name'),
            'wypisz' => OdnosnikWypisania::dla($tresc->odbiorca),
        ])->render();
    }

    /**
     * KONTROLA DODATNIA CAŁEGO PLIKU.
     *
     * Bez niej każda asercja „tytułu nie ma" przechodziłaby także wtedy, gdy
     * list jest pusty z dowolnego innego powodu — bo okno czasowe nie objęło
     * wpisu, bo obserwowanie nie zapisało się tak, jak myślę, albo bo
     * zapowiedź w ogóle nie powstała. Dokładnie ten rodzaj pomyłki złapała
     * kontrola dodatnia przy stronie wpisu.
     */
    public function test_tytul_widocznego_przepisu_w_liscie_jest(): void
    {
        [, $ola, , $zapowiedz] = $this->zapowiedz();

        $tresc = $this->zbierz($ola);

        $this->assertContains(
            (string) $zapowiedz->getKey(),
            collect($tresc->wpisyObserwowanych)->map(fn (Post $w): string => (string) $w->getKey())->all(),
            'Zapowiedź publicznego przepisu nie weszła do listu — asercje niżej nie mówiłyby wtedy '
            .'o bramce przepisu, tylko o pustym liście.',
        );

        $this->assertStringContainsString(
            'Bigos z kapusty kiszonej',
            $this->listy($tresc),
            'List nie wypisuje tytułu nawet dla przepisu w pełni publicznego — gałąź szablonu '
            .'`@elseif($wpis->recipe !== null)` nie odpala się i nie ma czego mierzyć.',
        );
    }

    /**
     * DRUGA KONTROLA DODATNIA, TYM RAZEM PRZED NADGORLIWOŚCIĄ.
     *
     * Bramka liczona „po staremu", czyli widokiem gościa
     * (`zWidocznymPrzepisem(null)`), przepuściłaby tylko przepisy `public`
     * i wycięłaby z listu przepisy „tylko dla obserwujących" — mimo że
     * adresat autora OBSERWUJE i na stronie widzi je bez przeszkód.
     * Ten test oblewa na takiej naprawie i jest jedynym, który ją odróżnia
     * od poprawnej.
     */
    public function test_przepis_tylko_dla_obserwujacych_zostaje_w_liscie_obserwujacego(): void
    {
        [$basia, $ola, $przepis] = $this->zapowiedz();

        $przepis->forceFill(['visibility' => 'followers'])->save();

        $this->assertTrue(
            $ola->fresh()->isFollowing($basia),
            'Ola nie obserwuje Basi — wtedy przepis „tylko dla obserwujących" jest dla niej '
            .'niewidoczny zgodnie z ustawieniem i ten test nie mierzy nadgorliwości.',
        );

        $this->assertStringContainsString(
            'Bigos z kapusty kiszonej',
            $this->listy($this->zbierz($ola)),
            'Bramka wycięła z listu przepis „tylko dla obserwujących", choć adresat obserwuje '
            .'autora i widzi ten przepis na stronie. Poprawne dane nigdy nie znikają.',
        );
    }

    public function test_przepis_schowany_do_prywatnego_nie_wychodzi_listem(): void
    {
        [, $ola, $przepis, $zapowiedz] = $this->zapowiedz();

        $przepis->forceFill(['visibility' => 'private'])->save();

        $this->assertSame(
            1,
            Post::query()->whereKey($zapowiedz->getKey())->count(),
            'Zapowiedź zniknęła razem ze schowaniem przepisu — nie ma czego mierzyć.',
        );

        $tresc = $this->zbierz($ola);

        $this->assertNotContains(
            (string) $zapowiedz->getKey(),
            collect($tresc->wpisyObserwowanych)->map(fn (Post $w): string => (string) $w->getKey())->all(),
            'Zapowiedź prywatnego przepisu weszła do treści listu.',
        );

        $this->assertStringNotContainsString(
            'Bigos z kapusty kiszonej',
            $this->listy($tresc),
            'Tytuł przepisu schowanego do prywatnego wyszedł pocztą poza serwis. '
            .'Listu raz wysłanego nie da się cofnąć.',
        );
    }

    public function test_przepis_zdjety_przez_moderacje_nie_wychodzi_listem(): void
    {
        [, $ola, $przepis, $zapowiedz] = $this->zapowiedz();

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $this->assertSame(
            1,
            Post::query()->whereKey($zapowiedz->getKey())->count(),
            'Zapowiedź zniknęła razem z ukryciem przepisu — nie ma czego mierzyć.',
        );

        $this->assertStringNotContainsString(
            'Bigos z kapusty kiszonej',
            $this->listy($this->zbierz($ola)),
            'Tytuł przepisu zdjętego przez moderację wyszedł pocztą poza serwis — i to do osoby, '
            .'która na stronie dostałaby 403.',
        );
    }
}
