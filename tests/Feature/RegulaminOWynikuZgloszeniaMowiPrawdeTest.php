<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * OBIETNICA Z REGULAMINU §7 („Poinformujemy Cię o wyniku") JEST WIĄZANA
 * Z REALNYM ZACHOWANIEM SERWISU — I Z TYM, CO O TYM MÓWI PODRĘCZNIK
 * MODERACJI.
 *
 * CO SIĘ STAŁO 10 WRZEŚNIA 2026
 * Audyt zewnętrzny zgłosił jako P0 BLOKUJĄCE START: „regulamin obiecuje
 * odpowiedź każdemu zgłaszającemu, a produkt tego nie robi". Sprawdzenie przy
 * kodzie pokazało, że produkt to robi od issue #10 — pętla zgłaszającego jest
 * zamknięta (`NotifyReporterReceipt`, `NotifyReporterDecision`,
 * `OdpowiedzDlaZglaszajacego`, `/zgloszenia`, `receipt_sent_at`,
 * `decision_sent_at`).
 *
 * Audytor jednak nie zmyślił. Przeczytał `docs/legal/MODERATION_PLAYBOOK.md`,
 * który w TRZECH miejscach twierdził, że osoba klikająca zwykłe „Zgłoś" nie
 * dostaje od serwisu nic i że moderator musi napisać do niej maila ręcznie.
 * Playbook opisywał stan sprzed #10.
 *
 * DLACZEGO TO JEST TEST, A NIE POPRAWKA W MARKDOWNIE
 * Bo sama poprawka nie broni się przed powtórką. Skutek rozjazdu jest
 * operacyjny i realny: moderator idący za playbookiem albo napisze do
 * zgłaszającego DRUGI raz ręcznie, albo uzna, że obietnicy z regulaminu §7
 * nie da się dotrzymać, i przestanie ją traktować poważnie.
 *
 * CZEGO TEN PLIK PILNUJE — DWIE RZECZY, W TEJ KOLEJNOŚCI
 *
 *  1. **Zachowania.** Zgłoszenie ze ZWYKŁEGO przycisku „Zgłoś" → decyzja
 *     moderatora → zgłaszający MA informację o wyniku. To jest właściwy
 *     pomiar; bez niego punkt 2 nie znaczy nic.
 *  2. **Że dokument tego nie zaprzecza.** Wąska, wymieniona z nazwy lista
 *     zdań, które w playbooku stały i były nieprawdą.
 *
 * Punkt 2 sam jeden byłby bezwartościowy: przechodziłby również wtedy, gdyby
 * kod PRZESTAŁ powiadamiać, bo brak zdania w pliku o niczym nie świadczy.
 * Dlatego oba stoją w jednym pliku i oba muszą być zielone.
 *
 * CZEGO TEN PLIK NIE PILNUJE
 * Brzmienia odpowiedzi (to robi `OdpowiedzDlaZglaszajacegoMowiPrawdeTest`)
 * ani pełnego cyklu zgłoszenia (`ZglaszajacyDostajeOdpowiedzTest`). Tutaj
 * chodzi wyłącznie o SPÓJNOŚĆ: publiczna obietnica, kod i instrukcja
 * operacyjna mówią to samo.
 */
class RegulaminOWynikuZgloszeniaMowiPrawdeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Zdania z playbooka, które opisywały stan sprzed issue #10. Każde z nich
     * mówi moderatorowi coś, co jest dziś nieprawdą — i każde prowadzi go do
     * jednego z dwóch złych działań: drugiego listu w tej samej sprawie albo
     * porzucenia obietnicy z regulaminu.
     */
    private const ZDANIA_ZAPRZECZAJACE = [
        'nie dostaje dziś ani powiadomienia o decyzji',
        'nie dostaje od serwisu nic',
        'pod zdjęciem nie dostanie nic',
        'nie obiecuj zgłaszającemu',
        'musisz napisać maila ręcznie',
        'trzeba napisać do niego ręcznie',
    ];

    private function plik(string $sciezka): string
    {
        $pelna = base_path($sciezka);
        $tresc = file_get_contents($pelna);

        $this->assertIsString($tresc, "Nie da się wczytać {$pelna}.");

        return (string) $tresc;
    }

    /**
     * Sekcja regulaminu wycięta po nagłówku, a nie cały dokument.
     *
     * To jest ta sama pułapka, która złapała już inne testy w tym repo:
     * asercja na całym pliku łapie to samo słowo z innego miejsca. „Wynik"
     * i „poinformujemy" pojawiają się w regulaminie także w §8 (decyzje wobec
     * autora treści) — a tamta obietnica dotyczy kogoś innego i jest
     * spełniana innym kodem.
     */
    private function paragrafRegulaminu(string $naglowek): string
    {
        $tresc = $this->plik('resources/legal/regulamin.md');

        $wzorzec = '/^'.preg_quote($naglowek, '/').'$(.*?)^## /msu';

        $this->assertSame(
            1,
            preg_match($wzorzec, $tresc, $dopasowanie),
            "Nie znaleziono w regulaminie sekcji „{$naglowek}”. Jeśli sekcję "
            .'przemianowano albo przeniesiono, popraw kotwicę w tym teście '
            .'RAZEM z dokumentem — a nie sam test.',
        );

        return $dopasowanie[1];
    }

    /**
     * KONTROLA METODY POMIARU, USTAWIONA PRZED RESZTĄ.
     *
     * Regulamin naprawdę składa tę obietnicę i naprawdę jest widoczny pod
     * `/regulamin`. Bez tego cały plik badałby zgodność z obietnicą, której
     * nikt nie złożył — i przechodziłby także wtedy, gdyby ktoś obietnicę po
     * cichu wykreślił, żeby uciszyć test.
     *
     * Gdyby produkt kiedyś świadomie WYCOFAŁ tę obietnicę, poprawka jest
     * odwrotna niż zwykle: najpierw zmiana w `resources/legal/regulamin.md`
     * (to jest dokument prawny — patrz D-024), a potem usunięcie tego pliku
     * testowego. Nie na odwrót.
     */
    public function test_kontrola_regulamin_obiecuje_informacje_o_wyniku_zgloszenia(): void
    {
        $paragraf = $this->paragrafRegulaminu('## 7. Zgłaszanie treści');

        $this->assertStringContainsString('Każde zgłoszenie sprawdzamy.', $paragraf);
        $this->assertStringContainsString('Poinformujemy Cię o wyniku.', $paragraf);

        // Dokument nie jest tylko plikiem w repo — to jest tekst, który czyta
        // człowiek na żywej stronie.
        $this->get('/regulamin')->assertOk()->assertSee('Poinformujemy Cię o wyniku.');
    }

    /**
     * WŁAŚCIWY POMIAR. Zgłoszenie ze zwykłego „Zgłoś" → decyzja → zgłaszający
     * MA informację o wyniku.
     *
     * Zwykłe „Zgłoś" jest tu istotą sprawy, nie szczegółem: droga prawna
     * (formularz DSA z adresem e-mail) odpowiadała już wcześniej, pocztą.
     * Nieprawdą było zdanie o TEJ ścieżce — o przycisku pod wpisem, którym
     * zgłasza zwykły użytkownik z kontem.
     */
    public function test_obietnica_z_paragrafu_7_jest_dotrzymana_po_zwyklym_zgloszeniu(): void
    {
        // Kontrola: obietnica stoi w dokumencie, więc jest co mierzyć.
        $this->assertStringContainsString(
            'Poinformujemy Cię o wyniku.',
            $this->paragrafRegulaminu('## 7. Zgłaszanie treści'),
        );

        $zglaszajaca = $this->user('halina');
        $autor = $this->user('krzysztof');
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_PUBLIC,
        ]);

        // ZWYKŁY przycisk „Zgłoś" pod wpisem — nie formularz DSA.
        $this->actingAs($zglaszajaca)
            ->post(route('reports.store', ['type' => 'post', 'id' => $wpis->getKey()]), [
                'reason' => 'harassment',
                'details' => 'Nazywa mnie oszustką.',
            ])
            ->assertSessionHasNoErrors();

        $zgloszenie = Report::query()
            ->where('reporter_id', $zglaszajaca->getKey())
            ->latest('created_at')
            ->firstOrFail();

        // „Każde zgłoszenie sprawdzamy" — potwierdzenie przyjęcia (art. 16
        // ust. 4) jest pierwszą połową obietnicy z §7.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $zglaszajaca->getKey(),
            'type' => Notification::TYPE_REPORT_RECEIVED,
        ]);
        $this->assertNotNull($zgloszenie->refresh()->receipt_sent_at);

        $this->rozstrzygnij($zgloszenie);

        // „Poinformujemy Cię o wyniku" — druga połowa (art. 16 ust. 5).
        $decyzja = Notification::query()
            ->where('user_id', $zglaszajaca->getKey())
            ->where('type', Notification::TYPE_REPORT_DECIDED)
            ->first();

        $this->assertNotNull(
            $decyzja,
            'Zgłaszający ze zwykłego „Zgłoś” nie dostał informacji o wyniku. '
            .'Regulamin §7 obiecuje ją każdemu — jeśli ten kod świadomie się '
            .'zmienia, zmienia się razem z tekstem regulaminu, nie osobno.',
        );

        $this->assertNotNull(
            $zgloszenie->refresh()->decision_sent_at,
            'Brak znacznika `decision_sent_at` — przy audycie to jest dowód, '
            .'że o wyniku poinformowano.',
        );

        // Człowiek naprawdę to widzi u siebie, a nie tylko baza to ma.
        // Asercja SPRAWDZANA W KONKRETNYM MIEJSCU — na karcie tej jednej
        // sprawy, nie w całym HTML-u serwisu.
        $this->actingAs($zglaszajaca)
            ->get(route('reports.mine.show', $zgloszenie))
            ->assertOk()
            ->assertSee('Uznaliśmy Twoje zgłoszenie za zasadne.')
            ->assertSee($zgloszenie->numer_sprawy);
    }

    /**
     * PODRĘCZNIK MODERACJI NIE ZAPRZECZA TEMU, CO POKAZAŁ TEST WYŻEJ.
     *
     * Lista zdań jest WĄSKA i wymieniona z nazwy — to nie jest próba
     * napisania ogólnego detektora sprzeczności. Każde z tych zdań naprawdę
     * stało w `MODERATION_PLAYBOOK.md` do 10 września 2026.
     */
    public function test_playbook_nie_zaprzecza_zachowaniu_serwisu(): void
    {
        $playbook = $this->plik('docs/legal/MODERATION_PLAYBOOK.md');

        foreach (self::ZDANIA_ZAPRZECZAJACE as $zdanie) {
            $this->assertStringNotContainsString(
                $zdanie,
                $playbook,
                "Podręcznik moderacji znowu twierdzi „{$zdanie}”. Serwis "
                .'powiadamia zgłaszającego SAM, przy przyjęciu zgłoszenia '
                .'i przy decyzji — także po zwykłym „Zgłoś" (issue #10). '
                .'Moderator, który to przeczyta, albo napisze do człowieka '
                .'drugi raz, albo uzna obietnicę z regulaminu §7 za '
                .'niewykonalną.',
            );
        }

        // KONTROLA: temat nie zniknął z playbooka razem z nieprawdą.
        // Bez tej asercji test przechodziłby po wykasowaniu całej sekcji
        // o zgłaszającym — a moderator bez instrukcji jest w gorszej
        // sytuacji niż z instrukcją nieaktualną.
        // Kotwice UNIKALNE, nie takie, które w playbooku występują dwa razy:
        // pierwsza wersja tego testu szukała nagłówka „Kto kogo powiadamia”,
        // a ten sam ciąg stoi też w odsyłaczu z §2 — kontrola przechodziła
        // więc po usunięciu samej tabeli. Nagłówek pierwszej kolumny tabeli
        // jest w tym pliku jeden.
        foreach (['| Kto ma być poinformowany |', 'NotifyReporterDecision', 'receipt_sent_at'] as $kotwica) {
            $this->assertStringContainsString(
                $kotwica,
                $playbook,
                "Z podręcznika moderacji zniknęła kotwica „{$kotwica}”. Opis "
                .'tego, co serwis robi SAM, a co wymaga ręki moderatora, jest '
                .'częścią tej instrukcji — nie wolno go usunąć razem '
                .'z nieprawdziwymi zdaniami.',
            );
        }
    }

    private function rozstrzygnij(Report $zgloszenie, string $akcja = ModerationAction::ACTION_HIDE): void
    {
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => $akcja,
                'reason_code' => 'harassment',
                'user_message' => 'Ukrywamy wpis, bo obraża konkretną osobę.',
                // Przy „Zawieś konto" termin jest obowiązkowy; przy
                // pozostałych decyzjach ta wartość jest ignorowana.
                'suspend_days' => '7',
            ])
            ->assertSessionHasNoErrors();
    }
}
