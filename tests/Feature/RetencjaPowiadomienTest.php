<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnionePowiadomienia;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Retencja `notifications` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.2, §5.6):
 * jeden wiek dla wszystkich, niezależnie od `read_at` (wariant A z ADR §6) —
 * Z WYJĄTKIEM powiadomień moderacyjnych, które żyją do upływu terminu
 * odwołania (DSA art. 20 ust. 1), patrz
 * `Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`.
 *
 * DECYZJA WŁAŚCICIELA (2026-09-07, druga tura, po zewnętrznej ocenie
 * prawnej): 3 miesiące, nie 24. Trzy miesiące są KRÓTSZE niż sześć
 * miesięcy, w które prawo do odwołania od decyzji moderacyjnej ma
 * obowiązywać (DSA art. 20 ust. 1) — bez wyjątku niżej automat kasowałby
 * jedyny w serwisie link „Odwołaj się", zanim minie termin, w którym to
 * prawo jeszcze obowiązuje.
 */
class RetencjaPowiadomienTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $data */
    private function powiadomienie(
        string $userId,
        \DateTimeInterface|string $createdAt,
        ?\DateTimeInterface $readAt = null,
        string $type = Notification::TYPE_COMMENT,
        array $data = ['tresc' => 'test'],
    ): Notification {
        $powiadomienie = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'data' => $data,
        ]);

        DB::table('notifications')->where('id', $powiadomienie->getKey())->update([
            'created_at' => $createdAt,
            'read_at' => $readAt,
        ]);

        return $powiadomienie->refresh();
    }

    private function decyzja(User $moderator, \DateTimeInterface $createdAt): ModerationAction
    {
        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
        ]);

        DB::table('moderation_actions')->where('id', $decyzja->getKey())->update(['created_at' => $createdAt]);

        return $decyzja->refresh();
    }

    // ------------------------------------------------------------------
    // Zwykłe powiadomienia — ogólny okres, bez wyjątku
    // ------------------------------------------------------------------

    public function test_powiadomienie_starsze_niz_prog_znika_a_mlodsze_zostaje(): void
    {
        config(['kuking.notifications.retention_months' => 24]);
        $basia = $this->user('basia');

        $stare = $this->powiadomienie($basia->getKey(), now()->subMonths(24)->subDay());
        $mlode = $this->powiadomienie($basia->getKey(), now()->subMonths(24)->addDay());

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(24);

        $this->assertSame(1, $raport->usunieteZwykle);
        $this->assertDatabaseMissing('notifications', ['id' => $stare->getKey()]);
        // Asercja kontrolna.
        $this->assertDatabaseHas('notifications', ['id' => $mlode->getKey()]);
    }

    /**
     * Wariant A z ADR §6: `read_at` NIE MA znaczenia. Nieprzeczytane
     * powiadomienie, stare ponad próg, znika tak samo jak przeczytane —
     * inaczej dałoby się je trzymać bezterminowo, unikając otwarcia.
     */
    public function test_nieprzeczytane_powiadomienie_starsze_niz_prog_rowniez_znika(): void
    {
        config(['kuking.notifications.retention_months' => 24]);
        $basia = $this->user('basia');

        $nieprzeczytane = $this->powiadomienie($basia->getKey(), now()->subMonths(30), readAt: null);
        // Kontrola pozytywna: to naprawdę było nieprzeczytane przed kasowaniem.
        $this->assertNull($nieprzeczytane->fresh()->read_at);

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(24);

        $this->assertSame(1, $raport->usunieteZwykle);
        $this->assertDatabaseMissing('notifications', ['id' => $nieprzeczytane->getKey()]);
    }

    public function test_komenda_retencji_dziala_i_respektuje_opcje_na_sucho(): void
    {
        config(['kuking.notifications.retention_months' => 24]);
        $basia = $this->user('basia');

        $this->powiadomienie($basia->getKey(), now()->subMonths(30));

        $this->artisan('kuking:sprzataj-powiadomienia', ['--na-sucho' => true])->assertSuccessful();
        $this->assertDatabaseCount('notifications', 1);

        $this->artisan('kuking:sprzataj-powiadomienia')->assertSuccessful();
        $this->assertDatabaseCount('notifications', 0);
    }

    // ------------------------------------------------------------------
    // Kolizja z sześciomiesięcznym terminem odwołania (DSA art. 20 ust. 1)
    // ------------------------------------------------------------------

    /**
     * OBIE STRONY W JEDNYM TEŚCIE, w tym samym wieku: zwykłe powiadomienie
     * starsze niż próg (3 miesiące) ZNIKA, powiadomienie moderacyjne
     * ZOSTAJE — bo jego własny termin (`appealDeadline()`, min. 6 miesięcy
     * od decyzji) jeszcze nie minął.
     */
    public function test_powiadomienie_moderacyjne_zostaje_a_zwykle_w_tym_samym_wieku_znika(): void
    {
        config(['kuking.notifications.retention_months' => 3]);
        $basia = $this->user('basia');
        $moderator = $this->moderator();

        $wiek = now()->subMonths(4); // > 3 mies. (ogólny próg), < 6 mies. (termin odwołania)
        $decyzja = $this->decyzja($moderator, $wiek);

        $zwykle = $this->powiadomienie($basia->getKey(), $wiek, type: Notification::TYPE_COMMENT);
        $moderacyjne = $this->powiadomienie(
            $basia->getKey(),
            $wiek,
            type: Notification::TYPE_MODERATION,
            data: ['title' => 't', 'message' => 'm', 'decision' => 'hide', 'appeal' => true, 'action_id' => $decyzja->getKey()],
        );

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertDatabaseMissing('notifications', ['id' => $zwykle->getKey()]);
        $this->assertDatabaseHas('notifications', ['id' => $moderacyjne->getKey()]);
        $this->assertSame(1, $raport->usunieteZwykle);
        $this->assertSame(0, $raport->usunieteModeracyjne);
        $this->assertSame(1, $raport->zatrzymaneTerminemOdwolania);
    }

    /**
     * Po upływie terminu odwołania powiadomienie moderacyjne PRZESTAJE być
     * chronione i znika w kolejnym przebiegu — to NIE jest bezterminowy
     * wyjątek jak `AuditLogEntry::NIGDY_NIE_KASUJ`, tylko własny, dłuższy
     * termin.
     */
    public function test_powiadomienie_moderacyjne_znika_po_uplywie_terminu_odwolania(): void
    {
        config(['kuking.notifications.retention_months' => 3]);
        $basia = $this->user('basia');
        $moderator = $this->moderator();

        // appealDeadline = created_at + 6 miesięcy = miesiąc temu (już minął).
        $decyzja = $this->decyzja($moderator, now()->subMonths(7));
        $moderacyjne = $this->powiadomienie(
            $basia->getKey(),
            now()->subMonths(7),
            type: Notification::TYPE_MODERATION,
            data: ['title' => 't', 'message' => 'm', 'decision' => 'hide', 'appeal' => true, 'action_id' => $decyzja->getKey()],
        );

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertSame(1, $raport->usunieteModeracyjne);
        $this->assertSame(0, $raport->zatrzymaneTerminemOdwolania);
        $this->assertDatabaseMissing('notifications', ['id' => $moderacyjne->getKey()]);
    }

    /**
     * Powiadomienie o WYNIKU odwołania (`NotifyAppealOutcome`) niesie
     * `data.appeal_id`, nie `action_id` — termin trzeba ustalić przez
     * `Appeal::moderationAction()`, nie wprost.
     */
    public function test_powiadomienie_o_wyniku_odwolania_liczy_termin_przez_appeal_id(): void
    {
        config(['kuking.notifications.retention_months' => 3]);
        $basia = $this->user('basia');
        $moderator = $this->moderator();

        $decyzja = $this->decyzja($moderator, now()->subMonths(4));
        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $basia->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Nie zgadzam się z decyzją.',
            'status' => Appeal::STATUS_UPHELD,
            'decided_at' => now()->subMonths(4),
            'decision_note' => 'Decyzja podtrzymana po ponownej analizie.',
        ]);

        $wynik = $this->powiadomienie(
            $basia->getKey(),
            now()->subMonths(4),
            type: Notification::TYPE_MODERATION,
            data: ['title' => 't', 'message' => 'm', 'decision' => 'appeal.upheld', 'appeal' => false, 'appeal_id' => $odwolanie->getKey()],
        );

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        // appealDeadline decyzji: sprzed 4 miesięcy + 6 miesięcy → wciąż w przyszłości.
        $this->assertSame(0, $raport->usunieteModeracyjne);
        $this->assertSame(1, $raport->zatrzymaneTerminemOdwolania);
        $this->assertDatabaseHas('notifications', ['id' => $wynik->getKey()]);
    }

    /**
     * Odniesienie do decyzji, którego nie da się rozwiązać (brak
     * `action_id`/`appeal_id` w `data`) — retencja NIE zgaduje i NIE
     * kasuje, zostawia wiersz do wyjaśnienia (patrz komentarz
     * `TerminOchronyOdwolawczej::dla()`).
     */
    public function test_powiadomienie_moderacyjne_bez_ustalalnej_decyzji_nie_jest_kasowane(): void
    {
        config(['kuking.notifications.retention_months' => 3]);
        $basia = $this->user('basia');

        $bezOdniesienia = $this->powiadomienie(
            $basia->getKey(),
            now()->subYears(5),
            type: Notification::TYPE_MODERATION,
            data: ['title' => 't', 'message' => 'm', 'decision' => 'hide', 'appeal' => true],
        );

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertSame(0, $raport->usunieteModeracyjne);
        $this->assertSame(1, $raport->bezPowiazanejDecyzji);
        $this->assertDatabaseHas('notifications', ['id' => $bezOdniesienia->getKey()]);
    }

    /**
     * Lista wyjątków jest ZAMKNIĘTA — ani krótsza (dziura w ochronie prawa
     * do odwołania), ani dłuższa bez zmierzonego powodu.
     *
     * DWIE POZYCJE OD ISSUE #10, nie jedna. Doszła `report.decided`:
     * odpowiedź dla ZGŁASZAJĄCEGO wraz z pouczeniem o dostępnych środkach
     * (DSA art. 16 ust. 5). Powód jest ten sam co przy `moderation.decision`
     * — trzymiesięczna retencja jest krótsza niż sześciomiesięczny termin,
     * w którym decyzja pozostaje zaskarżalna, a to powiadomienie jest
     * jedynym miejscem, gdzie zgłaszający przeczyta pouczenie drugi raz.
     *
     * `report.received` (potwierdzenie przyjęcia) NA LIŚCIE NIE JEST i to
     * też jest mierzone niżej — pełne uzasadnienie stoi przy samej stałej.
     */
    public function test_lista_wyjatkow_ma_dokladnie_dwie_pozycje(): void
    {
        $this->assertSame(
            [Notification::TYPE_MODERATION, Notification::TYPE_REPORT_DECIDED],
            Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA,
        );

        $this->assertNotContains(
            Notification::TYPE_REPORT_RECEIVED,
            Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA,
            'Potwierdzenie przyjęcia nie ma terminu odwołania, którym można je mierzyć — '
            .'na tej liście wisiałoby wiecznie, produkując ostrzeżenie przy każdym sprzątaniu.',
        );
    }

    /**
     * ODPOWIEDŹ DLA ZGŁASZAJĄCEGO (issue #10) chodzi tym samym mechanizmem
     * co decyzja dla autora — i to jest cały powód, dla którego `data` niesie
     * `action_id`.
     *
     * Sama obecność typu na liście wyjątków niczego nie dowodzi: gdyby
     * `NotifyReporterDecision` przestało zapisywać `action_id`, typ dalej
     * byłby na liście, a `TerminOchronyOdwolawczej::dla()` zwracałoby `null`
     * i wiersz wisiałby w `bezPowiazanejDecyzji` zamiast być realnie
     * chroniony terminem. Ten test mierzy ochronę, nie deklarację.
     */
    public function test_odpowiedz_dla_zglaszajacego_zyje_do_terminu_odwolania(): void
    {
        config(['kuking.notifications.retention_months' => 3]);
        $basia = $this->user('basia');
        $moderator = $this->moderator();

        // Cztery miesiące temu: po ogólnym progu (3), przed terminem
        // odwołania (co najmniej 6 miesięcy od decyzji).
        $swieza = $this->decyzja($moderator, now()->subMonths(4));
        $chroniona = $this->powiadomienie(
            $basia->getKey(),
            now()->subMonths(4),
            type: Notification::TYPE_REPORT_DECIDED,
            data: ['report_id' => (string) Str::uuid(), 'action_id' => $swieza->getKey()],
        );

        // Rok temu: termin odwołania od tej decyzji już minął, więc
        // powiadomienie wraca do bycia zwykłym kandydatem do usunięcia.
        $stara = $this->decyzja($moderator, now()->subYear());
        $przedawniona = $this->powiadomienie(
            $basia->getKey(),
            now()->subYear(),
            type: Notification::TYPE_REPORT_DECIDED,
            data: ['report_id' => (string) Str::uuid(), 'action_id' => $stara->getKey()],
        );

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertSame(1, $raport->zatrzymaneTerminemOdwolania);
        $this->assertSame(1, $raport->usunieteModeracyjne);
        $this->assertSame(0, $raport->bezPowiazanejDecyzji);
        $this->assertDatabaseHas('notifications', ['id' => $chroniona->getKey()]);
        $this->assertDatabaseMissing('notifications', ['id' => $przedawniona->getKey()]);
    }

    /**
     * POTWIERDZENIE PRZYJĘCIA stoi po DRUGIEJ stronie tej granicy i to też
     * jest decyzja, nie przeoczenie (patrz
     * `Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`). Nie niesie
     * decyzji ani pouczenia, więc nie ma terminu, którym można je mierzyć —
     * a trwałym zapisem sprawy jest wiersz w `reports` (36 miesięcy) i ekran
     * `/zgloszenia`, nie ten wpis.
     */
    public function test_potwierdzenie_przyjecia_idzie_ogolnym_okresem(): void
    {
        config(['kuking.notifications.retention_months' => 3]);
        $basia = $this->user('basia');

        $stare = $this->powiadomienie(
            $basia->getKey(),
            now()->subMonths(4),
            type: Notification::TYPE_REPORT_RECEIVED,
            data: ['report_id' => (string) Str::uuid(), 'numer_sprawy' => 'KU-2345-6789'],
        );

        $raport = (new PrzedawnionePowiadomienia)->posprzataj(3);

        $this->assertSame(1, $raport->usunieteZwykle);
        // NIE trafiło do gałęzi „bez powiązanej decyzji" — tam wisiałoby
        // wiecznie, produkując ostrzeżenie przy każdym sprzątaniu.
        $this->assertSame(0, $raport->bezPowiazanejDecyzji);
        $this->assertDatabaseMissing('notifications', ['id' => $stare->getKey()]);
    }

    public function test_komenda_retencji_respektuje_wyjatek_moderacyjny(): void
    {
        config(['kuking.notifications.retention_months' => 3]);
        $basia = $this->user('basia');
        $moderator = $this->moderator();

        $decyzja = $this->decyzja($moderator, now()->subMonths(4));
        $this->powiadomienie($basia->getKey(), now()->subMonths(4), type: Notification::TYPE_COMMENT);
        $this->powiadomienie(
            $basia->getKey(),
            now()->subMonths(4),
            type: Notification::TYPE_MODERATION,
            data: ['title' => 't', 'message' => 'm', 'decision' => 'hide', 'appeal' => true, 'action_id' => $decyzja->getKey()],
        );

        $this->artisan('kuking:sprzataj-powiadomienia')->assertSuccessful();

        // Zwykłe zniknęło, moderacyjne — jeszcze w oknie odwołania — zostało.
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', ['type' => Notification::TYPE_MODERATION]);
    }
}
