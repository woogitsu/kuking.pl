<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\ContactMessage;
use App\Models\ContactMessageReply;
use App\Models\CookedEvent;
use App\Models\DailyPick;
use App\Models\DataExport;
use App\Models\HeroPick;
use App\Models\LoginLinkToken;
use App\Models\MailFailure;
use App\Models\Media;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\PendingEmailChange;
use App\Models\Post;
use App\Models\ProductSignal;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\RegistrationInvite;
use App\Models\Report;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Masowe przypisanie kolumn wrażliwych — reguła AGENTS.md §7 rozciągnięta
 * z jednego modelu na WSZYSTKIE i pilnowana automatem.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  CO TEN TEST MIERZY I DLACZEGO AKURAT TO
 * ═══════════════════════════════════════════════════════════════════════
 *
 * `SecurityTest` i `AdresEmailPozaMasowymPrzypisaniemTest` pilnują trzech
 * kolumn jednego modelu (`users.role`, `users.status`, `users.email`).
 * To była cała ochrona: reszta — trzydzieści trzy modele — opierała się na
 * tym, że nikt się nie pomyli przy dopisywaniu pola do `$fillable`.
 *
 * Ten test nie pyta o zawartość `$fillable`, tylko o SKUTEK: bierze model,
 * podaje mu kolumnę wrażliwą masowym przypisaniem i sprawdza, czy atrybut
 * się ustawił. To rozróżnienie ma znaczenie — `assertNotContains(...,
 * getFillable())` przeszłoby także po `Model::unguard()`, po `$guarded = []`
 * i po własnym `fill()`. Ten sam wybór opisuje
 * `AdresEmailPozaMasowymPrzypisaniemTest`.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  SKĄD SIĘ BIERZE LISTA KOLUMN WRAŻLIWYCH
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Nie z palca. Lista powstaje przy każdym przebiegu z DWÓCH źródeł:
 * rzeczywistych kolumn tabeli (`Schema::getColumnListing`) i rzeczywistych
 * powiązań modelu (`BelongsTo` wskazujące na `User`). Dzięki temu nowa
 * kolumna `status` w nowej tabeli wchodzi pod ochronę sama, bez dopisywania
 * czegokolwiek tutaj.
 *
 * Cztery kategorie, każda z własnym powodem:
 *
 * ── 1. STAN KONTA I TREŚCI: `status`, `role`, `previous_status`.
 *    To jest reguła z AGENTS.md §7 wprost. Stan konta (aktywne, zawieszone,
 *    zbanowane, do skasowania) i stan treści (szkic, opublikowane, ukryte
 *    przez moderatora) są ROZSTRZYGNIĘCIEM, nie danymi z formularza.
 *    Gdyby dało się je przysłać, ukryty przez moderatora wpis wracałby na
 *    stronę jednym dodatkowym polem w żądaniu, a zawieszone konto
 *    odwieszało się samo.
 *
 * ── 2. KLUCZE WŁAŚCICIELA: kolumna, która jest kluczem obcym powiązania
 *    `BelongsTo(User::class)` — `user_id`, `author_id`, `owner_id`,
 *    `actor_id`, `reporter_id`, `curator_id`, `moderator_id`,
 *    `subject_user_id`, `editor_id`, `blocker_id`, `blocked_id`.
 *    Wyliczane z relacji, a NIE z końcówki `_id`: `recipe_id`, `post_id`,
 *    `tag_id` i `unit_id` też kończą się na `_id`, ale nie mówią, CZYJA
 *    jest treść — mówią, CZEGO dotyczy. Klucz właściciela przysłany
 *    z zewnątrz to podpisanie się cudzym nazwiskiem: wpis, komentarz albo
 *    zgłoszenie ląduje na koncie kogoś, kto go nie napisał.
 *
 * ── 3. ROZSTRZYGNIĘCIA MODERACJI I WIDOCZNOŚCI: `visibility`,
 *    `merged_into_tag_id`, `hide_as_memory` oraz pary „kto i kiedy
 *    rozstrzygnął" (`resolved_by`/`resolved_at`, `decided_by`/`decided_at`,
 *    `handled_by`/`handled_at`). Widoczność decyduje, kto zobaczy treść —
 *    czyli jest granicą prywatności, nie ozdobą. Znacznik rozstrzygnięcia
 *    przysłany z formularza sprawia, że sprawa wpada do bazy od razu jako
 *    załatwiona i nikt jej nigdy nie zobaczy w kolejce (dokładnie ten powód
 *    trzyma `status` poza `$fillable` w `ContactMessage`).
 *
 * ── 4. POŚWIADCZENIA: kolumny pasujące do `password`, `token`, `secret`
 *    oraz cała rodzina `two_factor_*`. Kto zapisze taką kolumnę, ten wchodzi
 *    na konto — bez znajomości hasła i bez jednego listu. To jest kategoria
 *    NIETYKALNA (patrz niżej): nie da się jej odblokować wpisem w rejestrze.
 *
 *    Świadomie NIE są tu `ip_hash` (dziennik audytu) ani `checksum_sha256`
 *    (odcisk pliku). Obie są hashami, ale żadna nie jest poświadczeniem:
 *    znajomość skrótu adresu IP ani sumy kontrolnej JPEG-a nie otwiera
 *    niczyjego konta. Kategoria mówi „poświadczenie", nie „ciąg szesnastkowy".
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  DWA POZIOMY: NIETYKALNE I REJESTR
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Zmierzone na gałęzi `main`: kolumn wrażliwych w `$fillable` jest
 * kilkadziesiąt i większość z nich stoi tam ŚWIADOMIE — `Post::$fillable`
 * ma `author_id`, bo `PublishPost` składa wiersz jednym `create()`
 * z kluczami wpisanymi na sztywno, a nie dlatego, że autora wolno przysłać
 * z przeglądarki. Test, który kazałby je wszystkie wyrzucić, byłby
 * przepisaniem połowy warstwy domenowej pod hasłem bezpieczeństwa i nikt by
 * go nie utrzymał.
 *
 * Dlatego poziomy są dwa:
 *
 *  - `NIETYKALNE` — poświadczenia w każdej tabeli oraz `role` i `status`
 *    na `users`. Zero wyjątków, rejestr ich nie przyjmuje.
 *
 *  - `REJESTR` — pozostałe kolumny wrażliwe, które w `$fillable` stoją dziś
 *    świadomie. Każdy wpis ma powód. Rejestr jest DOKŁADNY w obie strony:
 *    kolumna wrażliwa, której w nim nie ma, oblewa test (nikt nie dopisze
 *    nowej po cichu), a wpis, który nic nie opisuje — bo kolumna wypadła
 *    z `$fillable` albo z tabeli — oblewa test jako martwy. To jest zapadka:
 *    nie cofa dzisiejszego stanu, ale nie pozwala mu się pogorszyć bez
 *    napisania, dlaczego.
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  PUŁAPKA 2 Z `docs/PULAPKI_TESTOW.md`
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Test skanujący katalog przechodzi także wtedy, gdy nie znajdzie ani
 * jednego pliku — zero trafień jest dla niego sukcesem. Przeniesienie
 * `app/Models`, zmiana `glob()` albo pusty schemat bazy wyłączyłyby ten
 * plik bez jednego czerwonego przebiegu. Stąd `test_skan_naprawde_czyta_modele`
 * i progi minimalne w środku pętli.
 */
