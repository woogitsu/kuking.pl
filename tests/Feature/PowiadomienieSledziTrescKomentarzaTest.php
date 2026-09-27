<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Notifications\WycinkiKomentarzy;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ISSUE #758 — POWIADOMIENIE ŚLEDZI TREŚĆ KOMENTARZA.
 *
 * Decyzja właściciela z 20 września 2026 (wariant B): wycinek treści
 * komentarza jest LICZONY PRZY WYŚWIETLANIU, z aktualnej treści — jedno
 * źródło prawdy, nie zamrożona kopia w `notifications.data`. Eksport RODO
 * zmienia się tak samo, bo paczka ma pokazywać, co o kimś trzymamy DZIŚ.
 *
 * CO BYŁO ZEPSUTE
 * `PublishComment::handle()` wpisywał do `data.excerpt` 120 znaków treści
 * z chwili publikacji i nikt tego nigdy nie odświeżał. Autor poprawiał
 * „dodaję dwie łyżki masła" na „dwie łyżeczki" w dozwolonym oknie 15 minut,
 * wątek pokazywał poprawkę, a powiadomienie — i paczka RODO na zawsze —
 * dalej mówiło „łyżki".
 *
 * GRANICA, KTÓREJ TA ZMIANA NIE MA PRAWA PRZESUNĄĆ (najgroźniejsza możliwa
 * regresja tej poprawki): bramka z #757 (`body_removed_at` i soft delete
 * w `Notification::scopeVisibleTo()`) obowiązuje niezależnie. Żywy wycinek
 * NIE MOŻE przywrócić do widoku niczego, co ta bramka ukryła — ani na
 * ekranie, ani w eksporcie. Pilnuje tego
 * `test_usuniety_komentarz_nie_odzyskuje_tresci_ani_na_ekranie_ani_w_eksporcie`
 * wraz z asercją KONTROLNĄ obok każdego znikającego wycinka: gdyby lista
 * była pusta z innego powodu, kontrola też by zniknęła.
 *
 * KOSZT ZAPYTAŃ JEST CZĘŚCIĄ ZADANIA, NIE DODATKIEM (D-196).
 * Strona mieści 30 powiadomień, a eksport nie ma górnej granicy — wycinek
 * liczony naiwnie przy renderze dokładałby jedno zapytanie na wiersz.
 * Metoda pomiaru: nie „ile zapytań wypada", tylko „czy liczba ROŚNIE
 * z liczbą wierszy".
 */
class PowiadomienieSledziTrescKomentarzaTest extends TestCase
{
    use RefreshDatabase;

    private const STARA_TRESC = 'STARA-TRESC-DWIE-LYZKI-MASLA';

    private const NOWA_TRESC = 'NOWA-TRESC-DWIE-LYZECZKI-MASLA';

