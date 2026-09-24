<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Domain\Users\Exports\InwentarzDanychKonta;
use App\Models\Recipe;
use App\Models\Tag;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PACZKA NIE GUBI PO CICHU CAŁYCH KATEGORII DANYCH KONTA (#953).
 *
 * `dane.json` deklaruje RODO art. 15, a pomijał wersje przepisów, obserwowane
 * tagi, dziennik zgód, połączone konta i sesje. Nikt tego nie postanowił —
 * tabele powstawały, a paczka o nich nie wiedziała.
 *
 * Dlatego pierwsze dwa testy czytają SCHEMAT BAZY, nie listę z palca: każda
 * kolumna wskazująca na `users` i każda kolumna samej `users` musi mieć
 * rozstrzygnięcie w `InwentarzDanychKonta`. Nowa tabela z `user_id` oblewa
 * ten plik, dopóki ktoś nie napisze, czy wchodzi do paczki, a jeśli nie —
 * dlaczego. Wpis bez pokrycia w schemacie też oblewa: rejestr nie może
 * opisywać tabel, których już nie ma.
 *
 * Kolejne testy mierzą ZAWARTOŚĆ: rekordy z pięciu kategorii z #953 są
 * w paczce, a poświadczenia i cudze dane — nie.
 */
final class EksportObejmujeKazdaTabeleKontaTest extends TestCase
{
    use RefreshDatabase;

    public function test_kazda_kolumna_wskazujaca_na_konto_ma_rozstrzygniecie(): void
    {
        $zeSchematu = $this->kolumnyWskazujaceNaKonto();

        // Kontrola dodatnia: odczyt schematu naprawdę coś znalazł, i to
        // także kolumnę BEZ klucza obcego. Bez tego pusta lista ze
        // zepsutego zapytania dawałaby zielony test przy pustym rejestrze.
        $this->assertContains('recipes.author_id', $zeSchematu, 'Odczyt schematu nie widzi nawet `recipes.author_id` — test nic nie mierzy.');
        $this->assertContains('sessions.user_id', $zeSchematu, 'Odczyt schematu nie widzi `sessions.user_id` (kolumna bez klucza obcego) — nowa taka tabela przeszłaby niezauważona.');

        $bezRozstrzygniecia = array_values(array_diff($zeSchematu, array_keys(InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO)));
        $nieistniejace = array_values(array_diff(array_keys(InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO), $zeSchematu));

        $this->assertSame([], $bezRozstrzygniecia, 'Te kolumny wskazują na konto, a `InwentarzDanychKonta` nie mówi, czy wchodzą do paczki RODO: '.implode(', ', $bezRozstrzygniecia).'. Dopisz je do eksportu albo do rejestru z powodem.');
        $this->assertSame([], $nieistniejace, 'Rejestr opisuje kolumny, których nie ma w schemacie: '.implode(', ', $nieistniejace).'.');
    }

    public function test_kazda_kolumna_tabeli_users_ma_rozstrzygniecie(): void
    {
        $zeSchematu = DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('table_name', 'users')
            ->pluck('column_name')
            ->all();

        $this->assertContains('email', $zeSchematu, 'Odczyt kolumn `users` nic nie znalazł — test nic nie mierzy.');

        $bezRozstrzygniecia = array_values(array_diff($zeSchematu, array_keys(InwentarzDanychKonta::KOLUMNY_KONTA)));
        $nieistniejace = array_values(array_diff(array_keys(InwentarzDanychKonta::KOLUMNY_KONTA), $zeSchematu));

        $this->assertSame([], $bezRozstrzygniecia, 'Nowe kolumny `users` bez rozstrzygnięcia w `InwentarzDanychKonta::KOLUMNY_KONTA`: '.implode(', ', $bezRozstrzygniecia).'.');
        $this->assertSame([], $nieistniejace, 'Rejestr opisuje kolumny `users`, których nie ma: '.implode(', ', $nieistniejace).'.');
    }

    public function test_kazda_sekcja_z_rejestru_jest_w_paczce_a_wylaczenia_sa_nazwane(): void
    {
        $paczka = $this->paczka(User::factory()->create());

        foreach ([...InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO, ...InwentarzDanychKonta::KOLUMNY_KONTA] as $kolumna => [$tryb, $sekcjaAlboPowod]) {
            $this->assertContains($tryb, [InwentarzDanychKonta::EKSPORT, InwentarzDanychKonta::NA_ZADANIE, InwentarzDanychKonta::NIE_DOTYCZY], "Nieznane rozstrzygnięcie przy `{$kolumna}`.");

            if ($tryb === InwentarzDanychKonta::EKSPORT) {
                $this->assertArrayHasKey($sekcjaAlboPowod, $paczka, "Rejestr mówi, że `{$kolumna}` jest w sekcji „{$sekcjaAlboPowod}”, a takiej sekcji w paczce nie ma.");
            } else {
                $this->assertGreaterThan(30, mb_strlen($sekcjaAlboPowod), "Wyłączenie `{$kolumna}` nie ma prawdziwego powodu.");
            }
        }

        // Każda kolumna wydawana na żądanie jest wypisana w samej paczce.
        $wPaczce = array_merge(...array_column($paczka['o_tym_pliku']['kategorie_poza_paczka'], 'dane'));
        $naZadanie = array_keys(array_filter(InwentarzDanychKonta::KOLUMNY_WSKAZUJACE_NA_KONTO, fn (array $r): bool => $r[0] === InwentarzDanychKonta::NA_ZADANIE));

        $this->assertNotSame([], $naZadanie);
        $this->assertEqualsCanonicalizing($naZadanie, $wPaczce, 'Paczka nie wypisuje w `kategorie_poza_paczka` wszystkich danych wydawanych na żądanie.');
        $this->assertStringContainsString(route('kontakt'), $paczka['o_tym_pliku']['jak_uzyskac_pozostale']);
        $this->assertStringContainsString('kategorie_poza_paczka', $paczka['o_tym_pliku']['czego_nie_zawiera']);
    }

    public function test_piec_kategorii_z_953_jest_w_paczce_bez_sekretow(): void
    {
        $basia = User::factory()->create();
        $zenek = User::factory()->create();

        $przepis = Recipe::factory()->for($basia, 'author')->create(['title' => 'Bigos babci']);
        DB::table('recipe_versions')->insert([
            'id' => (string) Str::uuid(),
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $basia->getKey(),
            'version_number' => 1,
            'snapshot' => json_encode(['summary' => 'Pierwsza wersja bigosu z kminkiem.']),
            'change_note' => 'Mniej kminku',
            'created_at' => now(),
        ]);

        $tag = Tag::factory()->create(['name' => 'Kiszonki', 'slug' => 'kiszonki']);
        $basia->followedTags()->attach($tag->getKey(), ['created_at' => now()]);

        WpisZgody::create([
            'user_id' => $basia->getKey(),
            'cel' => WpisZgody::CEL_TYGODNIOWY_DIGEST,
            'czynnosc' => WpisZgody::UDZIELONA,
            'zrodlo' => WpisZgody::ZRODLO_USTAWIENIA,
            'wersja_polityki' => '2026-09-10',
        ]);

        DB::table('tozsamosci_zewnetrzne')->insert([
            'user_id' => $basia->getKey(),
            'dostawca' => TozsamoscZewnetrzna::DOSTAWCA_GOOGLE,
            'identyfikator' => 'google-sub-4242',
            'connected_at' => now(),
        ]);

        DB::table('sessions')->insert([
            ['id' => 'SESJA-BASI-NIE-WYCHODZI', 'user_id' => $basia->getKey(), 'ip_address' => '203.0.113.7', 'user_agent' => 'Firefox Basi', 'payload' => 'PAYLOAD-SESJI-NIE-WYCHODZI', 'last_activity' => now()->getTimestamp()],
            ['id' => 'SESJA-ZENKA', 'user_id' => $zenek->getKey(), 'ip_address' => '198.51.100.9', 'user_agent' => 'Chrome Zenka', 'payload' => 'x', 'last_activity' => now()->getTimestamp()],
        ]);

        $basia->forceFill([
            'remember_token' => 'REMEMBER-NIE-WYCHODZI',
            'two_factor_secret' => 'SEKRET-2FA-NIE-WYCHODZI',
            'two_factor_confirmed_at' => now(),
        ])->save();

        DB::table('login_link_tokens')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $basia->getKey(),
            'token_hash' => hash('sha256', 'LINK-NIE-WYCHODZI'),
            'created_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $paczka = $this->paczka($basia->fresh());
        $json = (string) json_encode($paczka, JSON_UNESCAPED_UNICODE);

        $this->assertSame('Bigos babci', $paczka['wersje_przepisow'][0]['przepis']);
        $this->assertSame('Mniej kminku', $paczka['wersje_przepisow'][0]['notatka_o_zmianie']);
        $this->assertSame('Pierwsza wersja bigosu z kminkiem.', $paczka['wersje_przepisow'][0]['tresc_wersji']['summary']);
        $this->assertSame('Kiszonki', $paczka['obserwowane_tagi'][0]['nazwa']);
        $this->assertSame(WpisZgody::UDZIELONA, $paczka['dziennik_zgod'][0]['czynnosc']);
        $this->assertSame('2026-09-10', $paczka['dziennik_zgod'][0]['wersja_polityki']);
        $this->assertSame('google-sub-4242', $paczka['polaczone_konta'][0]['identyfikator_u_dostawcy']);
        $this->assertCount(1, $paczka['aktywne_sesje'], 'Paczka Basi ma nieść wyłącznie JEJ sesje.');
        $this->assertSame('203.0.113.7', $paczka['aktywne_sesje'][0]['adres_ip']);
        $this->assertSame('Firefox Basi', $paczka['aktywne_sesje'][0]['przegladarka']);
        $this->assertNotNull($paczka['konto']['weryfikacja_dwuetapowa_od']);

        // Kontrola ujemna: poświadczenia i cudze dane nie wychodzą.
        foreach ([
            'SESJA-BASI-NIE-WYCHODZI', 'PAYLOAD-SESJI-NIE-WYCHODZI', 'REMEMBER-NIE-WYCHODZI',
            'SEKRET-2FA-NIE-WYCHODZI', hash('sha256', 'LINK-NIE-WYCHODZI'), (string) $basia->password,
            '198.51.100.9', 'Chrome Zenka',
        ] as $nieWolno) {
            $this->assertStringNotContainsString($nieWolno, $json, "Paczka niesie coś, czego nie wolno jej nieść: {$nieWolno}");
        }
    }

    /** @return list<string> */
    private function kolumnyWskazujaceNaKonto(): array
    {
        $kluczeObce = DB::select(<<<'SQL'
            SELECT DISTINCT kcu.table_name || '.' || kcu.column_name AS kolumna
            FROM information_schema.referential_constraints rc
            JOIN information_schema.key_column_usage kcu
              ON kcu.constraint_name = rc.constraint_name AND kcu.constraint_schema = rc.constraint_schema
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = rc.unique_constraint_name AND ccu.constraint_schema = rc.unique_constraint_schema
            WHERE ccu.table_name = 'users' AND rc.constraint_schema = current_schema()
            SQL);

        // Kolumna `user_id` bez klucza obcego (np. `sessions` z szablonu
        // Laravela) wskazuje na konto tak samo — po samej nazwie.
        $poNazwie = DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('current_schema()'))
            ->where('column_name', 'user_id')
            ->selectRaw("table_name || '.' || column_name AS kolumna")
            ->get()
            ->all();

        $kolumny = array_unique(array_map(fn (object $w): string => $w->kolumna, [...$kluczeObce, ...$poNazwie]));
        sort($kolumny);

        return $kolumny;
    }

    /** @return array<string, mixed> */
    private function paczka(User $user): array
    {
        return app(CollectUserExportData::class)->handle(
            $user,
            new ExportPhotoPlan($user),
            Carbon::parse('2026-09-24 12:00:00', 'UTC'),
        );
    }
}
