<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Powiadomienie w aplikacji.
 *
 * `type` jest stabilnym łańcuchem (np. `cooked_event.created`), nie nazwą klasy
 * PHP — po to, żeby refaktor kodu nie unieważnił historii powiadomień ani
 * eksportu danych użytkownika.
 */
class Notification extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Ktoś ugotował z Twojego przepisu. Najważniejsze powiadomienie w Kuking. */
    public const TYPE_COOKED = 'cooked_event.created';

    /**
     * Klucz sesji (flash na jedno żądanie): „to kliknięcie «Zobacz» zgasiło
     * to powiadomienie" — wartością jest identyfikator powiadomienia.
     * Ustawia `NotificationController::open()`, czyta
     * `CookedEventController::celebrate()` (issue #770).
     */
    public const SESJA_PIERWSZE_OTWARCIE = 'powiadomienie_pierwsze_otwarcie';

    public const TYPE_COMMENT = 'comment.created';

    public const TYPE_REPLY = 'comment.replied';

    public const TYPE_FOLLOW = 'follow.created';

    public const TYPE_SAVED = 'recipe.saved';

    public const TYPE_MODERATION = 'moderation.decision';

    /**
     * POTWIERDZENIE PRZYJĘCIA ZGŁOSZENIA (DSA art. 16 ust. 4), issue #10.
     *
     * Idzie do ZGŁASZAJĄCEGO, nie do zgłoszonego. Do tej zmiany jedyną
     * informacją, jaką dostawał człowiek po kliknięciu „Zgłoś", był flash
     * w sesji — znikał po odświeżeniu strony i nie zostawał nigdzie. Przepis
     * mówi o potwierdzeniu odbioru bez zbędnej zwłoki, a potwierdzenie,
     * którego nie da się odczytać drugi raz, nim nie jest.
     */
    public const TYPE_REPORT_RECEIVED = 'report.received';

    /**
     * INFORMACJA O ROZSTRZYGNIĘCIU ZGŁOSZENIA (DSA art. 16 ust. 5), issue #10.
     *
     * Osobny typ od `TYPE_MODERATION`, bo to jest inny obowiązek wobec innego
     * człowieka: `TYPE_MODERATION` idzie do AUTORA treści („zrobiliśmy coś
     * z Twoim wpisem", art. 17), ten idzie do osoby, która zgłosiła
     * („zrobiliśmy to a to z Twoim zgłoszeniem", art. 16 ust. 5). Wspólny typ
     * zlałby dwie różne treści, dwa różne pouczenia i dwa różne odbiorcy
     * w jeden wiersz nie do odróżnienia w eksporcie danych.
     */
    public const TYPE_REPORT_DECIDED = 'report.decided';

    public const TYPE_WELCOME = 'account.welcome';

    /**
     * NOWE ODWOŁANIE CZEKA W PANELU — powiadomienie dla ADMINISTRATORA, nie
     * dla użytkownika (zgłoszenie właściciela z 10 września: „nie mam jako
     * admin powiadomienia, że jakieś odwołanie jest").
     *
     * DLACZEGO TO JEST WAŻNIEJSZE NIŻ WYGODA: odwołanie ma termin
     * odpowiedzi (`Appeal::responseDeadline()`, siedem dni roboczych
     * z `docs/legal/MODERATION_PLAYBOOK.md` §3, wypisany człowiekowi na
     * ekranie jako „odpowiedz do …"). Kolejka, o której nikt nie wie, że
     * coś w niej leży, to termin, który upływa po cichu — a jest to
     * zobowiązanie z DSA art. 20 i z regulaminu §8, nie uprzejmość.
     *
     * KOMU IDZIE: wyłącznie administratorom. Odwołanie rozstrzyga admin,
     * nie moderator (`UserPolicy::resolveAppeals()`, D-039) — powiadomienie
     * dla kogoś, kto po kliknięciu zobaczy „tę sprawę zamyka
     * administrator", byłoby wezwaniem do czynności, której ta osoba nie
     * może wykonać. Moderator widzi kolejkę i licznik przy niej w menu,
     * i to jest właściwa dla niego dawka. Rozstrzyga to
     * `App\Domain\Moderation\Actions\PowiadomOOdwolaniu`.
     *
     * ŚWIADOMIE NIE MA GO NA LIŚCIE `WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`
     * i nie jest to przeoczenie: tamta lista chroni powiadomienia, które
     * NIOSĄ CZŁOWIEKOWI decyzję i pouczenie o odwołaniu, więc nie wolno ich
     * skasować przed upływem terminu na to odwołanie. To powiadomienie jest
     * po drugiej stronie biurka — jest zawiadomieniem o pracy do wykonania,
     * a nie dowodem niczyjego prawa. Trwałym zapisem sprawy jest `appeals`
     * i dziennik zdarzeń (`appeal.filed` w `AuditLogEntry`), które żyją
     * własnymi terminami.
     */
    public const TYPE_APPEAL_FILED = 'appeal.filed';

    /**
     * Pierwszy wpis nowej osoby — powiadomienie dla GOSPODARZA, nie dla
     * autora (issue #6).
     *
     * 55% osób 55-64 i 62% osób 65+ w mediach społecznościowych to wyłącznie
     * odbiorcy treści. Kto opublikuje pierwszy raz, robi to wbrew własnemu
     * nawykowi — i jeśli nikt nie odpowie, drugi raz już nie spróbuje.
     * Gospodarz musi się o tym dowiedzieć NATYCHMIAST, a nie przy najbliższym
     * zajrzeniu do panelu.
     */
    public const TYPE_FIRST_POST = 'post.first';

    /**
     * Typy powiadomień WYŁĄCZONE Z OGÓLNEGO OKRESU RETENCJI (issue #19,
     * docs/decyzje/ADR_RETENCJE.md §5.2, §5.6) — kolizja trzymiesięcznej
     * retencji (`config('kuking.notifications.retention_months')`) z
     * sześciomiesięcznym terminem na odwołanie od decyzji moderacyjnej
     * (DSA art. 20 ust. 1, `ModerationAction::appealDeadline()`).
     *
     * INNY KSZTAŁT WYJĄTKU NIŻ `AuditLogEntry::NIGDY_NIE_KASUJ`. Tam wyjątek
     * jest bezterminowy (dowód RODO art. 17 nie ma innego zapisu w bazie).
     * Tutaj NIE chodzi o wieczne przechowywanie — chodzi o to, że WŁASNY
     * termin ważności tego powiadomienia nie jest liczbą tego configu, tylko
     * terminem na odwołanie od decyzji, do której się odnosi. Gdy ten termin
     * minie, powiadomienie wraca do bycia zwykłym kandydatem do usunięcia —
     * patrz `terminOchronyOdwolawczej()` niżej i
     * `App\Domain\Compliance\PrzedawnionePowiadomienia`.
     *
     * `TYPE_MODERATION` — JEDYNY typ, którym serwis niesie: (a) decyzję
     * moderacyjną wraz z linkiem „Odwołaj się"
     * (`NotifyModerationDecision::handle()`, `data.action_id` wskazuje
     * `moderation_actions.id` wprost), (b) wynik już złożonego odwołania
     * (`NotifyAppealOutcome::handle()`, `data.appeal_id` wskazuje
     * `appeals.id`, z którego termin dochodzimy przez `Appeal::moderationAction()`).
     * Obie ścieżki prowadzą do tej samej decyzji, więc obie mierzymy tym
     * samym terminem — `ModerationAction::appealDeadline()` — a NIE osobno
     * wpisaną liczbą miesięcy: druga kopia terminu rozjechałaby się
     * z prawdziwym terminem przy pierwszej zmianie `appeal_days` w configu
     * albo ustawowego minimum wewnątrz samej `appealDeadline()`.
     *
     * Dla zgłaszającego BEZ konta droga do odwołania jest inna (mail,
     * `App\Notifications\DecyzjaWSprawieZgloszenia`) i w ogóle nie dotyka
     * tej tabeli — poczta nie jest tu zapisywana jako wiersz.
     *
     * `TYPE_REPORT_DECIDED` — DRUGI typ na tej liście, dołożony w issue #10.
     * Niesie odpowiedź dla ZGŁASZAJĄCEGO wraz z pouczeniem o dostępnych
     * środkach (DSA art. 16 ust. 5) i jest jedynym miejscem w serwisie, gdzie
     * ta osoba może to pouczenie przeczytać drugi raz. Skasowanie go po
     * trzech miesiącach zabrałoby pouczenie, kiedy decyzja, której ono
     * dotyczy, jest jeszcze przez trzy miesiące zaskarżalna — regulamin §8
     * daje sześć miesięcy i nie rozróżnia, która strona sprawy się odwołuje.
     * Termin dochodzimy tą samą drogą co przy `TYPE_MODERATION`, przez
     * `data.action_id` → `ModerationAction::appealDeadline()`, więc NIE
     * powstaje druga kopia liczby miesięcy.
     *
     * `TYPE_REPORT_RECEIVED` ŚWIADOMIE TU NIE JEST i nie jest to
     * przeoczenie. Potwierdzenie przyjęcia nie niesie decyzji ani pouczenia,
     * więc nie ma terminu odwołania, którym można by je mierzyć —
     * `terminOchronyOdwolawczej()` zwracałoby dla niego `null` w każdym
     * przebiegu, a `PrzedawnionePowiadomienia` traktuje `null` jako „nie
     * wiadomo, zostaw i zapisz ostrzeżenie w logu". Wpis wisiałby więc
     * wiecznie, produkując ostrzeżenie za każdym sprzątaniem — wyłączenie
     * retencji z niewłaściwego powodu. Trwałym zapisem sprawy jest samo
     * zgłoszenie (`reports`, `moderation.case_retention_months`, domyślnie
     * 36 miesięcy), które zgłaszający czyta na `/zgloszenia` niezależnie od
     * tego, czy powiadomienie jeszcze istnieje.
     *
     * LISTA JEST ZAMKNIĘTA, z tego samego powodu co `AuditLogEntry::NIGDY_NIE_KASUJ`:
     * trzymanie jej w configu dałoby się wyczyścić jedną zmianą wdrożeniową
     * bez recenzji kodu — dokładnie tego ta lista ma nie dopuścić.
     *
     * @var list<string>
     */
    public const WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA = [
        self::TYPE_MODERATION,
        self::TYPE_REPORT_DECIDED,
    ];

    protected $fillable = [
        'user_id',
        'actor_id',
        'type',
        'data',
    ];

    /**
     * Czy wykonanie z `data.cooked_event_id` wciąż istnieje (issue #771).
     * `null` = jeszcze nie sprawdzano. Lista ustawia to jednym zapytaniem
     * dla całej strony (`NotificationController::index()`), żeby każde
     * powiadomienie o ugotowaniu nie dokładało własnego `select`.
     */
    private ?bool $wykonanieIstnieje = null;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }

    /**
     * Dokąd prowadzi przycisk „Zobacz" — albo `null`, gdy nie ma dokąd.
     *
     * DLACZEGO TO STOI W MODELU, A NIE W WIDOKU (bo tam stało do 8 września).
     * Od kiedy „Zobacz" oznacza powiadomienie jako przeczytane, adres liczą
     * DWA miejsca: widok, żeby zdecydować, czy w ogóle pokazać przycisk,
     * i kontroler, żeby wiedzieć, dokąd odesłać. Dwie kopie tego samego
     * `match` rozjechałyby się przy pierwszym nowym typie powiadomienia —
     * a rozjazd wyglądałby tak, że przycisk oznacza przeczytane i odsyła
     * gdzie indziej, niż zapowiadał. Jedno źródło, dwóch odbiorców.
     */
    public function adresDocelowy(): ?string
    {
        $data = $this->data ?? [];

        return match ($this->type) {
            // Prowadzi do pełnoekranowego ekranu „Komuś wyszło" (issue #17),
            // nie od razu do zwykłego wpisu — to jest najcenniejszy moment
            // w produkcie i zasługuje na własną stronę, nie jeden wiersz
            // na liście. `celebrate()` sam się cofa do `cooked.show`,
            // kiedy ekran już był raz pokazany.
            //
            // ISSUE #771: usunięte wykonanie nie ma dokąd prowadzić. Link do
            // niego kończył się 404 — widok pokazuje wtedy uczciwy stan
            // („To ugotowanie zostało usunięte.") bez przycisku „Zobacz".
            self::TYPE_COOKED => isset($data['cooked_event_id']) && ! $this->wykonanieUsuniete()
                ? route('cooked.celebrate', $data['cooked_event_id'])
                : null,
            self::TYPE_SAVED => isset($data['recipe_slug']) ? route('recipes.show', $data['recipe_slug']) : null,
            // ISSUE #734: po AKTUALNYM profilu sprawcy (`actor_id`), nie po
            // `data.username` zapamiętanym w chwili obserwowania. Po zmianie
            // nazwy stara prowadziła na 404 — albo, gdy ktoś ją potem zajął,
            // do INNEJ osoby niż ta, którą powiadomienie opisuje. Brak
            // profilu = brak celu, nigdy zgadywanie po starej nazwie.
            self::TYPE_FOLLOW => is_string($nazwa = $this->actor?->profile?->username) && $nazwa !== ''
                ? route('profile.show', $nazwa)
                : null,
            self::TYPE_FIRST_POST => route('admin.unanswered'),
            // Wprost na kolejkę odwołań. Bez identyfikatora w adresie:
            // kolejka nie ma ekranu jednej sprawy, a odwołania otwarte stoją
            // na niej najstarsze na górze, czyli to z najbliższym terminem
            // jest pierwsze (`AppealController::index()`).
            self::TYPE_APPEAL_FILED => route('admin.appeals'),
            self::TYPE_WELCOME => route('posts.create'),
            // Obie drogi zgłaszającego (issue #10) prowadzą na kartę TEJ
            // sprawy, nie na listę: człowiek klika „Zobacz" przy konkretnym
            // powiadomieniu i ma zobaczyć konkretną sprawę. Trzymamy sam
            // identyfikator, nie gotowy adres — trasy się zmieniają,
            // a historia powiadomień zostaje na lata.
            self::TYPE_REPORT_RECEIVED, self::TYPE_REPORT_DECIDED => is_string($data['report_id'] ?? null) && $data['report_id'] !== ''
                ? route('reports.mine.show', $data['report_id'])
                : null,
            // ISSUE #759: komentarz/odpowiedź, nie tylko "gdzieś na tej treści".
            // Patrz `urlDoKomentarza()` niżej.
            self::TYPE_COMMENT, self::TYPE_REPLY => $this->urlDoKomentarza($data),
            default => is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null,
        };
    }

    /**
     * Powiadomienie o ugotowaniu, którego wykonanie zostało usunięte
     * (issue #771). Wykonanie kasuje się twardo (`CookedEventController::destroy()`),
     * a identyfikator w `data` nie jest kluczem obcym, więc powiadomienie
     * zostaje — i ma zostać: to było prawdziwe zdarzenie. Kłamać nie może
     * tylko o tym, co jest za nim dziś („Jest zdjęcie", „Zobacz").
     */
    public function wykonanieUsuniete(): bool
    {
        $id = $this->data['cooked_event_id'] ?? null;

        if ($this->type !== self::TYPE_COOKED || ! is_string($id) || $id === '') {
            return false;
        }

        // Nie-UUID nie trafi w żadne wykonanie (a PostgreSQL odrzuciłby je
        // błędem rzutowania), więc traktujemy je jak wykonanie, którego nie ma.
        $this->wykonanieIstnieje ??= Str::isUuid($id) && CookedEvent::query()->whereKey($id)->exists();

        return ! $this->wykonanieIstnieje;
    }

    /** Wynik zbiorczego sprawdzenia z listy — patrz `$wykonanieIstnieje`. */
    public function zapamietajIstnienieWykonania(bool $istnieje): void
    {
        $this->wykonanieIstnieje = $istnieje;
    }

    /**
     * Adres KONKRETNEGO komentarza/odpowiedzi, nie tylko pierwszej strony
     * treści, pod którą stoi.
     *
     * CO BYŁO ZEPSUTE
     * `data.url` (`PublishComment::urlFor()`) niesie WYŁĄCZNIE
     * `$subject->url()` — bez numeru strony i bez kotwicy. Wątek pod
     * popularnym wpisem/przepisem jest stronicowany
     * (`config('kuking.comments.page_size')`, `PostController::show()`,
     * `RecipeController::show()`), więc przy odpowiedzi w korzeniu leżącym
     * poza pierwszą stroną „Zobacz" otwierał stronę bez tego wątku w ogóle —
     * a powiadomienie było już oznaczone jako przeczytane.
     *
     * DLACZEGO LICZYMY STRONĘ TERAZ, A NIE ZAPISUJEMY JEJ PRZY PUBLIKACJI
     * Numer strony zależy od tego, ILE wątków przed tym konkretnym jest
     * WIDOCZNYCH DLA ODBIORCY w chwili kliknięcia — a widoczność (blokady,
     * moderacja, inne komentarze skasowane w międzyczasie) zmienia się po
     * drodze. Zapisanie strony przy publikacji zamroziłoby ją na zawsze
     * błędną, gdy coś nad tym wątkiem zniknie albo się pojawi.
     *
     * KOTWICA WSKAZUJE SAM KOMENTARZ, NIE TYLKO KORZEŃ WĄTKU
     * `comment-thread.blade.php` ma `id="komentarz-{uuid}"` na artykule
     * korzenia — dla odpowiedzi (`TYPE_REPLY`) wskazujemy więc stronę
     * korzenia, ale kotwicę samej odpowiedzi, żeby przeglądarka przewinęła
     * dokładnie do niej, a nie tylko do góry wątku.
     *
     * NIEDOSTĘPNY/USUNIĘTY KOMENTARZ: BEZ UJAWNIANIA FRAGMENTU
     * Gdy komentarza już nie ma, nie jest widoczny dla tego odbiorcy albo
     * treść nadrzędna zniknęła spod niego, wracamy do zwykłego adresu treści
     * (`data.url`) zamiast błędu albo strony bez kontekstu — dokładnie tak,
     * jak przed tą poprawką dla WSZYSTKICH powiadomień o komentarzu. Sam
     * fakt niedostępności nie jest tu ujawniany bardziej, niż był wcześniej.
     */
    private function urlDoKomentarza(array $data): ?string
    {
        $fallback = is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null;

        $commentId = $data['comment_id'] ?? null;

        if (! is_string($commentId) || $commentId === '') {
            return $fallback;
        }

        $viewer = $this->user;

        if ($viewer === null) {
            return $fallback;
        }

        $comment = Comment::query()->find($commentId);

        if ($comment === null) {
            return $fallback;
        }

        $subject = $comment->subject();

        if ($subject === null) {
            return $fallback;
        }

        $rootId = $comment->parent_id ?? $comment->getKey();
        $root = $rootId === $comment->getKey() ? $comment : Comment::query()->find($rootId);

        if ($root === null) {
            return $fallback;
        }

        // Kolejność i filtr IDENTYCZNE jak w kontrolerach (`Post::comments()`,
        // `Recipe::comments()`, `CookedEvent::comments()`: `whereNull('parent_id')`,
        // `status=published`, `oldest()->orderBy('id')`) plus `widoczneDla($viewer)`
        // — inna kolejność albo inny filtr policzyłaby INNĄ stronę niż ta,
        // na którą trafi kontroler przy renderowaniu.
        $widoczneKorzenie = $subject->comments()->widoczneDla($viewer);

        if (! $widoczneKorzenie->clone()->whereKey($root->getKey())->exists()) {
            // Rodzic niewidoczny dla TEGO odbiorcy — nie zdradzamy, gdzie
            // jest, tylko wracamy do zwykłego adresu treści.
            return $fallback;
        }

        $bazowy = $subject->url();
        $kotwica = '#komentarz-'.$comment->getKey();

        if (! ($subject instanceof Post || $subject instanceof Recipe)) {
            // "Ugotowałem" nie stronicuje komentarzy (`CookedEventController::show()`
            // ładuje je wszystkie naraz) — sama kotwica wystarczy.
            return $bazowy.$kotwica;
        }

        $pageSize = (int) config('kuking.comments.page_size');

        if ($pageSize < 1) {
            return $fallback;
        }

        $pozycja = $widoczneKorzenie->clone()
            ->where(function (Builder $wczesniejsze) use ($root): void {
                $wczesniejsze
                    ->where('comments.created_at', '<', $root->created_at)
                    ->orWhere(function (Builder $remis) use ($root): void {
                        $remis->where('comments.created_at', $root->created_at)
                            ->where('comments.id', '<', $root->getKey());
                    });
            })
            ->count();

        $strona = intdiv($pozycja, $pageSize) + 1;

        if ($strona <= 1) {
            return $bazowy.$kotwica;
        }

        $laczek = str_contains($bazowy, '?') ? '&' : '?';

        return $bazowy.$laczek.'komentarze='.$strona.$kotwica;
    }

    /**
     * Termin, do którego retencja (issue #19, ADR §5.2/§5.6) NIE MOŻE
     * skasować tego powiadomienia — wyłącznie dla typów z
     * `WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`. `null` dla pozostałych
     * typów znaczy „brak wydłużenia — obowiązuje ogólny okres wprost",
     * NIE „można skasować natychmiast".
     *
     * `null` wraca też, gdy powiązanej decyzji moderacyjnej nie da się
     * ustalić (odniesienie puste albo wiersz już nie istnieje) — retencja
     * (`PrzedawnionePowiadomienia`) świadomie NIE zgaduje w tej sytuacji:
     * traktuje `null` jak "nie wiadomo, więc nie kasujemy w tym przebiegu",
     * dokładnie tak samo, jak błąd kasowania w `PrzedawnioneSprawyModeracyjne`
     * nie może "zgadywać", że się udało.
     */
    public function terminOchronyOdwolawczej(): ?CarbonInterface
    {
        if (! in_array($this->type, self::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA, true)) {
            return null;
        }

        return $this->decyzjaModeracyjnaDlaRetencji()?->appealDeadline();
    }

    /**
     * `ModerationAction`, z którą to powiadomienie jest związane — przez
     * `data.action_id` wprost (`NotifyModerationDecision`) albo przez
     * `data.appeal_id` → `Appeal::moderationAction()` (`NotifyAppealOutcome`).
     * Patrz komentarz `WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA` po pełne
     * uzasadnienie obu ścieżek.
     */
    private function decyzjaModeracyjnaDlaRetencji(): ?ModerationAction
    {
        $akcjaId = $this->data['action_id'] ?? null;

        if (is_string($akcjaId) && $akcjaId !== '') {
            return ModerationAction::find($akcjaId);
        }

        $odwolanieId = $this->data['appeal_id'] ?? null;

        if (is_string($odwolanieId) && $odwolanieId !== '') {
            return Appeal::find($odwolanieId)?->moderationAction;
        }

        return null;
    }

    /**
     * Powiadomienia, które ta osoba ma prawo zobaczyć — bez tych od osób,
     * z którymi łączy ją blokada.
     *
     * DLACZEGO FILTR PRZY ODCZYCIE, A NIE KASOWANIE PRZY BLOKADZIE
     *
     * `NotifyUser` od początku odmawiał tworzenia NOWYCH powiadomień, gdy
     * między osobami jest blokada. Nie robił jednak nic z tymi, które już
     * leżały na liście — a ludzie blokują właśnie PO nieprzyjemnym zdarzeniu,
     * czyli wtedy, gdy powiadomienie o nim już istnieje. Blokada zostawiała
     * więc na liście nazwisko i zdjęcie osoby, od której człowiek się odciął.
     *
     * Kasowanie wierszy przy blokadzie byłoby nieodwracalne: odblokowanie
     * kogoś ma przywrócić stan sprzed blokady, a nie zostawić dziurę
     * w historii (i w eksporcie danych — RODO art. 15). Dlatego filtrujemy
     * przy odczycie.
     *
     * Blokada liczy się W OBIE STRONY, tak samo jak w
     * `User::hasBlockRelationWith()` — inaczej byłaby ochroną połowiczną.
     *
     * Powiadomienia bez autora (`actor_id IS NULL` — powitanie, wiadomość
     * od moderacji) zostają zawsze: `NOT EXISTS` nie ma wtedy do czego
     * przyrównać `blocked_id` i nie znajduje żadnego wiersza.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        // `recipe.saved` NIE idzie przez filtry sprawcy (ten niżej i ten po
        // statusie): to partia wielu osób (D-070), a `actor_id` trzyma tylko
        // pierwszą. Ukrycie całego „A oraz 2 inne osoby…” dlatego, że autor
        // zablokował A PO jej zapisie, gubiło B i C — i każdą kolejną osobę
        // dopisaną do tego ukrytego wiersza (przegląd PR #1213). Ta partia
        // ma własny warunek, po całej liście, w `widocznaPartiaZapisow()`.
        $query->whereNotExists(function (QueryBuilder $sub) use ($viewer): void {
            $sub->selectRaw('1')
                ->from('blocks')
                ->where('notifications.type', '!=', self::TYPE_SAVED)
                ->where(function (QueryBuilder $warunek) use ($viewer): void {
                    $warunek
                        ->where(function (QueryBuilder $ja) use ($viewer): void {
                            $ja->where('blocks.blocker_id', $viewer->getKey())
                                ->whereColumn('blocks.blocked_id', 'notifications.actor_id');
                        })
                        ->orWhere(function (QueryBuilder $on) use ($viewer): void {
                            $on->whereColumn('blocks.blocker_id', 'notifications.actor_id')
                                ->where('blocks.blocked_id', $viewer->getKey());
                        });
                });
        });

        /*
         * SPRAWCA ZDARZENIA ZBANOWANY ALBO OZNACZONY DO USUNIĘCIA PO FAKCIE.
         *
         * `UserPolicy::viewProfile()` daje w tym stanie 403 wszystkim poza
         * moderatorem (`User::jestDostepnyJakoAutor()`) — ta reguła w ogóle
         * nie miała odpowiednika tutaj. Nazwa i awatar osoby zbanowanej albo
         * czekającej na usunięcie konta wisiały więc na liście dalej, choć
         * kliknięcie w jej profil kończyło się ścianą. Zawieszenie
         * (`suspended`) CELOWO tu nie wchodzi — to kara za pisanie, a nie za
         * bycie widzianym, i `jestDostepnyJakoAutor()` też ją pomija.
         *
         * Od D-022 lista statusów jest JEDNĄ STAŁĄ
         * (`User::STATUSY_UKRYWAJACE_TRESC`), a nie czwartą kopią tego
         * samego `whereIn`. Powód jest zmierzony: `erased` powstał właśnie
         * dlatego, że dołożenie stanu do jednej warstwy nie dołożyło go do
         * pozostałych. `erased` w tej stałej NIE JEST — powiadomienie
         * o wykonaniu, którego autor wymazał konto, ma zostać widoczne
         * dokładnie tak samo jak samo wykonanie.
         *
         * Powiadomienia bez sprawcy (`actor_id IS NULL`) przechodzą zawsze,
         * z tego samego powodu co przy blokadzie wyżej.
         */
        /*
         * ZAWIADOMIENIE SŁUŻBOWE PO ODEBRANIU UPRAWNIEŃ (issue #1351).
         *
         * `appeal.filed` niesie nazwę składającego, rodzaj sprawy i termin —
         * dane z kolejki odwołań, do której wstęp ma tylko czynny
         * administrator. Adresatów wybiera `PowiadomOOdwolaniu` w chwili
         * złożenia odwołania, więc bez tego warunku zawiadomienie zostawało
         * na liście (i w liczniku) po odebraniu roli albo przy zawieszeniu.
         * Pytamy o `isAdmin()` PRZY ODCZYCIE, nie kasujemy wierszy: ponowne
         * nadanie roli albo koniec zawieszenia pokazuje je z powrotem,
         * a retencja tego typu zostaje bez zmian. `actor_id` jest tu NULL,
         * więc filtr sprawcy niżej niczego by nie ukrył.
         */
        if (! $viewer->isAdmin()) {
            $query->where('notifications.type', '!=', self::TYPE_APPEAL_FILED);
        }

        $query->whereNotExists(function (QueryBuilder $sub): void {
            $sub->selectRaw('1')
                ->from('users as sprawcy')
                ->where('notifications.type', '!=', self::TYPE_SAVED)
                ->whereColumn('sprawcy.id', 'notifications.actor_id')
                ->whereIn('sprawcy.status', User::STATUSY_UKRYWAJACE_TRESC);
        });

        $this->widocznaPartiaZapisow($query, $viewer);

        /*
         * POWIADOMIENIE O KOMENTARZU, KTÓREGO TREŚĆ ZNIKŁA ALBO DO KTÓREJ
         * ODBIORCA STRACIŁ DOSTĘP.
         *
         * `comment.created`/`comment.replied` niosą własną kopię fragmentu
         * (`data.excerpt`) — dlatego SAME W SOBIE nie znikają, kiedy znika
         * komentarz albo treść, pod którą stał: autor mógł go skasować,
         * moderacja mogła go ukryć, a wpis/przepis mógł w międzyczasie zmienić
         * widoczność na węższą (audyt: dokładnie ta usterka, co wpis
         * zbanowanego autora w `FollowingFeed`, tylko na powiadomieniach).
         *
         * Odbiorca takiego powiadomienia NIE musi być właścicielem treści —
         * przy odpowiedzi w cudzym wątku (`PublishComment::handle()`) idzie
         * też do autora komentarza-rodzica. Dlatego widoczność treści liczymy
         * dla KONKRETNEGO odbiorcy ($viewer), tymi samymi regułami co
         * `PostPolicy::view()` / `RecipePolicy::view()` / `CookedEventPolicy::view()`
         * — właściciel treści widzi zawsze własne, obcy tylko opublikowane,
         * z widocznością public/followers/private i blokadą w obie strony.
         *
         * Inne typy powiadomień (ugotowanie, zapis do zeszytu, obserwowanie...)
         * ten warunek pomija: ich odbiorcą jest zawsze właściciel treści,
         * który widzi własne rzeczy niezależnie od stanu publikacji — dodanie
         * tu tej samej reguły nic by nie zmieniło, a tylko powielałoby kod.
         *
         * ŚWIADOME UPROSZCZENIE: pomijamy furtkę dla moderatora, którą mają
         * Policy (`isModerator()`). Odbiorcą powiadomienia prawie nigdy nie
         * jest moderator, a pominięcie furtki jest OSTRZEJSZE, nie luźniejsze
         * — najwyżej moderator nie zobaczy własnego powiadomienia o cudzym
         * komentarzu na liście (ma do tego panel moderacji), nigdy odwrotnie.
         */
        // UWAGA NA TYP: to jedyne miejsce w tej metodzie, gdzie `where()` woła
        // się WPROST na $query (Eloquent\Builder), a nie w zagnieżdżeniu
        // `whereExists`/`whereNotExists`. `Eloquent\Builder::where(Closure)`
        // ma własne nadpisanie i przekazuje do closure NOWY `Eloquent\Builder`
        // (`$this->model->newQueryWithoutRelationships()`), nie surowy
        // `Illuminate\Database\Query\Builder` — inaczej niż każde inne miejsce
        // w tym pliku. Zły typ tutaj to `TypeError` w runtime, nie błąd SQL.
        $query->where(function (Builder $tylkoIstniejaceTresci) use ($viewer): void {
            $tylkoIstniejaceTresci
                ->whereNotIn('notifications.type', [self::TYPE_COMMENT, self::TYPE_REPLY])
                ->orWhereExists(function (QueryBuilder $sub) use ($viewer): void {
                    $sub->selectRaw('1')
                        ->from('comments as pc')
                        ->whereRaw("pc.id = (notifications.data->>'comment_id')::uuid")
                        ->where('pc.status', Comment::STATUS_PUBLISHED)
                        ->whereNull('pc.deleted_at')
                        // ISSUE #757: usunięcie komentarza Z ODPOWIEDZIAMI nie robi
                        // soft delete (zostaje `status=published`, `deleted_at=null`),
                        // żeby dzieci nie zawisły bez rodzica — `CommentController::destroy()`
                        // zostawia zamiast tego placeholder i ustawia `body_removed_at`.
                        // Bez tego warunku ta gałąź NIE łapała tej jedynej innej drogi
                        // usunięcia, więc zamrożony `excerpt` z chwili publikacji (do
                        // 120 znaków oryginalnej treści) dalej wychodził w powiadomieniu
                        // i w eksporcie danych (`CollectUserExportData` używa tego samego
                        // `visibleTo()`), mimo że treść w wątku jest już zastąpiona.
                        ->whereNull('pc.body_removed_at')
                        ->where(function (QueryBuilder $tresc) use ($viewer): void {
                            $tresc
                                ->where(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => $this->wierszTresciWidoczny($s, 'posts', 'pc.post_id', $viewer),
                                ))
                                ->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => $this->wierszTresciWidoczny($s, 'recipes', 'pc.recipe_id', $viewer),
                                ))
                                ->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                                    fn (QueryBuilder $s) => $this->wierszWykonaniaWidoczny($s, $viewer),
                                ));
                        });
                });
        });

        return $query;
    }

    /**
     * EXISTS potwierdzający, że wiersz `posts`/`recipes` wskazywany przez
     * $fk (np. `pc.post_id`) jest w tej chwili widoczny dla $widz — tymi
     * samymi regułami co `PostPolicy::view()` / `RecipePolicy::view()`
     * (obie tabele mają identyczny kształt: `author_id`, `status`,
     * `published_at`, `visibility`, `deleted_at`).
     */
    private function wierszTresciWidoczny(QueryBuilder $sub, string $tabela, string $fk, User $widz): void
    {
        $widzId = $widz->getKey();

        if ($tabela === 'posts' && ! config('kuking.questions.enabled')) {
            $sub->where('tw.kind', Post::KIND_DISH);
        }

        $sub->selectRaw('1')
            ->from("{$tabela} as tw")
            ->whereColumn('tw.id', $fk)
            // Skasowana (soft delete) treść nie wraca do nikogo, nawet do autora.
            ->whereNull('tw.deleted_at')
            ->where(function (QueryBuilder $w) use ($widzId): void {
                // Właściciel widzi zawsze własną treść — szkic, ukrytą przez
                // moderację, prywatną. „Poprawne dane nigdy nie znikają."
                $w->where('tw.author_id', $widzId)
                    ->orWhere(function (QueryBuilder $obce) use ($widzId): void {
                        $obce->where('tw.status', self::STATUS_TRESCI_OPUBLIKOWANA)
                            ->whereNotNull('tw.published_at')
                            // Autor treści zbanowany/do usunięcia odcina WSZYSTKICH
                            // poza sobą — już obsłużonym w gałęzi wyżej.
                            ->whereNotExists(function (QueryBuilder $autor): void {
                                $autor->selectRaw('1')
                                    ->from('users as autorzy_tresci')
                                    ->whereColumn('autorzy_tresci.id', 'tw.author_id')
                                    ->whereIn('autorzy_tresci.status', User::STATUSY_UKRYWAJACE_TRESC);
                            })
                            // Blokada między ODBIORCĄ a AUTOREM TREŚCI — może
                            // być inna osoba niż sprawca zdarzenia (odpowiedź
                            // w cudzym wątku).
                            ->whereNotExists(function (QueryBuilder $blok) use ($widzId): void {
                                $blok->selectRaw('1')
                                    ->from('blocks')
                                    ->where(function (QueryBuilder $w2) use ($widzId): void {
                                        $w2->where('blocks.blocker_id', $widzId)
                                            ->whereColumn('blocks.blocked_id', 'tw.author_id');
                                    })
                                    ->orWhere(function (QueryBuilder $w2) use ($widzId): void {
                                        $w2->whereColumn('blocks.blocker_id', 'tw.author_id')
                                            ->where('blocks.blocked_id', $widzId);
                                    });
                            })
                            ->where(function (QueryBuilder $widocznosc) use ($widzId): void {
                                $widocznosc->where('tw.visibility', 'public')
                                    ->orWhere(function (QueryBuilder $obserwujacy) use ($widzId): void {
                                        $obserwujacy->where('tw.visibility', 'followers')
                                            ->whereExists(function (QueryBuilder $f) use ($widzId): void {
                                                $f->selectRaw('1')
                                                    ->from('follows')
                                                    ->where('follows.follower_id', $widzId)
                                                    ->whereColumn('follows.followed_id', 'tw.author_id');
                                            });
                                    });
                            });
                    });
            });
    }

    /**
     * To samo dla komentarza pod „Ugotowałem" (`CookedEventPolicy::view()`):
     * wykonanie nie ma własnej widoczności, idzie za przepisem, a osobno
     * liczy się blokada widz↔kucharz. Brak przepisu (skasowany) zostawia
     * dostęp wyłącznie właścicielowi wykonania.
     */
    private function wierszWykonaniaWidoczny(QueryBuilder $sub, User $widz): void
    {
        $widzId = $widz->getKey();

        $sub->selectRaw('1')
            ->from('cooked_events as ce')
            ->whereColumn('ce.id', 'pc.cooked_event_id')
            ->whereNotExists(function (QueryBuilder $blok) use ($widzId): void {
                $blok->selectRaw('1')
                    ->from('blocks')
                    ->where(function (QueryBuilder $w) use ($widzId): void {
                        $w->where('blocks.blocker_id', $widzId)->whereColumn('blocks.blocked_id', 'ce.user_id');
                    })
                    ->orWhere(function (QueryBuilder $w) use ($widzId): void {
                        $w->whereColumn('blocks.blocker_id', 'ce.user_id')->where('blocks.blocked_id', $widzId);
                    });
            })
            ->where(function (QueryBuilder $w) use ($widzId, $widz): void {
                $w->where(function (QueryBuilder $bezPrzepisu) use ($widzId): void {
                    $bezPrzepisu->whereNull('ce.recipe_id')->where('ce.user_id', $widzId);
                })->orWhere(fn (QueryBuilder $q) => $q->whereExists(
                    fn (QueryBuilder $s) => $this->wierszTresciWidoczny($s, 'recipes', 'ce.recipe_id', $widz),
                ));
            });
    }

    /**
     * Ten sam literał co `Post::STATUS_PUBLISHED` i `Recipe::STATUS_PUBLISHED`
     * — nazwana stała zamiast magicznego stringa powtórzonego w SQL wyżej.
     */
    private const STATUS_TRESCI_OPUBLIKOWANA = 'published';

    /**
     * Partia zapisów (D-070) jest widoczna, dopóki JEDNA osoba z `data.savers`
     * jest dla odbiorcy widoczna — te same dwie reguły, co filtry sprawcy
     * w `scopeVisibleTo()` (blokada w obie strony, `User::STATUSY_UKRYWAJACE_TRESC`),
     * tylko liczone po liście, nie po `actor_id`.
     *
     * Gdy niewidoczni są WSZYSCY, wiersz znika z listy i z licznika —
     * dokładnie jak pojedyncze powiadomienie od zablokowanej osoby. Blokady
     * nie kasują wiersza: odblokowanie pokazuje go z powrotem.
     *
     * Wiersze sprzed zbiorczych zapisów nie mają `savers` — wtedy lista to
     * sam `actor_id`. Brak sprawcy (`actor_id IS NULL`) przechodzi zawsze,
     * z tego samego powodu co przy filtrach wyżej.
     */
    private function widocznaPartiaZapisow(Builder $query, User $viewer): void
    {
        $query->where(function (Builder $warunek) use ($viewer): void {
            $warunek->where('notifications.type', '!=', self::TYPE_SAVED)
                ->orWhereNull('notifications.actor_id')
                ->orWhereExists(function (QueryBuilder $sub) use ($viewer): void {
                    $sub->selectRaw('1')
                        ->fromRaw(
                            "jsonb_array_elements_text(COALESCE(notifications.data->'savers', jsonb_build_array(notifications.actor_id))) AS zapisujacy_z_partii(id)",
                        )
                        ->join('users as zapisujacy', 'zapisujacy.id', '=', DB::raw('zapisujacy_z_partii.id::uuid'))
                        ->whereNotIn('zapisujacy.status', User::STATUSY_UKRYWAJACE_TRESC)
                        ->whereNotExists(function (QueryBuilder $blokada) use ($viewer): void {
                            self::blokadaZOdbiorca($blokada, 'zapisujacy.id', (string) $viewer->getKey());
                        });
                });
        });
    }

    /**
     * `blocks` w obie strony między kolumną z osobą a odbiorcą — jedno
     * miejsce dla warunku widoczności partii i dla wyboru imienia do
     * pokazania (`zapisujacyDoPokazania()`), żeby lista i nagłówek nie
     * rozjechały się co do tego, kto jest widoczny.
     */
    private static function blokadaZOdbiorca(QueryBuilder $sub, string $kolumnaOsoby, string $odbiorcaId): void
    {
        $sub->selectRaw('1')
            ->from('blocks')
            ->where(function (QueryBuilder $warunek) use ($kolumnaOsoby, $odbiorcaId): void {
                $warunek
                    ->where(function (QueryBuilder $ja) use ($kolumnaOsoby, $odbiorcaId): void {
                        $ja->where('blocks.blocker_id', $odbiorcaId)
                            ->whereColumn('blocks.blocked_id', $kolumnaOsoby);
                    })
                    ->orWhere(function (QueryBuilder $on) use ($kolumnaOsoby, $odbiorcaId): void {
                        $on->whereColumn('blocks.blocker_id', $kolumnaOsoby)
                            ->where('blocks.blocked_id', $odbiorcaId);
                    });
            });
    }

    /**
     * Treść zbiorczego powiadomienia „X oraz Y innych osób zapisało Twój
     * przepis" — DECYZJA WŁAŚCICIELA z 20.09.2026 (issue #906).
     *
     * JEDNO ŹRÓDŁO, DWÓCH ODBIORCÓW (jak `adresDocelowy()` wyżej): treść
     * potrzebują i widok listy powiadomień, i testy, które sprawdzają
     * dokładną polską odmianę liczebnika — dwie kopie tego samego `match`
     * rozjechałyby się przy pierwszej poprawce.
     *
     * ODMIANA LICZEBNIKA JEST TU OBOWIĄZKOWA, NIE KOSMETYKĄ. „Jan oraz
     * 1 innych osób” jest błędem językowym, którego nie wolno wysłać komuś
     * w grupie 50+ — dokładnie ta grupa najdotkliwiej odczuwa niedbały
     * polski (patrz `docs/brand/COPY_STYLE.md`). Polska liczba mnoga
     * rzeczownika po liczebniku ma TRZY formy (1 / 2–4 / 5+), więc trzy
     * formy tu stoją wprost, żadna nie jest domyślna:
     *   1 → „1 inna osoba” + czasownik w liczbie pojedynczej,
     *   2–4 → „N inne osoby” + czasownik w mianowniku liczby mnogiej,
     *   5+ → „N innych osób” + czasownik zgadza się z dopełniaczem liczby
     *        mnogiej (stąd „zapisało”, nie „zapisali” — dokładnie forma,
     *        którą właściciel podał w decyzji, i ZARAZEM jedyna, która nie
     *        zdradza płci żadnej z wymienionych osób — patrz „ZDANIA BEZ
     *        ZAŁOŻENIA RODZAJU”, issue #38, w widoku listy powiadomień).
     * Czasownik zgadza się z NAJBLIŻSZYM członem („N inne(ych) osób”), nie
     * z pierwszą, wymienioną z nazwy osobą — to samo zjawisko widać
     * w podanym przez właściciela wzorcu „Jan oraz 3 innych osób ZAPISAŁO”,
     * gdzie forma nie zależy od rodzaju „Jana”.
     *
     * PIERWSZA OSOBA MOŻE ZNIKNĄĆ Z WIDOKU (pytanie właściciela, #906):
     * jeśli jest zablokowana przez autora (w obie strony, jak
     * `scopeVisibleTo()` wyżej) albo ma status z `User::STATUSY_UKRYWAJACE_TRESC`
     * (zbanowana / czeka na usunięcie), NIE pokazujemy jej imienia — mija się
     * to z resztą serwisu, gdzie taka osoba jest niedostępna jako autor.
     * Miejsce przejmuje pierwsza WIDOCZNA osoba z tej samej partii. Konto
     * USUNIĘTE (`erased`) NIE jest tu wyjątkiem — D-022 mówi wprost: tekst
     * zostaje, choć osoby nie ma, więc pokazujemy to, co zostało po
     * anonimizacji profilu (tak samo jak przy autorstwie treści).
     * Niewidoczne osoby NIE znikają z liczby „innych” — licznik, nie imię,
     * jest tu bezpieczny (D-081: „liczba, nie imiona”). Gdy niewidoczni są
     * WSZYSCY, lista i licznik w belce w ogóle tego wiersza nie pokażą
     * (`widocznaPartiaZapisow()`); gałąź „N osób zapisało” niżej zostaje
     * dla wywołań spoza `scopeVisibleTo()`, żeby nigdy nie wypisać imienia.
     */
    public function tresc(): string
    {
        if ($this->type !== self::TYPE_SAVED) {
            return '';
        }

        [$naglowek, $koniec] = $this->czesciZapisu();

        return trim("{$naglowek} {$koniec}");
    }

    /**
     * Sam nagłówek — to, co widok pokazuje pogrubione (`<strong>`), BEZ
     * tytułu przepisu ani końcówki zdania. Ten podział istnieje od dawna
     * dla każdego typu powiadomienia (patrz `resources/views/pages/notifications.blade.php`:
     * „X — ugotowane z Twojego przepisu” w `<strong>`, cytat pod spodem) —
     * zbiorcze powiadomienie o zapisie trzyma się tego samego wzorca, żeby
     * nie robić z siebie wyjątku w jednym miejscu w serwisie.
     */
    public function naglowekZapisu(): string
    {
        if ($this->type !== self::TYPE_SAVED) {
            return '';
        }

        return $this->czesciZapisu()[0];
    }

    /**
     * Reszta zdania POZA pogrubionym nagłówkiem — cytat tytułu przepisu
     * i końcówka. Osobna metoda, a nie sklejanie w widoku, z tego samego
     * powodu co `naglowekZapisu()`: jedno miejsce liczy, czy to pojedynczy
     * zapis („w swoim zeszycie.”) czy zbiorcza partia (kropka po tytule,
     * bez „w swoim zeszycie” — dokładnie kształt z decyzji właściciela).
     */
    public function resztaZapisu(): string
    {
        if ($this->type !== self::TYPE_SAVED) {
            return '';
        }

        return $this->czesciZapisu()[1];
    }

    /**
     * @return array{0: string, 1: string} [nagłówek pogrubiony, reszta zdania]
     */
    private function czesciZapisu(): array
    {
        $data = $this->data ?? [];
        $tytul = $data['recipe_title'] ?? 'przepis';
        $liczbaCalkowita = count($this->zapisujacy());
        $pierwszy = $this->zapisujacyDoPokazania();

        // NIKT Z PARTII NIE JEST DO WYMIENIENIA Z NAZWY (blokada/ban objęły
        // wszystkich, do jednego zapisu włącznie) — zostaje sama liczba,
        // bez „inne”/„innych", bo nie ma względem kogo liczyć.
        if ($pierwszy === null) {
            if ($liczbaCalkowita <= 1) {
                return ['Ktoś ma Twój przepis', "„{$tytul}” w swoim zeszycie."];
            }

            $naglowek = ucfirst($this->fraza($liczbaCalkowita, liczoneWzglemInnych: false))
                .' zapisał'.$this->koncowkaCzasownika($liczbaCalkowita)
                .' Twój przepis';

            return [$naglowek, "„{$tytul}”."];
        }

        $reszta = $liczbaCalkowita - 1;

        if ($reszta <= 0) {
            return ["{$pierwszy->displayName()} ma Twój przepis", "„{$tytul}” w swoim zeszycie."];
        }

        $naglowek = "{$pierwszy->displayName()} oraz ".$this->fraza($reszta, liczoneWzglemInnych: true)
            .' zapisał'.$this->koncowkaCzasownika($reszta)
            .' Twój przepis';

        return [$naglowek, "„{$tytul}”."];
    }

    /**
     * Identyfikatory osób z partii, w kolejności zapisu. Wiersze sprzed
     * zbiorczych zapisów nie mają `savers` — wtedy to sam `actor_id`.
     *
     * @return list<string>
     */
    private function zapisujacy(): array
    {
        return array_values(array_filter(
            $this->data['savers'] ?? [$this->actor_id],
            fn ($id) => is_string($id) && $id !== '',
        ));
    }

    /** Czy `zapisujacyDoPokazania()` już pytało bazę — `null` jest poprawnym wynikiem. */
    private bool $zapisujacyDoPokazaniaPoliczony = false;

    private ?User $zapisujacyDoPokazania = null;

    /**
     * Pierwsza osoba z partii, którą odbiorca może zobaczyć z imienia
     * i awatarem — albo `null`, gdy nie może żadnej (D-070).
     *
     * JEDNO ZAPYTANIE, NIEZALEŻNIE OD WIELKOŚCI PARTII (przegląd PR #1213).
     * Wcześniejsza wersja ładowała WSZYSTKICH zapisujących i dla każdego
     * pytała osobno o blokadę — partia 200 osób to było ~200 zapytań na
     * jedną pozycję listy, a widok woła nagłówek i resztę zdania osobno.
     * Imię potrzebne jest jedno, więc blokada idzie do `NOT EXISTS`,
     * kolejność zapisu do `array_position`, a wynik do `LIMIT 1`. Wynik
     * jest zapamiętany na tym obiekcie.
     *
     * Gdy tą osobą jest `actor_id` z załadowaną już relacją (lista ładuje
     * `actor.profile.avatar` hurtem), oddajemy TAMTEN obiekt — awatar nie
     * kosztuje wtedy ani jednego zapytania więcej.
     */
    public function zapisujacyDoPokazania(): ?User
    {
        if ($this->zapisujacyDoPokazaniaPoliczony) {
            return $this->zapisujacyDoPokazania;
        }

        $this->zapisujacyDoPokazaniaPoliczony = true;
        $savers = $this->zapisujacy();

        if ($savers === []) {
            return null;
        }

        $odbiorcaId = (string) $this->user_id;

        $pierwszy = User::query()
            ->whereIn('users.id', $savers)
            ->whereNotIn('users.status', User::STATUSY_UKRYWAJACE_TRESC)
            ->whereNotExists(function (QueryBuilder $blokada) use ($odbiorcaId): void {
                self::blokadaZOdbiorca($blokada, 'users.id', $odbiorcaId);
            })
            ->orderByRaw('array_position(?::uuid[], users.id)', ['{'.implode(',', $savers).'}'])
            ->first();

        if ($pierwszy !== null && $this->relationLoaded('actor') && $this->actor?->is($pierwszy)) {
            $pierwszy = $this->actor;
        }

        return $this->zapisujacyDoPokazania = $pierwszy;
    }

    /**
     * „1 inna osoba” / „N inne osoby” / „N innych osób” — trzy formy
     * polskiego liczebnika, patrz `tresc()` wyżej.
     */
    private function fraza(int $n, bool $liczoneWzglemInnych): string
    {
        if (! $liczoneWzglemInnych) {
            return match (true) {
                $n === 1 => '1 osoba',
                $this->wymagaFormyRzeczownikaKrotkiej($n) => "{$n} osoby",
                default => "{$n} osób",
            };
        }

        return match (true) {
            $n === 1 => '1 inna osoba',
            $this->wymagaFormyRzeczownikaKrotkiej($n) => "{$n} inne osoby",
            default => "{$n} innych osób",
        };
    }

    /**
     * Czasownik po liczebniku 1 jest w liczbie pojedynczej („zapisała”),
     * po 2–4 w mianowniku liczby mnogiej („zapisały”), a od 5 w górę
     * zgadza się z dopełniaczem liczby mnogiej — stąd „zapisało”
     * (nijaki, bezrodzajowy — patrz `tresc()` wyżej).
     */
    private function koncowkaCzasownika(int $inni): string
    {
        return match (true) {
            $inni === 1 => 'a',
            $this->wymagaFormyRzeczownikaKrotkiej($inni) => 'y',
            default => 'o',
        };
    }

    /**
     * Forma "2–4" polskiego liczebnika: liczby kończące się na 2, 3 lub 4,
     * Z WYJĄTKIEM 12–14 (te zawsze biorą formę "5+" — "12 osób", nie
     * "12 osoby"). Reguła jest ogólna, nie tylko dla małych liczb z
     * przykładów właściciela — partia zapisów może urosnąć znacznie
     * powyżej czterech osób.
     */
    private function wymagaFormyRzeczownikaKrotkiej(int $n): bool
    {
        $ostatnia = $n % 10;
        $dwieOstatnie = $n % 100;

        return in_array($ostatnia, [2, 3, 4], true) && ! in_array($dwieOstatnie, [12, 13, 14], true);
    }
}
