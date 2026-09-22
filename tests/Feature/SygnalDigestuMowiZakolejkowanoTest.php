<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\ProductSignal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * Sygnał tygodniowego podsumowania nazywa to, co faktycznie zaszło —
 * ZAKOLEJKOWANIE (audyt 10.09.2026 ustalenie MAIL-03, `docs/DECISIONS.md`
 * D-078, migracja `2026_09_10_400000_rename_weekly_digest_sent_signal`).
 *
 * PO CO OSOBNY PLIK, SKORO `TygodniowePodsumowanieTest` ISTNIEJE
 * Tamten plik pilnuje WYSYŁKI: kto dostaje list, kto nie, ile listów dziennie,
 * co jest w treści. Tu chodzi o coś innego i węższego: o to, czy nazwa
 * zapisanego zdarzenia nie obiecuje więcej, niż Kuking wie. To pytanie
 * przeżyje każdą przyszłą zmianę w samej wysyłce i ma się oblewać niezależnie
 * od niej.
 *
 * CZEGO TEN PLIK NIE UMIE SPRAWDZIĆ — i lepiej to napisać, niż udawać.
 * „Nic w kodzie nie twierdzi doręczenia" nie da się wyrazić jako jedna
 * asercja: żaden test nie przeczyta intencji przyszłej nazwy zmiennej ani
 * zdania w widoku. Co da się sprawdzić i co jest sprawdzone niżej, to
 * ZAMKNIĘTY SŁOWNIK ZDARZEŃ — czyli jedyne miejsce, w którym takie
 * twierdzenie musiałoby się pojawić, żeby wejść do danych i na wykres.
 * Nazwa mówiąca „doręczono", „otwarto" albo „kliknięto" nie przejdzie tu ani
 * przez CHECK w bazie, ani przez stałe w `ZapiszSygnal`.
 */
class SygnalDigestuMowiZakolejkowanoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nazwy, których w słowniku sygnałów być nie może, dopóki Kuking nie ma
     * na nie PRAWDZIWEGO sygnału od dostawcy poczty (otwarta sprawa #204).
     *
     * „Doręczono" wymagałoby webhooka o odbiciach — osobna, niezrobiona
     * robota (`docs/decyzje/POCZTA.md` §5 pkt 6). „Otwarto" i „kliknięto"
     * wymagałyby piksela śledzącego i przepisywania odnośników przez nasz
     * serwer, czyli zapisywania, kiedy konkretna osoba czyta pocztę i z
     * jakiego adresu IP — a polityka prywatności obiecuje wprost tego nie
     * robić i transport ma nawet wyłącznik śledzenia u dostawcy
     * (`X-TRACKING-OFF`), domyślnie włączony.
     *
     * GDY KIEDYŚ TEN WEBHOOK POWSTANIE, ten test ma OBLAĆ, a nie dać się
     * ominąć. Wtedy zdejmuje się `delivered` z tej listy JAWNIE, jedną
     * decyzją w `docs/DECISIONS.md`, tak jak zbiór nazw rośnie o jedną nazwę
     * na jedną migrację. Śledzenia otwarć i kliknięć nie zdejmuje się wcale
     * — to jest obietnica z polityki prywatności, nie brak funkcji.
     *
     * @var list<string>
     */
    private const ZAKAZANE_CZASTKI = ['delivered', 'opened', 'clicked', 'doreczon', 'otwart', 'klikni'];

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_10_400000_rename_weekly_digest_sent_signal.php',
        );
    }

    /** Zbiór nazw, jakie CHECK w bazie naprawdę dopuszcza — czytany z bazy, nie z kodu PHP. */
    private function dopuszczoneNazwyZBazy(): string
    {
        $definicja = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
            ['product_signals_signal_name_check'],
        );

        $this->assertNotNull($definicja, 'Nie ma CHECK-a `product_signals_signal_name_check` — zamknięty zbiór nazw zniknął.');

        return (string) $definicja->def;
    }

    public function test_sygnal_zapisany_po_zakolejkowaniu_nazywa_zakolejkowanie(): void
    {
        // Zapis idzie tą samą drogą co w komendzie wysyłkowej — przez
        // `ZapiszSygnal`, nie przez model — bo pytanie brzmi „jaką nazwę
        // NIESIE ta stała", a nie „czy Eloquent umie wstawić wiersz".
        app(ZapiszSygnal::class)->handle(
            $this->user('basia'),
            ZapiszSygnal::WEEKLY_DIGEST_QUEUED,
            ['wykonania' => 1, 'nowi_obserwujacy' => 0, 'wpisy' => 0],
        );

        $this->assertSame('weekly_digest_queued', ZapiszSygnal::WEEKLY_DIGEST_QUEUED);
        $this->assertSame(
            1,
            ProductSignal::query()->where('signal_name', 'weekly_digest_queued')->count(),
        );

        // I ANI JEDNEGO wiersza mówiącego „wysłano". To jest ta połowa
        // asercji, która oblewa się, gdy ktoś przywróci starą nazwę obok
        // nowej „dla zgodności".
        $this->assertSame(
            0,
            ProductSignal::query()->where('signal_name', 'weekly_digest_sent')->count(),
        );
    }

    public function test_baza_nie_przyjmuje_juz_nazwy_mowiacej_wyslano(): void
    {
        $basia = $this->user('basia');

        $this->expectException(QueryException::class);

        // Świadomie surowe `DB::table()`, nie `ZapiszSygnal::handle()`: ten
        // ostatni łyka KAŻDY wyjątek i tylko go loguje (sygnał nie może
        // wywrócić operacji, którą opisuje), więc przez niego nie da się
        // zobaczyć, czy CHECK w bazie w ogóle jeszcze działa.
        DB::table('product_signals')->insert([
            'user_id' => $basia->getKey(),
            'signal_name' => 'weekly_digest_sent',
            'properties' => '{}',
        ]);
    }

    public function test_migracja_przepisuje_stare_wiersze_zamiast_je_gubic(): void
    {
        $basia = $this->user('basia');

        // Cofamy migrację, żeby dostać stan sprzed zmiany — i dopiero wtedy
        // wiersz ze starą nazwą da się w ogóle wstawić.
        $this->migracja()->down();

        $id = DB::table('product_signals')->insertGetId([
            'user_id' => $basia->getKey(),
            'signal_name' => 'weekly_digest_sent',
            'properties' => json_encode(['wykonania' => 3, 'nowi_obserwujacy' => 0, 'wpisy' => 1], JSON_THROW_ON_ERROR),
        ]);

        $this->migracja()->up();

        $wiersz = DB::table('product_signals')->where('id', $id)->first();

        $this->assertNotNull($wiersz, 'Migracja skasowała wiersz zamiast go przepisać.');
        $this->assertSame('weekly_digest_queued', $wiersz->signal_name);
        // Liczby zostają nietknięte: zmienia się nazwa zdarzenia, nie jego
        // znaczenie.
        // `assertEqualsCanonicalizing`, nie `assertSame`: `jsonb` w PostgreSQL
        // nie zachowuje kolejności kluczy (przechowuje drzewo, nie tekst), więc
        // porównanie wrażliwe na kolejność sprawdzałoby tu właściwość Postgresa,
        // a nie migrację.
        $this->assertEqualsCanonicalizing(
            ['wykonania' => 3, 'nowi_obserwujacy' => 0, 'wpisy' => 1],
            json_decode((string) $wiersz->properties, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function test_rollback_wraca_do_starej_nazwy_i_tez_nic_nie_kasuje(): void
    {
        $basia = $this->user('basia');

        app(ZapiszSygnal::class)->handle($basia, ZapiszSygnal::WEEKLY_DIGEST_QUEUED, ['wykonania' => 1]);

        $this->migracja()->down();

        $this->assertSame(
            1,
            DB::table('product_signals')->where('signal_name', 'weekly_digest_sent')->count(),
        );
        $this->assertSame(
            0,
            DB::table('product_signals')->where('signal_name', 'weekly_digest_queued')->count(),
        );
    }

    public function test_zamkniety_zbior_nazw_nie_obiecuje_doreczenia_ani_otwarcia(): void
    {
        $definicjaCheck = $this->dopuszczoneNazwyZBazy();

        foreach (self::ZAKAZANE_CZASTKI as $czastka) {
            $this->assertStringNotContainsStringIgnoringCase(
                $czastka,
                $definicjaCheck,
                'CHECK w `product_signals` dopuszcza nazwę zawierającą cząstkę: '.$czastka.'. '
                .'Kuking nie ma od dostawcy poczty prawdziwego sygnału o doręczeniu, '
                .'a otwarć i kliknięć nie mierzymy z decyzji produktowej (#204, polityka prywatności). '
                .'Jeśli to się zmieniło, zdejmij tę cząstkę z listy JAWNIE i opisz decyzję w docs/DECISIONS.md.',
            );
        }

        // Ta sama reguła po stronie PHP: stała bez CHECK-a nie wejdzie do
        // danych, ale wejdzie do kodu i do rozmowy o metryce — a MAIL-03 był
        // dokładnie o tym, że nazwa w kodzie mówiła więcej niż zdarzenie.
        foreach ((new ReflectionClass(ZapiszSygnal::class))->getConstants() as $nazwa => $wartosc) {
            if (! is_string($wartosc)) {
                continue;
            }

            foreach (self::ZAKAZANE_CZASTKI as $czastka) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $czastka,
                    $wartosc,
                    'Stała `ZapiszSygnal::'.$nazwa.'` twierdzi: '.$czastka.' — a Kuking tego nie mierzy (#204).',
                );
            }
        }
    }
}
