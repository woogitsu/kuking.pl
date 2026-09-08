<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PAGINACJA NIE GUBI ANI NIE POWTARZA WIERSZY.
 *
 * SKĄD SIĘ WZIĄŁ TEN PLIK
 * Z czerwonego CI, nie z przeglądu kodu. `KomentarzeStronamiTest` padł raz na
 * kilkanaście przebiegów, na dwóch identycznych commitach raz zielony, raz
 * czerwony — czyli objaw, który najłatwiej odbębnić jako „migotanie".
 * Migotanie było prawdziwe, ale przyczyna nie była testowa.
 *
 * CO JEST ZEPSUTE NAPRAWDĘ
 * Wszystkie znaczniki czasu w tym schemacie to `timestamptz(0)` — sekundowa
 * dokładność, bo tak działa `timestampsTz()` w migracjach. Trzydzieści
 * komentarzy dodanych w pętli ma więc JEDNĄ I TĘ SAMĄ wartość `created_at`.
 *
 * `ORDER BY created_at` przy trzydziestu identycznych wartościach nie określa
 * kolejności: PostgreSQL może oddać te wiersze w dowolnej i przy każdym
 * zapytaniu w innej. A paginacja to DWA osobne zapytania z `LIMIT`/`OFFSET`.
 * Gdy porządek remisów zmieni się między nimi, ten sam komentarz wychodzi na
 * dwóch stronach, a inny nie wychodzi NIGDZIE.
 *
 * DLACZEGO TO NIE JEST TYLKO PROBLEM TESTU
 * Nie trzeba pętli, żeby trafić w remis — wystarczy, że dwie osoby skomentują
 * w tej samej sekundzie. Wtedy czyjś komentarz znika ze strony 2, mimo że
 * został zapisany poprawnie. UX_50_PLUS.md nazywa to wprost: poprawne dane
 * nigdy nie znikają. Ten test pilnuje właśnie tego zdania, a nie zielonego CI.
 *
 * DLACZEGO `id` JAKO DRUGI KLUCZ
 * `id` jest UUID-em v7 (`HasUuids` w Laravelu 12+), czyli rośnie z czasem
 * z dokładnością do milisekundy. Rozstrzyga remis i rozstrzyga go
 * CHRONOLOGICZNIE — dlatego drugi test niżej sprawdza nie samą stabilność,
 * ale konkretną, poprawną kolejność. Ten sam wzorzec stał już w `DiscoverFeed`
 * i `TagController`; listom paginowanym `LIMIT`/`OFFSET` go brakowało.
 */
class PaginacjaNieGubiAniNiePowtarzaTest extends TestCase
{
    use RefreshDatabase;

    private const ILE = 30;

    /** Rozmiar strony komentarzy z `config/kuking.php`. */
    private int $naStronie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->naStronie = (int) config('kuking.comments.page_size');
    }

    public function test_zadny_komentarz_nie_wypada_i_zaden_sie_nie_dubluje(): void
    {
        $przepis = $this->przepisZKomentarzamiWJednejSekundzie();

        $zebrane = [];
        $stron = (int) ceil(self::ILE / $this->naStronie);

        for ($strona = 1; $strona <= $stron; $strona++) {
            $naTejStronie = $this->numeryKomentarzy($przepis, $strona);

            $powtorzone = array_intersect($zebrane, $naTejStronie);

            $this->assertSame(
                [],
                array_values($powtorzone),
                "Strona {$strona} pokazała komentarze, które były już wcześniej: "
                .implode(', ', $powtorzone).'. Paginacja bez rozstrzygnięcia remisu '
                .'przestawia wiersze między zapytaniami.',
            );

            $zebrane = array_merge($zebrane, $naTejStronie);
        }

        sort($zebrane);

        $this->assertSame(
            range(1, self::ILE),
            $zebrane,
            'Po przejściu wszystkich stron brakuje komentarzy albo doszły '
            .'nadprogramowe. Ktoś napisał komentarz, serwis go przyjął, a potem '
            .'nie pokazał go na żadnej stronie.',
        );
    }

    public function test_kolejnosc_jest_chronologiczna_mimo_identycznego_znacznika_czasu(): void
    {
        $przepis = $this->przepisZKomentarzamiWJednejSekundzie();

        // KONTROLA: pierwsza strona w ogóle się zapełniła. Bez tej asercji
        // pusty wynik przeszedłby przez `assertSame` niżej tylko dlatego, że
        // porównywalibyśmy dwie puste listy.
        $pierwsza = $this->numeryKomentarzy($przepis, 1);

        $this->assertCount(
            $this->naStronie,
            $pierwsza,
            'Pierwsza strona nie ma pełnego kompletu komentarzy — dalsze '
            .'porównanie kolejności nic by nie znaczyło.',
        );

        $this->assertSame(
            range(1, $this->naStronie),
            $pierwsza,
            'Komentarze z tej samej sekundy wyszły w kolejności innej niż ta, '
            .'w której powstały. `id` jest UUID-em v7, więc jako drugi klucz '
            .'sortowania ma dawać porządek chronologiczny, a nie dowolny.',
        );
    }

    /**
     * Trzydzieści komentarzy z IDENTYCZNYM `created_at`.
     *
     * Znacznik jest dociskany zapytaniem, a nie zostawiony zegarowi: pętla
     * zwykle i tak mieści się w jednej sekundzie, ale „zwykle" to za mało dla
     * testu, który ma pilnować właśnie tego przypadku.
     */
    private function przepisZKomentarzamiWJednejSekundzie(): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->user('kucharka')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        for ($i = 1; $i <= self::ILE; $i++) {
            Comment::create([
                'recipe_id' => $przepis->getKey(),
                'author_id' => $this->user('gosc'.$i)->getKey(),
                'body' => 'Komentarz numer '.$i,
                'status' => Comment::STATUS_PUBLISHED,
            ]);
        }

        DB::table('comments')
            ->where('recipe_id', $przepis->getKey())
            ->update(['created_at' => '2026-09-08 12:00:00+00']);

        return $przepis;
    }

    /**
     * Numery komentarzy widoczne na danej stronie, w kolejności wyświetlenia.
     *
     * @return list<int>
     */
    private function numeryKomentarzy(Recipe $przepis, int $strona): array
    {
        $html = $this->get(route('recipes.show', $przepis->slug).'?komentarze='.$strona)
            ->assertOk()
            ->getContent();

        preg_match_all('/Komentarz numer (\d+)/u', (string) $html, $trafienia);

        return array_map(intval(...), $trafienia[1]);
    }
}
