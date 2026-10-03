<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zakres „Nieprzeczytane” na liście powiadomień (#2442, V2, decyzja właściciela
 * z 2.10.2026).
 *
 * Zakres to dodatkowy warunek `read_at IS NULL` w tym samym odczycie, który
 * stosuje ten sam filtr widoczności co lista, plakietka i „Oznacz wszystkie”.
 * Wejście na stronę, zmiana zakresu i kolejna strona NIE zmieniają `read_at`.
 * Każda scena ujemna ma kontrolę dodatnią obok.
 */
class PowiadomieniaFiltrNieprzeczytanychTest extends TestCase
{
    use RefreshDatabase;

    public function test_starsza_nieprzeczytana_za_ponad_trzydziestoma_przeczytanymi_jest_na_pierwszej_stronie_zakresu(): void
    {
        $ala = $this->user('ala_filtr');
        $nadawca = $this->user('nadawca_filtr');
        // 31 nowszych, już przeczytanych, i jedna stara, wciąż nieprzeczytana.
        for ($i = 0; $i < 31; $i++) {
            $this->powiadomienie($ala, $nadawca, now()->subMinutes($i), przeczytane: true, tekst: 'przeczytana-'.$i);
        }
        $this->powiadomienie($ala, $nadawca, now()->subDays(40), przeczytane: false, tekst: 'zalegla-stara');

        // Kontrola dodatnia: w zakresie „wszystkie” pierwsza strona jej nie ma.
        $wszystkie = $this->actingAs($ala)->get(route('notifications.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('zalegla-stara', $wszystkie);

        $nieprzeczytane = $this->actingAs($ala)->get(route('notifications.index', ['zakres' => 'nieprzeczytane']))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('zalegla-stara', $nieprzeczytane);
        $this->assertStringNotContainsString('przeczytana-', $nieprzeczytane);
        $this->assertStringContainsString('Oznacz wszystkie jako przeczytane', $nieprzeczytane);
    }

    public function test_zakres_zachowuje_kolejnosc_i_nie_pokazuje_cudzych_ani_przeczytanych(): void
    {
        $ala = $this->user('ala_kolejnosc');
        $basia = $this->user('basia_kolejnosc');
        $nadawca = $this->user('nadawca_kolejnosc');
        $this->powiadomienie($ala, $nadawca, now()->subHours(3), false, 'trzecie-najstarsze');
        $this->powiadomienie($ala, $nadawca, now()->subHours(1), false, 'pierwsze-najnowsze');
        $this->powiadomienie($ala, $nadawca, now()->subHours(2), false, 'drugie-srodkowe');
        $this->powiadomienie($ala, $nadawca, now()->subMinutes(5), true, 'przeczytane-pominiete');
        $this->powiadomienie($basia, $nadawca, now(), false, 'cudze-pominiete');

        $html = $this->actingAs($ala)->get(route('notifications.index', ['zakres' => 'nieprzeczytane']))->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'drugie-srodkowe'), strpos($html, 'pierwsze-najnowsze'));
        $this->assertLessThan(strpos($html, 'trzecie-najstarsze'), strpos($html, 'drugie-srodkowe'));
        $this->assertStringNotContainsString('przeczytane-pominiete', $html);
        $this->assertStringNotContainsString('cudze-pominiete', $html);

        // Kontrola dodatnia: druga osoba widzi swoje.
        $this->assertStringContainsString('cudze-pominiete', $this->actingAs($basia)->get(route('notifications.index', ['zakres' => 'nieprzeczytane']))->getContent());
    }

    public function test_odczyt_zmiana_zakresu_i_kolejna_strona_nie_zmieniaja_read_at(): void
    {
        $ala = $this->user('ala_readat');
        $nadawca = $this->user('nadawca_readat');
        for ($i = 0; $i < 35; $i++) {
            $this->powiadomienie($ala, $nadawca, now()->subMinutes($i), false, 'n-'.$i);
        }
        $this->assertSame(35, $this->nieprzeczytane($ala), 'Kontrola sceny.');

        foreach ([[], ['zakres' => 'nieprzeczytane'], ['zakres' => 'nieprzeczytane', 'page' => 2], ['page' => 2]] as $parametry) {
            $this->actingAs($ala)->get(route('notifications.index', $parametry))->assertOk();
        }

        $this->assertSame(35, $this->nieprzeczytane($ala), 'Samo oglądanie listy oznaczyło powiadomienia jako przeczytane.');
    }

    public function test_domyslnie_wszystkie_a_nieznany_zakres_to_pelna_lista(): void
    {
        $ala = $this->user('ala_domyslny');
        $nadawca = $this->user('nadawca_domyslny');
        $this->powiadomienie($ala, $nadawca, now()->subMinute(), true, 'przeczytane-widoczne');
        $this->powiadomienie($ala, $nadawca, now(), false, 'nieprzeczytane-widoczne');

        foreach ([[], ['zakres' => 'cokolwiek'], ['zakres' => ['x']]] as $parametry) {
            $html = $this->actingAs($ala)->get(route('notifications.index', $parametry))->assertOk()->getContent();
            $this->assertStringContainsString('przeczytane-widoczne', $html);
            $this->assertStringContainsString('nieprzeczytane-widoczne', $html);
        }

        $html = $this->actingAs($ala)->get(route('notifications.index'))->getContent();
        $this->assertMatchesRegularExpression('~<a class="tab" href="[^"]*/powiadomienia"\s+aria-current="page"\s*>Wszystkie</a>~', $html);
        $this->assertDoesNotMatchRegularExpression('~zakres=nieprzeczytane" aria-current~', $html);
    }

