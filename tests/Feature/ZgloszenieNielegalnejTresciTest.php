<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\Report;
use App\Notifications\DecyzjaWSprawieZgloszenia;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Zgłoszenie nielegalnej treści działa BEZ KONTA (DSA art. 16).
 *
 * DLACZEGO TO JEST OSOBNA DROGA
 * Istniejący przycisk „Zgłoś" stoi za logowaniem i to jest w porządku — on
 * obsługuje NASZE ZASADY: spam, chamstwo, niebezpieczna porada.
 *
 * Art. 16 to co innego: mechanizm zgłaszania treści NIELEGALNEJ, dostępny dla
 * każdej osoby i każdego podmiotu. Zgłasza prawnik w imieniu klienta, rodzic,
 * który rozpoznał dziecko na cudzym zdjęciu, autor tekstu przepisanego tu bez
 * zgody. Żadna z tych osób nie ma konta w serwisie kulinarnym i nie ma powodu,
 * żeby je zakładać — a wymóg konta wyklucza dokładnie tych, dla których ten
 * mechanizm istnieje.
 *
 * Do tego dochodzą dwa obowiązki, których przy zgłoszeniu społecznościowym
 * nie ma: potwierdzenie odbioru (ust. 4) i powiadomienie o decyzji wraz
 * z pouczeniem o środkach odwoławczych (ust. 5).
 */
class ZgloszenieNielegalnejTresciTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function poprawneZgloszenie(array $zmiany = []): array
    {
        return array_merge([
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepis/rosol-babci-zofii',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ], $zmiany);
    }

    public function test_gosc_bez_konta_moze_zlozyc_zgloszenie(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU. Cała reszta to szczegóły tego
        // jednego wymogu.
        Notification::fake();

        $this->get(route('zglos.nielegalna'))->assertOk();

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie())
            ->assertSessionHasNoErrors()
            ->assertRedirectContains(route('zglos.nielegalna.potwierdzenie').'?potwierdzenie=');

        $this->assertDatabaseHas('reports', [
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'notifier_name' => 'Anna Kowalska',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_zglaszajacy_dostaje_potwierdzenie_odbioru(): void
    {
        // Art. 16 ust. 4. To nie jest uprzejmość — to jedyny sygnał, że
        // zgłoszenie w ogóle doszło.
        Notification::fake();

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie());

        Notification::assertSentOnDemand(PotwierdzenieZgloszeniaNielegalnejTresci::class);

        $zgloszenie = Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail();
        $this->assertNotNull($zgloszenie->receipt_sent_at, 'Nie zapisaliśmy, że potwierdzenie poszło.');
    }

    public function test_zglaszajacy_dowiaduje_sie_o_decyzji(): void
    {
        // Art. 16 ust. 5, audyt W5-02. Do tej pory zgłaszający nie dowiadywał
        // się NICZEGO — nawet tego, że sprawa została zamknięta — a ekran
        // obiecywał „odpiszemy Ci, co zrobiliśmy".
        Notification::fake();

        $autor = $this->user('autor');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'target_url' => 'https://kuking.pl/przepis/'.$przepis->slug,
        ]));

        $zgloszenie = Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail();

        // Adres rozpoznany — moderator dostaje konkretną treść, nie sam link.
        $this->assertSame('recipe', $zgloszenie->target_type);
        $this->assertSame($przepis->getKey(), $zgloszenie->target_id);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'prawa-autorskie',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(DecyzjaWSprawieZgloszenia::class);
        $this->assertNotNull($zgloszenie->refresh()->decision_sent_at);
    }

    public function test_decyzja_odmowna_tez_dociera_do_zglaszajacego(): void
    {
        // Odmowa jest trudniejsza niż zgoda i właśnie dlatego musi dojść:
        // bez niej człowiek nie wie, czy sprawa stoi, czy została zamknięta.
        Notification::fake();

        $autor = $this->user('autor');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'target_url' => 'https://kuking.pl/przepis/'.$przepis->slug,
        ]));

        $zgloszenie = Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail();

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_NONE,
                'reason_code' => 'brak-naruszenia',
            ])
            ->assertSessionHasNoErrors();

        Notification::assertSentOnDemand(DecyzjaWSprawieZgloszenia::class);
    }

    public function test_zgloszenie_bez_adresu_email_jest_dopuszczalne(): void
    {
        // Art. 16 ust. 2 lit. c zwalnia z podania danych przy zgłoszeniach
        // dotyczących przestępstw z art. 3-7 dyrektywy 2011/93/UE. Wtedy nie
        // ma komu odpowiedzieć i to jest zgodne z przepisem, a nie brak.
        Notification::fake();

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'notifier_email' => null,
            'reason' => 'minor',
        ]))->assertSessionHasNoErrors();

        $zgloszenie = Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail();

        $this->assertNull($zgloszenie->notifier_email);
        $this->assertNull($zgloszenie->receipt_sent_at);
        Notification::assertNothingSent();
    }

    public function test_nierozpoznany_adres_nie_odrzuca_zgloszenia(): void
    {
        // Ktoś wkleja link z pamięci albo ze zrzutu ekranu; treść mogła już
        // zniknąć. Odrzucenie zgłoszenia, bo nie rozpoznaliśmy adresu, byłoby
        // odmówieniem mechanizmu, który przepis nakazuje udostępnić.
        Notification::fake();

        // `assertRedirect` OBOK `assertSessionHasNoErrors` — świadomie.
        // Samo `assertSessionHasNoErrors` przepuszczało błąd 500: sesja jest
        // wtedy czysta, bo walidacja w ogóle nie zdążyła się nie powieść.
        // Dokładnie tak ten test przeszedł za pierwszym razem, choć baza
        // odrzucała wiersz (CHECK na `target_type` nie znał `unknown`).
        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'target_url' => 'gdzieś na waszej stronie, widziałam to wczoraj',
        ]))
            ->assertSessionHasNoErrors()
            ->assertRedirectContains(route('zglos.nielegalna.potwierdzenie').'?potwierdzenie=');

        $zgloszenie = Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail();

        $this->assertSame('unknown', $zgloszenie->target_type);
        $this->assertSame('gdzieś na waszej stronie, widziałam to wczoraj', $zgloszenie->target_url);

        // Cel zostaje PUSTY, a nie wypełniony UUID-em z samych zer.
        // Identyfikator, który wygląda jak identyfikator i niczego nie
        // wskazuje, prędzej czy później trafiłby do zapytania albo na ekran.
        $this->assertNull($zgloszenie->target_id);

        // Moderator musi móc taką sprawę ZAMKNĄĆ. Jedyna dostępna decyzja
        // to „brak działania" — nie da się ukryć treści, której nie wskazano.
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_NONE,
                'reason_code' => 'nierozpoznany-adres',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Report::STATUS_REJECTED, $zgloszenie->refresh()->status);
    }

    public function test_brak_uzasadnienia_albo_oswiadczenia_zatrzymuje_zgloszenie(): void
    {
        // Art. 16 ust. 2 wymaga uzasadnienia i oświadczenia o dobrej wierze.
        // Bez nich moderator nie ma czego ocenić.
        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'illegality_explanation' => '',
        ]))->assertSessionHasErrors('illegality_explanation');

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'good_faith' => null,
        ]))->assertSessionHasErrors('good_faith');

        $this->assertDatabaseCount('reports', 0);
    }

    /**
     * Test regresyjny audytu SEC-08, blokujący cofnięcie ustalenia W7-10.
     *
     * `ZglosNielegalnaTresc` ŚWIADOMIE nie scala duplikatów — w przeciwieństwie
     * do `ReportContent` (zgłoszenie społecznościowe), gdzie dwa kliknięcia tej
     * SAMEJ osoby mają dać jeden wiersz. Tutaj dwa zgłoszenia tej samej treści
     * mogą pochodzić od DWÓCH RÓŻNYCH osób, z DWÓCH RÓŻNYCH podstaw prawnych
     * (np. praw autorskich i ochrony wizerunku), i każdej z nich należy się
     * osobna odpowiedź (art. 16 ust. 4 i 5) — scalenie ukradłoby odpowiedź
     * jednej z nich.
     *
     * Dziś nic tego nie pilnuje: gdyby ktoś kiedyś dopisał tu scalanie „przez
     * analogię" do `ReportContent`, ten test ma to złapać.
     */
    public function test_dwa_zgloszenia_tej_samej_tresci_od_dwoch_osob_nie_scalaja_sie(): void
    {
        Notification::fake();

        $autor = $this->user('autor_sec08');
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => 'published',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepis/'.$przepis->slug,
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
        ]))->assertSessionHasNoErrors();

        $this->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
            'notifier_name' => 'Jan Nowak',
            'notifier_email' => 'jan@example.com',
            'target_url' => 'https://kuking.pl/przepis/'.$przepis->slug,
            'reason' => 'personal_data',
            'illegality_explanation' => 'To zdjęcie mojej twarzy bez mojej zgody.',
        ]))->assertSessionHasNoErrors();

        // Dwa wiersze, nie jeden — inaczej Jan Nowak nigdy nie dowie się,
        // co zdecydowano w JEGO sprawie.
        $this->assertSame(2, Report::where('target_type', 'recipe')->where('target_id', $przepis->getKey())->count());

        Notification::assertSentOnDemandTimes(PotwierdzenieZgloszeniaNielegalnejTresci::class, 2);
    }

    public function test_wpisane_dane_nie_znikaja_po_bledzie(): void
    {
        // docs/UX_50_PLUS.md: poprawnie wpisane dane nigdy nie znikają.
        // Ten formularz wypełnia człowiek zdenerwowany, często długim tekstem.
        // Sprawdzamy EKRAN, a nie `old()`. Poza cyklem żądania `old()` czyta
        // pustą sesję i test przechodziłby albo nie przechodził niezależnie
        // od tego, co widzi człowiek — czyli mierzyłby nie to, co trzeba.
        $ekran = $this->from(route('zglos.nielegalna'))
            ->followingRedirects()
            ->post(route('zglos.nielegalna.store'), $this->poprawneZgloszenie([
                'good_faith' => null,
            ]));

        $ekran->assertOk()
            ->assertSee('Anna Kowalska', escape: false)
            ->assertSee('anna@kancelaria.example', escape: false)
            // Najdłuższe pole i najboleśniejsze do wpisania drugi raz.
            ->assertSee('przepisany bez zgody z mojej książki', escape: false)
            ->assertSee('Zaznacz oświadczenie na dole formularza.', escape: false);
    }

    public function test_formularz_da_sie_znalezc(): void
    {
        // Art. 16 ust. 1: mechanizm ma być ŁATWO DOSTĘPNY. Formularz, do
        // którego nie ma skąd kliknąć i którego nie widzi wyszukiwarka,
        // tego nie spełnia — a dokładnie taki był przez pierwszą wersję tej
        // zmiany: istniał pod adresem, którego nikt nie miał prawa znać.

        // 1. Widać go ze stopki KAŻDEJ strony, także bez zalogowania.
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(route('zglos.nielegalna'), escape: false);

        // 2. Nie jest wyjęty z indeksu. Człowiek, który znalazł tu swoje
        //    zdjęcie, wpisuje to w wyszukiwarkę, a nie szuka naszej stopki.
        $this->get(route('zglos.nielegalna'))
            ->assertOk()
            ->assertDontSee('name="robots"', escape: false);

        // 3. robots.txt też go nie blokuje. `Disallow: /zglos` bez ukośnika
        //    dopasowuje się po przedrostku i odcinało `/zglos-nielegalna-tresc`
        //    razem z formularzem społecznościowym.
        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            "Disallow: /zglos\n",
            $robots,
            'robots.txt blokuje po przedrostku `/zglos`, więc odcina także publiczną drogę z DSA art. 16.',
        );
        $this->assertStringContainsString('Disallow: /zglos/', $robots);

        // Ekran potwierdzenia ZOSTAJE poza indeksem — niesie numer sprawy.
        $this->get(route('zglos.nielegalna.potwierdzenie'))
            ->assertOk()
            ->assertSee('name="robots"', escape: false);
    }

    public function test_zwykle_zgloszenie_spolecznosciowe_dalej_wymaga_zalogowania(): void
    {
        // Dwie różne drogi i mają takie zostać. Otwarcie tamtej dla wszystkich
        // znaczyłoby otwarcie kolejki jedynego moderatora na cokolwiek.
        $this->get(route('reports.create', ['type' => 'post', 'id' => '00000000-0000-0000-0000-000000000000']))
            ->assertRedirect(route('login'));
    }
}
