<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tags\Actions\MergeTags;
use App\Models\Tag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #996 — graf scaleń tagów ma w BAZIE dokładnie jeden skok do
 * aktywnego celu (`tags_merged_not_self_check` + wyzwalacz
 * `tags_scalenie_jednym_skokiem_trg`).
 *
 * Każdy zapis idzie SUROWYM `DB::table('tags')`, z pominięciem `MergeTags`:
 * test ma mierzyć barierę PostgreSQL, a nie walidację w PHP, którą bariera
 * ma zastąpić na każdej innej drodze zapisu (import, seeder, konsola).
 *
 * Każda próba odrzucana idzie w zagnieżdżonej transakcji (savepoint):
 * w PostgreSQL nieudane zapytanie przerywa całą transakcję, a
 * `RefreshDatabase` trzyma test w jednej.
 *
 * Równoległość (dwa połączenia) mierzy osobno
 * `Tests\Dwa\ScalenieTagowNaDwochPolaczeniachTest`.
 *
 * @bez-kontroli-dodatniej plik migracji jest wczytywany tylko po to, żeby wywołać `up()`/`down()` na bazie; asercje dotyczą zachowania PostgreSQL, a kontrola dodatnia jest w samym teście (poprawne scalenie przechodzi, ten sam niespójny graf przechodzi po `down()`).
 */
class GrafScalenTagowWBazieTest extends TestCase
{
    use RefreshDatabase;

    private const PLIK = 'migrations/2026_09_24_100000_scalenia_tagow_jednym_skokiem_do_aktywnego.php';

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    private function tag(string $status = Tag::STATUS_ACTIVE): Tag
    {
        $tag = Tag::factory()->create();

        if ($status !== Tag::STATUS_ACTIVE) {
            DB::table('tags')->where('id', $tag->getKey())->update(['status' => $status]);
        }

        return $tag;
    }

    private function scalSurowo(Tag $zrodlo, Tag $cel): void
    {
        DB::table('tags')->where('id', $zrodlo->getKey())->update([
            'status' => Tag::STATUS_MERGED,
            'merged_into_tag_id' => $cel->getKey(),
        ]);
    }

    private function assertBazaOdrzuca(callable $zapis, string $oczekiwane, string $dlaczego): void
    {
        try {
            DB::transaction($zapis);
        } catch (QueryException $e) {
            $this->assertStringContainsString($oczekiwane, $e->getMessage(), $dlaczego);

            return;
        }

        $this->fail('PostgreSQL przyjął niespójny graf scaleń: '.$dlaczego);
    }