    public function test_zakres_jest_semantycznie_oznaczony_i_przechodzi_do_nastepnej_strony(): void
    {
        $ala = $this->user('ala_strony');
        $nadawca = $this->user('nadawca_strony');
        for ($i = 0; $i < 35; $i++) {
            $this->powiadomienie($ala, $nadawca, now()->subMinutes($i), false, 'n-'.$i);
        }

        $strona1 = $this->actingAs($ala)->get(route('notifications.index', ['zakres' => 'nieprzeczytane']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~zakres=nieprzeczytane"\s+aria-current="page"~', $strona1);
        $this->assertStringContainsString('Następna strona powiadomień', $strona1);
        $this->assertMatchesRegularExpression('~zakres=nieprzeczytane&amp;page=2|page=2&amp;zakres=nieprzeczytane~', $strona1, 'Następna strona zgubiła zakres.');
        // Zmiana zakresu zaczyna od pierwszej strony: linki zakresów nie niosą `page`.
        $this->assertDoesNotMatchRegularExpression('~class="tab" href="[^"]*page=~', $strona1);

        $strona2 = $this->actingAs($ala)->get(route('notifications.index', ['zakres' => 'nieprzeczytane', 'page' => 2]))->assertOk()->getContent();
        $this->assertStringContainsString('n-34', $strona2);
        $this->assertStringNotContainsString('Następna strona powiadomień', $strona2);
    }

    public function test_pusty_zakres_mowi_ze_nie_ma_nieprzeczytanych_a_historia_zostaje(): void
    {
        $ala = $this->user('ala_pusty');
        $nadawca = $this->user('nadawca_pusty');
        $this->powiadomienie($ala, $nadawca, now(), true, 'stara-historia');

        $html = $this->actingAs($ala)->get(route('notifications.index', ['zakres' => 'nieprzeczytane']))->assertOk()->getContent();

        $this->assertStringContainsString('Nie masz nieprzeczytanych powiadomień', $html);
        $this->assertStringNotContainsString('Nie ma jeszcze żadnych powiadomień', $html);
        $this->assertStringContainsString('Pokaż wszystkie powiadomienia', $html);
        $this->assertStringNotContainsString('Oznacz wszystkie jako przeczytane', $html);

        // Kontrola dodatnia: w pełnej liście historia jest.
        $this->assertStringContainsString('stara-historia', $this->actingAs($ala)->get(route('notifications.index'))->getContent());

        // Konto bez żadnych powiadomień dostaje dotychczasowy komunikat w pełnej liście.
        $nowa = $this->user('ala_zupelnie_pusta');
        $this->actingAs($nowa)->get(route('notifications.index'))->assertSee('Nie ma jeszcze żadnych powiadomień');
    }

    public function test_pusta_dalsza_strona_po_odczycie_w_drugiej_karcie_ma_droge_do_pierwszej(): void
    {
        $ala = $this->user('ala_karta');
        $nadawca = $this->user('nadawca_karta');
        $wiersze = [];
        for ($i = 0; $i < 35; $i++) {
            $wiersze[] = $this->powiadomienie($ala, $nadawca, now()->subMinutes($i), false, 'n-'.$i);
        }
        // Ktoś (druga karta) przeczytał to, co leżało na stronie drugiej: 5 najstarszych.
        DB::table('notifications')->whereIn('id', array_map(fn ($w) => $w, array_slice($wiersze, 30)))->update(['read_at' => now()]);

        $html = $this->actingAs($ala)->get(route('notifications.index', ['zakres' => 'nieprzeczytane', 'page' => 2]))->assertOk()->getContent();

        $this->assertStringContainsString('Ta strona jest już pusta', $html);
        $this->assertStringContainsString('Wróć do pierwszej strony nieprzeczytanych', $html);
        // To nie jest „brak wszystkich nieprzeczytanych”: pierwsza strona nadal je ma.
        $this->assertStringNotContainsString('Nie masz nieprzeczytanych powiadomień', $html);
        $this->assertStringContainsString('Oznacz wszystkie jako przeczytane', $html);
    }

    public function test_oznacz_wszystkie_obejmuje_nieprzeczytane_spoza_biezacej_strony_i_wraca_do_zakresu(): void
    {
        $ala = $this->user('ala_wszystkie');
        $nadawca = $this->user('nadawca_wszystkie');
        for ($i = 0; $i < 35; $i++) {
            $this->powiadomienie($ala, $nadawca, now()->subMinutes($i), false, 'n-'.$i);
        }
        $adres = route('notifications.index', ['zakres' => 'nieprzeczytane']);

        $this->actingAs($ala)->from($adres)->post(route('notifications.read'))->assertRedirect($adres);

        $this->assertSame(0, $this->nieprzeczytane($ala), '„Oznacz wszystkie” nie objęło nieprzeczytanych poza bieżącą stroną.');
        $this->actingAs($ala)->get($adres)->assertSee('Nie masz nieprzeczytanych powiadomień');
    }

    public function test_blokada_i_ukryty_status_nadawcy_nie_wracaja_przez_zakres(): void
    {
        $ala = $this->user('ala_blokada');
        $basia = $this->user('basia_blokada', ['display_name' => 'od-basi']);
        $celina = $this->user('celina_blokada', ['display_name' => 'od-celiny']);
        $this->powiadomienie($ala, $basia, now()->subMinute(), false, 'od-basi');
        $this->powiadomienie($ala, $celina, now(), false, 'od-celiny');
        $adres = route('notifications.index', ['zakres' => 'nieprzeczytane']);

        // Kontrola dodatnia: przed blokadą oba.
        $przed = $this->actingAs($ala)->get($adres)->getContent();
        $this->assertStringContainsString('od-basi', $przed);
        $this->assertStringContainsString('od-celiny', $przed);

        app(BlockUser::class)->handle($ala, $basia);
        $poBlokadzie = $this->actingAs($ala)->get($adres)->getContent();
        $this->assertStringNotContainsString('od-basi', $poBlokadzie);
        $this->assertStringContainsString('od-celiny', $poBlokadzie);

        // Blokada w drugą stronę.
        app(UnblockUser::class)->handle($ala, $basia);
        $this->assertStringContainsString('od-basi', $this->actingAs($ala)->get($adres)->getContent());
        app(BlockUser::class)->handle($basia, $ala);
        $this->assertStringNotContainsString('od-basi', $this->actingAs($ala)->get($adres)->getContent());
        app(UnblockUser::class)->handle($basia, $ala);

        $celina->forceFill(['status' => User::STATUSY_UKRYWAJACE_TRESC[0]])->save();
        $this->assertStringNotContainsString('od-celiny', $this->actingAs($ala)->get($adres)->getContent());
    }

    public function test_powiadomienie_bez_celu_zostaje_w_zakresie_i_da_sie_je_oznaczyc(): void
    {
        $ala = $this->user('ala_bez_celu');
        $nadawca = $this->user('nadawca_bez_celu');
        $this->powiadomienie($ala, $nadawca, now(), false, 'bez-celu');
        $this->powiadomienie($ala, $nadawca, now()->subMinute(), false, 'drugi-bez-celu');

        $html = $this->actingAs($ala)->get(route('notifications.index', ['zakres' => 'nieprzeczytane']))->assertOk()->getContent();

        $this->assertStringContainsString('bez-celu', $html);
        $this->assertMatchesRegularExpression('~(Zobacz|Oznacz jako przeczytane)~', $html);
    }

    public function test_zakres_nie_dodaje_pelnego_count_i_liczba_zapytan_nie_rosnie_z_liczba_wierszy(): void
    {
        $ala = $this->user('ala_zapytania');
        $nadawca = $this->user('nadawca_zapytania');
        $adres = route('notifications.index', ['zakres' => 'nieprzeczytane']);
        $licz = function () use ($ala, $adres): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($ala)->get($adres)->assertOk();
            $zapytania = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            return $zapytania;
        };

        for ($i = 0; $i < 3; $i++) {
            $this->powiadomienie($ala, $nadawca, now()->subMinutes($i), false, 'n-'.$i);
        }
        $malo = $licz();
        for ($i = 3; $i < 33; $i++) {
            $this->powiadomienie($ala, $nadawca, now()->subMinutes($i), false, 'n-'.$i);
        }
        $duzo = $licz();

        $this->assertSame(count($malo), count($duzo), 'Liczba zapytań rośnie z liczbą powiadomień.');
        foreach ($duzo as $sql) {
            $this->assertDoesNotMatchRegularExpression('/select count\(\*\) as aggregate from "notifications"/i', $sql, 'Zakres dołożył pełne COUNT(*) po powiadomieniach.');
        }
    }

    // -----------------------------------------------------------------

    private function powiadomienie(User $odbiorca, User $nadawca, \DateTimeInterface $kiedy, bool $przeczytane, string $tekst): string
    {
        // Znacznik w treści to imię nadawcy („X zaczyna Cię obserwować”).
        $aktor = $nadawca->displayName() === $tekst ? $nadawca : $this->user('akt_'.substr(md5($tekst.microtime()), 0, 14), ['display_name' => $tekst]);

        return (string) DB::table('notifications')->insertGetId([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => $aktor->getKey(),
            'type' => Notification::TYPE_FOLLOW,
            'data' => json_encode(['username' => 'x'], JSON_UNESCAPED_UNICODE),
            'read_at' => $przeczytane ? now() : null,
            'created_at' => $kiedy,
        ]);
    }

    private function nieprzeczytane(User $odbiorca): int
    {
        return DB::table('notifications')->where('user_id', $odbiorca->getKey())->whereNull('read_at')->count();
    }
}