    private function wpis(User $autor): Post
    {
        return Post::factory()->for($autor, 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    /** @return array<string, mixed> */
    private function paczka(User $osoba): array
    {
        return app(CollectUserExportData::class)->handle(
            $osoba,
            new ExportPhotoPlan($osoba),
            Carbon::parse('2026-09-20 12:00:00', 'UTC'),
        );
    }

    /**
     * Kryterium odbioru 1–4 z #758: publikacja ze znacznikiem STARA_TRESC,
     * odpowiedź w cudzym wątku daje DWÓCH uprawnionych odbiorców, edycja
     * przez rzeczywisty endpoint, oba ekrany pokazują NOWA_TRESC. Liczba
     * powiadomień i istniejące `read_at` zostają bez zmiany.
     */
    public function test_po_edycji_komentarza_oba_ekrany_powiadomien_pokazuja_nowa_tresc(): void
    {
        $wlascicielWpisu = $this->user('wlascicielwpisu');
        $k1 = $this->user('pierwszykomentator');
        $k2 = $this->user('drugikomentator');

        $wpis = $this->wpis($wlascicielWpisu);

        // Przez akcję domenową, nie `Comment::create()` — to ona decyduje,
        // KOMU idzie powiadomienie o odpowiedzi.
        $korzen = app(PublishComment::class)->handle($k1, $wpis, 'Korzeń wątku od K1.');
        $odpowiedz = app(PublishComment::class)->handle($k2, $wpis, self::STARA_TRESC, $korzen);

        // KONTROLA: drugi wątek pod tym samym wpisem, którego NIKT nie
        // poprawia. Gdyby poprawka wycięła wycinki w ogóle, zamiast je
        // odświeżyć, ten fragment też by zniknął i test by tego nie przepuścił.
        $korzenKontrolny = app(PublishComment::class)->handle($k1, $wpis, 'Korzeń kontrolny od K1.');
        app(PublishComment::class)->handle($k2, $wpis, 'FRAGMENT-KONTROLNY-BEZ-EDYCJI', $korzenKontrolny);

        $powiadomieniaPrzed = Notification::query()->count();

        // Jedno z powiadomień jest już przeczytane — edycja treści nie ma
        // prawa tym ruszyć (kryterium 4: „nie przywracać read_at do null").
        $przeczytane = Notification::query()
            ->where('user_id', $k1->getKey())
            ->where('type', Notification::TYPE_REPLY)
            ->firstOrFail();
        $przeczytane->forceFill(['read_at' => now()->subMinutes(5)])->save();
        $znacznikPrzeczytania = $przeczytane->fresh()->read_at;

        // EDYCJA PRZEZ RZECZYWISTY ENDPOINT, w dozwolonym oknie 15 minut.
        $this->actingAs($k2)
            ->put(route('comments.update', $odpowiedz), ['body' => self::NOWA_TRESC])
            ->assertRedirect();

        $this->assertSame(
            self::NOWA_TRESC,
            $odpowiedz->fresh()->body,
            'Test zakłada, że edycja naprawdę przeszła — inaczej mierzy coś innego.',
        );

        // ODBIORCA 1: właściciel wpisu (dostał TYPE_REPLY jako właściciel treści).
        $ekranWlasciciela = $this->actingAs($wlascicielWpisu)->get(route('notifications.index'))->assertOk();
        $ekranWlasciciela->assertDontSee(self::STARA_TRESC);
        $ekranWlasciciela->assertSee(self::NOWA_TRESC);
        $ekranWlasciciela->assertSee('FRAGMENT-KONTROLNY-BEZ-EDYCJI');

        // ODBIORCA 2: autor komentarza-rodzica.
        $ekranK1 = $this->actingAs($k1)->get(route('notifications.index'))->assertOk();
        $ekranK1->assertDontSee(self::STARA_TRESC);
        $ekranK1->assertSee(self::NOWA_TRESC);
        $ekranK1->assertSee('FRAGMENT-KONTROLNY-BEZ-EDYCJI');

        $this->assertSame(
            $powiadomieniaPrzed,
            Notification::query()->count(),
            'Poprawka literówki nie ma prawa dołożyć ani zabrać powiadomienia.',
        );

        $this->assertEquals(
            $znacznikPrzeczytania,
            $przeczytane->fresh()->read_at,
            'Edycja treści nie ma prawa ruszyć znacznika przeczytania — od niego liczy się retencja.',
        );
    }

    /**
     * Eksport RODO zmienia się razem z ekranem — decyzja właściciela wprost:
     * paczka ma pokazywać dane, które DZIŚ o kimś trzymamy, a nie ich
     * historyczną wersję.
     */
    public function test_paczka_rodo_niesie_aktualna_tresc_komentarza_a_nie_historyczna(): void
    {
        $wlascicielWpisu = $this->user('wlascicielpaczki');
        $k1 = $this->user('komentatorpaczki');

        $wpis = $this->wpis($wlascicielWpisu);
        $komentarz = app(PublishComment::class)->handle($k1, $wpis, self::STARA_TRESC);

        $this->actingAs($k1)
            ->put(route('comments.update', $komentarz), ['body' => self::NOWA_TRESC])
            ->assertRedirect();

        $paczka = json_encode($this->paczka($wlascicielWpisu), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($paczka);
        $this->assertStringNotContainsString(
            self::STARA_TRESC,
            $paczka,
            'Paczka RODO opisywałaby stan, którego w bazie już nie ma.',
        );
        $this->assertStringContainsString(
            self::NOWA_TRESC,
            $paczka,
            'Wycinek ma w paczce ZOSTAĆ — ma tylko być aktualny.',
        );
    }

    /**
     * GRANICA #757 — TO JEST NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Śledzenie treści czyta komentarz z bazy w chwili wyświetlania. Gdyby
     * czytało go bez bramki z #757, poprawka przywróciłaby do widoku treść,
     * którą usunięcie ukryło — na ekranie i w paczce RODO naraz. Obie drogi
     * usunięcia komentarza są tu sprawdzone, bo są DWIE i różnią się
     * wierszem w bazie:
     *
     *  1. komentarz Z ODPOWIEDZIAMI — wiersz zostaje (`status=published`,
     *     `deleted_at=null`), treść zastępuje placeholder,
     *     `body_removed_at` dostaje datę (`CommentController::destroy()`);
     *  2. komentarz BEZ odpowiedzi — zwykły soft delete.
     *
     * Ani placeholder, ani oryginał nie mają prawa wyjść żadną z tych dróg.
     */
    public function test_usuniety_komentarz_nie_odzyskuje_tresci_ani_na_ekranie_ani_w_eksporcie(): void
    {
        $wlascicielWpisu = $this->user('wlascicielgranicy');
        $k1 = $this->user('kasujacyzodpowiedziami');
        $k2 = $this->user('kasujacybezodpowiedzi');

        $wpis = $this->wpis($wlascicielWpisu);

        // DROGA 1: komentarz K1 dostaje odpowiedź, więc usunięcie zostawia
        // placeholder i `body_removed_at`.
        $zOdpowiedziami = app(PublishComment::class)->handle($k1, $wpis, 'TRESC-USUNIETA-Z-ODPOWIEDZIAMI');
        app(PublishComment::class)->handle($k2, $wpis, 'Odpowiedź, przez którą wiersz musi zostać.', $zOdpowiedziami);

        // DROGA 2: komentarz bez odpowiedzi — soft delete.
        $bezOdpowiedzi = app(PublishComment::class)->handle($k2, $wpis, 'TRESC-USUNIETA-SOFT-DELETE');

        // KONTROLA: komentarz, którego nikt nie usuwa. Bez niego pusta lista
        // przeszłaby ten test z zupełnie innego powodu.
        app(PublishComment::class)->handle($k1, $wpis, 'TRESC-KTORA-MA-ZOSTAC');

        $this->actingAs($k1)->delete(route('comments.destroy', $zOdpowiedziami))->assertRedirect();
        $this->actingAs($k2)->delete(route('comments.destroy', $bezOdpowiedzi))->assertRedirect();

        $this->assertNotNull(
            Comment::withTrashed()->find($zOdpowiedziami->getKey())?->body_removed_at,
            'Test zakłada drogę z placeholderem — inaczej nie mierzy bramki #757.',
        );
        $this->assertTrue(
            Comment::withTrashed()->find($bezOdpowiedzi->getKey())?->trashed() ?? false,
            'Test zakłada soft delete na drugiej drodze.',
        );

        // Flash „Komentarz usunięty." z `back()` po usunięciu siedzi jeszcze
        // w sesji i wyszedłby na następnej stronie — czyli asercja niżej
        // łapałaby komunikat operacji zamiast placeholdera w powiadomieniu.
        $this->flushSession();

        $ekran = $this->actingAs($wlascicielWpisu)->get(route('notifications.index'))->assertOk();
        $ekran->assertDontSee('TRESC-USUNIETA-Z-ODPOWIEDZIAMI');
        $ekran->assertDontSee('TRESC-USUNIETA-SOFT-DELETE');
        $ekran->assertDontSee('Komentarz usunięty.', false);
        $ekran->assertSee('TRESC-KTORA-MA-ZOSTAC');

        $paczka = json_encode($this->paczka($wlascicielWpisu), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($paczka);
        $this->assertStringNotContainsString('TRESC-USUNIETA-Z-ODPOWIEDZIAMI', $paczka);
        $this->assertStringNotContainsString('TRESC-USUNIETA-SOFT-DELETE', $paczka);
        $this->assertStringContainsString('TRESC-KTORA-MA-ZOSTAC', $paczka);
    }

    /**
     * D-196: koszt zapytań mierzymy przy ROSNĄCEJ liczbie rzeczy na ekranie.
     *
     * Wycinek liczony po jednym komentarzu na wiersz dałby wzrost równy
     * liczbie powiadomień na stronie — a strona mieści ich 30. Mierzymy
     * SAMO zbiorcze dociągnięcie, bo to ono jest tą zmianą: ma kosztować
     * jedno zapytanie niezależnie od tego, ile powiadomień dostanie.
     *
     * KONTROLA DODATNIA jest w drugiej asercji: gdyby metoda niczego nie
     * czytała (albo nie trafiała w żaden komentarz), pomiar „zero zapytań"
     * przeszedłby ten test z zupełnie innego powodu niż zamierzony.
     */
    public function test_zbiorcze_dociagniecie_wycinkow_kosztuje_jedno_zapytanie_niezaleznie_od_liczby_wierszy(): void
    {
        $odbiorca = $this->user('odbiorcapomiaru');
        $wpis = $this->wpis($odbiorca);

        $this->komentarze($wpis, 2);
        $dwa = Notification::query()->where('user_id', $odbiorca->getKey())->get();
        $wycinkiDwa = [];
        $maloZapytan = $this->policzZapytania(function () use ($dwa, &$wycinkiDwa): void {
            $wycinkiDwa = app(WycinkiKomentarzy::class)->zywe($dwa);
        });

        $this->komentarze($wpis, 10);
        $dwanascie = Notification::query()->where('user_id', $odbiorca->getKey())->get();
        $wycinkiDwanascie = [];
        $duzoZapytan = $this->policzZapytania(function () use ($dwanascie, &$wycinkiDwanascie): void {
            $wycinkiDwanascie = app(WycinkiKomentarzy::class)->zywe($dwanascie);
        });

        $this->assertCount(2, $wycinkiDwa, 'Pomiar bez wycinków mierzyłby pustą pętlę.');
        $this->assertCount(12, $wycinkiDwanascie, 'Pomiar bez wycinków mierzyłby pustą pętlę.');

        $this->assertSame(1, $maloZapytan, 'Wycinki mają iść jednym zapytaniem, nie jednym na wiersz.');
        $this->assertSame(
            $maloZapytan,
            $duzoZapytan,
            "Liczba zapytań rośnie z liczbą powiadomień: {$maloZapytan} przy 2, {$duzoZapytan} przy 12.",
        );
    }

    /**
     * #833: własny pomiar na bazie f8e6b444: 17 zapytań przy 2 wierszach,
     * 67 przy 12. Po zbiorczym rozwiązaniu adresów: 8 i 8.
     * Strażnik wycinka pozostaje: ani adres, ani wycinek nie może dokładać
     * zapytań wraz ze wzrostem liczby wierszy. Osobny test wyżej nadal
     * wymaga dokładnie jednego zapytania na same wycinki.
     */
    public function test_zywy_wycinek_nie_doklada_zapytania_na_wiersz_listy(): void
    {
        $odbiorca = $this->user('odbiorcapomiaruekranu');
        $wpis = $this->wpis($odbiorca);

        $this->komentarze($wpis, 2);
        $malo = $this->policzZapytania(
            fn () => $this->actingAs($odbiorca)->get(route('notifications.index'))->assertOk(),
        );

        $this->komentarze($wpis, 10);
        $duzo = $this->policzZapytania(
            fn () => $this->actingAs($odbiorca)->get(route('notifications.index'))->assertOk(),
        );

        $this->assertSame(
            12,
            Notification::query()->where('user_id', $odbiorca->getKey())->count(),
            'Pomiar bez wierszy mierzyłby pustą stronę — to jest kontrola dodatnia samego pomiaru.',
        );

        $this->assertSame(8, $malo, "Pomiar dla 2 wierszy: {$malo}; dla 12: {$duzo}.");
        $this->assertSame(
            0,
            $duzo - $malo,
            "Koszt wiersza listy powiadomień zmienił się: {$malo} zapytań przy 2 wierszach, {$duzo} przy 12. "
                .'Adresy i żywe wycinki mają stały koszt całej strony.',
        );
    }

    /** To samo dla eksportu — tam pozycji bywa więcej niż 30. */
    public function test_eksport_nie_ma_wachlarza_zapytan_na_wycinki(): void
    {
        $odbiorca = $this->user('odbiorcapomiarupaczki');
        $wpis = $this->wpis($odbiorca);

        $this->komentarze($wpis, 2);
        $malo = $this->policzZapytania(fn () => $this->paczka($odbiorca));

        $this->komentarze($wpis, 10);
        $duzo = $this->policzZapytania(fn () => $this->paczka($odbiorca));

        $this->assertSame(
            $malo,
            $duzo,
            "Liczba zapytań eksportu rośnie z liczbą powiadomień o komentarzu: {$malo} przy 2, {$duzo} przy 12.",
        );
    }

    /** Komentarze od OSOBNYCH autorów, każdy z własnym powiadomieniem. */
    private function komentarze(Post $wpis, int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $autor = $this->user();
            app(PublishComment::class)->handle($autor, $wpis, 'Komentarz numer '.$i.' o gotowaniu.');
        }
    }

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }
}
