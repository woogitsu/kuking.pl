<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Które profile trafiają do mapy strony (audyt SEO/PWA-02).
 *
 * NA CZYM POLEGAŁ BŁĄD
 * Nagłówek `SitemapController` obiecuje, że do mapy idą „profile, które mają
 * co najmniej jedną publiczną treść", a `docs/seo/SEO_TECHNICAL.md` §3 —
 * „profil z ≥1 publiczną treścią". Zapytanie pytało jednak wyłącznie
 * o `user.posts`. Autorka, która przepisała do Kuking dziesięć przepisów po
 * babci i ani razu nie dodała zwykłego wpisu ze zdjęciem, nie istniała
 * w mapie strony — mimo że jej profil jest dokładnie tą treścią, po którą
 * ta mapa istnieje. Kod nie robił tego, co mówił jego własny komentarz.
 *
 * DLACZEGO ASERCJE IDĄ PO `<loc>`, A NIE PO CAŁYM XML-U
 * Nazwa użytkownika wraca w mapie także w adresach jego przepisów i wpisów
 * (a `assertStringContainsString` po całej odpowiedzi tego nie odróżnia).
 * Zbieramy więc listę adresów z `<loc>` i pytamy o dokładne wystąpienie —
 * inaczej test byłby zielony również wtedy, gdyby profilu w mapie nie było.
 */
class MapaStronyProfileAutorowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Wszystkie adresy z mapy strony.
     *
     * @return list<string>
     */
    private function adresyZMapy(): array
    {
        // Mapa jest cache'owana na 6 godzin (`sitemap.urls`), więc bez tego
        // drugi przypadek w tym samym pliku oglądałby wynik pierwszego.
        Cache::flush();

        $tresc = $this->get(route('sitemap'))->assertOk()->getContent();

        $poprzedni = libxml_use_internal_errors(true);
        $xml = simplexml_load_string((string) $tresc);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzedni);

        $this->assertNotFalse($xml, 'Mapa strony nie jest poprawnym XML-em.');

        $adresy = [];

        foreach ($xml->url as $url) {
            $adresy[] = (string) $url->loc;
        }

        // ASERCJA KONTROLNA: pusta mapa nie może wyglądać jak „nie ma tego
        // profilu". Bez tego każdy przypadek negatywny przechodziłby też
        // wtedy, gdyby cała mapa przestała się generować.
        $this->assertNotEmpty($adresy, 'Mapa strony jest pusta — asercje niżej nic nie znaczą.');

        return $adresy;
    }

    public function test_autor_samego_publicznego_przepisu_jest_w_mapie(): void
    {
        $autorka = $this->user('halina');

        Recipe::factory()->for($autorka, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $this->assertSame(
            0,
            Post::query()->where('author_id', $autorka->getKey())->count(),
            'Ten przypadek ma sens tylko wtedy, gdy autorka NIE MA ani jednego wpisu.',
        );

        $this->assertContains(
            route('profile.show', 'halina'),
            $this->adresyZMapy(),
            'Profil autorki, która opublikowała przepis, ale nie dodała żadnego wpisu, '
            .'nie trafił do mapy strony. To jest dokładnie ta treść, po którą mapa istnieje.',
        );
    }

    /**
     * Kontrola ujemna do przypadku wyżej: sam fakt POSIADANIA przepisu nie
     * wystarcza. Przepis nieopublikowany albo nie-publiczny nie może wpuścić
     * profilu do mapy — inaczej „naprawa" polegałaby na tym, że do mapy idzie
     * każdy, kto kiedykolwiek zaczął pisać przepis.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function niepublicznePrzepisy(): array
    {
        return [
            'tylko dla obserwujących' => ['followers', ['visibility' => 'followers']],
            'prywatny' => ['private', ['visibility' => 'private']],
            'szkic' => ['draft', ['status' => Recipe::STATUS_DRAFT, 'published_at' => null]],
        ];
    }

    #[DataProvider('niepublicznePrzepisy')]
    public function test_autor_samego_nieopublikowanego_przepisu_nie_jest_w_mapie(
        string $opis,
        array $nadpisania,
    ): void {
        $autorka = $this->user('halina');

        Recipe::factory()->for($autorka, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            ...$nadpisania,
        ]);

        $this->assertNotContains(
            route('profile.show', 'halina'),
            $this->adresyZMapy(),
            "Mapa strony ogłasza profil, którego jedyny przepis jest „{$opis}”. "
            .'Wyszukiwarka dostaje adres bez treści — a to jest cienka treść na skalę.',
        );
    }

    /**
     * Warunek, który już istniał i którego nie wolno było zepsuć przy
     * dokładaniu przepisów: `widocznyJakoOsoba()` (D-022).
     *
     * Rozszerzenie warunku o `orWhereHas` jest tu realnym ryzykiem, bo `OR`
     * bez nawiasu rozrywa poprzedni `WHERE` — i mapa zaczęłaby ogłaszać
     * profile kont zbanowanych, kasowanych i usuniętych. Dlatego ten
     * przypadek chodzi po WSZYSTKICH statusach, a nie tylko po zbanowanym:
     * konto zawieszone ma w mapie ZOSTAĆ (kara za pisanie nie kasuje tego,
     * co ktoś już napisał), więc test pilnuje granicy z obu stron.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function statusyKonta(): array
    {
        return [
            'aktywne' => [User::STATUS_ACTIVE, true],
            'zawieszone' => [User::STATUS_SUSPENDED, true],
            'zbanowane' => [User::STATUS_BANNED, false],
            'kasowane' => [User::STATUS_PENDING_DELETE, false],
            'usunięte' => [User::STATUS_ERASED, false],
        ];
    }

    #[DataProvider('statusyKonta')]
    public function test_status_konta_decyduje_mimo_publicznego_przepisu(
        string $status,
        bool $powinienByc,
    ): void {
        $autorka = $this->user('halina');

        Recipe::factory()->for($autorka, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        // `status` nigdy nie jest w `$fillable` (AGENTS.md §7). Stan `erased`
        // ma własną nazwaną metodę, bo baza nie pozwala rozdzielić statusu
        // od `data_erased_at` (CHECK `users_data_erased_at_check`, D-022) —
        // `forceFill(['status' => 'erased'])` odbiłby się od ograniczenia.
        if ($status === User::STATUS_ERASED) {
            $autorka->markDataErased();
        } else {
            $autorka->forceFill(['status' => $status])->save();
        }

        $adresy = $this->adresyZMapy();
        $profil = route('profile.show', 'halina');

        if ($powinienByc) {
            $this->assertContains(
                $profil,
                $adresy,
                "Profil konta o statusie „{$status}” wypadł z mapy strony.",
            );
        } else {
            $this->assertNotContains(
                $profil,
                $adresy,
                "Mapa strony ogłasza profil konta o statusie „{$status}”. Wyszukiwarka dostaje "
                .'adres, pod którym zwykły człowiek dostaje 403 albo stronę bez treści.',
            );
        }
    }

    /**
     * Wpisy nadal wpuszczają profil do mapy — czyli nowy warunek jest
     * „wpis ALBO przepis", a nie „przepis zamiast wpisu".
     *
     * Bez tego przypadku poprawka mogłaby po cichu przenieść błąd na drugą
     * stronę i nikt by tego nie zauważył: obie połowy warunku wyglądają
     * w kodzie tak samo.
     */
    public function test_autor_samego_publicznego_wpisu_dalej_jest_w_mapie(): void
    {
        $autor = $this->user('marek');

        Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subDay(),
            'body' => 'Rosół wyszedł złoty.',
        ]);

        $this->assertSame(
            0,
            Recipe::query()->where('author_id', $autor->getKey())->count(),
            'Ten przypadek ma sens tylko wtedy, gdy autor NIE MA ani jednego przepisu.',
        );

        $this->assertContains(
            route('profile.show', 'marek'),
            $this->adresyZMapy(),
            'Profil autora samych wpisów wypadł z mapy strony — poprawka przeniosła błąd '
            .'na drugą stronę warunku.',
        );
    }

    /**
     * DWA STANY, W KTÓRYCH WADA NAPRAWDĘ DOSIĘGAŁA PRODUKCJI.
     *
     * Przypadki wyżej zakładają przepis BEZ zapowiedzi i dlatego trzymają
     * `Recipe::factory()`. W serwisie tak się nie zaczyna: publikacja
     * przepisu zakłada zapowiedź — wpis z `recipe_id` i pustym `body`,
     * publiczny — więc profil wchodził do mapy „przy okazji" i na świeżym
     * koncie wada w ogóle się nie pokazywała. Zmierzone: autor z jednym
     * publicznym przepisem JEST w mapie, dopóki zapowiedź stoi.
     *
     * Wada wychodziła dopiero wtedy, gdy zapowiedź znikała — autor ją
     * skasował albo moderacja ją ukryła. Mapa nadal ogłaszała publiczny
     * przepis, ale już nie autora, który go napisał. O obecności profilu
     * decydowała więc zapowiedź, a nie treść autora.
     *
     * Te dwa przypadki idą pełną drogą (`PublishRecipe`), żeby pilnować
     * wady takiej, jaką widział człowiek — a nie tylko stanu złożonego
     * fabryką.
     */
    private function autorkaPoPublikacjiPrzepisu(string $nazwa): User
    {
        $autorka = $this->user($nazwa);

        app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: [
                'title' => 'Żurek na zakwasie',
                'visibility' => 'public',
                'source_type' => 'own',
            ],
            ingredients: [['text' => 'zakwas żytni']],
            steps: [['instruction' => 'Zagotuj wywar i wlej zakwas.']],
            publish: true,
        );

        $this->assertSame(
            1,
            Post::query()->where('author_id', $autorka->getKey())->count(),
            'Publikacja przepisu przestała zakładać zapowiedź — te dwa przypadki '
            .'mierzą wtedy co innego, niż opisuje ich nagłówek.',
        );

        return $autorka;
    }

    public function test_profil_zostaje_w_mapie_gdy_autorka_skasowala_zapowiedz(): void
    {
        $autorka = $this->autorkaPoPublikacjiPrzepisu('zurkowa');

        Post::query()->where('author_id', $autorka->getKey())->delete();

        $this->assertContains(
            route('profile.show', 'zurkowa'),
            $this->adresyZMapy(),
            'Po skasowaniu zapowiedzi mapa ogłasza przepis, ale nie autorkę, '
            .'która go napisała.',
        );
    }

    public function test_profil_zostaje_w_mapie_gdy_moderacja_ukryla_zapowiedz(): void
    {
        $autorka = $this->autorkaPoPublikacjiPrzepisu('pierogowa');

        Post::query()
            ->where('author_id', $autorka->getKey())
            ->update(['status' => 'hidden']);

        $this->assertContains(
            route('profile.show', 'pierogowa'),
            $this->adresyZMapy(),
            'Ukryta przez moderację zapowiedź wypchnęła autorkę z mapy, choć jej '
            .'przepis nadal jest publiczny.',
        );
    }
}
