<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Digest\OdnosnikWypisania;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Da się wykazać, KIEDY i SKĄD ktoś zgodził się na tygodniowy digest i kiedy
 * zgodę wycofał (audyt 10.09.2026 — DB1 i RODO §4; `docs/DECISIONS.md` D-072).
 *
 * CO TU JEST DOWODEM, A CO BYŁO WCZEŚNIEJ
 * Wcześniej całym dowodem był boolean `users.wants_weekly_digest`. RODO
 * art. 7 ust. 1 każe zgodę WYKAZAĆ, a „dziś pole ma wartość `true`" nie
 * odpowiada na żadne z pytań, które przy sporze zada UODO albo sam człowiek:
 * kiedy to kliknął, czy wcześniej tego nie odklikał i czy wysyłka po
 * wycofaniu nie szła dalej.
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU jest ten o ciągu włącz → wyłącz → włącz.
 * Dwie kolumny z datami (`consented_at` / `withdrawn_at`), które audyt
 * dopuszczał jako minimum, dałyby tam DWIE wartości nadpisane przez trzecią
 * zmianę — czyli zgubiłyby dokładnie tę historię, o którą chodzi. Tabela
 * append-only daje trzy wiersze i to jest cała różnica między dowodem
 * a migawką.
 */
class DowodZgodyNaDigestTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int, string> */
    private function czynnosci(User $osoba): array
    {
        return WpisZgody::query()
            ->where('user_id', $osoba->getKey())
            ->orderBy('id')
            ->pluck('czynnosc')
            ->all();
    }

    /** @return array<int, string> */
    private function zrodla(User $osoba): array
    {
        return WpisZgody::query()
            ->where('user_id', $osoba->getKey())
            ->orderBy('id')
            ->pluck('zrodlo')
            ->all();
    }

    private function zapiszUstawienia(User $osoba, bool $chce): void
    {
        $this->actingAs($osoba)
            ->put(route('settings.privacy'), array_filter([
                'wants_weekly_digest' => $chce ? '1' : null,
            ]))
            ->assertRedirect();
    }

    // -----------------------------------------------------------------
    // 1. Ekran ustawień: udzielenie, wycofanie i pełna historia
    // -----------------------------------------------------------------

    public function test_wlaczenie_zgody_w_ustawieniach_zapisuje_udzielenie(): void
    {
        $osoba = $this->user('zapisuje_sie', ['wants_weekly_digest' => false]);

        $this->zapiszUstawienia($osoba, true);

        $wpis = WpisZgody::query()->where('user_id', $osoba->getKey())->sole();

        $this->assertSame(WpisZgody::UDZIELONA, $wpis->czynnosc);
        $this->assertSame(WpisZgody::ZRODLO_USTAWIENIA, $wpis->zrodlo);
        $this->assertSame(WpisZgody::CEL_TYGODNIOWY_DIGEST, $wpis->cel);
        $this->assertNotNull($wpis->wystapilo_at, 'Zdarzenie zgody bez momentu nie jest dowodem.');

        // WERSJA POLITYKI: bez niej dowód mówi „zgodził się", ale nie mówi
        // NA CO — a to jest pierwsze pytanie przy sporze o zakres zgody.
        $this->assertSame(
            (string) config('kuking.zgody.wersja_polityki'),
            $wpis->wersja_polityki,
        );
    }

    public function test_wylaczenie_zgody_w_ustawieniach_zapisuje_wycofanie(): void
    {
        $osoba = $this->user('rezygnuje', ['wants_weekly_digest' => true]);

        $this->zapiszUstawienia($osoba, false);

        $wpis = WpisZgody::query()->where('user_id', $osoba->getKey())->sole();

        $this->assertSame(WpisZgody::WYCOFANA, $wpis->czynnosc);
        $this->assertSame(WpisZgody::ZRODLO_USTAWIENIA, $wpis->zrodlo);
    }

    /**
     * SERCE TEGO PLIKU. Trzy zmiany = trzy wiersze, w kolejności, bez
     * nadpisywania. Przy dwóch kolumnach z datami zostałby po tym ciągu
     * jeden „consented_at" i jeden „withdrawn_at" — czyli obraz, w którym nie
     * da się odróżnić osoby zapisanej raz od osoby, która zmieniała zdanie.
     */
    public function test_wlacz_wylacz_wlacz_daje_trzy_zdarzenia_w_kolejnosci(): void
    {
        $osoba = $this->user('zmienia_zdanie', ['wants_weekly_digest' => false]);

        $this->zapiszUstawienia($osoba, true);
        $this->zapiszUstawienia($osoba, false);
        $this->zapiszUstawienia($osoba, true);

        $this->assertSame(
            [WpisZgody::UDZIELONA, WpisZgody::WYCOFANA, WpisZgody::UDZIELONA],
            $this->czynnosci($osoba),
            'Historia zgody została nadpisana albo zgubiona — a to jest cały dowód z art. 7 ust. 1.',
        );

        $this->assertTrue((bool) $osoba->fresh()->wants_weekly_digest);
    }

    /**
     * KONTROLA do testu wyżej: formularz prywatności wysyła stan OBU haczyków
     * przy każdym zapisie. Gdyby każdy zapis dopisywał wiersz, dziennik po
     * miesiącu opowiadałby o dziesiątkach zmian, których nikt nie wykonał —
     * czyli byłby gorszym dowodem niż brak dziennika.
     */
    public function test_zapis_formularza_bez_ruszenia_haczyka_nie_dopisuje_niczego(): void
    {
        $osoba = $this->user('zapisuje_wspomnienia', ['wants_weekly_digest' => true]);

        $this->actingAs($osoba)
            ->put(route('settings.privacy'), [
                'wants_weekly_digest' => '1',
                'memories_enabled' => '1',
            ])
            ->assertRedirect();

        $this->assertSame([], $this->czynnosci($osoba));
    }

    // -----------------------------------------------------------------
    // 2. Odnośnik z listu (RFC 8058) — inne źródło niż ustawienia
    // -----------------------------------------------------------------

    public function test_wypisanie_odnosnikiem_z_listu_zapisuje_wycofanie_z_wlasnym_zrodlem(): void
    {
        $osoba = $this->user('wypisuje_sie_z_maila', ['wants_weekly_digest' => true]);

        // Bez `actingAs` — dokładnie jak z odnośnika w skrzynce otwartego na
        // telefonie, na którym nikt nie jest zalogowany.
        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();

        $wpis = WpisZgody::query()->where('user_id', $osoba->getKey())->sole();

        $this->assertSame(WpisZgody::WYCOFANA, $wpis->czynnosc);
        $this->assertSame(
            WpisZgody::ZRODLO_LINK_WYPISANIA,
            $wpis->zrodlo,
            'Wypisanie z listu musi być odróżnialne od wypisania w ustawieniach — to część dowodu.',
        );
    }

    /**
     * Nagłówek `List-Unsubscribe-Post` (RFC 8058) każe Gmailowi wysłać PUSTY
     * `POST` bez sesji i bez tokenu CSRF. Ta droga też musi zostawić dowód —
     * dla części ludzi jest to JEDYNE wypisanie, jakie w ogóle wykonają.
     */
    public function test_wypisanie_metoda_post_z_klienta_pocztowego_tez_zostawia_dowod(): void
    {
        $osoba = $this->user('gmail_wypisuje', ['wants_weekly_digest' => true]);

        $this->post(OdnosnikWypisania::dla($osoba))->assertOk();

        $this->assertSame([WpisZgody::WYCOFANA], $this->czynnosci($osoba));
        $this->assertSame([WpisZgody::ZRODLO_LINK_WYPISANIA], $this->zrodla($osoba));
    }

    public function test_powrot_po_wypisaniu_zapisuje_udzielenie_z_trzeciego_zrodla(): void
    {
        $osoba = $this->user('wraca_z_maila', ['wants_weekly_digest' => true]);

        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();
        $this->post(OdnosnikWypisania::powrotDla($osoba))->assertOk();

        $this->assertSame(
            [WpisZgody::WYCOFANA, WpisZgody::UDZIELONA],
            $this->czynnosci($osoba),
        );

        $this->assertSame(
            [WpisZgody::ZRODLO_LINK_WYPISANIA, WpisZgody::ZRODLO_LINK_POWROTNY],
            $this->zrodla($osoba),
        );
    }

    /**
     * Drugie wejście na ten sam odnośnik to odświeżenie strony albo skaner
     * odnośników w firmowej poczcie — nie drugie wypisanie.
     */
    public function test_drugie_wejscie_na_odnosnik_nie_dopisuje_drugiego_wycofania(): void
    {
        $osoba = $this->user('skaner_poczty', ['wants_weekly_digest' => true]);

        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();
        $this->get(OdnosnikWypisania::dla($osoba))->assertOk();

        $this->assertSame([WpisZgody::WYCOFANA], $this->czynnosci($osoba));
    }

    // -----------------------------------------------------------------
    // 3. Czego w dowodzie NIE MA: IP i User-Agenta
    // -----------------------------------------------------------------

    /**
     * ASERCJA NA LISTĘ KOLUMN, nie na przekonanie autora. Do wykazania zgody
     * IP i `User-Agent` nie są potrzebne (dowodem jest fakt, moment, cel
     * i droga), a AGENTS.md §7 zabrania PII tam, gdzie nie musi być.
     *
     * Test wymienia PEŁNY zestaw kolumn, więc oblewa się także wtedy, gdy
     * ktoś doda kolumnę nazwaną neutralnie („`kontekst`", „`meta`") i włoży
     * tam to samo.
     */
    public function test_dziennik_zgod_nie_ma_kolumn_na_ip_ani_user_agenta(): void
    {
        $kolumny = collect(DB::select(
            "select column_name from information_schema.columns
             where table_name = 'dziennik_zgod'",
        ))->map(fn (object $w): string => (string) $w->column_name)->sort()->values()->all();

        $this->assertSame(
            ['cel', 'czynnosc', 'id', 'user_id', 'wersja_polityki', 'wystapilo_at', 'zrodlo'],
            $kolumny,
            'Zestaw kolumn dziennika zgód się zmienił — sprawdź, czy nowa kolumna nie niesie PII (D-072).',
        );
    }

    /**
     * Druga strona tej samej reguły: nie wystarczy brak kolumny „ip", jeśli
     * adres wyląduje w treści innego pola. Żądanie idzie z konkretnym IP
     * i konkretnym `User-Agent`, a potem sprawdzamy KAŻDĄ wartość wiersza.
     */
    public function test_zapisany_wiersz_nie_zawiera_adresu_ip_ani_user_agenta(): void
    {
        $osoba = $this->user('bez_pii', ['wants_weekly_digest' => false]);

        $ip = '203.0.113.77';
        $przegladarka = 'Mozilla/5.0 (Linux; Android 14; SM-A546B) Kuking-Test';

        $this->actingAs($osoba)
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['User-Agent' => $przegladarka])
            ->put(route('settings.privacy'), ['wants_weekly_digest' => '1'])
            ->assertRedirect();

        $wiersz = DB::table('dziennik_zgod')->where('user_id', $osoba->getKey())->sole();

        foreach ((array) $wiersz as $kolumna => $wartosc) {
            $this->assertStringNotContainsString($ip, (string) $wartosc, "Kolumna {$kolumna} niesie adres IP.");
            $this->assertStringNotContainsString(
                'Mozilla',
                (string) $wartosc,
                "Kolumna {$kolumna} niesie User-Agenta.",
            );
        }
    }

    // -----------------------------------------------------------------
    // 4. Append-only naprawdę, nie z nazwy
    // -----------------------------------------------------------------

    public function test_modelu_nie_da_sie_zmienic_ani_skasowac(): void
    {
        $osoba = $this->user('append_only_model', ['wants_weekly_digest' => false]);
        $this->zapiszUstawienia($osoba, true);

        $wpis = WpisZgody::query()->where('user_id', $osoba->getKey())->sole();

        try {
            $wpis->update(['czynnosc' => WpisZgody::WYCOFANA]);
            $this->fail('Model pozwolił zmienić wiersz dziennika zgód.');
        } catch (LogicException $wyjatek) {
            $this->assertStringContainsString('tylko do dopisywania', $wyjatek->getMessage());
        }

        try {
            $wpis->delete();
            $this->fail('Model pozwolił skasować wiersz dziennika zgód.');
        } catch (LogicException $wyjatek) {
            $this->assertStringContainsString('nie wolno kasować', $wyjatek->getMessage());
        }

        $this->assertSame([WpisZgody::UDZIELONA], $this->czynnosci($osoba));
    }

    /**
     * Blokada w modelu łapie pomyłkę programisty, ale nie widzi
     * `DB::table(...)` ani ręcznego `psql`. Druga linia obrony jest
     * w bazie — wyzwalacz `dziennik_zgod_bez_zmian`.
     *
     * `DB::transaction()` wokół każdej próby, bo nieudane zapytanie zatruwa
     * na PostgreSQL całą otaczającą transakcję (tu: transakcję
     * `RefreshDatabase`), a savepoint cofa wyłącznie tę jedną próbę.
     */
    public function test_baza_odrzuca_update_i_delete_takze_z_pominieciem_modelu(): void
    {
        $osoba = $this->user('append_only_baza', ['wants_weekly_digest' => false]);
        $this->zapiszUstawienia($osoba, true);

        $id = (int) DB::table('dziennik_zgod')->where('user_id', $osoba->getKey())->value('id');

        try {
            DB::transaction(fn () => DB::table('dziennik_zgod')->where('id', $id)->update([
                'zrodlo' => WpisZgody::ZRODLO_LINK_WYPISANIA,
            ]));
            $this->fail('Baza pozwoliła zmienić wiersz dziennika zgód.');
        } catch (QueryException $wyjatek) {
            $this->assertStringContainsString('tylko do dopisywania', $wyjatek->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('dziennik_zgod')->where('id', $id)->delete());
            $this->fail('Baza pozwoliła skasować wiersz dziennika zgód.');
        } catch (QueryException $wyjatek) {
            $this->assertStringContainsString('tylko do dopisywania', $wyjatek->getMessage());
        }

        $this->assertSame(
            WpisZgody::ZRODLO_USTAWIENIA,
            DB::table('dziennik_zgod')->where('id', $id)->value('zrodlo'),
        );
    }

    public function test_baza_odrzuca_takze_truncate(): void
    {
        $osoba = $this->user('bez_czyszczenia', ['wants_weekly_digest' => false]);
        $this->zapiszUstawienia($osoba, true);

        try {
            DB::transaction(fn () => DB::statement('TRUNCATE TABLE dziennik_zgod'));
            $this->fail('Baza pozwoliła wyczyścić cały dziennik zgód jednym poleceniem.');
        } catch (QueryException $wyjatek) {
            $this->assertStringContainsString('tylko do dopisywania', $wyjatek->getMessage());
        }

        $this->assertSame(1, WpisZgody::query()->count());
    }

    // -----------------------------------------------------------------
    // 5. Usunięcie konta — napięcie „dowód zgody vs prawo do usunięcia"
    // -----------------------------------------------------------------

    /**
     * ROZSTRZYGNIĘCIE Z D-072: anonimizacja konta DOMYKA historię zgody
     * wpisem `wycofana` ze źródłem `usuniecie_konta` i NIE kasuje dziennika.
     * Po `EraseAccountData` wiersz `users` nie ma już adresu ani nazwy, więc
     * `user_id` w dzienniku nie wskazuje na dane osobowe — a dowód, że
     * wysyłka miała podstawę prawną, zostaje.
     */
    public function test_usuniecie_konta_domyka_historie_i_nie_kasuje_dziennika(): void
    {
        $osoba = $this->user('usuwa_konto', ['wants_weekly_digest' => false]);
        $this->zapiszUstawienia($osoba, true);

        $osoba->refresh()->markForDeletion();

        $this->assertTrue(app(EraseAccountData::class)->handle($osoba->fresh()));

        $this->assertSame(
            [WpisZgody::UDZIELONA, WpisZgody::WYCOFANA],
            $this->czynnosci($osoba),
            'Usunięcie konta zgasiło wysyłkę bez śladu, dlaczego ustała.',
        );

        $this->assertSame(
            [WpisZgody::ZRODLO_USTAWIENIA, WpisZgody::ZRODLO_USUNIECIE_KONTA],
            $this->zrodla($osoba),
        );

        // Konto jest zanonimizowane — dowód zgody nie trzyma już przy życiu
        // żadnych danych osobowych.
        $konto = $osoba->fresh();
        $this->assertNotNull($konto->data_erased_at);
        $this->assertStringContainsString('usuniete+', (string) $konto->email);
        $this->assertFalse((bool) $konto->wants_weekly_digest);
    }

    /**
     * Konto BEZ zgody nie dostaje przy usuwaniu żadnego wiersza — nie było
     * czego wycofywać. Kontrola do testu wyżej: bez niej przechodziłby także
     * kod dopisujący „wycofana" każdemu, kto kiedykolwiek usunął konto.
     */
    public function test_usuniecie_konta_bez_zgody_nie_dopisuje_niczego(): void
    {
        $osoba = $this->user('usuwa_bez_zgody', ['wants_weekly_digest' => false]);

        $osoba->markForDeletion();

        $this->assertTrue(app(EraseAccountData::class)->handle($osoba->fresh()));

        $this->assertSame([], $this->czynnosci($osoba));
    }
}
