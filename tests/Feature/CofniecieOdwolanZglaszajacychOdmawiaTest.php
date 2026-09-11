<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `appeals_open_to_reporters` nie łamie po cichu odwołań
 * ZGŁASZAJĄCYCH (D-088, issue #23, DSA art. 20 ust. 1).
 *
 * DLACZEGO AKURAT TA MIGRACJA WYMAGA STRAŻNIKA
 * Migracja otworzyła system skarg dla zgłaszających: `appeals.user_id`
 * przestało być `NOT NULL`, a `appellant` + `report_id` mówią, kto się
 * odwołuje i gdzie jest jego tożsamość. Odwołanie zgłaszającego ma więc
 * `user_id NULL` — a stary schemat wymagał tam wartości. Cofnięcie musiałoby
 * albo te wiersze skasować, albo dopisać im cudze `user_id`; jedno i drugie
 * niszczy dowód, że zgłaszający DOSTAŁ odpowiedź na skargę wymaganą przez
 * art. 20 ust. 1 DSA. I jak przy każdym `down()`: po nim prawie zawsze idzie
 * kolejny `migrate`, kolumny wracają, CHECK-i wracają — NIE MA BŁĘDU DO
 * ZAUWAŻENIA, bo brakuje wyłącznie wierszy, których nikt nie liczy.
 *
 * Ta migracja świadomie NIE MA furtki przez zmienną środowiskową: nie ma
 * bezpiecznego sposobu automatycznego „naprawienia" tych wierszy, więc jedyne
 * dwa wyjścia wymienia komunikat odmowy — usunąć je ręcznie albo zostać na
 * tej wersji schematu.
 *
 * Test sprawdza OBIE strony. Sama odmowa nie wystarczy: migracja, która nie
 * cofa się nigdy, blokowałaby świeże wdrożenie i lokalne bazy bez powodu
 * i byłaby błędem tej samej wagi w drugą stronę.
 */
class CofniecieOdwolanZglaszajacychOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const PLIK = 'migrations/2026_09_07_800000_appeals_open_to_reporters.php';

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    /**
     * Zgłoszenie prawne od osoby BEZ KONTA — dokładnie ten wiersz, w którym
     * siedzi tożsamość zgłaszającego (art. 16 ust. 2 lit. c).
     *
     * Kształt danych przepisany z `ZglaszajacyMaDostepDoSkargTest::zgloszeniePrawne()`,
     * żeby oba testy mówiły o tym samym zgłoszeniu — `reports` ma własne
     * CHECK-i (`reports_legal_notice_complete_check`,
     * `reports_community_target_check`) i wymyślanie danych od nowa kończy się
     * odbiciem od bazy, nie testem.
     */
    private function zgloszeniePrawne(): Report
    {
        return Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'post',
            'target_id' => null,
            'reason' => 'illegal',
            'illegality_explanation' => 'Treść narusza prawo, bo zawiera cudze dane osobowe.',
            'good_faith_at' => now(),
            'notifier_name' => 'Jan Zgłaszający',
            'notifier_email' => 'jan@przyklad.test',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /**
     * Decyzja moderatora na tym zgłoszeniu. Jedna na zgłoszenie — pilnuje
     * tego `moderation_actions_one_per_report`, więc każde odwołanie w tym
     * teście dostaje własne zgłoszenie i własną decyzję.
     */
    private function decyzja(Report $zgloszenie): ModerationAction
    {
        return ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'post',
            'target_id' => $zgloszenie->target_id,
            'action' => ModerationAction::ACTION_NONE,
            'reason_code' => 'brak_naruszenia',
        ]);
    }

    /**
     * Odwołanie ZGŁASZAJĄCEGO: `appellant = 'reporter'`, `report_id`
     * wypełnione, `user_id` puste. Taki układ pól wymusza
     * `appeals_appellant_identity_check`.
     */
    private function odwolanieZglaszajacego(): Appeal
    {
        $zgloszenie = $this->zgloszeniePrawne();

        return Appeal::create([
            'moderation_action_id' => $this->decyzja($zgloszenie)->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'appellant' => Appeal::APPELLANT_REPORTER,
            'body' => 'Decyzja „bez działania" jest moim zdaniem błędna, proszę o ponowne rozpatrzenie.',
        ]);
    }

    /**
     * Odwołanie AUTORA treści: `appellant = 'author'`, `user_id` wypełnione,
     * `report_id` puste. To jest rola, którą stary schemat umiał wyrazić —
     * i której ten strażnik NIE MA prawa dotyczyć.
     *
     * @return array{0: Appeal, 1: User, 2: Report}
     */
    private function odwolanieAutora(): array
    {
        $autorka = $this->user('autorka');
        $post = Post::factory()->create(['author_id' => $autorka->getKey()]);

        $zgloszenie = $this->zgloszeniePrawne();
        $zgloszenie->forceFill(['target_id' => $post->getKey()])->save();

        $odwolanie = Appeal::create([
            'moderation_action_id' => $this->decyzja($zgloszenie)->getKey(),
            'user_id' => $autorka->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie było naruszeniem niczyich praw, proszę o cofnięcie decyzji.',
        ]);

        return [$odwolanie, $autorka, $zgloszenie];
    }

    /**
     * Czy baza przyjmie dziś odwołanie o ROZJECHANEJ tożsamości — rolę
     * zgłaszającego z `user_id` zamiast `report_id`.
     *
     * ZAGNIEŻDŻONA TRANSAKCJA NIE JEST OZDOBNIKIEM. Pytamy o to jedynym
     * uczciwym sposobem — próbą zapisu, która ma się odbić o CHECK — a
     * w PostgreSQL nieudane zapytanie przerywa CAŁĄ transakcję, tę samą,
     * w której `RefreshDatabase` trzyma test. Bez SAVEPOINT-a każde następne
     * zapytanie, także zwykły SELECT w asercji, kończyłoby się
     * `SQLSTATE[25P02] current transaction is aborted`. `catch` uspokaja PHP,
     * nie bazę. Wzór: `CofniecieSkaliTekstuOdmawiaTest::bazaPrzyjmuje()`.
     */
    private function bazaPrzyjmujeRozjechanaTozsamosc(): bool
    {
        $zgloszenie = $this->zgloszeniePrawne();
        $decyzja = $this->decyzja($zgloszenie);
        $ktos = $this->user();

        DB::beginTransaction();

        try {
            DB::table('appeals')->insert([
                'id' => (string) Str::uuid(),
                'moderation_action_id' => $decyzja->getKey(),
                'user_id' => $ktos->getKey(),
                'report_id' => null,
                'appellant' => 'reporter',
                'body' => 'To nie powinno się zapisać.',
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            DB::rollBack();

            return false;
        }

        DB::rollBack();

        return true;
    }

    // ------------------------------------------------------------------
    // 1. ODMOWA, gdy jest co stracić
    // ------------------------------------------------------------------

    public function test_cofniecie_odmawia_i_zostawia_odwolania_zglaszajacych_nietkniete(): void
    {
        // JEDNO ODWOŁANIE, NIE DWA. Komunikat wklejał dotąd liczbę w sztywną
        // frazę („jest 1 odwołań"), więc test zakładał dwa wiersze, żeby nie
        // zamrażać w asercji błędnej polszczyzny — a przypadek najbardziej
        // prawdopodobny na produkcji, czyli jedno odwołanie, nie był
        // sprawdzany. Komunikat stawia teraz rzeczownik przed liczbą, więc
        // jedynka jest zdaniem poprawnym i to ona jest tu mierzona.
        $pierwsze = $this->odwolanieZglaszajacego();
        $drugie = $pierwsze;

        $ile = 1;
        $this->assertSame($ile, Appeal::where('appellant', Appeal::APPELLANT_REPORTER)->count());

        // `fail()` NIE MOŻE stać wewnątrz `try` z `catch (RuntimeException)`:
        // `PHPUnit\Framework\AssertionFailedError` dziedziczy po
        // `RuntimeException`, więc własny `catch` połknąłby własną porażkę
        // testu (pułapka 3 z `docs/PULAPKI_TESTOW.md` — sabotaż i asercja
        // trafiają w tę samą gałąź hierarchii). Wyjątek odkładamy więc do
        // zmiennej i oceniamy go POZA blokiem.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i złamało odwołania zgłaszających.');

        // Komunikat ma powiedzieć ILU wierszy to dotyczy, CO ZROBIĆ
        // ZAMIAST TEGO i DLACZEGO te wiersze są warte zatrzymania
        // rollbacku. Komunikat bez tych trzech rzeczy zostawia człowieka
        // z samym „nie da się".
        //
        // Liczbę składamy ze zmiennej, a nie wpisujemy w tekst asercji:
        // „2 odwołań" jest po polsku niepoprawne, ale to jest DOSŁOWNY
        // kształt komunikatu, a asercja ma pilnować LICZBY, nie odmiany.
        // Sama cyfra nie wystarczy — w komunikacie stoi jeszcze „art. 20
        // ust. 1", więc „2" i „1" złapałyby się tam przypadkiem.
        $this->assertStringContainsString(
            "Liczba odwołań złożonych przez zgłaszających (appellant='reporter') w bazie: {$ile}.",
            $odmowa->getMessage(),
        );

        // Marker roli — to on rozstrzyga, o KTÓRE wiersze chodzi, i tylko
        // ta migracja go zna.
        $this->assertStringContainsString("(appellant='reporter')", $odmowa->getMessage());

        // DWA wyjścia, oba wymienione wprost. Ta migracja świadomie nie ma
        // trzeciego (furtki przez zmienną środowiskową).
        $this->assertStringContainsString('Usuń je ręcznie', $odmowa->getMessage());
        $this->assertStringContainsString('zostań na tej wersji schematu', $odmowa->getMessage());

        // DLACZEGO warto: to nie są „jakieś rekordy", to dowód z art. 20.
        $this->assertStringContainsString('art. 20 ust. 1 DSA', $odmowa->getMessage());

        // NAJWAŻNIEJSZE: dowód nadal jest. Odmowa, która zdążyła już złamać
        // wiersze, byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertTrue(Schema::hasColumn('appeals', 'appellant'),
            'Odmowa zdążyła zdjąć kolumnę `appellant` — to już jest utrata roli odwołującego.');
        $this->assertTrue(Schema::hasColumn('appeals', 'report_id'),
            'Odmowa zdążyła zdjąć kolumnę `report_id` — to już jest utrata tożsamości zgłaszającego.');

        foreach ([$pierwsze, $drugie] as $odwolanie) {
            $wiersz = DB::table('appeals')->where('id', $odwolanie->getKey())->first();

            $this->assertNotNull($wiersz, 'Odwołanie zgłaszającego zniknęło mimo odmowy.');
            $this->assertSame('reporter', $wiersz->appellant);
            $this->assertNotNull($wiersz->report_id, 'Zgłaszający stracił powiązanie ze swoim zgłoszeniem.');
            $this->assertNull($wiersz->user_id);
        }
    }

    public function test_po_odmowie_schemat_nadal_pilnuje_tozsamosci_odwolujacego(): void
    {
        $this->odwolanieZglaszajacego();

        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło mimo odwołania zgłaszającego w bazie.');

        // Same kolumny to za mało: gdyby odmowa zdążyła zdjąć
        // `appeals_appellant_identity_check`, baza przyjęłaby odwołanie
        // zgłaszającego z cudzym `user_id` i nikt by tego nie zauważył,
        // dopóki ktoś nie zapytałby, czyje to odwołanie.
        $this->assertFalse(
            $this->bazaPrzyjmujeRozjechanaTozsamosc(),
            'Po odmowie baza przyjmuje rolę zgłaszającego z `user_id` — CHECK tożsamości już nie stoi.',
        );
    }

    // ------------------------------------------------------------------
    // 2. KONTROLA DODATNIA — bez tego test przechodziłby także dla migracji,
    //    która nie cofa się NIGDY
    // ------------------------------------------------------------------

    public function test_na_pustej_tabeli_cofniecie_dziala_bez_pytania(): void
    {
        // Świeże wdrożenie: nie ma czego stracić i nie ma o co pytać.
        $this->assertSame(0, (int) DB::table('appeals')->count());

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasColumn('appeals', 'appellant'),
            'Na pustej tabeli `down()` ma po prostu zadziałać — inaczej nic nie cofnął.',
        );
        $this->assertFalse(Schema::hasColumn('appeals', 'report_id'));
    }

    public function test_same_odwolania_autorow_nie_blokuja_cofniecia(): void
    {
        // Odmowa ma być WĄSKA (D-088): blokuje ją wyłącznie obecność wierszy,
        // których stary schemat NIE POMIEŚCI. Odwołanie autora ma `user_id`,
        // więc mieści się w nim bez zmiany choćby jednego pola.
        $this->odwolanieAutora();

        $this->assertSame(0, Appeal::where('appellant', Appeal::APPELLANT_REPORTER)->count());

        $this->migracja()->down();

        $this->assertFalse(
            Schema::hasColumn('appeals', 'appellant'),
            'Samo odwołanie autora zatrzymało rollback — strażnik jest za szeroki.',
        );
        $this->assertSame(1, (int) DB::table('appeals')->count(),
            'Cofnięcie skasowało odwołanie autora, które miało przejść bez uszczerbku.');
    }

    // ------------------------------------------------------------------
    // 3. WĄSKOŚĆ — odmowa nie rusza niczego poza swoim zakresem
    // ------------------------------------------------------------------

    public function test_odmowa_nie_rusza_odwolania_autora_ani_zgloszenia_ani_konta(): void
    {
        [$autorskie, $autorka, $zgloszenieAutora] = $this->odwolanieAutora();
        $this->odwolanieZglaszajacego();

        $odwolaniePrzed = DB::table('appeals')->where('id', $autorskie->getKey())->first();
        $zgloszeniePrzed = DB::table('reports')->where('id', $zgloszenieAutora->getKey())->first();
        $kontoPrzed = DB::table('users')->where('id', $autorka->getKey())->first();

        // Pusty `catch` z `fail()` w środku byłby tu najgorszą możliwą
        // konstrukcją: `AssertionFailedError` jest `RuntimeException`, więc
        // porażka testu wpadłaby do własnego `catch` i test przeszedłby
        // NAWET wtedy, gdyby strażnika w ogóle nie było.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło mimo odwołania zgłaszającego w bazie.');

        $this->assertEquals(
            $odwolaniePrzed,
            DB::table('appeals')->where('id', $autorskie->getKey())->first(),
            'Odmowa ruszyła odwołanie AUTORA, którego ten strażnik w ogóle nie dotyczy.',
        );

        $this->assertEquals(
            $zgloszeniePrzed,
            DB::table('reports')->where('id', $zgloszenieAutora->getKey())->first(),
            'Odmowa ruszyła zgłoszenie, na którym zapadła decyzja.',
        );

        $this->assertEquals(
            $kontoPrzed,
            DB::table('users')->where('id', $autorka->getKey())->first(),
            'Odmowa ruszyła konto osoby, której dotyczy odwołanie.',
        );
    }
}
