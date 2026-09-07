<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Report;
use App\Support\NumerSprawy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Numer sprawy jest unikalny — bo wcześniej nie był.
 *
 * CO BYŁO ZMIERZONE (7 września 2026, HANDOVER §7.4 pkt 4)
 * Numer pokazywany zgłaszającemu był ośmioma pierwszymi znakami UUID-a v7
 * wiersza. W UUID-zie v7 pierwsze 48 bitów to znacznik czasu w milisekundach,
 * więc osiem znaków szesnastkowych to jego 32 GÓRNE bity — zmieniają się raz
 * na 2^16 ms, czyli raz na 65,5 sekundy. Dwie sprawy przyjęte w tym samym
 * okienku dostawały ten sam numer.
 *
 * DLACZEGO TO WAŻNIEJSZE, NIŻ WYGLĄDA
 * Dla zgłaszającego BEZ KONTA numer jest jedynym śladem sprawy: nie ma konta,
 * nie ma listy zgłoszeń, a poczty serwis dziś nie wysyła. Numer powtórzony
 * znaczy, że ani on, ani moderator nie umie powiedzieć, o którą z dwóch spraw
 * chodzi — a każda ma własny termin odpowiedzi z DSA art. 16.
 *
 * `test_stary_sposob_wyliczania_numeru_naprawde_dawal_kolizje` jest tu
 * ASERCJĄ KONTROLNĄ całego pliku: pilnuje, że opisany wyżej defekt istniał
 * naprawdę. Gdyby UUID v7 kiedyś zmienił kształt i przestał kolidować, ten
 * test padnie i powie, że reszta pliku broni już czegoś innego, niż myśli.
 */
class NumerSprawyTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszeniePrawne(array $atrybuty = []): Report
    {
        return Report::create(array_merge([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ], $atrybuty));
    }

    /**
     * Zgłoszenie z numerem podstawionym WPROST.
     *
     * `numer_sprawy` nie jest w `$fillable`, więc `create()` go po cichu
     * pomija — a bez tej drogi nie da się sprawdzić samych ograniczeń w bazie
     * (UNIQUE i CHECK), tylko zachowanie haka w modelu. To jedyne miejsce
     * w kodzie, które omija `$fillable`, i po to właśnie istnieje.
     */
    private function zgloszeniePrawneZNumerem(string $numer): Report
    {
        $zgloszenie = new Report;

        $zgloszenie->forceFill([
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'spam',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
            'numer_sprawy' => $numer,
        ])->save();

        return $zgloszenie;
    }

    /**
     * ASERCJA KONTROLNA — patrz komentarz klasy.
     */
    public function test_stary_sposob_wyliczania_numeru_naprawde_dawal_kolizje(): void
    {
        $chwila = now()->setDateTime(2026, 9, 7, 19, 0, 30);

        $pierwszy = (string) Str::uuid7($chwila);
        $drugi = (string) Str::uuid7($chwila->copy()->addSeconds(40));

        $this->assertNotSame($pierwszy, $drugi, 'Dwa UUID-y v7 z różnych chwil muszą być różne.');

        $this->assertSame(
            mb_strtoupper(mb_substr($pierwszy, 0, 8)),
            mb_strtoupper(mb_substr($drugi, 0, 8)),
            'Osiem pierwszych znaków UUID-a v7 przestało kolidować w odstępie 40 sekund — '
            .'reszta tego pliku broni wtedy czegoś innego, niż opisuje.',
        );
    }

    public function test_dwie_sprawy_w_tym_samym_okienku_maja_rozne_numery(): void
    {
        $pierwsza = $this->zgloszeniePrawne();
        $druga = $this->zgloszeniePrawne(['reason' => 'spam']);

        // Kontrola, że test nie przechodzi „bo tabela jest pusta".
        $this->assertSame(2, Report::query()->count());

        $this->assertNotSame(
            $pierwsza->numer_sprawy,
            $druga->numer_sprawy,
            'Dwie sprawy przyjęte jedna po drugiej dostały ten sam numer.',
        );
    }

    public function test_numer_ma_format_do_przepisania_z_ekranu(): void
    {
        $numer = $this->zgloszeniePrawne()->numer_sprawy;

        $this->assertMatchesRegularExpression(NumerSprawy::WZOR, (string) $numer);

        // Znaki mylące sprawdzamy WPROST NA ALFABECIE, nie na wylosowanej
        // wartości. Pierwsza wersja tego testu patrzyła na wynik losowania
        // i dlatego przepuściła `U` w alfabecie: szansa, że osiem losowych
        // znaków go nie zawiera, to (29/30)^8 ≈ 76%. Test, który przechodzi
        // w trzech na cztery przebiegi, nie broni niczego.
        foreach (NumerSprawy::ZNAKI_MYLACE as $znak) {
            $this->assertStringNotContainsString(
                $znak,
                NumerSprawy::ALFABET,
                "Alfabet numeru sprawy zawiera mylący znak „{$znak}\".",
            );
        }

        $this->assertSame(30, mb_strlen(NumerSprawy::ALFABET), 'Alfabet zmienił rozmiar — przelicz szansę kolizji.');

        // I kontrola w drugą stronę: każdy znak numeru pochodzi z alfabetu.
        foreach (mb_str_split(str_replace(['KU', '-'], '', (string) $numer)) as $znak) {
            $this->assertStringContainsString($znak, NumerSprawy::ALFABET);
        }
    }

    public function test_baza_nie_przyjmuje_drugiej_sprawy_z_tym_samym_numerem(): void
    {
        $pierwsza = $this->zgloszeniePrawne();

        $this->expectException(QueryException::class);

        // Numer podstawiony wprost — hak modelu nadpisuje tylko brak, więc
        // to jest jedyna droga, żeby sprawdzić samo ograniczenie w bazie.
        $this->zgloszeniePrawneZNumerem((string) $pierwsza->numer_sprawy);
    }

    public function test_baza_nie_przyjmuje_numeru_w_innym_formacie(): void
    {
        $this->expectException(QueryException::class);

        // CHECK w bazie, nie sam wzór w PHP: wartość w innym formacie nie ma
        // prawa wejść żadną drogą, także przez `tinker` albo przyszłe API.
        $this->zgloszeniePrawneZNumerem('AB-0000-0000');
    }

    public function test_numeru_nie_da_sie_podstawic_z_zadania(): void
    {
        // `numer_sprawy` nie jest w `$fillable` — tożsamość sprawy nadaje
        // serwer, nie człowiek (AGENTS.md §7, ta sama zasada co `status`).
        $zgloszenie = new Report;
        $zgloszenie->fill(['numer_sprawy' => 'KU-2222-3333', 'reason' => 'spam']);

        $this->assertNull($zgloszenie->numer_sprawy, '`numer_sprawy` da się ustawić przez `fill()`.');
        $this->assertSame('spam', $zgloszenie->reason, 'Kontrola: `fill()` w ogóle działa na tym modelu.');
    }

    public function test_zgloszenie_bez_konta_widzi_na_ekranie_numer_ze_swojego_wiersza(): void
    {
        $formularz = $this->get(route('zglos.nielegalna'));
        $formularz->assertOk();

        $odpowiedz = $this->post(route('zglos.nielegalna.store'), [
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
            'klucz_wyslania' => (string) Str::uuid7(),
        ]);

        $zgloszenie = Report::query()->sole();

        $this->assertSame(
            $zgloszenie->numer_sprawy,
            $odpowiedz->getSession()->get('numer'),
            'Ekran potwierdzenia pokazuje inny numer, niż stoi w wierszu sprawy.',
        );

        // I ten sam numer musi być widoczny na ekranie — nie tylko w sesji.
        $this->followRedirects($odpowiedz)->assertSee((string) $zgloszenie->numer_sprawy);
    }

    public function test_kazda_droga_przez_model_daje_numer_a_baza_pilnuje_reszty(): void
    {
        // Droga przez model (formularz, seeder, komenda, `tinker`) numer
        // dostaje z haka `creating`.
        $zgloszenie = $this->zgloszeniePrawne();

        $this->assertNotNull($zgloszenie->numer_sprawy, 'Zgłoszenie z `create()` nie dostało numeru.');
        $this->assertSame(
            0,
            DB::table('reports')->whereNull('numer_sprawy')->count(),
            'W tabeli jest wiersz bez numeru sprawy.',
        );

        // A droga OMIJAJĄCA model — surowy `INSERT` — nie przechodzi wcale,
        // bo kolumna jest `NOT NULL`. To jest ta połowa ochrony, której hak
        // w modelu dać nie może, i dlatego jedno nie zastępuje drugiego.
        $this->expectException(QueryException::class);

        DB::table('reports')->insert([
            'id' => (string) Str::uuid7(),
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/przepisy/rosol-babci',
            'reason' => 'spam',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
