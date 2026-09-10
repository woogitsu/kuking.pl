<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Moderation\PriorytetSprawy;
use App\Domain\Moderation\Przeglad;
use App\Support\NumerSprawy;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_OPEN = 'open';

    /**
     * Sprawa WZIĘTA DO PRZEGLĄDU przez człowieka (D-070).
     *
     * Do 10 września 2026 ten status istniał w schemacie i nie nadawała go
     * ANI JEDNA linia kodu: sprawa szła z `open` prosto do
     * `resolved`/`rejected`, więc zakładka „W trakcie" w panelu była stale
     * pusta, a licznik zawsze pokazywał zero. Dziś nadaje go
     * `wezDoPrzegladu()` i jest to jedyna droga — razem z osobą i czasem,
     * bo bez czasu sprawa nie umiałaby wrócić do kolejki
     * (`App\Domain\Moderation\Przeglad`).
     */
    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Stany, w których sprawa jeszcze CZEKA NA CZŁOWIEKA — jedno miejsce
     * dla siedmiu zapytań, które o to pytały.
     *
     * Do dziś ten zbiór stał w kodzie ośmiokrotnie, jako
     * `[STATUS_OPEN, STATUS_TRIAGE, STATUS_REVIEWING]` przepisane z palca
     * w każdym z ośmiu miejsc (kolejka, liczniki panelu, kolejka automatu,
     * dwie komendy raportowe, deduplikacja zgłoszeń, `isOpen()`). Usunięcie
     * `triage` — statusu, którego nic nie nadawało (D-070) — wymagało
     * poprawki w każdym z nich osobno i pokazało, ile taki rozsyp kosztuje:
     * jedno przeoczone miejsce znaczyłoby kolejkę liczącą inny zbiór spraw
     * niż lista, którą pokazuje.
     *
     * `triage` nie ma tu wpisu i nie jest to przeoczenie — patrz migracja
     * `2026_09_10_400000_priorytet_w_kolejce_zgloszen`, gdzie `CHECK`
     * przestaje tę wartość dopuszczać.
     *
     * @var list<string>
     */
    public const STANY_OTWARTE = [
        self::STATUS_OPEN,
        self::STATUS_REVIEWING,
    ];

    /**
     * Powody zgłoszenia w języku, który rozumie zgłaszający.
     * Klucz idzie do bazy, wartość na ekran.
     */
    public const REASONS = [
        'spam' => 'Spam albo reklama',
        'scam' => 'Oszustwo lub podejrzany link',
        'impersonation' => 'Ktoś podaje się za inną osobę',
        'harassment' => 'Obraża lub nęka kogoś',
        'hate' => 'Mowa nienawiści',
        'sexual' => 'Treść nieprzyzwoita',
        'personal_data' => 'Ujawnia czyjeś dane osobowe',
        'copyright' => 'To nie jest treść tej osoby',
        'dangerous_advice' => 'Niebezpieczna porada kulinarna',
        'minor' => 'Dotyczy dziecka',
        'other' => 'Coś innego',
    ];

    /**
     * Czego dotyczyło zgłoszenie — po polsku, dla ZGŁASZAJĄCEGO (issue #10).
     *
     * Karta sprawy na `/zgloszenia/{report}` musi przypomnieć człowiekowi,
     * co właściwie zgłosił; sam `target_type` („cooked_event") tego nie robi.
     * Kolejka moderatora pokazuje surową wartość dalej i to jest w porządku —
     * tam czyta ją osoba, która zna schemat bazy.
     *
     * `unknown` to adres, którego nie umieliśmy rozpoznać przy zgłoszeniu
     * prawnym (patrz `ZglosNielegalnaTresc`). Na tej liście prawie nigdy nie
     * wystąpi, bo droga prawna nie ma `reporter_id` — zostaje, żeby ekran
     * nigdy nie pokazał pustego miejsca zamiast zdania.
     *
     * @var array<string, string>
     */
    public const TARGET_LABELS = [
        'post' => 'wpis',
        'recipe' => 'przepis',
        'comment' => 'komentarz',
        'cooked_event' => 'wykonanie przepisu',
        'user' => 'profil osoby',
        'media' => 'zdjęcie',
        'unknown' => 'strona spod podanego adresu',
    ];

    /**
     * Zgłoszenie społecznościowe: „to jest spam", „to jest chamskie".
     * Nasze zasady, nasza kolejka, może wymagać zalogowania.
     */
    public const SOURCE_COMMUNITY = 'community';

    /**
     * Zgłoszenie nielegalnej treści w rozumieniu DSA art. 16.
     *
     * Inna rzecz niż wyżej i dlatego ma osobną nazwę. Ten mechanizm MUSI być
     * dostępny dla każdej osoby i każdego podmiotu, także bez konta — nie
     * wolno kazać komuś zakładać konta w serwisie kulinarnym po to, żeby mógł
     * zgłosić przestępstwo. Niesie też własne obowiązki: potwierdzenie odbioru
     * i powiadomienie o decyzji z pouczeniem o środkach odwoławczych.
     */
    public const SOURCE_LEGAL_NOTICE = 'legal_notice';

    /**
     * Oznaczenie DO PRZEGLĄDU postawione przez automat (D-052).
     *
     * TO NIE JEST ZGŁOSZENIE i dlatego ma własne źródło, a nie `community`
     * z pustym `reporter_id`. Różnica jest praktyczna, nie kosmetyczna:
     *
     *  - nikt tu niczego nie zgłosił, więc nie ma komu potwierdzić odbioru
     *    ani przekazać decyzji (DSA art. 16 ust. 4 i 5 nie ma zastosowania);
     *  - powstaje najwyżej RAZ na treść — pilnuje tego indeks
     *    `reports_jeden_automat_na_tresc`, dzięki czemu „to nic takiego"
     *    zamyka sprawę na zawsze;
     *  - ma własny ekran (`/admin/sygnaly`), żeby maszynowe podejrzenia nigdy
     *    nie zasypały kolejki rzeczy zgłoszonych przez ludzi.
     *
     * Czego oznaczenie NIE robi: nie ukrywa treści, nie ogranicza jej zasięgu,
     * nie powiadamia autora i nie zmienia niczego, co widzi czytelnik
     * (`docs/INSPIRATION_DECISIONS.md` poz. 3.6, 3.10, 3.14, 3.16).
     */
    public const SOURCE_AUTOMAT = 'automat';

    /**
     * Powody, które wpisuje AUTOMAT — osobno od `REASONS`.
     *
     * DLACZEGO OSOBNA LISTA, A NIE TRZY POZYCJE W `REASONS`
     * Tamta lista jest jednocześnie treścią pola wyboru na obu formularzach
     * zgłoszenia (`ReportController`, `ZgloszenieNielegalnejTresciController`)
     * ORAZ regułą walidacji `in:`. Dopisanie tam „Automat: powtórzona treść"
     * pokazałoby tę pozycję ludziom do wyboru, a jednocześnie pozwoliłoby
     * podszyć się pod automat, wysyłając ten kod z formularza.
     *
     * Klucz mówi, KTÓRY sygnał zdecydował o zakwalifikowaniu sprawy (przy
     * kilku naraz — najcięższy, patrz `WAGA`). Wszystkie powody, po polsku
     * i pełnym zdaniem, idą do `details`.
     *
     * @var array<string, string>
     */
    public const REASONS_AUTOMAT = [
        'automat_model' => 'Automat: model wskazał treść do przejrzenia',
        'automat_wzorzec' => 'Automat: znany wzorzec spamu',
        'automat_odnosnik' => 'Automat: odnośnik zewnętrzny u świeżego konta',
        'automat_powtorzenie' => 'Automat: powtórzona treść',
    ];

    /**
     * Kolejność przeglądania kolejki automatu: im większa liczba, tym pilniej.
     *
     * JEDEN MODERATOR PRZY TYSIĄCU KONT NIE PRZECZYTA WSZYSTKIEGO, więc
     * kolejność nie jest ozdobą — jest decyzją o tym, czego ten człowiek nie
     * zdąży przejrzeć. Najwyżej stoi wzorzec spamu: przy trafieniu szkoda
     * jest największa (oszustwo, wyłudzenie), a fałszywy alarm najrzadszy.
     * Najniżej powtórzenie — bywa całkiem niewinne, a szkoda z niego to
     * najwyżej ten sam wpis dwa razy w czyimś feedzie.
     *
     * Wartość idzie do `ORDER BY CASE`, a nie do kolumny: to jest reguła
     * produktu, nie fakt o wierszu, i ma się zmieniać razem z kodem, nie
     * migracją danych.
     *
     * @var array<string, int>
     */
    public const WAGA = [
        // Ocena modelem stoi najwyżej, bo dotyczy INNEJ KLASY treści niż
        // pozostałe trzy: nienawiści, przemocy, treści seksualnych
        // i samookaleczenia (D-055). Najgorszy możliwy spam to zmarnowana
        // minuta czytelnika; najgorsze trafienie modelu to sprawa, o której
        // trzeba zawiadomić organy.
        'automat_model' => 4,
        'automat_wzorzec' => 3,
        'automat_odnosnik' => 2,
        'automat_powtorzenie' => 1,
    ];

    protected $fillable = [
        'reporter_id',
        // Autor OZNACZONEJ treści — wypełniany wyłącznie przy `source =
        // 'automat'` i wyłącznie przez `OznaczDoPrzegladu`. Nie przychodzi
        // z żadnego formularza; stoi tu, bo akcja tworzy wiersz jednym
        // `create()`, a nie dlatego, że wolno go przysłać z zewnątrz.
        'autor_tresci_id',
        // Tożsamość jednego wysłania formularza zgłoszenia BEZ KONTA (DSA
        // art. 16 ust. 2 lit. c). Częściowy indeks UNIQUE
        // `reports_one_per_klucz_wyslania` sprawia, że podwójne kliknięcie
        // nie zakłada drugiej sprawy z własnym terminem odpowiedzi.
        'klucz_wyslania',
        'source',
        'notifier_name',
        'notifier_email',
        'target_type',
        'target_id',
        'target_url',
        'reason',
        'details',
        'illegality_explanation',
        'good_faith_at',
        'status',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * Numer sprawy nadaje MODEL, nie kontroler i nie akcja.
     *
     * Zgłoszenie powstaje kilkoma drogami: formularz społecznościowy,
     * formularz prawny bez konta, seeder, fabryka w teście. Gdyby numer
     * nadawało któreś z tych miejsc, wystarczyłoby dopisać piąte, żeby
     * powstał wiersz bez numeru — a kolumna jest `NOT NULL`, więc taki zapis
     * padłby dopiero w bazie i dopiero na produkcji.
     *
     * `numer_sprawy` NIE jest w `$fillable` i to jest celowe: to jest
     * tożsamość sprawy nadana przez serwer, nie dana od człowieka
     * (`AGENTS.md` §7, ta sama zasada co dla `status` i `role` użytkownika).
     * Wartość podstawioną wprost (migracja, test sprawdzający unikalność)
     * zostawiamy — hak nadpisuje tylko brak.
     */
    protected static function booted(): void
    {
        static::creating(function (self $zgloszenie): void {
            if (! is_string($zgloszenie->numer_sprawy) || $zgloszenie->numer_sprawy === '') {
                $zgloszenie->numer_sprawy = NumerSprawy::wygeneruj();
            }

            /*
             * PRIORYTET NADAJE MODEL, Z POWODU ZGŁOSZENIA (D-070).
             *
             * Ten sam wybór i to samo uzasadnienie co przy `numer_sprawy`
             * wyżej: sprawa powstaje pięcioma drogami (formularz
             * społecznościowy, formularz prawny bez konta, automat, seeder,
             * fabryka w teście), a szósta droga bez priorytetu znaczyłaby
             * zgłoszenie, którego kolejka nie umie ustawić — czyli
             * najprawdopodobniej takie, które utknie na końcu. Kolumna ma
             * `DEFAULT`, więc taki wiersz nie padłby w bazie i nikt by tego
             * nie zauważył.
             *
             * WARTOŚĆ PODSTAWIONĄ WPROST ZOSTAWIAMY. Hak uzupełnia tylko
             * brak: migracja, test sprawdzający porządek kolejki i seeder
             * demonstracyjny mają prawo powiedzieć wprost, jaki priorytet
             * ma powstać, i nie po to, żeby obejść mapowanie, a po to, żeby
             * zbudować konkretną sytuację.
             *
             * `priorytet` NIE JEST w `$fillable` — to jest wartość nadana
             * przez serwer, nie dana od człowieka (`AGENTS.md` §7, ta sama
             * zasada co dla `numer_sprawy` oraz dla `status` i `role`
             * użytkownika). Zmiana ręczna idzie jawną, nazwaną metodą
             * `zmienPriorytet()`.
             */
            if ($zgloszenie->priorytet === null) {
                $zgloszenie->priorytet = PriorytetSprawy::dlaPowodu($zgloszenie->reason);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'good_faith_at' => 'datetime',
            'receipt_sent_at' => 'datetime',
            'decision_sent_at' => 'datetime',
            'priorytet' => 'integer',
            'priorytet_zmieniony_o' => 'datetime',
            'przeglad_zaczety_o' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Autor oznaczonej treści — wypełniony tylko przy `source = 'automat'`.
     *
     * `null` znaczy „to nie jest oznaczenie automatu" ALBO „konto zostało
     * skasowane" (`nullOnDelete`). Kolejka automatu obsługuje oba przypadki
     * jednakowo: grupa bez autora zostaje pozycją do przejrzenia, a nie
     * pustym miejscem na ekranie.
     */
    public function autorTresci(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_tresci_id');
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason]
            ?? self::REASONS_AUTOMAT[$this->reason]
            ?? $this->reason;
    }

    /** Czy tę pozycję postawił automat, a nie człowiek (D-052). */
    public function wykrylAutomat(): bool
    {
        return $this->source === self::SOURCE_AUTOMAT;
    }

    public function targetLabel(): string
    {
        return self::TARGET_LABELS[$this->target_type] ?? self::TARGET_LABELS['unknown'];
    }

    /**
     * Czy sprawa jest już zamknięta — z punktu widzenia ZGŁASZAJĄCEGO.
     *
     * Odwrotność `isOpen()`, napisana wprost zamiast `! isOpen()` w widoku:
     * statusów jest pięć, a nie dwa, i przy dopisaniu szóstego chcemy jedno
     * miejsce do poprawienia, nie negację rozsianą po Blade.
     */
    public function jestRozstrzygniete(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_REJECTED], true);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::STANY_OTWARTE, true);
    }

    public function jestZgloszeniemPrawnym(): bool
    {
        return $this->source === self::SOURCE_LEGAL_NOTICE;
    }

    /**
     * Moderator, który ręcznie zmienił priorytet sprawy (D-070).
     *
     * `null` znaczy „priorytet jest ten z mapowania" ALBO „konto moderatora
     * skasowano" (`nullOnDelete`). Rozróżnia je `priorytet_zmieniony_o`:
     * ono zostaje, bo nie ma klucza obcego. Dlatego karta sprawy pyta
     * o CZAS, gdy chce wiedzieć, czy zmiana była, a o OSOBĘ tylko wtedy,
     * gdy chce ją nazwać.
     */
    public function priorytetZmienilo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'priorytet_zmieniony_przez');
    }

    /** Moderator, który wziął sprawę do przeglądu (D-070). */
    public function przegladajacy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'przeglad_zaczety_przez');
    }

    /**
     * SPRAWY, KTÓRE NADAL CZEKAJĄ NA CZŁOWIEKA.
     *
     * Nie to samo co `status = 'open'` i nie to samo co `STANY_OTWARTE`:
     * sprawa wzięta do przeglądu i NIE DOMKNIĘTA w ciągu ośmiu godzin
     * wraca tu z powrotem. Pełne uzasadnienie tego progu i tego, dlaczego
     * powrót robi warunek w zapytaniu, a nie zadanie w tle:
     * `App\Domain\Moderation\Przeglad`.
     *
     * To jest zapytanie, na którym stoi oznaczenie „P0 nieprzejrzane" —
     * a ono musi być NIEMOŻLIWE do wyciszenia bez podjęcia sprawy. Gdyby
     * liczyło samo `status = 'open'`, „wziąłem do przeglądu" gasiłoby alarm
     * na zawsze jednym kliknięciem.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNieprzejrzane($query): void
    {
        $query->where(function ($warunek): void {
            $warunek
                ->where('status', self::STATUS_OPEN)
                ->orWhere(function ($wygasle): void {
                    $wygasle
                        ->where('status', self::STATUS_REVIEWING)
                        ->where('przeglad_zaczety_o', '<=', Przeglad::granica());
                });
        });
    }

    /** Nazwa priorytetu na plakietkę: „P0". */
    public function nazwaPriorytetu(): string
    {
        return PriorytetSprawy::nazwa((int) $this->priorytet);
    }

    /** Pełna etykieta priorytetu: „P0 — krytyczny". Skrót nigdy nie stoi sam (`AGENTS.md` §5). */
    public function etykietaPriorytetu(): string
    {
        return PriorytetSprawy::etykieta((int) $this->priorytet);
    }

    /** Czy to sprawa krytyczna — CSAM, groźba życia, aktywny doxxing. */
    public function jestKrytyczna(): bool
    {
        return (int) $this->priorytet === PriorytetSprawy::P0;
    }

    /** Czy priorytet ustawił człowiek, a nie mapowanie powodu. */
    public function priorytetZmienionyRecznie(): bool
    {
        return $this->priorytet_zmieniony_o !== null;
    }

    /**
     * Priorytet, jaki ta sprawa dostałaby z samego powodu zgłoszenia.
     *
     * Nie jest kolumną i celowo: da się go policzyć w każdej chwili,
     * a druga kolumna z tą samą wiedzą to druga okazja, żeby się
     * rozjechały. Karta sprawy pokazuje go obok bieżącego, żeby było
     * widać, CO moderator zmienił, a nie tylko że coś zmienił.
     */
    public function priorytetZPowodu(): int
    {
        return PriorytetSprawy::dlaPowodu($this->reason);
    }

    /**
     * WZIĘCIE SPRAWY DO PRZEGLĄDU (D-070).
     *
     * Jawna, nazwana metoda, a nie `update(['status' => …])` w kontrolerze —
     * ta sama zasada, którą `AGENTS.md` §7 stawia dla `User::suspend()`:
     * zmiana stanu sprawy ma jedno wejście, więc nie da się jej obejść,
     * dodając drugi endpoint. Osoba i czas idą razem ze statusem, bo bez
     * czasu sprawa nie umiałaby wrócić do kolejki, a `CHECK`
     * `reports_przeglad_check` i tak by takiego wiersza nie przyjął.
     *
     * Zwraca `false`, gdy sprawy nie da się wziąć: została już rozpatrzona
     * albo ktoś ją właśnie przegląda. Kontroler zamienia to na zdanie
     * po polsku, a nie na wyjątek — dla moderatora to jest normalna
     * sytuacja („ktoś był szybszy"), nie awaria.
     */
    public function wezDoPrzegladu(User $moderator): bool
    {
        if ($this->status !== self::STATUS_OPEN) {
            return false;
        }

        return $this->forceFill([
            'status' => self::STATUS_REVIEWING,
            'przeglad_zaczety_przez' => $moderator->getKey(),
            'przeglad_zaczety_o' => now(),
        ])->save();
    }

    /**
     * ODDANIE SPRAWY DO KOLEJKI — bez decyzji.
     *
     * Potrzebne, bo „wziąłem do przeglądu" nie może być pułapką: moderator,
     * który otworzył sprawę i widzi, że nie da jej dziś domknąć (trzeba
     * czekać na odpowiedź prawnika, na tłumaczenie, na kontakt z organami),
     * ma móc ją oddać OD RAZU, a nie czekać osiem godzin, aż wróci sama.
     *
     * Ślad, kto ją brał, ZOSTAJE (`przeglad_zaczety_*` nie czyścimy):
     * przy dwóch osobach to jest wiedza o tym, kogo zapytać, a przy jednej —
     * o tym, że ta sprawa już raz komuś nie wyszła.
     */
    public function oddajDoKolejki(): bool
    {
        if ($this->status !== self::STATUS_REVIEWING) {
            return false;
        }

        return $this->forceFill(['status' => self::STATUS_OPEN])->save();
    }

    /**
     * RĘCZNA ZMIANA PRIORYTETU, ZAWSZE Z UZASADNIENIEM (D-070).
     *
     * DLACZEGO CZŁOWIEK MUSI MÓC TO ZROBIĆ
     * Mapowanie czyta wyłącznie KATEGORIĘ wybraną przez zgłaszającego, nie
     * treść. „Ujawnia czyjeś dane osobowe" to domyślnie P1 — ale jeśli
     * w treści stoi adres domowy razem z wezwaniem, żeby tam pojechać, to
     * jest P0 i musi trafić na sam szczyt w tej samej minucie. W drugą
     * stronę działa to równie często: „Dotyczy dziecka" jest domyślnie P0
     * (koszt pomyłki jest niesymetryczny), a bywa zdjęciem wnuka przy
     * urodzinowym torcie.
     *
     * DLACZEGO POWÓD JEST OBOWIĄZKOWY
     * Bo bez niego zmiana priorytetu jest w logu nieodróżnialna od pomyłki,
     * a przy dwóch moderatorach — od cudzej pomyłki. `CHECK`
     * `reports_priorytet_zmiana_check` nie przyjmie zmiany bez powodu, więc
     * ta reguła nie stoi na samej walidacji formularza.
     *
     * CZEGO TA METODA NIE ROBI: nie podejmuje decyzji i nie zmienia statusu.
     * Priorytet ustala KOLEJNOŚĆ, nie wyrok — sprawa podniesiona do P0 dalej
     * czeka na człowieka, tylko czeka pierwsza.
     */
    public function zmienPriorytet(int $priorytet, string $powod, User $moderator): void
    {
        $this->forceFill([
            'priorytet' => $priorytet,
            'priorytet_powod' => $powod,
            'priorytet_zmieniony_przez' => $moderator->getKey(),
            'priorytet_zmieniony_o' => now(),
        ])->save();
    }

    /**
     * Czy mamy komu odpowiedzieć.
     *
     * Art. 16 ust. 2 lit. c przewiduje wyjątek: przy zgłoszeniach dotyczących
     * przestępstw z art. 3-7 dyrektywy 2011/93/UE dane zgłaszającego nie są
     * wymagane. Wtedy nie ma adresu i to jest zgodne z przepisem, a nie brak
     * w naszych danych.
     */
    public function maAdresDoOdpowiedzi(): bool
    {
        return $this->jestZgloszeniemPrawnym() && $this->notifier_email !== null;
    }
}
