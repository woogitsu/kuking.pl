<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\PodstawaDecyzji;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uzasadnienie decyzji dla AUTORA treści musi zawierać to, czego wymaga
 * art. 17 ust. 3 DSA (pomiar: `docs/decyzje/DSA_POMIAR.md` §2).
 *
 * CO BYŁO ZMIERZONE JAKO BRAK
 *  1. Podstawa decyzji — ani punktu zasad, ani podstawy prawnej.
 *     `moderation_actions.reason_code` był swobodnym tekstem, zostawał
 *     wewnętrzny i nie było ODWZOROWANIA powodu na punkt zasad.
 *  2. Czy decyzja wynikła ze ZGŁOSZENIA, czy z własnego przeglądu.
 *  3. Zdanie o braku automatu (lit. e) — a automatu naprawdę nie ma.
 *  4. Autor dostawał MNIEJ pouczenia niż zgłaszający: samo „możesz się
 *     odwołać", bez terminu, bez organu pozasądowego i bez sądu.
 *
 * DLACZEGO TESTY IDĄ PO HTTP
 * Wiersz w `notifications` niczego nie dowodzi — zdanie, którego widok nie
 * renderuje, jest tak samo niewidoczne jak jego brak. Ten sam powód mają
 * testy ekranu logowania: dla konta ZABLOKOWANEGO to jedyny kanał, w którym
 * człowiek cokolwiek od nas przeczyta.
 */
class UzasadnienieDecyzjiTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(string $typ, ?string $celId, string $powod = 'harassment'): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => $typ,
            'target_id' => $celId,
            'reason' => $powod,
            'status' => Report::STATUS_OPEN,
        ]);
    }

    /** @param  array<string, string>  $dane */
    private function decyzja(User $moderator, Report $report, array $dane): void
    {
        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), $dane)
            ->assertRedirect(route('admin.reports'));
    }

    /** Wpis Basi, decyzja moderatora, treść strony powiadomień Basi. */
    private function powiadomienieAutora(string $akcja, string $podstawa, ?string $wiadomosc = 'Komentarz obraża inną osobę.'): string
    {
        $moderator = $this->moderator();
        $basia = $this->user();
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), array_filter([
            'action' => $akcja,
            'reason_code' => $podstawa,
            'user_message' => $wiadomosc,
        ], fn ($wartosc): bool => $wartosc !== null));

        return $this->actingAs($basia)->get(route('notifications.index'))->assertOk()->getContent();
    }

    // -----------------------------------------------------------------
    // BRAK 1 — podstawa decyzji (art. 17 ust. 3 lit. d i e)
    // -----------------------------------------------------------------

    /** WŁAŚCIWY POMIAR: powiadomienie podaje punkt zasad, nie sam kod. */
    public function test_powiadomienie_podaje_punkt_zasad(): void
    {
        $tresc = $this->powiadomienieAutora(ModerationAction::ACTION_HIDE, 'obrazanie-nekanie');

        $this->assertStringContainsString('punkt 4 zasad Kuking', $tresc);
        $this->assertStringContainsString('Szanuj innych', $tresc);
    }

    /**
     * KONTROLA: wewnętrzny kod powodu NIE wychodzi do człowieka. Podstawa ma
     * być punktem zasad, a nie żargonem z panelu.
     */
    public function test_powiadomienie_nie_pokazuje_wewnetrznego_kodu(): void
    {
        $tresc = $this->powiadomienieAutora(ModerationAction::ACTION_HIDE, 'obrazanie-nekanie');

        $this->assertStringNotContainsString('obrazanie-nekanie', $tresc);
    }

    /**
     * ASERCJA NEGATYWNA: przy powodzie, którego NIE MA w zamkniętej liście,
     * nie wolno zmyślić numeru punktu. Zostaje prawdziwe, ogólne zdanie.
     */
    public function test_nieznany_powod_nie_wymysla_numeru_punktu(): void
    {
        $tresc = $this->powiadomienieAutora(ModerationAction::ACTION_HIDE, 'jakis_stary_kod_z_2025');

        $this->assertStringNotContainsString('punkt 1 zasad', $tresc);
        $this->assertStringNotContainsString('punkt 4 zasad', $tresc);
        $this->assertStringNotContainsString('jakis_stary_kod_z_2025', $tresc);
        // KONTROLA: podstawa jednak jest podana — nie zniknął cały akapit.
        $this->assertStringContainsString('zasady Kuking', $tresc);
    }

    /**
     * Zamknięta lista jest ODWZOROWANIEM na realne punkty `zasady.md`,
     * a nie zbiorem wymyślonych numerów. Plik ma 12 punktów.
     */
    public function test_kazdy_punkt_z_listy_istnieje_w_zasadach(): void
    {
        $zasady = file_get_contents(resource_path('legal/zasady.md'));
        $this->assertIsString($zasady);

        $sprawdzone = 0;

        foreach (PodstawaDecyzji::PODSTAWY as $kod => $opis) {
            $punkt = $opis['punkt'];

            if ($punkt === null) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^'.$punkt.'\. \*\*'.preg_quote($opis['zasada'], '/').'/m',
                $zasady,
                "Podstawa `{$kod}` wskazuje punkt {$punkt} o brzmieniu „{$opis['zasada']}”, a w `zasady.md` tam tego nie ma.",
            );
            $sprawdzone++;
        }

        // ASERCJA KONTROLNA: gdyby lista była pusta albo same `null`,
        // pętla wyżej przeszłaby bez sprawdzenia czegokolwiek.
        $this->assertGreaterThanOrEqual(9, $sprawdzone);
    }

    /**
     * Podstawa PRAWNA (lit. d) jest czymś innym niż punkt zasad (lit. e)
     * i nie wolno jej podać bez wyjaśnienia — dlatego przy tej podstawie
     * wiadomość do człowieka jest OBOWIĄZKOWA.
     */
    public function test_podstawa_prawna_wymaga_wiadomosci_do_czlowieka(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user();
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $this->zgloszenie('post', $post->getKey())), [
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => PodstawaDecyzji::NIEZGODNE_Z_PRAWEM,
            ])
            ->assertSessionHasErrors('user_message');

        // KONTROLA: z wiadomością ta sama decyzja przechodzi.
        $tresc = $this->powiadomienieAutora(
            ModerationAction::ACTION_REMOVE,
            PodstawaDecyzji::NIEZGODNE_Z_PRAWEM,
            'Przepis jest przepisany słowo w słowo z książki „Kuchnia polska”.',
        );

        $this->assertStringContainsString('niezgodn', $tresc);
        $this->assertStringNotContainsString('punkt 4 zasad', $tresc);
    }

    // -----------------------------------------------------------------
    // BRAK 2 — czy decyzja wynikła ze zgłoszenia (art. 17 ust. 3 lit. b)
    // -----------------------------------------------------------------

    public function test_powiadomienie_mowi_ze_sprawa_zaczela_sie_od_zgloszenia(): void
    {
        $tresc = $this->powiadomienieAutora(ModerationAction::ACTION_WARN, 'obrazanie-nekanie');

        $this->assertStringContainsString('od zgłoszenia', $tresc);
    }

    /**
     * ASERCJA NEGATYWNA i jednocześnie granica, której nie wolno przekroczyć:
     * zgłaszający zostaje ANONIMOWY dla autora treści.
     */
    public function test_powiadomienie_nie_zdradza_kto_zglosil(): void
    {
        $moderator = $this->moderator();
        $zglaszajacy = $this->user('donosiciel', ['display_name' => 'Halina Zgłaszająca']);
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $report = Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->decyzja($moderator, $report, [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'obrazanie-nekanie',
            'user_message' => 'Wpis obraża inną osobę.',
        ]);

        $tresc = $this->actingAs($basia)->get(route('notifications.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Halina Zgłaszająca', $tresc);
        $this->assertStringNotContainsString('donosiciel', $tresc);
        $this->assertStringNotContainsString((string) $zglaszajacy->email, $tresc);
        // KONTROLA: sam fakt zgłoszenia jednak jest napisany.
        $this->assertStringContainsString('od zgłoszenia', $tresc);
    }

    /**
     * Przywrócenie treści idzie z NASZEJ inicjatywy i tak ma być opisane —
     * ale tylko wtedy, gdy naprawdę nie stoi za nim zgłoszenie. Cofnięcie
     * ukrycia przy zgłoszeniu zapisuje `report_id`, więc zdanie „nikt tego
     * nie zgłosił" nie ma prawa się tam pojawić.
     */
    public function test_przywrocenie_przy_zgloszeniu_pamieta_zgloszenie(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $comment = Comment::factory()->create(['author_id' => $basia->getKey()]);
        $report = $this->zgloszenie('comment', $comment->getKey());

        $this->decyzja($moderator, $report, [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'obrazanie-nekanie',
            'user_message' => 'Komentarz obraża inną osobę.',
        ]);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $report), [
                'reason_code' => 'autor-poprawil',
                'user_message' => 'Dziękujemy za poprawienie komentarza.',
            ])
            ->assertRedirect(route('admin.reports'));

        $przywrocenie = ModerationAction::query()
            ->where('action', ModerationAction::ACTION_UNHIDE)
            ->firstOrFail();

        $this->assertSame($report->getKey(), $przywrocenie->report_id);

        // KONTROLA: przywrócenie NIE dostaje akapitu o podstawie i odwołaniu.
        // Od dobrej wiadomości nikt się nie odwołuje.
        $tresc = $this->actingAs($basia)->get(route('notifications.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Dziękujemy za poprawienie komentarza.', $tresc);
        $this->assertStringNotContainsString('Podstawą tej decyzji', $tresc);
    }

    // -----------------------------------------------------------------
    // BRAK 3 — brak automatu (art. 17 ust. 3 lit. e)
    // -----------------------------------------------------------------

    public function test_powiadomienie_mowi_ze_decyzje_podjal_czlowiek(): void
    {
        $tresc = $this->powiadomienieAutora(ModerationAction::ACTION_SUSPEND, 'obrazanie-nekanie');

        $this->assertStringContainsString('Decyzję podjął człowiek', $tresc);
        $this->assertStringContainsString('automat', $tresc);
    }

    /**
     * ZDANIE „DECYZJĘ PODJĄŁ CZŁOWIEK" MUSI BYĆ PRAWDĄ, A NIE OBIETNICĄ.
     *
     * Dowodem jest to, że wiersza w `moderation_actions` NIE DA SIĘ utworzyć
     * bez moderatora: kolumna `moderator_id` jest NOT NULL z kluczem obcym do
     * `users`, a w całym `app/` są tylko dwa miejsca, które ten wiersz tworzą
     * — oba przyjmują konkretnego człowieka i oba stoją za
     * `authorize('moderate')`.
     *
     * Ten test pilnuje jednego i drugiego. Dopisanie automatycznego
     * ukrywania „po trzech zgłoszeniach" psuje go natychmiast — i o to chodzi,
     * bo od tej chwili zdanie w powiadomieniu byłoby nieprawdą.
     */
    public function test_nie_ma_w_kodzie_drogi_do_decyzji_bez_czlowieka(): void
    {
        $dozwolone = [
            'app/Http/Controllers/Admin/ModerationController.php',
            'app/Domain/Moderation/Actions/RestoreContent.php',
        ];

        $znalezione = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

        foreach ($iterator as $plik) {
            if (! $plik->isFile() || $plik->getExtension() !== 'php') {
                continue;
            }

            $kod = (string) file_get_contents($plik->getPathname());

            if (str_contains($kod, 'ModerationAction::create(') || str_contains($kod, 'new ModerationAction(')) {
                $znalezione[] = str_replace(base_path().'/', '', $plik->getPathname());
            }
        }

        sort($znalezione);
        sort($dozwolone);

        $this->assertSame(
            $dozwolone,
            $znalezione,
            'Powstało nowe miejsce tworzące decyzję moderacyjną. Sprawdź, czy wymaga człowieka '
            .'— powiadomienie mówi autorowi, że decyzji nie podejmuje automat.',
        );

        // ASERCJA POZYTYWNA na poziomie bazy: bez moderatora wiersz nie wejdzie.
        $this->expectException(\Illuminate\Database\QueryException::class);

        ModerationAction::create([
            'moderator_id' => null,
            'report_id' => null,
            'target_type' => 'post',
            'target_id' => (string) \Illuminate\Support\Str::uuid7(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'obrazanie-nekanie',
        ]);
    }

    // -----------------------------------------------------------------
    // BRAK 4 — autor dostaje pełne pouczenie (art. 17 ust. 3 lit. f)
    // -----------------------------------------------------------------

    public function test_autor_dostaje_termin_organ_pozasadowy_i_sad(): void
    {
        $tresc = $this->powiadomienieAutora(ModerationAction::ACTION_HIDE, 'obrazanie-nekanie');

        $decyzja = ModerationAction::query()->where('action', ModerationAction::ACTION_HIDE)->firstOrFail();

        $this->assertStringContainsString('możesz się odwołać', $tresc);
        $this->assertStringContainsString(
            \App\Support\Czas::data($decyzja->appealDeadline(), 'j F Y'),
            $tresc,
            'Powiadomienie nie podaje terminu na odwołanie.',
        );
        $this->assertStringContainsString('pozasądowego organu', $tresc);
        $this->assertStringContainsString('sądu', $tresc);
    }

    /** ASERCJA NEGATYWNA: termin to sześć miesięcy, nigdy 14 dni. */
    public function test_nigdzie_nie_ma_czternastu_dni(): void
    {
        $tresc = $this->powiadomienieAutora(ModerationAction::ACTION_BAN, 'obrazanie-nekanie');

        $this->assertStringNotContainsString('14 dni', $tresc);
        $this->assertStringNotContainsString('czternastu dni', $tresc);
    }

    /**
     * ASERCJA NEGATYWNA: wycofane dziś zdanie o „innym człowieku" nie wraca
     * ani w tej, ani w żadnej innej postaci. Serwis prowadzi jedna osoba.
     */
    public function test_pouczenie_nie_obiecuje_niezaleznosci(): void
    {
        foreach ([ModerationAction::ACTION_HIDE, ModerationAction::ACTION_WARN, ModerationAction::ACTION_BAN] as $akcja) {
            $tresc = $this->powiadomienieAutora($akcja, 'obrazanie-nekanie');

            $this->assertStringNotContainsString('wcześniej nie prowadził', $tresc);
            $this->assertStringNotContainsString('inny moderator', $tresc);
            $this->assertStringNotContainsString('niezależn', $tresc);
        }
    }

    /**
     * ZABLOKOWANE KONTO ma jeden kanał: ekran logowania. Pouczenie musi być
     * tam, inaczej dla tej osoby art. 17 nie istnieje.
     */
    public function test_zablokowana_osoba_czyta_pouczenie_na_ekranie_logowania(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia', ['password' => bcrypt('tajne-haslo-123')]);
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_BAN,
            'reason_code' => 'obrazanie-nekanie',
            'user_message' => 'Nękanie innej osoby w komentarzach.',
        ]);

        $this->post('/login', [
            'login' => $basia->email,
            'password' => 'tajne-haslo-123',
        ])->assertSessionHasErrors('login');

        $blad = (string) session('errors')?->first('login');

        $this->assertStringContainsString('Nękanie innej osoby w komentarzach.', $blad);
        $this->assertStringContainsString('punkt 4 zasad Kuking', $blad);
        $this->assertStringContainsString('pozasądowego organu', $blad);
        $this->assertStringContainsString(route('appeals.guest'), $blad);
        // KONTROLA: nadal mówimy, CO SIĘ STAŁO.
        $this->assertStringContainsString('zablokowane', $blad);
        // NEGATYWNA: żadnych danych zgłaszającego ani nazwiska moderatora.
        $this->assertStringNotContainsString((string) $moderator->email, $blad);
    }
}