class WrazliweKolumnyPozaMasowymPrzypisaniemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Kategoria 4 — poświadczenia. Wyrażenie, nie lista nazw, żeby nowa
     * kolumna `..._token` weszła pod ochronę sama.
     */
    private const WZORZEC_POSWIADCZENIA = '/(^|_)(password|token|secret)(_|$)|^two_factor_/';

    /**
     * Wartość podstawiana przy próbie masowego przypisania — data tekstem,
     * patrz `daloSieUstawic()`.
     */
    private const WARTOSC_NAPASTNIKA = '2026-01-01 00:00:00';

    /** Kategoria 1 — stan konta i treści. */
    private const STAN = ['status', 'role', 'previous_status'];

    /** Kategoria 3 — rozstrzygnięcia moderacji i widoczności. */
    private const ROZSTRZYGNIECIA = [
        'visibility',
        'merged_into_tag_id',
        'hide_as_memory',
        'resolved_by', 'resolved_at',
        'decided_by', 'decided_at',
        'handled_by', 'handled_at',
    ];

    /**
     * Kolumny, których żaden rejestr nie odblokuje.
     *
     * `users.role` i `users.status` — AGENTS.md §7 wprost.
     * Poświadczenia w dowolnej tabeli — patrz kategoria 4 w komentarzu klasy.
     *
     * @return bool czy kolumna jest nietykalna
     */
    private function nietykalna(string $tabela, string $kolumna): bool
    {
        if ($tabela === 'users' && in_array($kolumna, ['role', 'status'], true)) {
            return true;
        }

        return preg_match(self::WZORZEC_POSWIADCZENIA, $kolumna) === 1;
    }

    /**
     * Kolumny wrażliwe, które w `$fillable` stoją ŚWIADOMIE — z powodem.
     *
     * Wspólny mianownik wszystkich wpisów: wiersz składa akcja domenowa
     * albo kontroler JEDNYM wywołaniem z kluczami wpisanymi na sztywno
     * (`create(['author_id' => $author->getKey(), ...])`), więc wartość nie
     * pochodzi z żądania, choć technicznie mogłaby. Zmierzone osobno
     * i niezależnie od tej listy: `PodniesienieRoliZadaniemHttpTest` wysyła
     * te kolumny prawdziwym żądaniem na siedemnaście tras i żadna nie
     * przechodzi.
     *
     * @var array<class-string<Model>, array<string, string>>
     */
    private const REJESTR = [
        Appeal::class => [
            'user_id' => 'FileAppeal składa wiersz jednym create() — autor odwołania to zalogowana osoba, nie pole formularza.',
            'status' => 'FileAppeal/FileReporterAppeal wpisują STATUS_OPEN na sztywno; zmianę robi panel moderacji.',
            'decided_by' => 'Wypełnia wyłącznie ResolveAppeal po decyzji moderatora, nigdy formularz odwołania.',
            'decided_at' => 'Znacznik decyzji — ustawiany razem z decided_by, tą samą drogą.',
        ],
        AuditLogEntry::class => [
            'actor_id' => 'Dziennik audytu zapisuje wyłącznie AuditLogEntry::record(), z obiektu User podanego w kodzie.',
        ],
        Block::class => [
            'blocker_id' => 'BlockUser::handle() bierze blokującego z sesji, nie z żądania.',
            'blocked_id' => 'Ta sama akcja; blokowany przychodzi jako model rozwiązany przez trasę.',
        ],
        Collection::class => [
            'owner_id' => 'CollectionController zakłada zeszyt przez $user->collections()->create() — relacja sama ustawia właściciela.',
            'visibility' => 'To JEST wybór człowieka na ekranie („kto ma widzieć ten zeszyt"), walidowany regułą in:public,private.',
        ],
        Comment::class => [
            'author_id' => 'PublishComment składa wiersz jednym create() z autorem z sesji.',
            'status' => 'Tą samą drogą wpisywany na sztywno jako STATUS_PUBLISHED; ukrycie robi panel moderacji.',
        ],
        ContactMessage::class => [
            'user_id' => 'Kontroler wpisuje zalogowaną osobę albo null dla gościa; status i handled_by są POZA $fillable celowo.',
        ],
        ContactMessageReply::class => [
            'author_id' => 'WyslijOdpowiedz bierze moderatora z sesji; status jest POZA $fillable celowo.',
        ],
        CookedEvent::class => [
            'user_id' => 'RecordCookedEvent składa wiersz jednym create() z osobą z sesji.',
        ],
        DailyPick::class => [
            'curator_id' => 'Tablica dnia powstaje wyłącznie w panelu moderacji; kurator to zalogowany moderator.',
        ],
        DataExport::class => [
            'user_id' => 'DataSettingsController tworzy eksport dla $request->user(), nie dla podanego identyfikatora.',
            'status' => 'Stan eksportu przestawia zadanie w tle, wpisując wartość na sztywno.',
        ],
        HeroPick::class => [
            'curator_id' => 'Kolaż powitalny układa wyłącznie panel moderacji.',
        ],
        Media::class => [
            'owner_id' => 'StoreUploadedImage składa wiersz jednym create() z właścicielem z sesji.',
            'status' => 'Stan przetwarzania zdjęcia ustawia wyłącznie ProcessUploadedImage; widoki pokazują tylko `ready` (AGENTS.md §7).',
        ],
        ModerationAction::class => [
            'moderator_id' => 'Decyzję zapisuje panel moderacji, moderator pochodzi z sesji.',
            'subject_user_id' => 'Osoba, której decyzja dotyczy — wyliczana z treści zgłoszenia, nie przysyłana.',
            'previous_status' => 'Stan SPRZED decyzji, odczytany z bazy tuż przed zmianą — to jest dowód do odwołania.',
        ],
        Notification::class => [
            'user_id' => 'Adresat powiadomienia wynika z treści zdarzenia; powiadomienia nie powstają z żądań HTTP.',
            'actor_id' => 'Sprawca zdarzenia, brany z tego samego miejsca co adresat.',
        ],
        Post::class => [
            'author_id' => 'PublishPost składa wiersz jednym create() z autorem z sesji.',
            'status' => 'Wpisywany na sztywno przez PublishPost; ukrycie i przywrócenie robi wyłącznie panel moderacji (forceFill).',
            'visibility' => 'To JEST wybór człowieka na ekranie („kto ma widzieć ten wpis"), walidowany regułą in:public,followers,private.',
        ],
        ProductSignal::class => [
            'user_id' => 'Sygnał produktowy zapisuje kod serwera, zawsze dla osoby z sesji.',
        ],
        Profile::class => [
            'user_id' => 'Profil powstaje razem z kontem, w jednej transakcji, z identyfikatorem świeżo zapisanego wiersza.',
        ],
        Recipe::class => [
            'author_id' => 'PublishRecipe ustawia autora wyłącznie przy create(); edycja przechodzi przez RecipePolicy i nie aktualizuje tej kolumny.',
            'status' => 'O stanie decyduje macierz przejść RecipeStatusTransitions, nie pole formularza.',
            'visibility' => 'To JEST wybór człowieka na ekranie, walidowany regułą in.',
        ],
        RecipeVersion::class => [
            'editor_id' => 'Wersję przepisu zapisuje PublishRecipe, edytor pochodzi z sesji.',
        ],
        Report::class => [
            'reporter_id' => 'Zgłoszenie składa jeden create() w akcji domenowej; zgłaszający to sesja albo null dla gościa.',
            'autor_tresci_id' => 'Wypełnia wyłącznie OznaczDoPrzegladu przy source=automat — nie ma formularza, który by to przysyłał.',
            'status' => 'Nowe zgłoszenie dostaje STATUS_OPEN na sztywno; rozstrzyga panel moderacji.',
            'resolved_by' => 'Wypełnia wyłącznie panel moderacji razem ze statusem.',
            'resolved_at' => 'Znacznik rozstrzygnięcia — ta sama droga co resolved_by.',
        ],
        WpisZgody::class => [
            'user_id' => 'Dziennik zgód jest tylko do dopisywania (D-072); wiersz składa klasa domenowa z osobą podaną w kodzie.',
        ],
    ];

    /**
     * Modele, tabele i kolumny wrażliwe — policzone przy przebiegu.
     *
     * @return list<array{klasa: class-string<Model>, model: Model, tabela: string, wrazliwe: list<string>}>
     */
    private function inwentarz(): array
    {
        $inwentarz = [];

        foreach ($this->pliki() as $plik) {
            $klasa = 'App\\Models\\'.basename($plik, '.php');
            $refleksja = new \ReflectionClass($klasa);

            if ($refleksja->isAbstract() || ! $refleksja->isSubclassOf(Model::class)) {
                continue;
            }

            /** @var Model $model */
            $model = $refleksja->newInstance();
            $tabela = $model->getTable();

            $this->assertTrue(
                Schema::hasTable($tabela),
                "Model {$klasa} wskazuje na tabelę `{$tabela}`, której nie ma w schemacie — "
                .'bez tabeli nie da się wyliczyć kolumn wrażliwych i test cicho nic by nie sprawdzał.',
            );

            $kolumny = Schema::getColumnListing($tabela);
            $wlasciciele = $this->kluczeWlasciciela($model, $refleksja);

            $wrazliwe = array_values(array_filter($kolumny, fn (string $kolumna): bool => in_array($kolumna, self::STAN, true)
                || in_array($kolumna, self::ROZSTRZYGNIECIA, true)
                || in_array($kolumna, $wlasciciele, true)
                || preg_match(self::WZORZEC_POSWIADCZENIA, $kolumna) === 1));

            $inwentarz[] = [
                'klasa' => $klasa,
                'model' => $model,
                'tabela' => $tabela,
                'wrazliwe' => $wrazliwe,
            ];
        }

        return $inwentarz;
    }

    /** @return list<string> */
    private function pliki(): array
    {
        return glob(app_path('Models/*.php')) ?: [];
    }

    /**
     * Klucze obce powiązań `BelongsTo(User::class)` — kategoria 2.
     *
     * @param  \ReflectionClass<Model>  $refleksja
     * @return list<string>
     */
    private function kluczeWlasciciela(Model $model, \ReflectionClass $refleksja): array
    {
        $klucze = [];

        foreach ($refleksja->getMethods(\ReflectionMethod::IS_PUBLIC) as $metoda) {
            if ($metoda->isStatic() || $metoda->getNumberOfParameters() > 0) {
                continue;
            }

            $typ = $metoda->getReturnType();

            if (! $typ instanceof \ReflectionNamedType || $typ->getName() !== BelongsTo::class) {
                continue;
            }

            $relacja = $model->{$metoda->getName()}();

            if ($relacja instanceof BelongsTo && $relacja->getRelated() instanceof User) {
                $klucze[] = $relacja->getForeignKeyName();
            }
        }

        return array_values(array_unique($klucze));
    }

    /**
     * Czy masowe przypisanie USTAWIŁO kolumnę.
     *
     * Sprawdzamy obecność klucza w `getAttributes()`, a nie wartość: rzutowania
     * (`hashed`, `array`, `datetime`) zmieniają wartość po drodze, więc
     * porównanie z tym, co podaliśmy, dawałoby fałszywe „nie ustawiło się".
     *
     * Wartość jest datą zapisaną tekstem, bo część kolumn wrażliwych ma
     * rzutowanie `datetime` (`decided_at`, `resolved_at`, `handled_at`)
     * i odrzuca dowolny ciąg wyjątkiem parsowania — a wtedy test przestałby
     * mierzyć masowe przypisanie, zaczynając mierzyć parser dat.
     *
     * `MassAssignmentException` to najmocniejsza z możliwych odpowiedzi:
     * rzuca ją model z pustym `$fillable` i domyślnym `$guarded = ['*']`
     * (`totallyGuarded()`), czyli taki, który odrzuca WSZYSTKO.
     */
    private function daloSieUstawic(string $klasa, string $kolumna): bool
    {
        try {
            /** @var Model $model */
            $model = new $klasa([$kolumna => self::WARTOSC_NAPASTNIKA]);
        } catch (MassAssignmentException) {
            return false;
        }

        return array_key_exists($kolumna, $model->getAttributes());
    }

    /**
     * GŁÓWNY POMIAR: żadna kolumna wrażliwa nie daje się ustawić masowym
     * przypisaniem, chyba że stoi w rejestrze z powodem.
     */
    /**
     * Rejestr nazywa tylko symbole, które istnieją (audyt A5-18).
     *
     * Uzasadnienia w `REJESTR` wskazują, KTÓRA klasa ustawia daną kolumnę —
     * to jest mapa dla kolejnego audytu. Audyt A5 znalazł w niej nazwy,
     * których w kodzie nie ma (`AddComment` zamiast `PublishComment`,
     * `STATUS_NEW` zamiast `Report::STATUS_OPEN`): uzasadnienie było
     * merytorycznie prawdziwe, ale prowadziło w pustkę.
     *
     * Sprawdzamy dwie rzeczy: każde słowo w CamelCase (co najmniej dwa
     * człony) ma plik klasy o tej nazwie w `app/`, a każda stała `STATUS_*`
     * jest zdefiniowana w modelu, którego dotyczy wpis. Tego, czy nazwana
     * klasa NAPRAWDĘ ustawia kolumnę, test nie rozstrzyga — to zostaje
     * czytelnikowi.
     */
    public function test_rejestr_nazywa_tylko_istniejace_klasy_i_stale(): void
    {
        $klasy = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $plik) {
            if ($plik->isFile() && $plik->getExtension() === 'php') {
                $klasy[$plik->getBasename('.php')] = true;
            }
        }

        $sprawdzone = 0;

        foreach (self::REJESTR as $model => $kolumny) {
            foreach ($kolumny as $kolumna => $uzasadnienie) {
                preg_match_all('/\b[A-Z][a-z0-9]+(?:[A-Z][a-z0-9]*)+\b/', $uzasadnienie, $nazwy);

                foreach ($nazwy[0] as $nazwa) {
                    $sprawdzone++;
                    $this->assertArrayHasKey(
                        $nazwa,
                        $klasy,
                        "REJESTR[{$model}][{$kolumna}] wskazuje klasę {$nazwa}, której nie ma w app/. "
                        .'Popraw nazwę na tę, która naprawdę ustawia kolumnę.',
                    );
                }

                preg_match_all('/\bSTATUS_[A-Z_]+\b/', $uzasadnienie, $stale);

                foreach ($stale[0] as $stala) {
                    $sprawdzone++;
                    $this->assertTrue(
                        defined($model.'::'.$stala),
                        "REJESTR[{$model}][{$kolumna}] wskazuje stałą {$stala}, której {$model} nie definiuje.",
                    );
                }
            }
        }

        // Kontrola dodatnia: parser naprawdę coś znalazł. Bez tego zmiana
        // wzorca na niepasujący dałaby zielony test z pustą pętlą.
        $this->assertGreaterThan(20, $sprawdzone, 'Strażnik rejestru prawie niczego nie sprawdził — wzorzec przestał pasować.');
    }

    public function test_zadna_kolumna_wrazliwa_nie_wchodzi_masowym_przypisaniem_poza_rejestrem(): void
    {
        $sprawdzonychKolumn = 0;
        $modeliZKolumnamiWrazliwymi = 0;
        $bledy = [];
        $inwentarz = $this->inwentarz();

        foreach ($inwentarz as $wpis) {
            if ($wpis['wrazliwe'] !== []) {
                $modeliZKolumnamiWrazliwymi++;
            }

            $rejestr = self::REJESTR[$wpis['klasa']] ?? [];

            foreach ($wpis['wrazliwe'] as $kolumna) {
                $sprawdzonychKolumn++;

                if (! $this->daloSieUstawic($wpis['klasa'], $kolumna)) {
                    continue;
                }

                if ($this->nietykalna($wpis['tabela'], $kolumna)) {
                    $bledy[] = "{$wpis['klasa']}::\$fillable wpuszcza `{$kolumna}` — to jest kolumna NIETYKALNA "
                        .'(poświadczenie albo role/status konta, AGENTS.md §7). Żaden wpis w rejestrze jej nie odblokuje: '
                        .'zmiana tej wartości ma iść jawną, nazwaną metodą.';

                    continue;
                }

                if (! array_key_exists($kolumna, $rejestr)) {
                    $bledy[] = "{$wpis['klasa']}::\$fillable wpuszcza kolumnę wrażliwą `{$kolumna}`, "
                        .'której nie ma w REJESTRZE tego testu. Albo wyjmij ją z $fillable, albo dopisz do rejestru '
                        .'zdanie mówiące, skąd ta wartość pochodzi, jeśli nie z żądania.';
                }
            }
        }

        $this->assertSame([], $bledy, "Masowe przypisanie kolumn wrażliwych:\n- ".implode("\n- ", $bledy));

        // PUŁAPKA 2: skan, który nie przejrzał niczego, wygląda tak samo jak
        // skan, który przejrzał wszystko i wszystko było w porządku.
        $this->assertGreaterThanOrEqual(
            34,
            count($inwentarz),
            'Skan przejrzał mniej modeli niż jest w app/Models — zła ścieżka albo zły glob?',
        );
        // Zmierzone 12.09.2026: 34 modele, 28 z nich ma co najmniej jedną
        // kolumnę wrażliwą, kolumn wrażliwych razem 61.
        $this->assertGreaterThanOrEqual(
            28,
            $modeliZKolumnamiWrazliwymi,
            'Kolumny wrażliwe znalazły się w zbyt małej liczbie modeli — reguła wyliczająca je przestała działać.',
        );
        $this->assertGreaterThanOrEqual(
            61,
            $sprawdzonychKolumn,
            'Sprawdzono zbyt mało kolumn wrażliwych — wyrażenia z kategorii 1-4 przestały cokolwiek łapać.',
        );
    }

    /**
     * REJESTR JEST DOKŁADNY W DRUGĄ STRONĘ — kontrola dodatnia (pułapka 4).
     *
     * Bez tego rejestr byłby workiem bez dna: dopisanie do niego wszystkich
     * kolumn świata uciszyłoby test na zawsze, a wpisy po kolumnach dawno
     * wyjętych z `$fillable` udawałyby, że coś opisują. Każdy wpis musi
     * odpowiadać kolumnie, która NAPRAWDĘ dziś wchodzi masowym przypisaniem.
     */
    public function test_rejestr_nie_ma_martwych_wpisow(): void
    {
        $martwe = [];
        $sprawdzonych = 0;

        foreach (self::REJESTR as $klasa => $kolumny) {
            /** @var Model $model */
            $model = new $klasa;

            foreach ($kolumny as $kolumna => $powod) {
                $sprawdzonych++;

                $this->assertNotSame('', trim($powod), "Wpis {$klasa}.{$kolumna} nie ma powodu.");

                if (! Schema::hasColumn($model->getTable(), $kolumna)) {
                    $martwe[] = "{$klasa}.{$kolumna} — takiej kolumny nie ma już w tabeli `{$model->getTable()}`.";

                    continue;
                }

                if (! $this->daloSieUstawic($klasa, $kolumna)) {
                    $martwe[] = "{$klasa}.{$kolumna} — kolumna NIE wchodzi już masowym przypisaniem, "
                        .'więc wpis w rejestrze niczego nie opisuje. Skasuj go: inaczej następna osoba przeczyta '
                        .'rejestr jako opis stanu, którego nie ma.';
                }
            }
        }

        $this->assertSame([], $martwe, "Martwe wpisy w rejestrze:\n- ".implode("\n- ", $martwe));
        // Zmierzone 12.09.2026: rejestr ma 40 wpisów — dokładnie tyle kolumn
        // wrażliwych wchodzi dziś masowym przypisaniem po wyjęciu `password`.
        $this->assertGreaterThanOrEqual(
            40,
            $sprawdzonych,
            'Rejestr się skurczył — albo kolumny naprawdę wyszły z $fillable (to dobrze, obniż próg), '
            .'albo pętla przestała je czytać (to źle).',
        );
    }

    /**
     * PUŁAPKA 2 WPROST: skan naprawdę czyta katalog modeli i naprawdę widzi
     * schemat bazy.
     */
    public function test_skan_naprawde_czyta_modele(): void
    {
        $this->assertGreaterThanOrEqual(
            34,
            count($this->pliki()),
            'W app/Models widać mniej plików niż powinno — `glob()` albo ścieżka przestały działać.',
        );

        $inwentarz = $this->inwentarz();
        $klasy = array_column($inwentarz, 'klasa');

        // Kotwice: trzy modele, o których wiemy, że istnieją i mają kolumny
        // wrażliwe. Jeśli skan ich nie widzi, nie widzi niczego.
        $this->assertContains(User::class, $klasy);
        $this->assertContains(Post::class, $klasy);
        $this->assertContains(Report::class, $klasy);

        $poWrazliwych = [];
        foreach ($inwentarz as $wpis) {
            $poWrazliwych[$wpis['klasa']] = $wpis['wrazliwe'];
        }

        $this->assertContains('role', $poWrazliwych[User::class]);
        $this->assertContains('status', $poWrazliwych[User::class]);
        $this->assertContains('password', $poWrazliwych[User::class]);
        $this->assertContains('two_factor_secret', $poWrazliwych[User::class]);
        $this->assertContains('author_id', $poWrazliwych[Post::class]);

        // Kontrola dodatnia reguły z kategorii 2: `recipe_id` kończy się na
        // `_id`, ale NIE jest kluczem właściciela — i test ma to wiedzieć,
        // inaczej „wyliczanie z relacji" byłoby wyliczaniem z końcówki nazwy.
        $this->assertNotContains(
            'recipe_id',
            $poWrazliwych[Post::class],
            'Klucz obcy na przepis trafił do kolumn wrażliwych — reguła liczy końcówkę `_id`, a nie relację do User.',
        );
    }

    /**
     * TEST REGRESYJNY JEDYNEGO ZNALEZISKA TEJ PRACY (12.09.2026).
     *
     * `password` stał w `User::$fillable` — jedyna kolumna poświadczenia
     * w całym repozytorium, którą dało się ustawić masowym przypisaniem.
     * Nie było wtedy trasy HTTP, którą dałoby się to wykorzystać (zmierzone
     * siedemnastoma prawdziwymi żądaniami w
     * `PodniesienieRoliZadaniemHttpTest`) — ale bariera, która trzyma tylko
     * dlatego, że nikt jeszcze nie napisał `update($request->all())`, nie
     * jest barierą. Dokładnie ten argument wyprowadził stąd `email`
     * (issue #195).
     *
     * Test stoi osobno od skanu wyżej celowo: skan pilnuje REGUŁY i oblałby
     * się także po zmianie wyrażenia opisującego kategorię. Ten pilnuje tej
     * jednej kolumny, po nazwie, i mówi wprost, co było zepsute.
     */
    public function test_hasla_nie_da_sie_ustawic_masowym_przypisaniem(): void
    {
        $basia = $this->user('basia', ['password' => Hash::make('stare-haslo-basi')]);

        $basia->update(['password' => 'podstawione-przez-napastnika']);

        $this->assertTrue(
            Hash::check('stare-haslo-basi', (string) $basia->fresh()->password),
            'Hasło da się ustawić masowym przypisaniem — `password` wróciło do $fillable. '
            .'To jest przejęcie konta przez dowolny `update($request->all())`, także taki, '
            .'który o haśle w ogóle nie myśli.',
        );

        $nowe = new User(['password' => 'podstawione-przez-napastnika', 'locale' => 'pl']);

        $this->assertNull($nowe->password, 'Nowe konto przyjęło hasło masowym przypisaniem.');
        $this->assertSame('pl', $nowe->locale, 'Kontrola: pola dozwolone nadal przechodzą.');
    }

    /**
     * KONTROLA DRUGIEJ STRONY (pułapka 4). Bez niej „hasło się nie zapisuje"
     * mogłoby znaczyć, że nie zapisuje się NIGDY — czyli że rejestracja
     * i zmiana hasła są zepsute, a test wyżej i tak zielony.
     */
    public function test_kontrola_dodatnia_jawna_droga_haslo_ustawia(): void
    {
        $basia = $this->user('basia');

        $basia->assignPassword('zupelnie-nowe-haslo-basi')->save();

        $this->assertTrue(
            Hash::check('zupelnie-nowe-haslo-basi', (string) $basia->fresh()->password),
            '`assignPassword()` nie zapisuje hasła — jedyna droga do kolumny przestała działać.',
        );

        // I ta sama droga w praktyce: rejestracja zakłada konto, którym da
        // się od razu zalogować.
        $this->post(route('register'), [
            'display_name' => 'Nowa Basia',
            'username' => 'nowa_basia',
            'email' => 'nowa@example.test',
            'password' => 'zielonapietruszkarano',
            'password_confirmation' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect();

        $konto = User::query()->where('email', 'nowa@example.test')->sole();

        $this->assertTrue(
            Hash::check('zielonapietruszkarano', (string) $konto->password),
            'Rejestracja przestała zapisywać hasło — to jest konto, na które nikt nigdy nie wejdzie.',
        );
    }

    /**
     * PIĘĆ MODELI CHRONI SIĘ MOCNIEJ NIŻ RESZTA — pustym `$fillable`.
     *
     * `LoginLinkToken`, `RegistrationInvite`, `PendingEmailChange`,
     * `TozsamoscZewnetrzna` i `MailFailure` odrzucają masowe przypisanie
     * W CAŁOŚCI (`totallyGuarded()` rzuca wyjątek), bo każdy z tych wierszy
     * jest drogą wejścia na konto albo zapisem technicznym, do którego nie
     * prowadzi żaden formularz. Ten test pilnuje, żeby ktoś nie „naprawił"
     * ich przez dopisanie `$fillable`.
     */
    public function test_modele_bez_fillable_odrzucaja_masowe_przypisanie_w_calosci(): void
    {
        $calkowicieChronione = [
            LoginLinkToken::class => 'token_hash',
            RegistrationInvite::class => 'token_hash',
            PendingEmailChange::class => 'user_id',
            TozsamoscZewnetrzna::class => 'user_id',
            MailFailure::class => 'user_id',
        ];

        foreach ($calkowicieChronione as $klasa => $kolumna) {
            $this->assertFalse(
                $this->daloSieUstawic($klasa, $kolumna),
                "{$klasa} przyjął `{$kolumna}` masowym przypisaniem — ten model ma CELOWO puste \$fillable.",
            );

            // Kontrola dodatnia: sprawdzamy, że mierzymy właściwą klasę,
            // a nie że literówka w nazwie modelu daje nam „nie da się".
            $this->assertTrue(
                Schema::hasColumn((new $klasa)->getTable(), $kolumna),
                "Kolumny `{$kolumna}` nie ma w tabeli modelu {$klasa} — test sprawdza nieistniejącą rzecz.",
            );
        }
    }
}
