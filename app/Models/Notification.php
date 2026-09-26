<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\CelPowiadomienia;
use App\Domain\Notifications\WidocznoscPowiadomien;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
     * „Dziś urodziny: Ania" (issue #1755, etap d). Aktorem jest solenizant.
     * Powstaje TYLKO, gdy solenizant sam włączył
     * `users.birthday_visible_to_followers`, najwyżej raz na dobę na parę
     * (odbiorca, solenizant) i w dobowym limicie na odbiorcę
     * (`kuking.urodziny.przypomnienia_na_odbiorce_dziennie`). Nie jest wpisem
     * w feedzie — feed obserwowanych zostaje chronologiczny, bez wstawek.
     */
    public const TYPE_BIRTHDAY = 'birthday.today';

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
     * patrz `App\Domain\Compliance\TerminOchronyOdwolawczej::dla()` i
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
     * `TerminOchronyOdwolawczej::dla()` zwracałoby dla niego `null` w każdym
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

    /**
     * AKTUALNY slug przepisu z `data.recipe_id` (issue #1034).
     * `false` = jeszcze nie sprawdzano, `null` = przepisu już nie ma
     * (miękko usunięty albo nigdy nie istniał). Lista ustawia to jednym
     * zapytaniem dla całej strony, tak samo jak `$wykonanieIstnieje`.
     */
    private string|false|null $slugPrzepisu = false;

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

    /**
     * Powiadomienie o zapisaniu przepisu, którego już nie ma (issue #1034).
     * `RecipeController::destroy()` usuwa przepis miękko, a `recipe_id`
     * w `data` nie jest kluczem obcym — powiadomienie zostaje jako
     * prawdziwe zdarzenie, tylko nie może obiecywać „Zobacz".
     */
    public function przepisUsuniety(): bool
    {
        return $this->type === self::TYPE_SAVED && $this->slugZapisanegoPrzepisu() === null;
    }

    /** Wynik zbiorczego sprawdzenia z listy — patrz `$slugPrzepisu`. */
    public function zapamietajSlugPrzepisu(?string $slug): void
    {
        $this->slugPrzepisu = $slug;
    }

    /**
     * Aktualny slug zapisanego przepisu albo `null`, gdy przepisu nie ma.
     * Publiczne, bo cel „Zobacz” liczy `CelPowiadomienia` (issue #1687).
     */
    public function slugZapisanegoPrzepisu(): ?string
    {
        if ($this->slugPrzepisu !== false) {
            return $this->slugPrzepisu;
        }

        $id = $this->data['recipe_id'] ?? null;

        // Nie-UUID nie trafi w żaden przepis (a PostgreSQL odrzuciłby je
        // błędem rzutowania). `Recipe` ma `SoftDeletes`, więc usunięty
        // przepis nie wraca tym zapytaniem.
        $slug = is_string($id) && Str::isUuid($id)
            ? Recipe::query()->whereKey($id)->value('slug')
            : null;

        return $this->slugPrzepisu = is_string($slug) ? $slug : null;
    }

    /** Wynik zbiorczego sprawdzenia z listy — patrz `$wykonanieIstnieje`. */
    public function zapamietajIstnienieWykonania(bool $istnieje): void
    {
        $this->wykonanieIstnieje = $istnieje;
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
     * `$zTrescia = false` pomija WYŁĄCZNIE ostatni warunek (czy komentarz
     * i treść nad nim są jeszcze dostępne) — issue #759: `open()` odróżnia
     * nim „komentarz zniknął między listą a kliknięciem" (uczciwy
     * komunikat) od wiersza ukrytego blokadą (dalej 404). Lista, licznik
     * i eksport wołają zawsze pełny filtr.
     *
     * @param  Builder<Notification>  $query
     * @return Builder<Notification>
     */
    public function scopeVisibleTo(Builder $query, User $viewer, bool $zTrescia = true): Builder
    {
        return app(WidocznoscPowiadomien::class)->zawez($query, $viewer, $zTrescia);
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
     * (`WidocznoscPowiadomien::widocznaPartiaZapisow()`); gałąź „N osób zapisało” niżej zostaje
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
                WidocznoscPowiadomien::blokadaZOdbiorca($blokada, 'users.id', $odbiorcaId);
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
