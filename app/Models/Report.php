<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\NumerSprawy;
use Database\Factories\ReportFactory;
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

    public const STATUS_TRIAGE = 'triage';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_REJECTED = 'rejected';

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
        });
    }

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'good_faith_at' => 'datetime',
            'receipt_sent_at' => 'datetime',
            'decision_sent_at' => 'datetime',
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
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_TRIAGE, self::STATUS_REVIEWING], true);
    }

    public function jestZgloszeniemPrawnym(): bool
    {
        return $this->source === self::SOURCE_LEGAL_NOTICE;
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
