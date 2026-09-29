<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\OdczytPowiadomien;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\UnblockUser;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `OdczytPowiadomien` (#1687, etap 4) — jedno wejście dla listy, liczników,
 * „Oznacz wszystkie" i otwarcia pojedynczego powiadomienia.
 *
 * Pilnowane są dwie rzeczy, które rozjeżdżały się, gdy każde miejsce składało
 * filtr po swojemu (#969, #1401, #1351):
 *  1. wszystkie wejścia widzą TEN SAM zbiór — blokada ukrywa wiersz na liście,
 *     w obu licznikach, w „Oznacz wszystkie" i przy otwarciu, a odblokowanie
 *     przywraca go wszędzie naraz;
 *  2. liczba zapytań strony nie rośnie z liczbą wierszy (#833).
 */
class OdczytPowiadomienTest extends TestCase
{
    use RefreshDatabase;

    private function odczyt(): OdczytPowiadomien
    {
        return app(OdczytPowiadomien::class);
    }

    private function obserwuje(User $odbiorca, User $nadawca): Notification
    {
        return Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => $nadawca->getKey(),
            'type' => Notification::TYPE_FOLLOW,
            'data' => ['username' => 'x'],
        ]);
    }

    /** @return list<string> */
    private function identyfikatory(User $odbiorca): array
    {
        return collect($this->odczyt()->strona($odbiorca)->items())
            ->map(fn (Notification $n): string => (string) $n->getKey())
            ->sort()
            ->values()
            ->all();
    }

    public function test_blokada_ukrywa_wiersz_we_wszystkich_wejsciach_naraz(): void
    {
        $ala = $this->user('alaodczyt');
        $basia = $this->user('basiaodczyt');
        $celina = $this->user('celinaodczyt');

        $odBasi = $this->obserwuje($ala, $basia);
        $odCeliny = $this->obserwuje($ala, $celina);

        // Kontrola dodatnia: przed blokadą oba wiersze są wszędzie.
        $this->assertCount(2, $this->identyfikatory($ala));
        $this->assertSame(2, $this->odczyt()->liczbaNieprzeczytanych($ala));
        $this->assertSame(2, $this->odczyt()->liczbaDoPlakietki($ala));
        $this->assertNotNull($this->odczyt()->widoczne($ala, (string) $odBasi->getKey()));

        app(BlockUser::class)->handle($ala, $basia);

        $this->assertSame([(string) $odCeliny->getKey()], $this->identyfikatory($ala));
        $this->assertSame(1, $this->odczyt()->liczbaNieprzeczytanych($ala));
        $this->assertSame(1, $this->odczyt()->liczbaDoPlakietki($ala));
        $this->assertNull($this->odczyt()->widoczne($ala, (string) $odBasi->getKey()));
        $this->assertNotNull($this->odczyt()->widoczne($ala, (string) $odCeliny->getKey()));

        // „Oznacz wszystkie" nie dotyka wiersza, którego człowiek nie widzi.
        $this->assertSame(1, $this->odczyt()->oznaczWszystkieJakoPrzeczytane($ala));
        $this->assertNull($odBasi->fresh()->read_at);
        $this->assertNotNull($odCeliny->fresh()->read_at);

        // Odblokowanie przywraca wiersz wszędzie naraz, wciąż nieprzeczytany.
        app(UnblockUser::class)->handle($ala, $basia);

        $this->assertContains((string) $odBasi->getKey(), $this->identyfikatory($ala));
        $this->assertSame(1, $this->odczyt()->liczbaNieprzeczytanych($ala));
        $this->assertNotNull($this->odczyt()->widoczne($ala, (string) $odBasi->getKey()));
    }

    public function test_metody_uzytkownika_licza_to_samo_co_serwis(): void
    {
        $ala = $this->user('alalicznik');
        $basia = $this->user('basialicznik');

        $this->obserwuje($ala, $basia);
        $this->obserwuje($ala, $this->user('celinalicznik'));
        app(BlockUser::class)->handle($ala, $basia);

        $this->assertSame($this->odczyt()->liczbaNieprzeczytanych($ala), $ala->unreadNotificationsCount());
        $this->assertSame($this->odczyt()->liczbaDoPlakietki($ala), $ala->unreadNotificationsBadgeCount());
        $this->assertSame(1, $ala->unreadNotificationsCount());
    }

    public function test_cudze_powiadomienie_nie_otwiera_sie_przez_identyfikator(): void
    {
        $ala = $this->user('alaobca');
        $basia = $this->user('basiaobca');
        $cudze = $this->obserwuje($basia, $this->user('celinaobca'));

        // UUID w adresie to nie autoryzacja (AGENTS.md §7).
        $this->assertNull($this->odczyt()->widoczne($ala, (string) $cudze->getKey()));
        $this->assertFalse($this->odczyt()->ukrytePrzezBrakTresciKomentarza($ala, (string) $cudze->getKey()));
    }

    public function test_powiadomienie_bez_komentarza_nie_udaje_ukrytego_przez_brak_tresci(): void
    {
        $ala = $this->user('alabrak');
        $basia = $this->user('basiabrak');
        $ala->refresh();

        // Wiersz ukryty blokadą i typu spoza komentarzy: kontroler ma dać 404,
        // więc serwis nie może go opisać jako „komentarza już nie ma".
        $odBasi = $this->obserwuje($ala, $basia);
        app(BlockUser::class)->handle($ala, $basia);

        $this->assertFalse($this->odczyt()->ukrytePrzezBrakTresciKomentarza($ala, (string) $odBasi->getKey()));
    }

    public function test_liczba_zapytan_strony_nie_rosnie_z_liczba_wierszy(): void
    {
        $ala = $this->user('alazapytania');

        $this->wierszeRoznychTypow($ala, 2);
        $malo = $this->zapytaniaStrony($ala);

        $this->wierszeRoznychTypow($ala, 8);
        $duzo = $this->zapytaniaStrony($ala);

        // Kontrola dodatnia: pomiar dotyczy wielu wierszy tych typów, których
        // stan serwis dociąga zbiorczo (wykonanie, przepis).
        $this->assertSame(30, $ala->notifications()->count());
        $typy = collect($this->odczyt()->strona($ala)->items())->pluck('type')->unique()->sort()->values()->all();
        $this->assertContains(Notification::TYPE_COOKED, $typy);
        $this->assertContains(Notification::TYPE_SAVED, $typy);

        $this->assertSame($malo, $duzo, "Zapytania strony rosną z liczbą wierszy: {$malo} przy 6, {$duzo} przy 30.");
    }

    /**
     * Stan celu jest doładowany zbiorczo: pytanie o usunięte wykonanie i przepis
     * po odczycie strony nie robi już żadnego zapytania.
     */
    public function test_stan_wykonan_i_przepisow_jest_doladowany_zbiorczo(): void
    {
        $ala = $this->user('alastan');
        $this->wierszeRoznychTypow($ala, 5);

        $strona = $this->odczyt()->strona($ala);

        DB::flushQueryLog();
        DB::enableQueryLog();
        foreach ($strona->items() as $powiadomienie) {
            $powiadomienie->wykonanieUsuniete();
            $powiadomienie->przepisUsuniety();
        }
        $dodatkowe = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $dodatkowe, 'Serwis nie doładował stanu wykonań/przepisów — widok pytałby o każdy wiersz osobno.');
    }

    private function zapytaniaStrony(User $odbiorca): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $strona = $this->odczyt()->strona($odbiorca);
        foreach ($strona->items() as $powiadomienie) {
            $powiadomienie->wykonanieUsuniete();
            $powiadomienie->przepisUsuniety();
        }
        $ile = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $ile;
    }

    /** Po $ile wierszy: obserwowanie, ugotowanie i zapis przepisu (cele bez rekordów). */
    private function wierszeRoznychTypow(User $odbiorca, int $ile): void
    {
        $nadawca = $this->user('nadawca'.Str::lower(Str::random(6)));

        for ($i = 0; $i < $ile; $i++) {
            Notification::create([
                'user_id' => $odbiorca->getKey(),
                'actor_id' => $nadawca->getKey(),
                'type' => Notification::TYPE_FOLLOW,
                'data' => ['username' => 'x'],
            ]);
            Notification::create([
                'user_id' => $odbiorca->getKey(),
                'actor_id' => $nadawca->getKey(),
                'type' => Notification::TYPE_COOKED,
                'data' => ['cooked_event_id' => (string) Str::uuid()],
            ]);
            Notification::create([
                'user_id' => $odbiorca->getKey(),
                'actor_id' => $nadawca->getKey(),
                'type' => Notification::TYPE_SAVED,
                'data' => ['recipe_id' => (string) Str::uuid()],
            ]);
        }
    }
}
