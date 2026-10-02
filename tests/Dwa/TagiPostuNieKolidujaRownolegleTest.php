<?php

declare(strict_types=1);

namespace Tests\Dwa;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dwa równoległe wpisy z NOWYM tagiem (`ResolveTagsForPost`).
 *
 * `znajdzAlboUtworz()` wylicza wolny slug przez `exists()`, a insert idzie
 * osobno, więc dwa procesy mogą wybrać ten sam slug. Semantyka (docs/DATABASE.md,
 * `Tag::slugDlaNazwy`): TA SAMA nazwa to TEN SAM tag (`normalized_name UNIQUE`,
 * `firstOrCreate` pod savepointem), a RÓŻNE nazwy o wspólnym slugu („żurek"
 * i „zurek") to dwa tagi, z których drugi dostaje slug z numerem.
 *
 * Bariera (blokada doradcza) stoi w zdarzeniu `creating` tagu: po wyliczeniu
 * slugu, przed insertem — oba procesy NA PEWNO mają tego samego kandydata.
 */
#[Group('dwa-polaczenia')]
final class TagiPostuNieKolidujaRownolegleTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $nazwy = [];

    protected function tearDown(): void
    {
        foreach ($this->nazwy as $nazwa) {
            try {
                $ids = DB::table('tags')->where('normalized_name', $nazwa)->pluck('id')->all();
                DB::table('post_tags')->whereIn('tag_id', $ids)->delete();
                DB::table('tags')->whereIn('id', $ids)->delete();
            } catch (\Throwable $e) {
                fwrite(STDERR, "\nNie udało się posprzątać tagów testu: ".$e->getMessage()."\n");
            }
        }
        $this->nazwy = [];

        parent::tearDown();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} */
    private function dwaRownolegle(string $nazwaA, string $nazwaB): array
    {
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2404, 1)', []);

        $a = $this->wTle('rozwiaz-tagi-z-bariera', ['nazwa' => $nazwaA]);
        $b = $this->wTle('rozwiaz-tagi-z-bariera', ['nazwa' => $nazwaB]);

        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikA = $a->wynik();
        $wynikB = $b->wynik();

        $this->assertBezZakleszczenia($wynikA, 'pierwszy wpis z tagiem');
        $this->assertBezZakleszczenia($wynikB, 'drugi wpis z tagiem');

        return [$wynikA, $wynikB];
    }

    public function test_ten_sam_nowy_tag_w_dwoch_wpisach_to_jeden_tag(): void
    {
        $nazwa = 'parówkibabci'.bin2hex(random_bytes(4));
        $this->nazwy[] = $nazwa;

        [$a, $b] = $this->dwaRownolegle($nazwa, $nazwa);

        $this->assertSame(
            [true, true],
            [$a['ok'], $b['ok']],
            'Równoległy zapis tego samego tagu skończył się błędem: '
            .$a['wyjatek'].' '.$a['komunikat'].' | '.$b['wyjatek'].' '.$b['komunikat'],
        );
        $this->assertSame(
            1,
            DB::table('tags')->where('normalized_name', $nazwa)->count(),
            'Ta sama nazwa musi dać jeden wiersz tagu, nie dwa.',
        );
        $this->assertSame($a['wartosc'], $b['wartosc'], 'Oba wpisy dostają TEN SAM tag.');
    }

    public function test_dwie_nazwy_o_wspolnym_slugu_dostaja_rozne_slugi_bez_bledu(): void
    {
        $sufiks = bin2hex(random_bytes(4));
        $zAkcentem = 'żurek'.$sufiks;
        $bezAkcentu = 'zurek'.$sufiks;
        $this->nazwy[] = $zAkcentem;
        $this->nazwy[] = $bezAkcentu;

        [$a, $b] = $this->dwaRownolegle($zAkcentem, $bezAkcentu);

        $this->assertSame(
            [true, true],
            [$a['ok'], $b['ok']],
            'Równoległy zapis tagów o wspólnym slugu skończył się błędem zamiast slugiem z numerem: '
            .$a['wyjatek'].' '.$a['komunikat'].' | '.$b['wyjatek'].' '.$b['komunikat'],
        );

        $slugi = DB::table('tags')->whereIn('normalized_name', [$zAkcentem, $bezAkcentu])->pluck('slug')->all();

        $this->assertCount(2, $slugi, 'Oba tagi muszą istnieć (to dwie różne nazwy).');
        $this->assertCount(2, array_unique($slugi), 'Slugi muszą być różne.');
    }
}