    public function test_scalenie_w_samego_siebie_odbija_sie_o_baze(): void
    {
        $tag = $this->tag();

        $this->assertBazaOdrzuca(
            fn () => $this->scalSurowo($tag, $tag),
            'tags_merged_not_self_check',
            'UPDATE: A → A nie ma kanonicznego końca.',
        );

        $id = (string) Str::uuid();
        $this->assertBazaOdrzuca(
            fn () => DB::table('tags')->insert([
                'id' => $id, 'name' => 'Pętla', 'normalized_name' => 'pętla', 'slug' => 'petla',
                'status' => Tag::STATUS_MERGED, 'merged_into_tag_id' => $id,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'tags_merged_not_self_check',
            'INSERT: nowy wiersz wskazujący sam siebie.',
        );
    }

    public function test_cel_ukryty_albo_scalony_odbija_sie_o_baze(): void
    {
        $zrodlo = $this->tag();
        $ukryty = $this->tag(Tag::STATUS_HIDDEN);
        $aktywny = $this->tag();
        $scalony = $this->tag();
        $this->scalSurowo($scalony, $aktywny);

        $this->assertBazaOdrzuca(
            fn () => $this->scalSurowo($zrodlo, $ukryty),
            'mozna scalic tylko w aktywny tag',
            'Cel ukryty — strona tagu przekierowałaby na tag, którego nie ma.',
        );
        $this->assertBazaOdrzuca(
            fn () => $this->scalSurowo($zrodlo, $scalony),
            'mozna scalic tylko w aktywny tag',
            'Cel scalony — łańcuch A → B → C, a `tagKanoniczny()` robi jeden skok.',
        );
    }

    public function test_lancuch_i_cykl_powstale_od_strony_celu_odbijaja_sie_o_baze(): void
    {
        $a = $this->tag();
        $b = $this->tag();
        $c = $this->tag();
        $this->scalSurowo($a, $b);

        $this->assertBazaOdrzuca(
            fn () => $this->scalSurowo($b, $c),
            'jest celem innych scalen',
            'A → B, potem B → C: łańcuch zbudowany „od tyłu”.',
        );
        $this->assertBazaOdrzuca(
            fn () => $this->scalSurowo($b, $a),
            // Tu łapie już strona 1 (A jest scalony), a gdyby jej nie było —
            // strona 2. Obie treści są poprawną odmową cyklu.
            'scal',
            'A → B, potem B → A: cykl.',
        );
        $this->assertBazaOdrzuca(
            fn () => DB::table('tags')->where('id', $b->getKey())->update(['status' => Tag::STATUS_HIDDEN]),
            'jest celem innych scalen',
            'Ukrycie celu, na który wskazuje scalony tag.',
        );
    }

    public function test_poprawne_scalenia_przechodza(): void
    {
        // Kontrola dodatnia — bariera odrzucająca każde scalenie przeszłaby
        // wszystkie testy wyżej.
        $a = $this->tag();
        $b = $this->tag();
        $this->scalSurowo($a, $b);
        $this->assertSame($b->getKey(), $a->refresh()->merged_into_tag_id);
        $this->assertSame($b->getKey(), $a->tagKanoniczny()->getKey());

        // Zmiana nazwy i `updated_at` celu nie budzi wyzwalacza ani go nie myli.
        DB::table('tags')->where('id', $b->getKey())->update(['name' => 'Nowa nazwa', 'updated_at' => now()]);

        // Droga domenowa: scalenie celu dalej przepina dawne źródła PRZED
        // oznaczeniem celu jako scalonego, więc przechodzi przez barierę.
        $c = $this->tag();
        $wynik = app(MergeTags::class)->handle($b->refresh(), $c);
        $this->assertSame($c->getKey(), $wynik->getKey());
        $this->assertSame($c->getKey(), $a->refresh()->merged_into_tag_id);
        $this->assertSame($c->getKey(), $b->refresh()->merged_into_tag_id);

        // Ukrycie tagu, na który nic nie wskazuje, nadal działa.
        $samotny = $this->tag();
        DB::table('tags')->where('id', $samotny->getKey())->update(['status' => Tag::STATUS_HIDDEN]);
        $this->assertSame(Tag::STATUS_HIDDEN, $samotny->refresh()->status);
    }

    public function test_migracja_odmawia_przy_istniejacym_lancuchu_i_niczego_nie_zmienia(): void
    {
        $migracja = $this->migracja();
        $migracja->down();

        $a = $this->tag();
        $b = $this->tag();
        $c = $this->tag();
        $this->scalSurowo($b, $c);
        // Bez bariery łańcuch A → B → C wchodzi — to jest stan, który
        // migracja ma wykryć (i zarazem kontrola, że `down()` zdjął barierę).
        $this->scalSurowo($a, $b);

        try {
            $migracja->up();
            $this->fail('Migracja założyła barierę mimo istniejącego łańcucha scaleń.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cel scalenia sam jest scalony (łańcuch albo cykl): 1', $e->getMessage());
            $this->assertStringContainsString('docs/diagnostyka/996_graf_scalen_tagow.sql', $e->getMessage());
        }

        $this->assertSame($b->getKey(), $a->refresh()->merged_into_tag_id, 'Migracja zmieniła dane, zamiast odmówić.');
        $this->assertFalse($this->barieraIstnieje(), 'Migracja odmówiła, ale zostawiła połowę bariery.');

        // Po ręcznej naprawie (A prosto do C) ta sama migracja przechodzi.
        DB::table('tags')->where('id', $a->getKey())->update(['merged_into_tag_id' => $c->getKey()]);
        $migracja->up();
        $this->assertTrue($this->barieraIstnieje());
    }

    public function test_rollback_zdejmuje_bariere_bez_utraty_danych(): void
    {
        $a = $this->tag();
        $b = $this->tag();
        $this->scalSurowo($a, $b);
        $this->assertTrue($this->barieraIstnieje());

        $this->migracja()->down();

        $this->assertFalse($this->barieraIstnieje());
        $this->assertSame($b->getKey(), $a->refresh()->merged_into_tag_id);
    }

    private function barieraIstnieje(): bool
    {
        $wyzwalacz = DB::selectOne(
            "SELECT count(*) AS n FROM pg_trigger WHERE tgname = 'tags_scalenie_jednym_skokiem_trg' AND tgrelid = 'tags'::regclass",
        );
        $check = DB::selectOne(
            "SELECT count(*) AS n FROM pg_constraint WHERE conname = 'tags_merged_not_self_check' AND conrelid = 'tags'::regclass",
        );

        $this->assertSame((int) $wyzwalacz->n, (int) $check->n, 'Wyzwalacz i CHECK mają istnieć razem albo wcale.');

        return (int) $wyzwalacz->n === 1;
    }
}
