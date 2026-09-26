<?php

declare(strict_types=1);

namespace App\Http\Requests\Moderation;

use App\Domain\Moderation\DlugoscZawieszenia;
use App\Domain\Moderation\PodstawaDecyzji;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator as Walidator;

/**
 * Wejście decyzji moderacyjnej z kolejki zgłoszeń (`admin.reports.decide`).
 *
 * Wyjęte z `ModerationController::decide()` bez zmiany zachowania (issue
 * #970, krok 2): te same reguły, te same komunikaty, ta sama kolejność
 * sprawdzeń. Samą decyzję — blokadę, zapis, sankcję, powiadomienia i dziennik
 * — wykonuje `RozstrzygnijZgloszenie`.
 *
 * KOLEJNOŚĆ JEST ŚWIADOMA I TAKA SAMA JAK PRZED #970:
 *
 *  1. `moderate` — kto nie jest moderatorem, dostaje 403;
 *  2. własna sprawa — powrót z BŁĘDEM I Z WPISANYMI DANYMI;
 *  3. sprawa już rozstrzygnięta — powrót z błędem, bez danych;
 *  4. dopiero potem reguły pól.
 *
 * FormRequest waliduje się przy wstrzyknięciu, czyli ZANIM ruszy ciało
 * kontrolera, a `prepareForValidation()` biegnie jeszcze przed autoryzacją.
 * Dlatego punkty 1-3 stoją w `authorize()`: tylko ona leży między wejściem
 * a regułami w dobrej kolejności. Punkty 2 i 3 nie są odmową dostępu, tylko
 * gotową odpowiedzią — `HttpResponseException` oddaje dokładnie to samo
 * przekierowanie, które wcześniej zwracał kontroler.
 */
final class DecyzjaModeracyjnaRequest extends FormRequest
{
    public const JUZ_ROZSTRZYGNIETE = 'To zgłoszenie zostało już rozstrzygnięte. Odśwież stronę, żeby zobaczyć decyzję.';

    public function authorize(): bool
    {
        // Ten sam wyjątek co `$this->authorize()` w kontrolerze — odmowa
        // wygląda identycznie.
        Gate::authorize('moderate', User::class);

        $report = $this->zgloszenie();

        // Wstępne sprawdzenie — tanie i daje sensowny komunikat bez wchodzenia
        // w transakcję. NIE JEST GWARANCJĄ: prawdziwe rozstrzygnięcie stoi
        // w `RozstrzygnijZgloszenie`, pod blokadą wiersza.
        $wlasnaSprawa = Gate::forUser($this->user())->inspect('decide', $report);

        if ($wlasnaSprawa->denied()) {
            throw new HttpResponseException(
                back()->withInput()->withErrors(['action' => $wlasnaSprawa->message()]),
            );
        }

        if ($report->status !== Report::STATUS_OPEN) {
            throw new HttpResponseException(
                back()->withErrors(['action' => self::JUZ_ROZSTRZYGNIETE]),
            );
        }

        return true;
    }

