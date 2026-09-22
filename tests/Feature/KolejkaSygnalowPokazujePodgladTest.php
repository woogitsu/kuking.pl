<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Moderacja\OcenaModelem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kolejka oznaczeń ma dać się przejrzeć wzrokiem, nie kliknięciami.
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Pierwsze prawdziwe trafienie modelu OpenAI (10 września 2026): właściciel
 * wystawił wpis ze zdjęciem przemocy, model ocenił je poprawnie — i wtedy
 * okazało się, że karta w kolejce pokazuje wyłącznie odnośnik „Otwórz treść
 * i przeczytaj ją". Zgłoszenie właściciela: „trzeba dać jakąś miniaturkę
 * i mniejszym tekstem treść, a nie klikać otwierać treść".
 *
 * Przy jednym oznaczeniu dziennie to nie miało znaczenia. Przy fali z
 * Garnek.pl i modelu oceniającym każde zdjęcie moderator musiałby otwierać
 * kilkadziesiąt kart, żeby dowiedzieć się rzeczy, którą widać w pół sekundy.
 *
 * CZEGO PODGLĄD NIE ZMIENIA
 * Odnośnik zostaje i przed prawdziwą decyzją moderator MUSI go użyć — 240
 * znaków cytatu nie jest podstawą do ukrycia komuś treści. Podgląd służy do
 * odsiewania oczywistych przypadków, nie do orzekania.
 */
class KolejkaSygnalowPokazujePodgladTest extends TestCase
{
    use RefreshDatabase;

    private function moderatorZ2FA(): User
    {
        return $this->moderator();
    }

