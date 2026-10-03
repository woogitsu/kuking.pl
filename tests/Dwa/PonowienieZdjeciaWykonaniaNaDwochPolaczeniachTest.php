<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

#[Group('dwa-polaczenia')]
final class PonowienieZdjeciaWykonaniaNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    public function test_ten_sam_klucz_czeka_przed_utworzeniem_media(): void
    {
        $kucharz = $this->konto(['email' => 'zdjecia2811-'.Str::random(12).'@example.invalid']);
        $autor = $this->konto(['email' => 'autor2811-'.Str::random(12).'@example.invalid']);
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public',
        ]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'cooked_at' => now()->subDay(),
        ]);
        $klucz = (string) Str::uuid7();
        $nazwa = 'zdjecie2811-'.bin2hex(random_bytes(6));
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2812, hashtext(?))', [$nazwa]);
        $argumenty = [
            'user' => (string) $kucharz->getKey(), 'event' => (string) $wykonanie->getKey(),
            'key' => $klucz, 'barrier' => $nazwa,
        ];
        $srodowisko = ['DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path()];
        $pierwszy = ProcesRownolegly::start(__DIR__.'/bin/dolacz-zdjecie-2811.php', 'first', $argumenty, $srodowisko);

        try {
            $this->czekajNaZablokowane(1); // pierwszy trzyma klucz wysłania i czeka na barierze
            $drugi = ProcesRownolegly::start(__DIR__.'/bin/dolacz-zdjecie-2811.php', 'second', $argumenty, $srodowisko);
            try {
                $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
                while ($this->ilu() < 2 && microtime(true) < $koniec) {
                    usleep(20_000);
                }
                $this->assertGreaterThanOrEqual(2, $this->ilu(), 'DOLACZENIE_2811_RYWAL_CZEKA_PRZED_MEDIA');
            } finally {
                $this->zwolnijBariere($bariera);
            }

            $wynikPierwszy = $pierwszy->wynik();
            $wynikDrugi = $drugi->wynik();
            $this->assertTrue($wynikPierwszy['ok'], $wynikPierwszy['komunikat']);
            $this->assertTrue($wynikDrugi['ok'], $wynikDrugi['komunikat']);
            $this->assertSame('added', $wynikPierwszy['wartosc'], 'DOLACZENIE_2811_PIERWSZY_DOLACZA');
            $this->assertSame('duplicate', $wynikDrugi['wartosc'], 'DOLACZENIE_2811_DRUGI_POMINIETY');
            $this->assertSame(1, DB::table('cooked_event_media')->where('cooked_event_id', $wykonanie->getKey())->count());
            $this->assertSame(1, Media::query()->where('owner_id', $kucharz->getKey())->count(), 'DOLACZENIE_2811_WYSCIG_BEZ_OSIEROCONEGO_MEDIA');
        } finally {
            if ($bariera->inTransaction()) {
                $this->zwolnijBariere($bariera);
            }
            $pierwszy->zabij();
            DB::table('cooked_event_media')->where('cooked_event_id', $wykonanie->getKey())->delete();
            Media::query()->where('owner_id', $kucharz->getKey())->delete();
            $wykonanie->delete();
            $przepis->delete();
        }
    }
}
