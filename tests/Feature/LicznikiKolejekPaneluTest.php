<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\KolejkiPanelu;
use App\Models\Appeal;
use App\Models\ContactMessage;
use App\Models\ModerationAction;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * CO LICZNIK PRZY POZYCJI PANELU MA ZNACZYĆ (zgłoszenie właściciela
 * z 10 września: „w »Odwołania« nie ma takiego kwadracika jak przy
 * Powiadomieniach, że np. są 2 nieodczytane odwołania").
 *
 * TRZY RZECZY, KTÓRYCH TE TESTY PILNUJĄ — I ŻADNA Z NICH NIE JEST
 * KOSMETYCZNA:
 *
 *  1. LICZNIK ZNACZY „TO CZEKA NA CIEBIE", nie „tyle jest wszystkiego".
 *     Sprawa wzięta do przeglądu (`reviewing`, `in_progress`) albo już
 *     zamknięta jest u człowieka i nie ma po co wołać go drugi raz. Licznik,
 *     który liczy wszystko, przestaje być powodem do kliknięcia — a wtedy
 *     nie ma go po co pokazywać.
 *  2. ZERO NIE POKAZUJE NICZEGO. „0" pięć razy na każdym ekranie panelu to
 *     sam hałas: mówi tyle samo, co brak plakietki, tylko zajmuje uwagę.
 *  3. CZYTNIK EKRANU SŁYSZY, CZEGO DOTYCZY LICZBA. Sama kolorowa plamka
 *     z cyfrą jest dla osoby korzystającej z czytnika niczym (AGENTS.md §5:
 *     ikona nigdy sama; WCAG 1.4.1: kolor nigdy jako jedyny nośnik).
 */
class LicznikiKolejekPaneluTest extends TestCase
{
    use RefreshDatabase;

    /** Dowolny ekran panelu — liczniki są w MENU, więc widać je na każdym. */
    private function ekranPanelu(): TestResponse
    {
        return $this->actingAs($this->moderator())->get(route('admin.tag-promotions'));
    }

    private function zgloszenie(string $status): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'spam',
            'status' => $status,
            // `reports_resolution_complete_check` (#997): stan końcowy ma datę.
            'resolved_at' => in_array($status, [Report::STATUS_RESOLVED, Report::STATUS_REJECTED], true) ? now() : null,
        ]);
    }

    private function odwolanie(string $status): Appeal
    {
        $decyzja = ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'subject_user_id' => $this->user()->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Wpis wygląda na reklamę.',
        ]);

        return Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $decyzja->subject_user_id,
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie była reklama, tylko przepis mojej mamy.',
            'status' => $status,
            'decided_at' => $status === Appeal::STATUS_OPEN ? null : now(),
            'decided_by' => $status === Appeal::STATUS_OPEN ? null : $decyzja->moderator_id,
            'decision_note' => $status === Appeal::STATUS_OPEN ? null : 'Podtrzymuję decyzję, bo wpis był reklamą.',
        ]);
    }

    public function test_zero_nie_pokazuje_zadnego_licznika(): void
    {
        app(KolejkiPanelu::class)->przelicz();

        $odpowiedz = $this->ekranPanelu()->assertOk();

        $odpowiedz->assertDontSee('licznik-kolejki', false);
        $odpowiedz->assertDontSee('0 czeka');

        // Pozycje menu są na miejscu — to nie tak, że ekran nie ma menu.
        $odpowiedz->assertSee('Odwołania');
        $odpowiedz->assertSee('Wiadomości do nas');
    }

    public function test_licznik_liczy_tylko_to_co_czeka_na_czlowieka(): void
    {
        // Jedno odwołanie czeka, jedno jest już rozstrzygnięte.
        $this->odwolanie(Appeal::STATUS_OPEN);
        $this->odwolanie(Appeal::STATUS_UPHELD);

        // Jedno zgłoszenie nowe, jedno wzięte do przeglądu, jedno zamknięte.
        $this->zgloszenie(Report::STATUS_OPEN);
        $this->zgloszenie(Report::STATUS_REVIEWING);
        $this->zgloszenie(Report::STATUS_RESOLVED);

        // Jedna wiadomość nowa, jedna w toku.
        ContactMessage::factory()->create(['status' => ContactMessage::STATUS_NOWA]);
        // `status <> 'new'` wymaga kompletu `handled_by` + `handled_at`
        // (CHECK `contact_messages_handled_complete`), więc wiadomość „w toku"
        // buduje ten stan przez własną metodę modelu, a nie przez atrybut.
        ContactMessage::factory()->create()->oznaczJako(
            ContactMessage::STATUS_W_TOKU,
            $this->moderator(),
        );

        $liczby = app(KolejkiPanelu::class)->przelicz();

        $this->assertSame(1, $liczby['odwolania'], 'Rozstrzygnięte odwołanie już na nikogo nie czeka.');
        $this->assertSame(1, $liczby['zgloszenia'], 'Sprawa w przeglądzie jest już u człowieka.');
        $this->assertSame(1, $liczby['wiadomosci'], 'Wiadomość „w toku" ma już swojego opiekuna.');

        // I to samo widać na ekranie: przy każdej z tych pozycji stoi „1",
        // a nie „2" ani „3".
        $odpowiedz = $this->ekranPanelu()->assertOk();
        $odpowiedz->assertSee('1 czeka');
        $odpowiedz->assertDontSee('2 czeka');
        $odpowiedz->assertDontSee('3 czeka');
    }

    public function test_czytnik_ekranu_slyszy_czego_dotyczy_liczba(): void
    {
        $this->odwolanie(Appeal::STATUS_OPEN);
        $this->odwolanie(Appeal::STATUS_OPEN);

        app(KolejkiPanelu::class)->przelicz();

        $tresc = $this->ekranPanelu()->assertOk()->getContent();

        // Nazwę kolejki niesie tekst odnośnika, licznik dokłada resztę zdania:
        // czytnik ekranu czyta całą pozycję jako „Odwołania, 2 czekają".
        $this->assertMatchesRegularExpression(
            '/Odwołania(?:<\/span>)?\s*<span class="badge licznik-kolejki">\s*<span aria-hidden="true">2<\/span>\s*'
            .'<span class="visually-hidden">2 czekają<\/span>/u',
            (string) $tresc,
            'Liczba w plakietce musi mieć obok siebie pełny opis dla czytnika ekranu, '
            .'a widoczna cyfra — `aria-hidden`, żeby nie czytać jej dwa razy.',
        );
    }

    public function test_licznik_odwolan_pojawia_sie_bez_wolania_harmonogramu(): void
    {
        // Świeżość liczników pilnują zdarzenia modeli (`AppServiceProvider`),
        // nie tylko harmonogram — inaczej odwołanie złożone minutę po
        // przeliczeniu byłoby niewidoczne w menu do następnego przebiegu.
        app(KolejkiPanelu::class)->przelicz();

        $odwolanie = $this->odwolanie(Appeal::STATUS_OPEN);

        $this->assertSame(
            1,
            app(KolejkiPanelu::class)->liczby()['odwolania'],
            'Nowe odwołanie musi podnieść licznik od razu, bez czekania na harmonogram.',
        );

        // …i zniknąć, gdy sprawa zostanie zamknięta. Licznik, który zostaje
        // na „1" po zamknięciu ostatniej sprawy, kłamie raz i traci zaufanie.
        $odwolanie->update([
            'status' => Appeal::STATUS_UPHELD,
            'decided_at' => now(),
            'decided_by' => $this->moderator()->getKey(),
            'decision_note' => 'Podtrzymuję decyzję, bo wpis był reklamą.',
        ]);

        $this->assertSame(
            0,
            app(KolejkiPanelu::class)->liczby()['odwolania'],
            'Zamknięta sprawa nie może dalej wołać człowieka do kolejki.',
        );
    }

    public function test_zwykly_uzytkownik_nie_widzi_licznikow_panelu(): void
    {
        $this->odwolanie(Appeal::STATUS_OPEN);
        app(KolejkiPanelu::class)->przelicz();

        $this->actingAs($this->user('zwykla_osoba'))
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('licznik-kolejki', false)
            ->assertDontSee('1 czeka');
    }
}
