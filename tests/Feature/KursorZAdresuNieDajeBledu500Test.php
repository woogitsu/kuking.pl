<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\User;
use App\Support\KursorListy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * KURSOR Z ADRESU, KTÓRY NIE PASUJE DO LISTY, DAJE PIERWSZĄ STRONĘ — NIE 500 (#2308).
 *
 * `Cursor::fromEncoded()` Laravela przyjmuje każdy JSON z kluczem
 * `_pointsToNextItems`. Przed #2308 `/tag/{slug}?cursor=eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9`
 * (czyli `{"_pointsToNextItems":true}`) kończył się `UnexpectedValueException`,
 * a kursor z właściwymi kluczami i napisem `abc` zamiast UUID-u albo czasu —
 * `SQLSTATE[22P02]`/`[22007]` z Postgresa. Zmierzone na `origin/main`
 * (b1c96678f): HTTP 500 na tagu, „Poradźcie”, starcie (obserwowani),
 * szkicach przepisów i liście blokad w ustawieniach prywatności.
 *
 * Każda lista kursorowa idzie teraz przez `App\Support\KursorListy`. Każdy
 * przypadek niżej ma KONTROLĘ DODATNIĄ (pułapka 4): prawdziwy odnośnik
 * „następna strona” z tej samej listy daje drugą stronę. Bez niej „zły
 * kursor = pierwsza strona” przechodziłoby także wtedy, gdyby lista
 * odrzucała każdy kursor.
 */
class KursorZAdresuNieDajeBledu500Test extends TestCase
{
    use RefreshDatabase;

    private const UUID = '01a0f1ee-92ba-71f7-953c-3968ecd6dad8';

    /** @return array<string, array{0: string}> */
    public static function listy(): array
    {
        return [
            'tag' => ['tag'],
            'Poradźcie' => ['pytania'],
            'Start: obserwowani' => ['obserwowani'],
            'Odkrywanie' => ['odkrywanie'],
            'szkice przepisów' => ['szkice'],
            'blokady w ustawieniach' => ['blokady'],
        ];
    }

    /**
     * Złe kursory zbudowane z PRAWDZIWEGO kursora tej listy — te same klucze,
     * więc sprawdzamy typy wartości, a nie tylko to, że klucze się nie zgadzają.
     *
     * @param  array<string, mixed>  $prawdziwy  parametry kursora bez `_pointsToNextItems`
     * @return array<string, array<string, mixed>> opis => parametry kursora
     */
    private static function zleKursory(array $prawdziwy): array
    {
        $kazdy = static fn (mixed $wartosc): array => array_map(static fn () => $wartosc, $prawdziwy);
        $bezPierwszej = $prawdziwy;
        array_shift($bezPierwszej);

        $zle = [
            'sam _pointsToNextItems (zgłoszenie #2308)' => [],
            'bez pierwszej kolumny' => $bezPierwszej,
            'kolumna spoza listy' => $prawdziwy + ['obca_kolumna' => 1],
            'napis we wszystkich kolumnach' => $kazdy('abc'),
            'data, której nie ma, we wszystkich kolumnach' => $kazdy('2026-02-31 25:00:00'),
            'tablica we wszystkich kolumnach' => $kazdy(['a']),
            'liczba ułamkowa we wszystkich kolumnach' => $kazdy(1.5),
        ];
        // Po jednej zepsutej kolumnie, reszta prawdziwa — wtedy odpowiada
        // wyłącznie sprawdzenie typu TEJ kolumny.
        foreach (array_keys($prawdziwy) as $kolumna) {
            $zle["napis w kolumnie {$kolumna}"] = array_replace($prawdziwy, [$kolumna => 'abc']);
        }

        return $zle;
    }

    #[DataProvider('listy')]
    public function test_zly_kursor_daje_pierwsza_strone_a_prawdziwy_druga(string $lista): void
    {
        [$kto, $adres, $klucz] = $this->swiat($lista);

        $pierwsza = $this->wejdz($kto, $adres)->assertOk();
        $idPierwszej = $this->identyfikatory($pierwsza->viewData($klucz)->items());
        $nastepna = $pierwsza->viewData($klucz)->nextPageUrl();
        $this->assertNotSame([], $idPierwszej, "{$lista}: pierwsza strona jest pusta — świat testu się nie zbudował.");
        $this->assertNotNull($nastepna, "{$lista}: lista nie ma drugiej strony — kontrola dodatnia nie ma czego sprawdzić.");

        // KONTROLA DODATNIA: prawdziwy kursor z tej listy przechodzi.
        $druga = $this->wejdz($kto, $nastepna)->assertOk();
        $idDrugiej = $this->identyfikatory($druga->viewData($klucz)->items());
        $this->assertNotSame([], $idDrugiej, "{$lista}: prawdziwy kursor dał pustą stronę.");
        $this->assertSame([], array_intersect($idPierwszej, $idDrugiej),
            "{$lista}: prawdziwy kursor dał znowu pierwszą stronę — KursorListy odrzuca poprawne kursory.");

        $prawdziwy = $druga->viewData($klucz)->cursor()?->toArray();
        $this->assertIsArray($prawdziwy, "{$lista}: druga strona nie niesie kursora, z którego przyszła.");
        unset($prawdziwy['_pointsToNextItems']);
        $this->assertNotSame([], $prawdziwy, "{$lista}: prawdziwy kursor nie ma ani jednej kolumny.");

        foreach (self::zleKursory($prawdziwy) as $opis => $parametry) {
            $zly = $this->podmienKursor($nastepna, $parametry);
            $odpowiedz = $this->wejdz($kto, $zly);

            $this->assertSame(200, $odpowiedz->getStatusCode(),
                "{$lista}, kursor „{$opis}”: HTTP {$odpowiedz->getStatusCode()} zamiast pierwszej strony.");
            $this->assertSame($idPierwszej, $this->identyfikatory($odpowiedz->viewData($klucz)->items()),
                "{$lista}, kursor „{$opis}”: to nie jest pierwsza strona listy.");
        }
    }

    public function test_adres_ze_zgloszenia_otwiera_pierwsza_strone_tagu_i_poradzcie(): void
    {
        config(['kuking.questions.enabled' => true]);
        $tag = Tag::factory()->create();
        $wpis = Post::factory()->create();
        $wpis->tags()->attach($tag->getKey(), ['position' => 0]);
        $pytanie = Post::factory()->question()->create();

        $tagu = $this->get(route('tags.show', $tag).'?cursor=eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9')->assertOk();
        $this->assertSame([(string) $wpis->getKey()], $this->identyfikatory($tagu->viewData('posts')->items()));

        $poradzcie = $this->get(route('questions.index').'?cursor=eyJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9')->assertOk();
        $this->assertSame([(string) $pytanie->getKey()], $this->identyfikatory($poradzcie->viewData('questions')->items()));
    }

    public function test_pasuje_sprawdza_klucze_i_typy(): void
    {
        $kolumny = ['published_at' => KursorListy::CZAS, 'id' => KursorListy::UUID];

        $this->assertTrue(KursorListy::pasuje(new Cursor(['published_at' => '2026-09-30 12:00:00', 'id' => self::UUID]), $kolumny));
        $this->assertTrue(KursorListy::pasuje(new Cursor(['published_at' => '2026-09-30T12:00:00.123456+02:00', 'id' => self::UUID]), $kolumny));
        $this->assertTrue(KursorListy::pasuje(new Cursor(['published_at' => null, 'id' => self::UUID]), $kolumny));
        $this->assertTrue(KursorListy::pasuje(new Cursor(['n' => 3]), ['n' => KursorListy::LICZBA]));
        $this->assertTrue(KursorListy::pasuje(new Cursor(['n' => '3']), ['n' => KursorListy::LICZBA]));

        $this->assertFalse(KursorListy::pasuje(new Cursor([]), $kolumny));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['id' => self::UUID]), $kolumny));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['published_at' => '2026-09-30 12:00:00', 'id' => self::UUID, 'x' => 1]), $kolumny));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['published_at' => '2026-09-30 12:00:00', 'id' => 'abc']), $kolumny));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['published_at' => 'wczoraj', 'id' => self::UUID]), $kolumny));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['published_at' => '2026-13-01 12:00:00', 'id' => self::UUID]), $kolumny));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['published_at' => 1727690000, 'id' => self::UUID]), $kolumny));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['n' => '3.5']), ['n' => KursorListy::LICZBA]));
        $this->assertFalse(KursorListy::pasuje(new Cursor(['n' => ['3']]), ['n' => KursorListy::LICZBA]));
    }

    /**
     * Kolumny podane przez wywołującego muszą być sortowaniem zapytania —
     * inaczej sprawdzalibyśmy kursor innej listy niż ta, którą stronicujemy.
     */
    public function test_kolumny_rozne_od_sortowania_zapytania_to_blad_programisty(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nie są sortowaniem zapytania');

        KursorListy::strona(Post::query()->orderByDesc('published_at')->orderByDesc('id'), 10, ['id' => KursorListy::UUID]);
    }

    /**
     * Nowa lista kursorowa bez `KursorListy` wróciłaby do HTTP 500 na
     * zmyślonym kursorze, a przypadki wyżej by jej nie znały.
     */
    public function test_w_app_cursor_paginate_woła_tylko_kursor_listy(): void
    {
        $pliki = File::allFiles(app_path());
        $this->assertGreaterThan(300, count($pliki), 'Skan nie widzi katalogu app/.');

        $zGolymWywolaniem = [];
        $wKursorListy = false;
        foreach ($pliki as $plik) {
            $zrodlo = (string) file_get_contents($plik->getPathname());
            if (! str_contains($zrodlo, '->cursorPaginate(')) {
                continue;
            }
            if ($plik->getRealPath() === realpath(app_path('Support/KursorListy.php'))) {
                $wKursorListy = true;

                continue;
            }
            $zGolymWywolaniem[] = str_replace(base_path().'/', '', $plik->getPathname());
        }

        // Pułapka 2: wzorzec, który przestał cokolwiek łapać, też daje pustą listę.
        $this->assertTrue($wKursorListy, 'Skan nie znalazł `->cursorPaginate(` nawet w KursorListy — wzorzec przestał działać.');
        $this->assertSame([], $zGolymWywolaniem,
            "Lista kursorowa bez KursorListy — zmyślony `?cursor=` da tam HTTP 500 (#2308). Użyj `KursorListy::strona()`:\n  • "
            .implode("\n  • ", $zGolymWywolaniem));
    }

    // ───────────────────────────── świat ─────────────────────────────

    /**
     * Po trzy pozycje na listę, dwie na stronę — jest pierwsza i druga strona.
     *
     * @return array{0: ?User, 1: string, 2: string} kto, adres pierwszej strony, klucz listy w widoku
     */
    private function swiat(string $lista): array
    {
        config(['kuking.feed.page_size' => 2]);
        $autorka = $this->user('autorka_kursora');

        return match ($lista) {
            'tag' => (function () use ($autorka): array {
                $tag = Tag::factory()->create();
                foreach (range(1, 3) as $n) {
                    Post::factory()->create(['author_id' => $autorka->getKey(), 'published_at' => now()->subMinutes($n)])
                        ->tags()->attach($tag->getKey(), ['position' => 0]);
                }

                return [null, route('tags.show', $tag), 'posts'];
            })(),
            'pytania' => (function () use ($autorka): array {
                config(['kuking.questions.enabled' => true]);
                foreach (range(1, 3) as $n) {
                    Post::factory()->question()->create(['author_id' => $autorka->getKey(), 'published_at' => now()->subMinutes($n)]);
                }

                return [null, route('questions.index'), 'questions'];
            })(),
            'obserwowani' => (function () use ($autorka): array {
                $czytelniczka = $this->user('czytelniczka_kursora');
                $czytelniczka->following()->attach($autorka->getKey());
                foreach (range(1, 3) as $n) {
                    Post::factory()->create(['author_id' => $autorka->getKey(), 'published_at' => now()->subMinutes($n)]);
                }

                return [$czytelniczka, route('home'), 'posts'];
            })(),
            'odkrywanie' => (function (): array {
                foreach (range(1, 3) as $n) {
                    Post::factory()->create(['author_id' => $this->user('autor_odkrywania_'.$n)->getKey(), 'published_at' => now()->subMinutes($n)]);
                }

                return [null, route('discover'), 'posts'];
            })(),
            'szkice' => (function () use ($autorka): array {
                foreach (range(1, 21) as $n) {
                    Recipe::factory()->draft()->create(['author_id' => $autorka->getKey(), 'updated_at' => now()->subMinutes($n)]);
                }

                return [$autorka, route('recipes.drafts'), 'drafts'];
            })(),
            'blokady' => (function () use ($autorka): array {
                foreach (range(1, 21) as $n) {
                    $autorka->blocking()->attach($this->user('blokowana_'.$n)->getKey());
                }

                return [$autorka, route('settings.privacy'), 'blocked'];
            })(),
        };
    }

    private function wejdz(?User $kto, string $adres): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $kto !== null ? $this->actingAs($kto)->get($adres) : $this->get($adres);
    }

    /**
     * Ten sam adres (z `stan`, `zrodlo`), tylko z innym kursorem.
     *
     * @param  array<string, mixed>  $parametry
     */
    private function podmienKursor(string $adres, array $parametry): string
    {
        $kursor = rtrim(strtr(base64_encode((string) json_encode($parametry + ['_pointsToNextItems' => true])), '+/', '-_'), '=');
        $zamieniony = preg_replace('/([?&])cursor=[^&#]*/', '${1}cursor='.$kursor, $adres, 1, $ile);
        $this->assertSame(1, $ile, "Adres następnej strony nie ma kursora: {$adres}");

        return (string) $zamieniony;
    }

    /**
     * @param  iterable<Model>  $pozycje
     * @return list<string>
     */
    private function identyfikatory(iterable $pozycje): array
    {
        $wynik = [];
        foreach ($pozycje as $pozycja) {
            $wynik[] = (string) $pozycja->getKey();
        }

        return $wynik;
    }
}
