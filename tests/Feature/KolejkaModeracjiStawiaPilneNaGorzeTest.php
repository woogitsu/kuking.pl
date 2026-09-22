<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ReportContent;
use App\Domain\Moderation\Actions\ZglosNielegalnaTresc;
use App\Domain\Moderation\PriorytetSprawy;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Notifications\PilneZgloszenieOdCzlowieka;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * KOLEJKA ZGŁOSZEŃ CZYTA SIĘ OD RZECZY, KTÓRA NIE MOŻE CZEKAĆ.
 *
 * CO BYŁO NIE TAK — ZMIERZONE, NIE WYWNIOSKOWANE
 * `/admin/zgloszenia` sortowało wyłącznie `created_at DESC, id DESC`.
 * Zgłoszenie „Dotyczy dziecka" sprzed dwóch dni leżało POD trzydziestoma
 * zgłoszeniami spamu z ostatniej godziny, czyli na drugiej stronie kolejki
 * stronicowanej po 25. Spam jest jedyną kategorią, która przychodzi falami,
 * więc im gorszy dzień, tym głębiej schodziła rzecz najcięższa.
 *
 * Alarm istniał tylko po stronie automatu (D-055): model, który SAM
 * podejrzewał treść seksualną z udziałem dziecka, budził moderatora listem,
 * a człowiek, który to samo zgłosił przyciskiem „Zgłoś", trafiał wyłącznie
 * do kolejki — czyli do ekranu, na który w nocy i w weekend nikt nie wchodzi.
 * Cichsza była ta droga, na której ktoś to JUŻ ZOBACZYŁ.
 *
 * CZEGO TEN PLIK NIE TWIERDZI
 * Że kategoria wybrana przez zgłaszającego jest prawdą. To jest jego słowo,
 * nikt tej treści jeszcze nie obejrzał — i dlatego priorytet zmienia
 * WYŁĄCZNIE kolejność czytania oraz wysyła jeden list. Nie ukrywa treści,
 * nie ogranicza jej zasięgu i nie powiadamia autora.
 */
