<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\PodstawaDecyzji;
use App\Domain\Moderation\UzasadnienieDecyzji;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\Czas;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
            // TERMIN JEST OBOWIĄZKOWY PRZY „ZAWIEŚ KONTO".
            // Brak wyboru znaczył kiedyś „bezterminowo", czyli najsurowszą
            // karę przez zaniechanie. Formularz ma dziś „Bez zawieszenia"
            // jako pozycję domyślną, a bezterminowość wymaga jawnego
            // kliknięcia (`App\Domain\Moderation\DlugoscZawieszenia`).
            // Przy decyzjach innych niż zawieszenie ta wartość jest
            // ignorowana.
            'suspend_days' => '7',
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
     * ZDANIE „NIKT TEGO NIE ZGŁOSIŁ" NIE MA PRAWA TRAFIĆ DO PRZYWRÓCENIA.
     *
     * `RestoreContent` zapisuje `report_id` jako NULL i musi tak robić:
     * indeks częściowy `moderation_actions_one_per_report` (migracja
     * 2026_09_06_190000) dopuszcza JEDNĄ decyzję na zgłoszenie, a
     * przywrócenie jest drugą. Gdyby uzasadnienie powstawało dla `unhide`,
     * autor przeczytałby „nikt tego nie zgłosił" o sprawie, która od
     * zgłoszenia się zaczęła.
     *
     * Dlatego `UzasadnienieDecyzji::zdania()` nie tworzy uzasadnienia dla
     * decyzji nieodwoływalnych — a ten test pilnuje jednego i drugiego.
     */
    public function test_przywrocenie_nie_dostaje_uzasadnienia(): void
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

        // Tak wygląda ograniczenie bazy: druga decyzja dla tego samego
        // zgłoszenia nie może nieść jego identyfikatora.
        $this->assertNull($przywrocenie->report_id);

        // WŁAŚCIWY POMIAR: skoro `report_id` jest puste, uzasadnienia dla tej
        // decyzji nie wolno budować wcale.
        $this->assertSame([], UzasadnienieDecyzji::zdania($przywrocenie));

        $tresc = $this->actingAs($basia)->get(route('notifications.index'))->assertOk()->getContent();

        // ASERCJA NEGATYWNA: nigdzie ani słowa o tym, że sprawy nikt nie
        // zgłosił — a to właśnie to zdanie byłoby tu nieprawdą.
        $this->assertStringNotContainsString('Nikt tego nie zgłosił', $tresc);

        // Na stronie są DWA powiadomienia: ukrycie i przywrócenie. Akapit
        // o podstawie ma być dokładnie jeden — ten od ukrycia. Zliczamy,
        // bo `assertStringNotContainsString` nie odróżni jednego od dwóch.
        $this->assertSame(
            1,
            substr_count($tresc, 'Podstawą tej decyzji'),
            'Przywrócenie treści dostało własny akapit z podstawą decyzji.',
        );

        // KONTROLA: sama wiadomość o przywróceniu jednak dociera.
        $this->assertStringContainsString('Dziękujemy za poprawienie komentarza.', $tresc);

        // KONTROLA DRUGIEJ STRONY: pierwotna decyzja `hide` MA `report_id`,
        // więc zdanie „sprawa zaczęła się od zgłoszenia" jest przy niej
        // prawdziwe. Bez tego test przeszedłby też wtedy, gdyby `report_id`
        // przestało być zapisywane w ogóle.
        $ukrycie = ModerationAction::query()
            ->where('action', ModerationAction::ACTION_HIDE)
            ->firstOrFail();

        $this->assertSame($report->getKey(), $ukrycie->report_id);
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
            // TRZECIE MIEJSCE, DOPISANE ŚWIADOMIE (D-052).
            //
            // `SygnalyController::odrzucGrupe()` zamyka hurtem oznaczenia
            // postawione przez automat decyzją `no_action` — czyli „automat
            // się pomylił, treść zostaje". Spełnia oba warunki, o które
            // pyta ten test: stoi za `authorize('moderate', User::class)`
            // i zapisuje `moderator_id` konkretnego zalogowanego człowieka
            // (`$request->user()`).
            //
            // Zdanie „decyzję podjął człowiek" zostaje więc prawdą. Automat
            // w D-052 tylko STAWIA POZYCJĘ W KOLEJCE (`OznaczDoPrzegladu`,
            // tabela `reports`) i tamta akcja świadomie NIE tworzy wiersza
            // w `moderation_actions` — właśnie po to, żeby ta lista nie
            // musiała rosnąć o miejsce bez człowieka.
            'app/Http/Controllers/Admin/SygnalyController.php',
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
        $this->expectException(QueryException::class);

        ModerationAction::create([
            'moderator_id' => null,
            'report_id' => null,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'obrazanie-nekanie',
        ]);
    }

    /**
     * DRUGA POŁOWA TEGO SAMEGO ZDANIA: „automat, który sam ukrywa, usuwa albo
     * blokuje".
     *
     * Test wyżej pilnuje LOGU decyzji. Ten pilnuje SKUTKÓW: kara mogłaby
     * przecież zadziałać bez wiersza w `moderation_actions` — wystarczyłoby
     * zadanie w harmonogramie wołające `$user->ban()` albo ustawiające status
     * treści na ukryty. Wtedy wiersza w logu nie ma, test wyżej jest zielony,
     * a zdanie w powiadomieniu — nieprawdziwe.
     *
     * Dlatego kara i ukrycie wolno wywołać z JEDNEGO miejsca: z kontrolera
     * moderacji, za `authorize('moderate', User::class)`.
     */
    public function test_nie_ma_automatu_ktory_sam_ukrywa_albo_blokuje(): void
    {
        $oczekiwane = [
            // Kara na koncie — wyłącznie z panelu moderacji.
            '->ban()' => ['app/Http/Controllers/Admin/ModerationController.php'],
            '->suspend(' => ['app/Http/Controllers/Admin/ModerationController.php'],
            // Ustawienie statusu „ukryte" — panel plus słownik statusów,
            // który tę wartość tylko definiuje i czyta. Szukamy `UKRYTY[`
            // bez nazwy klasy, bo w samym słowniku odwołanie brzmi `self::`.
            'UKRYTY[' => [
                'app/Domain/Moderation/ModeratedContent.php',
                'app/Http/Controllers/Admin/ModerationController.php',
            ],
        ];

        foreach ($oczekiwane as $wywolanie => $dozwolone) {
            $znalezione = [];

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));

            foreach ($iterator as $plik) {
                if (! $plik->isFile() || $plik->getExtension() !== 'php') {
                    continue;
                }

                if (str_contains((string) file_get_contents($plik->getPathname()), $wywolanie)) {
                    $znalezione[] = str_replace(base_path().'/', '', $plik->getPathname());
                }
            }

            sort($znalezione);
            sort($dozwolone);

            $this->assertSame(
                $dozwolone,
                $znalezione,
                "Wywołanie `{$wywolanie}` pojawiło się w nowym miejscu. Jeśli to droga, "
                .'która działa bez człowieka, powiadomienie o decyzji zaczyna kłamać.',
            );
        }
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
            Czas::data($decyzja->appealDeadline(), 'j F Y'),
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
     * PO UPŁYWIE SZEŚCIU MIESIĘCY pouczenie nie może zapraszać na stronę,
     * która odmówi. `ModerationAction::isAppealable()` przestaje wtedy
     * przepuszczać odwołanie, a powiadomienie zostaje w serwisie na lata.
     */
    public function test_po_terminie_nie_zapraszamy_juz_do_odwolania(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user();
        $post = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $this->decyzja($moderator, $this->zgloszenie('post', $post->getKey()), [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'obrazanie-nekanie',
            'user_message' => 'Wpis obraża inną osobę.',
        ]);

        $decyzja = ModerationAction::query()->where('action', ModerationAction::ACTION_HIDE)->firstOrFail();
        // Wiersz cofnięty w czasie, nie `travel()`: chodzi o decyzję STARĄ,
        // a nie o serwis przeniesiony w przyszłość razem z sesją i limitami.
        $decyzja->forceFill(['created_at' => now()->subMonths(7)])->save();

        $zdania = implode(' ', UzasadnienieDecyzji::zdania($decyzja->refresh()));

        // WŁAŚCIWY POMIAR.
        $this->assertStringContainsString('ten termin już minął', $zdania);
        $this->assertStringNotContainsString('możesz się odwołać', $zdania);

        // KONTROLA: pozostałe dwie drogi zostają — one się nie przedawniają
        // razem z naszym formularzem.
        $this->assertStringContainsString('pozasądowego organu', $zdania);
        $this->assertStringContainsString('sądu', $zdania);
        // KONTROLA: podstawa decyzji nadal jest podana.
        $this->assertStringContainsString('punkt 4 zasad Kuking', $zdania);
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

        // Bez tego POST /login odbija się od grupy `guest` — w teście wciąż
        // trwa sesja moderatora, który właśnie wydał decyzję.
        Auth::logout();

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

        // NEGATYWNA: jedno pouczenie, nie dwa. Zdanie „jeśli uważasz, że to
        // pomyłka" stało tu od zawsze, a uzasadnienie niesie je teraz samo —
        // powtórzone dwa razy w jednym komunikacie wygląda jak usterka.
        $this->assertSame(
            1,
            substr_count(mb_strtolower($blad), 'jeśli uważasz, że to pomyłka'),
            'Komunikat powtarza to samo pouczenie dwa razy.',
        );
    }
}
