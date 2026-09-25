<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\CelPowiadomienia;
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
     * Dokąd prowadzi przycisk „Zobacz" — albo `null`, gdy nie ma dokąd.
     *
     * Tylko wejście: reguły żyją w `App\Domain\Notifications\CelPowiadomienia`
     * (issue #1687, etap 2). Z tego samego źródła liczą adres widok listy
     * (`CelPowiadomienia::adresy()`) i kontroler otwarcia.
     */
    public function adresDocelowy(): ?string
    {
        return app(CelPowiadomienia::class)->adres($this);
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