    /** Zgłoszenie z adresu. */
    public function zgloszenie(): Report
    {
        $report = $this->route('report');

        if (! $report instanceof Report) {
            abort(404);
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Lista dozwolonych decyzji zależy od TYPU zgłoszenia — patrz
        // ModerationAction::DOZWOLONE. Kombinacja spoza listy jest błędem,
        // a nie cichym „zrób coś innego".
        $dozwolone = array_keys(ModerationAction::dozwoloneDla($this->zgloszenie()->target_type));

        /*
         * CZY LICZBA DNI Z POLA „WŁASNY TERMIN" ZOSTANIE W OGÓLE UŻYTA.
         *
         * Od tego zależy, czy pilnujemy jej zakresu — i to jest różnica
         * zamierzona, nie oszczędność. Moderator, który zaznaczył „własny
         * termin", wpisał 14, a potem zmienił zdanie i wybrał „Na 7 dni",
         * ma dostać zapisaną decyzję, nie wykład o polu, którego nie użył.
         * Liczba jest wtedy po cichu ignorowana
         * (`RozstrzygnijZgloszenie::terminKary`).
         */
        $wlasnyTermin = $this->input('action') === ModerationAction::ACTION_SUSPEND
            && $this->input('suspend_days') === DlugoscZawieszenia::WLASNY;

        /*
         * PODSTAWA DECYZJI IDZIE DO CZŁOWIEKA (DSA art. 17 ust. 3 lit. d i e).
         *
         * `reason_code` przestał być „kodem wewnętrznym": formularz oferuje
         * teraz wyłącznie zamkniętą listę `PodstawaDecyzji`, a każdy jej
         * element wskazuje konkretny punkt `resources/legal/zasady.md`, który
         * autor treści przeczyta w powiadomieniu.
         *
         * REGUŁA ZOSTAJE ŚWIADOMIE MIĘKKA (`string`, nie `in:`). W bazie leżą
         * decyzje sprzed tej zmiany, a `RestoreContent` z odwołania zapisuje
         * `appeal_overturned` — twarda lista unieważniłaby jedno i drugie.
         * Kod spoza listy po prostu nie dostaje numeru punktu:
         * `PodstawaDecyzji::zdanie()` mówi wtedy prawdę ogólną, zamiast
         * wymyślać numer, którego nie zna.
         */
        return [
            'action' => ['required', 'in:'.implode(',', $dozwolone)],
            'reason_code' => ['required', 'string', 'max:80'],
            'note' => ['nullable', 'string', 'max:2000'],
            /*
             * WIADOMOŚĆ OBOWIĄZKOWA PRZY PODSTAWIE PRAWNEJ.
             *
             * Przy „treść niezgodna z prawem" uzasadnienie mówi autorowi:
             * „Wyjaśnienie masz w wiadomości od moderacji powyżej"
             * (`PodstawaDecyzji::zdanie()`). Nie ma dziś kolumny na konkretny
             * przepis — `reason_code` mieści 80 znaków i trzyma sam rodzaj
             * podstawy — więc to jedyne miejsce, w którym człowiek dowie się,
             * CO uznaliśmy za niezgodne z prawem. Puste pole zamieniłoby
             * tamto zdanie w odesłanie w próżnię.
             */
            'user_message' => ['nullable', 'string', 'max:2000', 'required_if:reason_code,'.PodstawaDecyzji::NIEZGODNE_Z_PRAWEM],
            /*
             * DŁUGOŚĆ ZAWIESZENIA — LISTA Z `DlugoscZawieszenia`, NIE Z PALCA.
             *
             * Doszły dwie pozycje: `brak` („Bez zawieszenia", pierwsza
             * i domyślnie zaznaczona) oraz `wlasny` (liczba dni z pola
             * `suspend_days_custom`). Powód obu — i powód, dla którego
             * BRAK WYBORU przestał znaczyć „bezterminowo" — stoi w tamtej
             * klasie.
             *
             * Reguła zostaje `nullable`: żądanie bez tego pola jest wciąż
             * poprawne przy decyzji innej niż zawieszenie. Przy „Zawieś
             * konto" pilnuje tego `after()` niżej, bo `in:` nie odróżni
             * „nie wybrałem" od „wybrałem nie zawieszać", a różnica między
             * nimi jest tu żadna: obie znaczą, że kary nie ma.
             */
            'suspend_days' => ['nullable', 'in:'.implode(',', DlugoscZawieszenia::wartosci())],
            /*
             * WŁASNY TERMIN W DNIACH — REGUŁY TYLKO WTEDY, GDY LICZBA JEST
             * UŻYWANA.
             *
             * Przy wyborze „własny termin" liczba jest obowiązkowa i musi
             * mieścić się w zakresie 1-365 (uzasadnienie zakresu:
             * `DlugoscZawieszenia`) — bo `status_expires_at` przyjmie
             * dowolną datę, a w polu można wpisać cokolwiek.
             *
             * Przy każdym innym wyborze zostaje samo `nullable`: liczba
             * leżąca w polu po zmianie zdania nie ma prawa zatrzymać
             * decyzji. Nie krzyczymy na człowieka za pole, którego nie użył.
             */
            'suspend_days_custom' => $wlasnyTermin
                ? ['required', 'integer', 'min:'.DlugoscZawieszenia::MIN_DNI, 'max:'.DlugoscZawieszenia::MAX_DNI]
                : ['nullable'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Wybierz decyzję.',
            'action.in' => 'Ta decyzja nie ma zastosowania do tego zgłoszenia. Wybierz jedną z pokazanych.',
            'reason_code.required' => 'Wybierz podstawę decyzji — autor treści zobaczy ją w powiadomieniu.',
            'user_message.required_if' => 'Przy podstawie „treść niezgodna z prawem" napisz autorowi, '
                .'co dokładnie uznaliśmy za niezgodne z prawem. Bez tego uzasadnienie odsyła w próżnię.',
            'suspend_days.in' => 'Wybierz długość zawieszenia z listy.',
            'suspend_days_custom.required' => 'Przy „Własnym terminie" wpisz liczbę dni od '
                .DlugoscZawieszenia::MIN_DNI.' do '.DlugoscZawieszenia::MAX_DNI
                .'. Albo zaznacz jeden z gotowych terminów wyżej.',
            'suspend_days_custom.integer' => 'Wpisz własny termin jako liczbę dni, na przykład 14.',
            'suspend_days_custom.min' => 'Najkrótsze zawieszenie to '.DlugoscZawieszenia::MIN_DNI.' dzień. '
                .'Jeśli chcesz tylko zwrócić uwagę, wybierz decyzję „Ostrzeżenie".',
            'suspend_days_custom.max' => 'Najdłuższe zawieszenie z terminem to '.DlugoscZawieszenia::MAX_DNI.' dni. '
                .'Jeśli kara ma trwać dłużej, zaznacz „Bezterminowo, do mojej decyzji".',
        ];
    }

    /**
     * „ZAWIEŚ KONTO" BEZ WYBRANEGO TERMINU TO POMYŁKA, NIE BEZTERMINOWOŚĆ.
     *
     * Reguła stoi tu, a nie w `rules()`, bo dotyczy DWÓCH pól naraz
     * i bo `in:` nie odróżni „nie wybrałem" od „wybrałem nie zawieszać".
     * Ta różnica jest tu żadna — obie odpowiedzi znaczą, że kary nie ma.
     *
     * Wcześniej brak wyboru dawał karę BEZ TERMINU, czyli najsurowszą
     * z możliwych, a podpis pod grupą mówił o tym wprost, jakby to było
     * w porządku. Teraz brakujący termin zatrzymuje decyzję i mówi,
     * czego brakuje. Cicho wykonać jej nie wolno w ŻADNĄ stronę:
     * bezterminowo byłoby karą, której nikt nie wybrał, a pominięcie
     * kary zostawiłoby w logu moderacji „zawieszono" przy koncie, które
     * działa dalej.
     *
     * Błąd z tego haka dochodzi PO błędach reguł pól — tak jak wcześniej
     * `$walidator->after()` w kontrolerze. Nieudana walidacja wraca na
     * kolejkę z BŁĘDAMI I Z WPISANYMI DANYMI: moderator nie ma przepisywać
     * uzasadnienia drugi raz tylko dlatego, że pomylił się w jednym polu.
     *
     * @return list<callable(Walidator): void>
     */
    public function after(): array
    {
        return [
            function (Walidator $sprawdzenie): void {
                if ($this->input('action') !== ModerationAction::ACTION_SUSPEND) {
                    return;
                }

                $wybor = $this->input('suspend_days');

                if (! DlugoscZawieszenia::zawiesza(is_string($wybor) ? $wybor : null)) {
                    $sprawdzenie->errors()->add(
                        'suspend_days',
                        'Przy decyzji „Zawieś konto" zaznacz jeszcze, na jak długo. '
                        .'„Bez zawieszenia" znaczy, że kary nie ma.',
                    );
                }
            },
        ];
    }
}
