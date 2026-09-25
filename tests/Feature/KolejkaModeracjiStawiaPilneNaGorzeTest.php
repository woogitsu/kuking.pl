<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ReportContent;
use App\Domain\Moderation\Actions\ZglosNielegalnaTresc;
use App\Domain\Moderation\PriorytetSprawy;
use App\Domain\Security\DziennyBudzetListow;
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

    /**
     * LIST NIE MÓWI ADRESATOWI „DECYZJA NALEŻY DO CIEBIE" (D-244).
     *
     * Adres alarmowy jest wspólny, a zgłosić może też moderator. Własnej
     * sprawy nie rozstrzyga nikt, więc list mówi, kto rozstrzyga — zamiast
     * zapraszać do decyzji, której serwer i tak odmówi.
     */
    public function test_list_alarmowy_nie_zaprasza_zglaszajacego_do_wlasnej_sprawy(): void
    {
        $zgloszenie = $this->zglosWpis('minor');

        $list = (new PilneZgloszenieOdCzlowieka($zgloszenie))->toMail(new \stdClass);
        $tresc = implode("\n", [...$list->introLines, ...$list->outroLines]);

        $this->assertStringNotContainsString('Decyzja należy do Ciebie', $tresc);
        $this->assertStringContainsString('Rozstrzyga moderator, który tego zgłoszenia nie wniósł.', $tresc);
    }

    // ---------------------------------------------------------------
    // Alarm nie daje się zalać (przegląd PR #1284)
    // ---------------------------------------------------------------

    /**
     * CZTERDZIEŚCI ZGŁOSZEŃ JEDNEGO WPISU TO JEDEN LIST.
     *
     * Przed poprawką każde zgłoszenie P0 wysyłało list; `reports_one_open_per_pair`
     * pilnuje pary osoba–treść, więc wiele osób = wiele listów.
     */
    public function test_wiele_zgloszen_tego_samego_wpisu_daje_jeden_list(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => 'moderacja@kuking.test']);

        $wpis = $this->wpis('autorfali');

        for ($i = 0; $i < 5; $i++) {
            app(ReportContent::class)->handle($this->user('zglaszajacyfali'.$i), $wpis, 'minor');
        }

        Notification::assertSentOnDemandTimes(PilneZgloszenieOdCzlowieka::class, 1);
        $this->assertSame(5, Report::query()->where('target_id', $wpis->getKey())->count(),
            'Deduplikacja listu nie może gubić zgłoszeń — każde ma stać w kolejce.');
    }

    /** Droga bez konta: ten sam adres z doklejonym „?x=1" nie otwiera nowego okna. */
    public function test_zgloszenia_prawne_tego_samego_adresu_daja_jeden_list(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => 'moderacja@kuking.test']);

        foreach (['https://kuking.test/wpisy/cel', 'https://kuking.test/wpisy/cel/?x=1', 'HTTPS://kuking.test/wpisy/cel#a'] as $adres) {
            app(ZglosNielegalnaTresc::class)->handle(
                imie: null,
                email: null,
                adres: $adres,
                uzasadnienie: 'Uzasadnienie zgłoszenia dla potrzeb testu.',
                powod: 'minor',
            );
        }

        Notification::assertSentOnDemandTimes(PilneZgloszenieOdCzlowieka::class, 1);
    }

    /** Po oknie ten sam cel znów budzi moderatora — deduplikacja nie jest wieczna. */
    public function test_po_oknie_ten_sam_wpis_znow_alarmuje(): void
    {
        Notification::fake();
        config([
            'kuking.moderation.model.alarm_email' => 'moderacja@kuking.test',
            'kuking.moderation.alarm_czlowieka.okno_celu_godzin' => 6,
        ]);

        $wpis = $this->wpis('autorokna');
        app(ReportContent::class)->handle($this->user('zglaszajacyokna1'), $wpis, 'minor');

        $this->travel(7)->hours();
        app(ReportContent::class)->handle($this->user('zglaszajacyokna2'), $wpis, 'minor');

        Notification::assertSentOnDemandTimes(PilneZgloszenieOdCzlowieka::class, 2);
    }

    /**
     * DOBOWY SUFIT: jedno konto przy kolejnych celach nie wyśle więcej niż
     * sufit, a ostatni list mówi wprost, że kolejnych dziś nie będzie.
     */
    public function test_dobowy_sufit_alarmow_i_ostatni_list_to_mowi(): void
    {
        Notification::fake();
        config([
            'kuking.moderation.model.alarm_email' => 'moderacja@kuking.test',
            'kuking.moderation.alarm_czlowieka.dzienny_sufit' => 3,
        ]);

        $zglaszajacy = $this->user('zglaszajacysufit');

        for ($i = 0; $i < 6; $i++) {
            app(ReportContent::class)->handle($zglaszajacy, $this->wpis('autorsufit'.$i), 'minor');
        }

        Notification::assertSentOnDemandTimes(PilneZgloszenieOdCzlowieka::class, 3);

        $ostatnie = 0;
        Notification::assertSentOnDemand(
            PilneZgloszenieOdCzlowieka::class,
            function (PilneZgloszenieOdCzlowieka $n) use (&$ostatnie): bool {
                $list = $n->toMail(new \stdClass);
                $tresc = implode("\n", [...$list->introLines, ...$list->outroLines]);
                $ostatnie += str_contains($tresc, 'To ostatni taki list dzisiaj.') ? 1 : 0;

                return true;
            },
        );
        $this->assertSame(1, $ostatnie, 'Dokładnie jeden — ostatni — list doby ma mówić, że kolejnych nie będzie.');
    }

    /** Alarm zajmuje miejsce we WSPÓLNYM liczniku poczty (D-239), a nie obok niego. */
    public function test_alarm_liczy_sie_we_wspolnej_puli_poczty(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => 'moderacja@kuking.test']);

        $wspolny = DziennyBudzetListow::wspolny(DziennyBudzetListow::KLASA_WEJSCIE);
        $przed = $wspolny->zuzyte();

        $this->zglosWpis('minor');

        $this->assertSame($przed + 1, $wspolny->zuzyte());
        $this->assertSame(1, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
    }

    /**
     * Pusta pula = bez listu, ale klucz celu wraca: kolejne zgłoszenie tego
     * samego wpisu, gdy miejsce się znajdzie, ma szansę kogoś obudzić.
     */
    public function test_pusta_pula_nie_wysyla_i_nie_blokuje_celu(): void
    {
        Notification::fake();
        config([
            'kuking.moderation.model.alarm_email' => 'moderacja@kuking.test',
            'kuking.poczta.limit_dostawcy_dobowy' => 0,
        ]);

        $wpis = $this->wpis('autorpuli');
        app(ReportContent::class)->handle($this->user('zglaszajacypuli1'), $wpis, 'minor');

        Notification::assertNothingSent();

        config(['kuking.poczta.limit_dostawcy_dobowy' => 300]);
        app(ReportContent::class)->handle($this->user('zglaszajacypuli2'), $wpis, 'minor');

        Notification::assertSentOnDemandTimes(PilneZgloszenieOdCzlowieka::class, 1);
    }

    // ---------------------------------------------------------------
    // Priorytet tylko dla spraw, które czekają
    // ---------------------------------------------------------------

    /** Rozstrzygnięte P0 nie mówi „Nie może czekać" — to już nieprawda. */
    public function test_zamknieta_pilna_sprawa_nie_ma_plakietki(): void
    {
        $moderator = $this->moderator();

        $zamkniete = $this->zgloszenie('minor', now()->subDay(), $this->user('zglaszajacazamknieta'));
        $zamkniete->forceFill(['status' => Report::STATUS_RESOLVED, 'resolved_at' => now()])->save();

        $odpowiedz = $this->actingAs($moderator)->get(route('admin.reports', ['status' => 'wszystkie']));

        $odpowiedz->assertOk();
        $odpowiedz->assertDontSee('Nie może czekać');
    }

    /** W „Wszystkie" dzisiejsze otwarte P2 stoi nad archiwum P0 i P1. */
    public function test_we_wszystkich_otwarta_zwykla_sprawa_stoi_nad_zamknietym_p0(): void
    {
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajacawszystkie');

        $archiwumP0 = $this->zgloszenie('minor', now()->subHours(2), $zglaszajaca);
        $archiwumP0->forceFill(['status' => Report::STATUS_RESOLVED, 'resolved_at' => now()])->save();
        $archiwumP1 = $this->zgloszenie('scam', now()->subHour(), $zglaszajaca);
        $archiwumP1->forceFill(['status' => Report::STATUS_RESOLVED, 'resolved_at' => now()])->save();
        $otwarteP2 = $this->zgloszenie('spam', now()->subDays(3), $zglaszajaca);

        $odpowiedz = $this->actingAs($moderator)->get(route('admin.reports', ['status' => 'wszystkie']));
        $odpowiedz->assertOk();

        $kolejnosc = $odpowiedz->viewData('reports')
            ->map(static fn (Report $r): string => (string) $r->getKey())
            ->values()
            ->all();

        $this->assertSame(
            [(string) $otwarteP2->getKey(), (string) $archiwumP1->getKey(), (string) $archiwumP0->getKey()],
            $kolejnosc,
            'Zamknięte sprawy mają stać za otwartymi i między sobą po dacie, bez priorytetu.',
        );
    }

    /** PHP i SQL zgadzają się też co do „nie czeka" — karta i pozycja w kolejce się nie rozjadą. */
    public function test_priorytet_kolejki_z_php_zgadza_sie_z_baza_dla_kazdego_statusu(): void
    {
        $zglaszajaca = $this->user('zglaszajacastatusy');
        $oczekiwane = [];

        foreach ([Report::STATUS_OPEN, Report::STATUS_REVIEWING, Report::STATUS_RESOLVED] as $stan) {
            $wiersz = $this->zgloszenie('minor', now()->subHour(), $zglaszajaca);
            $wiersz->forceFill(['status' => $stan] + ($stan === Report::STATUS_RESOLVED ? ['resolved_at' => now()] : []))->save();
            $oczekiwane[(string) $wiersz->getKey()] = PriorytetSprawy::wKolejce($wiersz) ?? PriorytetSprawy::NIE_CZEKA;
        }

        [$wyrazenie, $parametry] = PriorytetSprawy::wyrazenieSqlKolejki();

        $zBazy = Report::query()
            ->selectRaw('id, ('.$wyrazenie.') AS priorytet', $parametry)
            ->pluck('priorytet', 'id')
            ->map(static fn ($p): int => (int) $p)
            ->all();

        $this->assertSame(
            $this->wedlugId($oczekiwane),
            $this->wedlugId(array_intersect_key($zBazy, $oczekiwane)),
        );
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
            $this->wedlugId($oczekiwane),
            $this->wedlugId(array_intersect_key($zBazy, $oczekiwane)),
            'Priorytet policzony w PHP różni się od tego, którym sortuje baza. '
            .'Karta pokazywałaby wtedy co innego, niż mówi pozycja w kolejce.',
        );
    }

    // ---------------------------------------------------------------
    // Pomocnicze
    // ---------------------------------------------------------------

    /**
     * Pary id → priorytet ułożone po id, żeby porównanie nie zależało od
     * kolejności wierszy. `pluck()` bez ORDER BY oddaje je w kolejności,
     * jaką wybierze PostgreSQL — i ta potrafi się różnić między runnerami.
     * `assertSame` na tablicy asocjacyjnej porównuje też kolejność kluczy,
     * więc bez tego test oblewał przy identycznych wartościach.
     *
     * @param  array<string, int>  $pary
     * @return array<string, int>
     */
    private function wedlugId(array $pary): array
    {
        ksort($pary, SORT_STRING);

        return $pary;
    }

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

    private function wpis(string $autor): Post
    {
        return Post::factory()->for($this->user($autor), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
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
