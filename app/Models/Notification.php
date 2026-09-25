<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\WidocznoscPowiadomien;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    /** Ile znaków komentarza niesie powiadomienie (issue #758, D-229). */
    public const DLUGOSC_WYCINKA_KOMENTARZA = 120;

    /** Typy, których wycinek JEST treścią komentarza — i tylko te. */
    public const TYPY_Z_WYCINKIEM_KOMENTARZA = [self::TYPE_COMMENT, self::TYPE_REPLY];

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
     * AKTUALNE wycinki komentarzy dla podanych powiadomień — JEDNYM zapytaniem.
     *
     * DECYZJA WŁAŚCICIELA Z 20 WRZEŚNIA 2026 (issue #758, D-229): wycinek
     * treści komentarza liczy się PRZY WYŚWIETLANIU, z aktualnej treści.
     * Jedno źródło prawdy — nie zamrożona kopia w `notifications.data`.
     * Do tej zmiany `PublishComment` wpisywał do `data.excerpt` 120 znaków
     * z chwili publikacji i nikt tego nigdy nie odświeżał: ktoś pisał
     * „dodaję dwie łyżki masła", poprawiał w oknie 15 minut na „łyżeczki",
     * a powiadomienie — i paczka RODO na zawsze — dalej mówiło „łyżki".
     *
     * TA METODA NIE JEST FURTKĄ DOOKOŁA `scopeVisibleTo()`.
     * Warunki `status`/`deleted_at`/`body_removed_at` stoją tu drugi raz
     * ŚWIADOMIE, choć bramka z #757 odcina takie powiadomienia już przy
     * odczycie listy. Żywy wycinek czyta `comments` bezpośrednio, więc gdyby
     * kiedykolwiek zawołał go ekran BEZ `visibleTo()`, brak tych trzech
     * warunków przywróciłby do widoku treść, którą usunięcie ukryło —
     * na ekranie i w paczce RODO naraz. To jest najgroźniejsza regresja tej
     * zmiany i dlatego ma własny test
     * (`PowiadomienieSledziTrescKomentarzaTest`).
     *
     * WIDOCZNOŚCI TRESCI NADRZĘDNEJ tu NIE liczymy — to robi `visibleTo()`
     * dla konkretnego odbiorcy i to jest jedyne miejsce, które zna odbiorcę.
     * Brak wiersza w wyniku znaczy „bez wycinka", NIGDY „weź stary z `data`":
     * sięgnięcie po zamrożoną kopię jako zapasowy plan byłoby dokładnie tym
     * wyciekiem, przed którym broni warunek wyżej.
     *
     * KOSZT (D-196). Strona mieści 30 powiadomień, a eksport nie ma górnej
     * granicy — wycinek liczony po jednym komentarzu na wiersz dokładałby
     * jedno zapytanie na wiersz. Wzór jest ten sam co
     * `NotificationController::decyzje()`: zbieramy identyfikatory z całej
     * strony i pytamy raz.
     *
     * @param  iterable<Notification>  $powiadomienia
     * @return array<string, string> identyfikator powiadomienia → wycinek
     */
    public static function zyweWycinkiKomentarzy(iterable $powiadomienia): array
    {
        /** @var array<string, list<string>> $poKomentarzu */
        $poKomentarzu = [];

        foreach ($powiadomienia as $powiadomienie) {
            if (! in_array($powiadomienie->type, self::TYPY_Z_WYCINKIEM_KOMENTARZA, true)) {
                continue;
            }

            $komentarzId = ($powiadomienie->data ?? [])['comment_id'] ?? null;

            if (is_string($komentarzId) && $komentarzId !== '') {
                // Jeden komentarz potrafi mieć DWA powiadomienia (odpowiedź
                // w cudzym wątku idzie i do autora treści, i do autora
                // komentarza-rodzica), więc mapa jest jeden-do-wielu.
                $poKomentarzu[$komentarzId][] = (string) $powiadomienie->getKey();
            }
        }

        if ($poKomentarzu === []) {
            return [];
        }

        $wiersze = Comment::query()
            ->whereIn('id', array_keys($poKomentarzu))
            ->where('status', Comment::STATUS_PUBLISHED)
            ->whereNull('deleted_at')
            ->whereNull('body_removed_at')
            ->get(['id', 'body']);

        $wycinki = [];

        foreach ($wiersze as $komentarz) {
            $wycinek = mb_substr((string) $komentarz->body, 0, self::DLUGOSC_WYCINKA_KOMENTARZA);

            foreach ($poKomentarzu[(string) $komentarz->getKey()] ?? [] as $idPowiadomienia) {
                $wycinki[$idPowiadomienia] = $wycinek;
            }
        }

        return $wycinki;
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
        $viewer = $this->user;

        if ($viewer === null) {
            return self::commentFallback($data);
        }

        return self::destinationUrls([$this], $viewer)[(string) $this->getKey()];
    }

    private static function commentFallback(array $data): ?string
    {
        return is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null;
    }

    /**
     * Adresy całej strony, z jednym odczytem komentarzy (#833).
     * Odbiorca i jego widoczność obowiązują tylko podczas tego wywołania:
     * nie zapisujemy numerów stron ani nie buforujemy ich między żądaniami.
     *
     * @param  iterable<Notification>  $notifications  powiadomienia jednego odbiorcy
     * @return array<string, string|null>
     */
    public static function destinationUrls(iterable $notifications, User $viewer): array
    {
        $urls = [];
        $byComment = [];

        foreach ($notifications as $notification) {
            $id = (string) $notification->getKey();
            if (! in_array($notification->type, self::TYPY_Z_WYCINKIEM_KOMENTARZA, true)) {
                $urls[$id] = $notification->adresDocelowy();

                continue;
            }

            $data = $notification->data ?? [];
            $urls[$id] = self::commentFallback($data);
            $commentId = $data['comment_id'] ?? null;
            if ((string) $notification->user_id === (string) $viewer->getKey()
                && is_string($commentId) && $commentId !== '') {
                $byComment[$commentId][] = $id;
            }
        }

        if ($byComment === []) {
            return $urls;
        }

        // Ten sam zakres co w relacjach comments() i na ekranie rozmowy.
        // Korelacja po korzeniu nie pobiera całych rozmów do pamięci PHP.
        $roots = Comment::query()->whereNull('comments.parent_id')->widoczneDla($viewer);
        $preceding = (clone $roots)->selectRaw('count(*)')
            ->where(function (Builder $subject): void {
                $subject->whereColumn('comments.post_id', 'root.post_id')
                    ->orWhereColumn('comments.recipe_id', 'root.recipe_id')
                    ->orWhereColumn('comments.cooked_event_id', 'root.cooked_event_id');
            })
            ->whereRaw('(comments.created_at, comments.id) < (root.created_at, root.id)');

        $comments = Comment::query()
            ->join('comments as root', function ($join): void {
                $join->whereRaw('root.id = coalesce(comments.parent_id, comments.id)');
            })
            ->leftJoin('posts', 'posts.id', '=', 'comments.post_id')
            ->leftJoin('recipes', 'recipes.id', '=', 'comments.recipe_id')
            ->leftJoin('cooked_events', 'cooked_events.id', '=', 'comments.cooked_event_id')
            ->whereIn('comments.id', array_keys($byComment))
            ->whereIn('root.id', (clone $roots)->select('comments.id'))
            ->where(function (Builder $subject): void {
                $subject->whereColumn('comments.post_id', 'root.post_id')
                    ->orWhereColumn('comments.recipe_id', 'root.recipe_id')
                    ->orWhereColumn('comments.cooked_event_id', 'root.cooked_event_id');
            })
            ->where(function (Builder $subject): void {
                $subject->where(fn (Builder $post) => $post->whereNotNull('posts.id')->whereNull('posts.deleted_at'))
                    ->orWhere(fn (Builder $recipe) => $recipe->whereNotNull('recipes.id')->whereNull('recipes.deleted_at'))
                    ->orWhereNotNull('cooked_events.id');
            })
            ->select(['comments.id', 'comments.post_id', 'comments.recipe_id', 'comments.cooked_event_id', 'posts.kind', 'recipes.slug'])
            ->selectSub($preceding, 'preceding_count')
            ->get();

        $pageSize = (int) config('kuking.comments.page_size');
        foreach ($comments as $comment) {
            if ($comment->cooked_event_id !== null) {
                $subject = (new CookedEvent)->forceFill(['id' => $comment->cooked_event_id]);
                $page = 1;
            } else {
                if ($pageSize < 1) {
                    continue;
                }
                $subject = $comment->post_id !== null
                    ? (new Post)->forceFill(['id' => $comment->post_id, 'kind' => $comment->kind])
                    : (new Recipe)->forceFill(['slug' => $comment->slug]);
                $page = intdiv((int) $comment->preceding_count, $pageSize) + 1;
            }

            $url = $subject->url();
            if ($page > 1) {
                $url .= (str_contains($url, '?') ? '&' : '?').'komentarze='.$page;
            }
            $url .= '#komentarz-'.$comment->getKey();
            foreach ($byComment[(string) $comment->getKey()] as $id) {
                $urls[$id] = $url;
            }
        }

        return $urls;
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
     * Powiadomienia, które ta osoba ma prawo zobaczyć.
     *
     * Tylko wejście: reguły (blokada, sprawca, typ służbowy, widoczność
     * treści pod komentarzem) żyją w `App\Domain\Notifications\WidocznoscPowiadomien`,
     * a widoczność samej treści — w `App\Domain\Widocznosc\WidocznoscTresciSql`
     * (issue #1687). Model powiadomienia nie powtarza już warunków Policy
     * innych modułów.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return app(WidocznoscPowiadomien::class)->zawez($query, $viewer);
    }
}
