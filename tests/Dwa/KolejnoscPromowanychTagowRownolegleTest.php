<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Tags\PromowaneTagi;
use App\Models\Tag;
use App\Models\TagPromotion;
use PDOException;
use PHPUnit\Framework\Attributes\Group;

/**
 * RÓWNOLEGŁE ZMIANY LISTY TAGÓW PROMOWANYCH NA DWÓCH POŁĄCZENIACH (#1308).
 *
 * Dawny `TagPromotionController` liczył `max(position) + 1` i wybierał
 * sąsiada do zamiany BEZ wspólnej blokady, na stanie odczytanym przed
 * zapisem. Dwa równoległe żądania dawały dwie promocje z tą samą pozycją,
 * a dwa przesunięcia przez wspólnego sąsiada — pozycję nadpisaną przez
 * drugie. `PromowaneTagi` bierze blokadę listy PRZED odczytem.
 *
 * ── PRZEPLOT ──
 *
 * Dodanie: bariera trzyma oba wiersze `tags` pod `FOR UPDATE`, a `INSERT
 * INTO tag_promotions` sprawdza klucz obcy `tag_id` (`FOR KEY SHARE`) i staje
 * za nią. Przesunięcie: bariera trzyma wiersz `tag_promotions` wspólnego
 * sąsiada, którego obie zamiany muszą zapisać.
 *
 *   z blokadą listy                       bez niej (stan sprzed #1308)
 *   ────────────────────────────────      ────────────────────────────────
 *   A: blokada listy, czyta stan,         A: czyta stan, czeka na barierę
 *      czeka na barierę                   B: czyta TEN SAM stan, czeka
 *   B: czeka na BLOKADĘ LISTY             zwolnienie: A i B zapisują
 *   zwolnienie: A zatwierdza, B czyta        decyzje z tego samego odczytu
 *   stan PO A i decyduje na nowo          → ta sama pozycja dwa razy
 *
 * KONTROLA UJEMNA (wykonana ręcznie przy #1308): po usunięciu
 * `TagMutationLock::forPromotions()` z `PromowaneTagi` oba testy oblewają —
 * dodanie na dwóch równych pozycjach, przesunięcie na zdublowanej pozycji
 * i złej kolejności.
 */
#[Group('dwa-polaczenia')]
final class KolejnoscPromowanychTagowRownolegleTest extends TestDwochPolaczen
{
    private const PREFIKS = 'wyscig1308-';

    /** @var list<string> */
    private array $tagi = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Sprzątanie po identyfikatorach (zasada 3) — tu po prefiksie sluga,
        // bo tagi nie należą do kont. Zostawione przez przerwany przebieg
        // wiersze przesunęłyby „koniec listy", na którym stoi ten test.
        Tag::query()->where('slug', 'like', self::PREFIKS.'%')->delete();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->tagi !== []) {
                $sprzataczka = $this->nowePolaczenie();
                $sprzataczka->prepare('DELETE FROM tags WHERE id = ANY(?::uuid[])')
                    ->execute(['{'.implode(',', $this->tagi).'}']);
            }
        } catch (PDOException $e) {
            fwrite(STDERR, "\nSprzątanie tagów po teście nie powiodło się: ".$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_dwa_rownolegle_dodania_dostaja_rozne_pozycje(): void
    {
        app(PromowaneTagi::class)->dodaj($this->tag('istniejacy'));
        $zupy = $this->tag('zupy');
        $ciasta = $this->tag('ciasta');

        $bariera = $this->bariera(
            'SELECT 1 FROM tags WHERE id = ANY(?::uuid[]) FOR UPDATE',
            ['{'.$zupy->getKey().','.$ciasta->getKey().'}'],
        );

        $pierwszy = $this->wTle('promuj-tag', ['tag' => (string) $zupy->getKey()]);
        $this->czekajNaZablokowane(1);

        $drugi = $this->wTle('promuj-tag', ['tag' => (string) $ciasta->getKey()]);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $this->assertObaZmienily($pierwszy->wynik(), $drugi->wynik(), 'dodanie');

        $pozycje = TagPromotion::query()
            ->whereIn('tag_id', [$zupy->getKey(), $ciasta->getKey()])
            ->pluck('position')
            ->all();

        $this->assertCount(2, $pozycje);
        $this->assertCount(2, array_unique($pozycje), 'Dwa równoległe dodania dostały tę samą pozycję: '.implode(', ', $pozycje));
    }

    public function test_dwa_rownolegle_przesuniecia_przez_wspolnego_sasiada_nie_gubia_zmiany(): void
    {
        $promowane = app(PromowaneTagi::class);
        [$a, $b, $c] = [$this->tag('a'), $this->tag('b'), $this->tag('c')];
        $promowane->dodaj($a);
        $promowane->dodaj($b);
        $promowane->dodaj($c);

        $bariera = $this->bariera('SELECT 1 FROM tag_promotions WHERE tag_id = ? FOR UPDATE', [(string) $b->getKey()]);

        // C w górę (zamiana z B), potem A w dół — po pierwszej zmianie
        // sąsiadem A jest już C, nie B.
        $pierwszy = $this->wTle('przesun-promowany', ['tag' => (string) $c->getKey(), 'kierunek' => '-1']);
        $this->czekajNaZablokowane(1);

        $drugi = $this->wTle('przesun-promowany', ['tag' => (string) $a->getKey(), 'kierunek' => '1']);
        $this->czekajNaZablokowane(2);

        $this->zwolnijBariere($bariera);

        $this->assertObaZmienily($pierwszy->wynik(), $drugi->wynik(), 'przesunięcie');

        $moje = TagPromotion::query()
            ->whereIn('tag_id', [$a->getKey(), $b->getKey(), $c->getKey()])
            ->wKolejnosci()
            ->get();

        $this->assertCount(3, array_unique($moje->pluck('position')->all()), 'Przesunięcia zostawiły zdublowaną pozycję.');
        $this->assertSame(
            [(string) $c->getKey(), (string) $a->getKey(), (string) $b->getKey()],
            $moje->pluck('tag_id')->map(fn ($id): string => (string) $id)->all(),
            'Drugie przesunięcie nadpisało pierwsze zamiast zadziałać na stanie po nim.',
        );
    }

    private function tag(string $nazwa): Tag
    {
        $slug = self::PREFIKS.$nazwa.'-'.bin2hex(random_bytes(3));
        $tag = Tag::create(['slug' => $slug, 'name' => $slug, 'normalized_name' => $slug]);
        $this->tagi[] = (string) $tag->getKey();

        return $tag;
    }

    /**
     * KONTROLA DODATNIA: oba procesy doszły do końca i oba zameldowały
     * zmianę. Bez tego test przeszedłby, gdyby któryś padł przed zapisem.
     *
     * @param  array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}  $pierwszy
     * @param  array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string}  $drugi
     */
    private function assertObaZmienily(array $pierwszy, array $drugi, string $co): void
    {
        $this->assertBezZakleszczenia($pierwszy, "pierwsze {$co}");
        $this->assertBezZakleszczenia($drugi, "drugie {$co}");
        $this->assertTrue($pierwszy['ok'], "Pierwsze {$co} padło: ".$pierwszy['komunikat']);
        $this->assertTrue($drugi['ok'], "Drugie {$co} padło: ".$drugi['komunikat']);
        $this->assertTrue($pierwszy['wartosc'], "Pierwsze {$co} nie zameldowało zmiany.");
        $this->assertTrue($drugi['wartosc'], "Drugie {$co} nie zameldowało zmiany.");
    }
}