    private function oznaczonyWpis(User $autor, string $tresc): Post
    {
        $wpis = Post::factory()->for($autor, 'author')->create([
            'body' => $tresc,
            'status' => Post::STATUS_PUBLISHED,
        ]);

        // Fabryki dla `Report` nie ma — te wiersze powstają w produkcji
        // wyłącznie przez `OznaczDoPrzegladu`, a tu potrzebuję dokładnie
        // jednego oznaczenia o znanej treści, bez uruchamiania wykrywacza.
        Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'source' => Report::SOURCE_AUTOMAT,
            'status' => Report::STATUS_OPEN,
            'reason' => OcenaModelem::KOD,
            'details' => 'Model ocenił zdjęcie: przemoc (pewność 86%).',
        ]);

        return $wpis;
    }

    #[Test]
    public function test_kolejka_pokazuje_poczatek_tresci_bez_otwierania_wpisu(): void
    {
        $autor = User::factory()->hasProfile()->create();
        $this->oznaczonyWpis($autor, 'Zupa pomidorowa z ryżem, tak jak robiła moja mama.');

        $this->actingAs($this->moderatorZ2FA())
            ->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertSee('Zupa pomidorowa z ryżem', false)
            // Odnośnik ZOSTAJE — podgląd go nie zastępuje.
            ->assertSee('Otwórz treść i przeczytaj ją', false);
    }

    #[Test]
    public function test_dluga_tresc_jest_urwana_a_nie_wklejona_w_calosci(): void
    {
        $autor = User::factory()->hasProfile()->create();
        $dlugie = str_repeat('rosół z domowym makaronem ', 40);
        $this->oznaczonyWpis($autor, $dlugie);

        $html = (string) $this->actingAs($this->moderatorZ2FA())
            ->get(route('admin.sygnaly'))->assertOk()->getContent();

        $this->assertStringContainsString('rosół z domowym makaronem', $html);
        $this->assertStringContainsString('…', $html, 'Brakuje znaku urwania — cała treść wjechała do kolejki.');
        $this->assertStringNotContainsString(trim($dlugie), $html, 'Cała treść wklejona do kolejki — miała być urwana.');
    }

    #[Test]
    public function test_wpis_ze_zdjeciem_pokazuje_miniature(): void
    {
        $autor = User::factory()->hasProfile()->create();
        $wpis = $this->oznaczonyWpis($autor, 'passeratti');

        // Warianty mieszkają w `metadata['variants']` jako mapa nazwa → dane
        // z kluczem obiektu (patrz `Media::warianty()`), nie jako lista.
        $zdjecie = Media::factory()->for($autor, 'owner')->create([
            'status' => 'ready',
            'metadata' => ['variants' => ['thumb' => ['key' => 'media/thumb.webp']]],
        ]);

        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->actingAs($this->moderatorZ2FA())
            ->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertSee('sygnal-podglad-zdjecie', false)
            ->assertSee(route('media.show', ['media' => $zdjecie->getKey(), 'wariant' => 'thumb']), false);
    }

    /**
     * Zdjęcie, którego warianty jeszcze się przetwarzają, NIE dostaje ikony
     * zastępczej: pusty prostokąt mówiłby moderatorowi „nie ma zdjęcia",
     * a to nieprawda — jest, tylko nie ma czego pokazać.
     */
    #[Test]
    public function test_zdjecie_bez_gotowych_wariantow_nie_udaje_miniatury(): void
    {
        $autor = User::factory()->hasProfile()->create();
        $wpis = $this->oznaczonyWpis($autor, 'passeratti');

        $zdjecie = Media::factory()->for($autor, 'owner')->create([
            'status' => 'pending',
            'metadata' => ['variants' => []],
        ]);

        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->actingAs($this->moderatorZ2FA())
            ->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertDontSee('sygnal-podglad-zdjecie', false);
    }

    /**
     * Podgląd nie może kosztować zapytania na oznaczenie — przy fali
     * migracyjnej kolejka miałaby ich setki.
     */
    #[Test]
    public function test_podglad_nie_robi_wachlarza_zapytan(): void
    {
        $autor = User::factory()->hasProfile()->create();

        for ($i = 0; $i < 3; $i++) {
            $this->oznaczonyWpis($autor, 'trzecia zupa numer '.$i);
        }

        $moderator = $this->moderatorZ2FA();

        DB::enableQueryLog();
        $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $przyTrzech = count(DB::getQueryLog());
        DB::disableQueryLog();

        for ($i = 3; $i < 9; $i++) {
            $this->oznaczonyWpis($autor, 'trzecia zupa numer '.$i);
        }

        // Dziennik CZYSZCZĘ i włączam dopiero po dołożeniu wierszy. Dwie
        // pułapki, w które wdepnąłem po kolei: `enableQueryLog()` po
        // `disable...()` NIE czyści zebranych wpisów (drugi pomiar wychodził
        // dokładnie dwa razy większy), a `INSERT`-y fabryki liczyły się jako
        // zapytania ekranu, jeśli powstawały przy włączonym dzienniku.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($moderator)->get(route('admin.sygnaly'))->assertOk();
        $log = DB::getQueryLog();
        $przyDziewieciu = count($log);
        DB::disableQueryLog();

        if ($przyTrzech !== $przyDziewieciu) {
            $powtorzone = [];
            foreach ($log as $q) {
                $powtorzone[$q['query']] = ($powtorzone[$q['query']] ?? 0) + 1;
            }
            arsort($powtorzone);
            fwrite(STDERR, "\nNAJCZĘSTSZE ZAPYTANIA:\n");
            foreach (array_slice($powtorzone, 0, 4, true) as $sql => $ile) {
                fwrite(STDERR, "  ×{$ile}  ".mb_strimwidth($sql, 0, 150, '…')."\n");
            }
        }

        $this->assertSame(
            $przyTrzech,
            $przyDziewieciu,
            'Liczba zapytań rośnie razem z liczbą oznaczeń ('.$przyTrzech.' → '.$przyDziewieciu.'). '
            .'Podgląd musi dociągać zdjęcia jednym `with()`, nie zapytaniem na wpis.',
        );
    }

    /**
     * Usterka zgłoszona razem z podglądem: pole notatki i przycisk stały
     * na sobie, a etykieta mówiła „(nieobowiązkowa)" dwa razy, bo komponent
     * `x-field` dokłada tę adnotację sam.
     */
    #[Test]
    public function test_etykieta_notatki_nie_powtarza_nieobowiazkowosci(): void
    {
        $autor = User::factory()->hasProfile()->create();
        $this->oznaczonyWpis($autor, 'passeratti');

        $html = (string) $this->actingAs($this->moderatorZ2FA())
            ->get(route('admin.sygnaly'))->assertOk()->getContent();

        $this->assertStringContainsString('Notatka wewnętrzna', $html);
        $this->assertStringNotContainsString('Notatka wewnętrzna (nieobowiązkowa)', $html);
        $this->assertSame(
            1,
            preg_match_all('/\(nieobowiązkowe\)/u', $html),
            'Adnotacja „(nieobowiązkowe)" pada więcej niż raz — dubel wrócił.',
        );
    }
}