class KolejkaModeracjiStawiaPilneNaGorzeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * POMIAR TEJ USTERKI, jeden do jednego: fala spamu z ostatniej godziny
     * kontra jedno zgłoszenie o dziecku sprzed dwóch dni.
     *
     * Bez łatki sprawa o dziecku jest na DRUGIEJ stronie (31 pozycji, 25 na
     * stronę, sortowanie po samym czasie). Po łatce — pierwsza pozycja
     * pierwszej strony.
     */
    public function test_zgloszenie_o_dziecku_sprzed_dwoch_dni_stoi_nad_swiezym_spamem(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajacafala');

        $pilne = $this->zgloszenie('minor', now()->subDays(2), $zglaszajaca);

        for ($i = 0; $i < 30; $i++) {
            $this->zgloszenie('spam', now()->subMinutes(60 - $i), $zglaszajaca);
        }

        $pierwszaStrona = $this->actingAs($moderator)->get(route('admin.reports'));
        $pierwszaStrona->assertOk();

        $kolejnosc = $pierwszaStrona->viewData('reports')->map(
            static fn (Report $r): string => (string) $r->getKey(),
        )->all();

        $this->assertSame(
            (string) $pilne->getKey(),
            $kolejnosc[0] ?? null,
            'Zgłoszenie z kategorii „Dotyczy dziecka" nie stoi pierwsze w kolejce. '
            .'Przy sortowaniu po samym czasie leży pod falą spamu z ostatniej '
            .'godziny — czyli dokładnie w te dni, w które moderator ma najmniej '
            .'czasu, schodzi najgłębiej sprawa, która może go potrzebować najbardziej.',
        );
    }

    /**
     * PODŁOGA DLA DROGI PRAWNEJ (DSA art. 16 ust. 5).
     *
     * Nie dlatego, że zgłoszenie prawne jest cięższe — „to nie jest treść tej
     * osoby" opisuje tę samą rzecz obiema drogami. Dlatego, że niesie TERMIN:
     * zgłaszającemu trzeba bez zbędnej zwłoki zawiadomić o decyzji.
     */
    public function test_zgloszenie_prawne_o_zwyklej_kategorii_wyprzedza_te_sama_kategorie_bez_terminu(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajacaprawna');

        // Społecznościowe jest NOWSZE — bez podłogi stałoby pierwsze.
        $prawne = $this->zgloszenie('copyright', now()->subHours(3), null, Report::SOURCE_LEGAL_NOTICE);
        $spolecznosciowe = $this->zgloszenie('copyright', now()->subHour(), $zglaszajaca);

        $kolejnosc = $this->kolejnoscKolejki($moderator);

        $this->assertSame(
            [(string) $prawne->getKey(), (string) $spolecznosciowe->getKey()],
            $kolejnosc,
            'Zgłoszenie złożone drogą z DSA art. 16 nie wyprzedziło nowszego '
            .'zgłoszenia społecznościowego o tej samej kategorii. Termin '
            .'odpowiedzi biegnie tylko przy tym pierwszym.',
        );
    }

    /**
     * WEWNĄTRZ JEDNEGO PRIORYTETU NIC SIĘ NIE ZMIENIŁO — i to jest świadome.
     *
     * Odrzucona gałąź `claude/priorytet-w-kolejce-moderacji` odwracała także
     * kierunek wewnątrz wagi (najstarsze na górze, „bliżej terminu z SLA").
     * Tego tu NIE robimy: to jest osobna zmiana, bez dowodu, z własną ceną —
     * góra kolejki przestałaby się odświeżać, a moderator patrzyłby codziennie
     * na te same sprawy, których z jakiegoś powodu nie rozstrzygnął.
     *
     * Zmieniamy jedną rzecz naraz: kolejność MIĘDZY wagami.
     */
    public function test_wewnatrz_jednego_priorytetu_nadal_najnowsze_na_gorze(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajacarowne');

        $starsze = $this->zgloszenie('spam', now()->subHours(3), $zglaszajaca);
        $nowsze = $this->zgloszenie('spam', now()->subHour(), $zglaszajaca);

        $this->assertSame(
            [(string) $nowsze->getKey(), (string) $starsze->getKey()],
            $this->kolejnoscKolejki($moderator),
            'Wewnątrz jednego priorytetu porządek przestał być „najnowsze na górze". '
            .'Ta gwarancja miała zostać nietknięta — zmieniamy kolejność MIĘDZY '
            .'wagami, nie wewnątrz nich.',
        );
    }

    /**
     * MODERATOR MA TO PRZECZYTAĆ, NIE WYWNIOSKOWAĆ Z POZYCJI.
     *
     * Sam porządek nie mówi nic osobie, która weszła na drugą stronę albo na
     * zakładkę „Wszystkie": widzi listę bez początku i nie wie, czy to, na co
     * patrzy, jest ciężkie, czy zwykłe.
     */
    public function test_karta_pilnej_sprawy_mowi_wprost_ze_nie_moze_czekac(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajacakarta');

        $this->zgloszenie('minor', now()->subHour(), $zglaszajaca);

        $odpowiedz = $this->actingAs($moderator)->get(route('admin.reports'));

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('Nie może czekać');
    }

    /** Zwykła sprawa NIE dostaje plakietki — „zwykłe" pięćdziesiąt razy na ekranie nie mówi nic. */
    public function test_zwykla_sprawa_nie_dostaje_plakietki(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajacazwykla');

        $this->zgloszenie('spam', now()->subHour(), $zglaszajaca);

        $odpowiedz = $this->actingAs($moderator)->get(route('admin.reports'));

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('Nie może czekać');
        $odpowiedz->assertDontSee('Na dziś');
    }

    // ---------------------------------------------------------------
    // Alarm
    // ---------------------------------------------------------------

    public function test_zgloszenie_o_dziecku_budzi_moderatora_listem(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => 'moderacja@kuking.test']);

        $this->zglosWpis('minor');

        Notification::assertSentOnDemand(
            PilneZgloszenieOdCzlowieka::class,
            static fn ($powiadomienie, array $kanaly, object $adresat): bool => $adresat->routes['mail'] === 'moderacja@kuking.test',
        );
    }

    /**
     * Alarm, który zapala się przy pięciu rzeczach, przestaje być alarmem.
     * Spam NIE robi się groźniejszy przez noc.
     */
    public function test_zgloszenie_spamu_nie_budzi_nikogo(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => 'moderacja@kuking.test']);

        $this->zglosWpis('spam');

        Notification::assertNothingSent();
    }

    /** Pusty adres znaczy „bez poczty" — normalny stan lokalnie i w testach. */
    public function test_bez_adresu_alarmowego_nic_nie_wychodzi_a_sprawa_zostaje(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => null]);

        $zgloszenie = $this->zglosWpis('minor');

        Notification::assertNotSentTo(
            Notification::route('mail', 'moderacja@kuking.test'),
            PilneZgloszenieOdCzlowieka::class,
        );
        $this->assertDatabaseHas('reports', ['id' => $zgloszenie->getKey()]);
    }

    /**
     * DROGA BEZ KONTA (DSA art. 16 ust. 1) ALARMUJE TAK SAMO.
     *
     * To jest ta droga, którą przyjdzie zgłoszenie od kogoś, kto nie ma u nas
     * konta i nie założy go po to, żeby zgłosić przestępstwo. Cisza po tej
     * stronie byłaby najgorsza z możliwych.
     */
    public function test_zgloszenie_prawne_o_dziecku_alarmuje_choc_zglaszajacy_nie_ma_konta(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => 'moderacja@kuking.test']);

        app(ZglosNielegalnaTresc::class)->handle(
            imie: null,
            email: null,
            adres: 'https://kuking.test/wpisy/testowy-wpis',
            uzasadnienie: 'Uzasadnienie zgłoszenia dla potrzeb testu.',
            powod: 'minor',
        );

        Notification::assertSentOnDemandTimes(PilneZgloszenieOdCzlowieka::class, 1);
    }

    // ---------------------------------------------------------------
    // Reguła: PHP i SQL liczą to samo
    // ---------------------------------------------------------------

    /**
     * KARTA I KOLEJNOŚĆ NIE MAJĄ SIĘ JAK ROZJECHAĆ.
     *
     * Widok liczy priorytet w PHP (`PriorytetSprawy::dla`), baza w `ORDER BY
     * CASE` (`PriorytetSprawy::wyrazenieSql`). Rozjazd wyglądałby tak, że
     * karta pokazuje „Nie może czekać", a sprawa leży na trzeciej stronie —
     * i nikt by tego nie zgłosił, bo nikt by tam nie dotarł.
     */
    public function test_priorytet_z_php_zgadza_sie_z_priorytetem_z_bazy_dla_kazdej_kategorii(): void
    {
        $zglaszajaca = $this->user('zglaszajacazgodnosc');
        $oczekiwane = [];

        foreach (array_keys(Report::REASONS) as $powod) {
            foreach ([Report::SOURCE_COMMUNITY, Report::SOURCE_LEGAL_NOTICE] as $zrodlo) {
                $wiersz = $this->zgloszenie(
                    $powod,
                    now()->subHour(),
                    $zrodlo === Report::SOURCE_LEGAL_NOTICE ? null : $zglaszajaca,
                    $zrodlo,
                );

                $oczekiwane[(string) $wiersz->getKey()] = PriorytetSprawy::dla($wiersz);
            }
        }

        [$wyrazenie, $parametry] = PriorytetSprawy::wyrazenieSql();

        $zBazy = Report::query()
            ->selectRaw('id, ('.$wyrazenie.') AS priorytet', $parametry)
            ->pluck('priorytet', 'id')
            ->map(static fn ($p): int => (int) $p)
            ->all();

        $this->assertSame(
            $oczekiwane,
            array_intersect_key($zBazy, $oczekiwane),
            'Priorytet policzony w PHP różni się od tego, którym sortuje baza. '
            .'Karta pokazywałaby wtedy co innego, niż mówi pozycja w kolejce.',
        );
    }

    // ---------------------------------------------------------------
    // Pomocnicze
    // ---------------------------------------------------------------

    private function zgloszenie(
        string $powod,
        CarbonInterface $kiedy,
        ?User $zglaszajaca = null,
        string $zrodlo = Report::SOURCE_COMMUNITY,
    ): Report {
        $wiersz = Report::create([
            'reporter_id' => $zglaszajaca?->getKey(),
            'source' => $zrodlo,
            // Losowy cel, bo `reports_one_open_per_pair` nie pozwala złożyć
            // dwóch otwartych zgłoszeń na tę samą parę.
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => $powod,
            'status' => Report::STATUS_OPEN,
        ] + ($zrodlo === Report::SOURCE_LEGAL_NOTICE ? [
            'illegality_explanation' => 'Uzasadnienie zgłoszenia dla potrzeb testu.',
            'good_faith_at' => now(),
        ] : []));

        $wiersz->forceFill(['created_at' => $kiedy, 'updated_at' => $kiedy])->save();

        return $wiersz;
    }

    /** @return list<string> */
    private function kolejnoscKolejki(User $moderator): array
    {
        $odpowiedz = $this->actingAs($moderator)->get(route('admin.reports'));
        $odpowiedz->assertOk();

        return $odpowiedz->viewData('reports')
            ->map(static fn (Report $r): string => (string) $r->getKey())
            ->values()
            ->all();
    }

    private function zglosWpis(string $powod): Report
    {
        $wpis = Post::factory()->for($this->user('autoralarmu'), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        return app(ReportContent::class)->handle(
            $this->user('zglaszajacaalarmu'),
            $wpis,
            $powod,
        );
    }
}
