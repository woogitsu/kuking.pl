<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Domain\Moderation\ListaKont;
use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Wejście listy kont w panelu moderacji (`admin.users`, `/admin/uzytkownicy`).
 *
 * Wyjęte z `UzytkownicyController::index()` bez zmiany zachowania (issue
 * #970): te same filtry, te same wartości domyślne, ta sama normalizacja
 * frazy. Zapytania wykonuje `App\Domain\Moderation\ListaKont`.
 *
 * ══════════════════════════════════════════════════════════════════════════
 *  TU NIE MA ANI JEDNEJ REGUŁY, KTÓRA ODSYŁA Z BŁĘDEM — I TO JEST DECYZJA
 * ══════════════════════════════════════════════════════════════════════════
 *
 * Wszystkie parametry przychodzą z paska adresu (formularz `GET`, odnośniki
 * sortowania i zakładek), nie z pól, które człowiek wypełnił źle. Wartość,
 * której nie da się odczytać — podrobione `?sortuj=`, nieznany `?status=`,
 * data „jutro" — sprowadzamy do wartości domyślnej i ekran się otwiera.
 * Odesłanie z błędem walidacji zamieniłoby stary zakładkowy odnośnik albo
 * literówkę w adresie w przekierowanie donikąd, a lista kont to ekran do
 * patrzenia, nie formularz. Dlatego `rules()` jest puste, a cała praca
 * dzieje się w `filtry()` i `sortowanie()`; pilnuje tego
 * `ListaKontRequestTest::test_bledne_parametry_nie_odsylaja_z_bledem_walidacji`.
 */
final class ListaKontRequest extends FormRequest
{
    /** Najdłuższa fraza szukania, która w ogóle trafia do zapytania. */
    public const NAJDLUZSZA_FRAZA = 120;

    public function authorize(): bool
    {
        // Middleware `moderator` pilnuje wejścia do całej grupy `/admin`,
        // ale bramka na politykę zostaje przy ekranie — tak samo jak w
        // `AppealController::index()`. Adres nie jest autoryzacją
        // (AGENTS.md §7), a trasa może kiedyś trafić do innej grupy.
        // Ten sam wyjątek co `$this->authorize()` w kontrolerze — odmowa
        // wygląda identycznie.
        Gate::authorize('moderate', User::class);

        return true;
    }

    /**
     * Świadomie puste — patrz nagłówek klasy.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Filtry z adresu, sprowadzone do wartości, których nie da się podrobić.
     *
     * CZTERY, NIE OSIEM. Każdy odpowiada na pytanie, które moderator naprawdę
     * zadaje przy tysiącu kont:
     *
     *  * `szukaj`     — „mam adres e-mail ze zgłoszenia, czyje to konto";
     *  * `status`     — „pokaż zawieszone" (zakładki z licznikami);
     *  * `od`/`do`    — „kto przyszedł dzisiaj / w zeszłym tygodniu";
     *  * `bez_wpisow` — „kto założył konto i nic nie napisał", czyli lista
     *    osób do powitania (issue #6: pierwsza reakcja od człowieka jest
     *    ważniejsza niż którakolwiek funkcja z MVP).
     *
     * ŚWIADOMIE NIE MA FILTRA „konta z zawieszeniem w historii". Zakładka
     * „Zawieszone" odpowiada na to pytanie dla stanu BIEŻĄCEGO, a pełną
     * historię widać na karcie konta. Osobna lista „ludzie, którzy kiedyś
     * dostali karę" jest tym samym, czym publiczny ranking najaktywniejszych
     * (AGENTS.md §12), tylko z odwróconym znakiem — a decyzja 3.3
     * (docs/INSPIRATION_DECISIONS.md) mówi wprost, że log samych kar wygląda
     * jak akt oskarżenia.
     *
     * @return array{status: string, od: ?CarbonImmutable, do: ?CarbonImmutable, bez_wpisow: bool, szukaj: string, fraza: string}
     */
    public function filtry(): array
    {
        $wpisane = trim((string) $this->query('szukaj', ''));
        $wpisane = mb_substr($wpisane, 0, self::NAJDLUZSZA_FRAZA);

        $status = (string) $this->query('status', 'wszystkie');

        return [
            'status' => array_key_exists($status, User::ETYKIETY_STATUSU) ? $status : 'wszystkie',
            'od' => $this->dzien('od'),
            // Górna granica jest WŁĄCZAJĄCA dla całego wskazanego dnia:
            // porównanie idzie do początku dnia następnego (`<`). Bez tego
            // „do 9 września" gubiłoby wszystkie konta założone 9 września
            // po północy, czyli praktycznie wszystkie z tego dnia.
            'do' => $this->dzien('do')?->addDay(),
            'bez_wpisow' => $this->query('bez_wpisow') === '1',
            // Surowa fraza wraca do pola formularza (człowiek ma widzieć to,
            // co wpisał), znormalizowana idzie do zapytania.
            'szukaj' => $wpisane,
            'fraza' => $wpisane === '' ? '' : $this->normalizuj($wpisane),
        ];
    }

    /**
     * Wybrane sortowanie sprowadzone do pary, której nie da się podrobić
     * z adresu. Klucz wyłącznie z `ListaKont::SORTOWANIA`.
     *
     * @return array{string, string}
     */
    public function sortowanie(): array
    {
        $sortuj = (string) $this->query('sortuj', ListaKont::SORTOWANIE_DOMYSLNE);
        $kierunek = (string) $this->query('kierunek', 'desc');

        return [
            array_key_exists($sortuj, ListaKont::SORTOWANIA) ? $sortuj : ListaKont::SORTOWANIE_DOMYSLNE,
            $kierunek === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * Data z adresu jako początek dnia w strefie CZŁOWIEKA, nie w UTC.
     *
     * `users.created_at` jest w UTC, a moderator wpisuje „9 września" myśląc
     * o polskim dniu. Bez `App\Support\Czas::strefa()` filtr „od dzisiaj"
     * gubiłby konta założone między północą a drugą w nocy czasu polskiego —
     * czyli te, które na liście widać z datą dzisiejszą.
     */
    private function dzien(string $parametr): ?CarbonImmutable
    {
        $wartosc = trim((string) $this->query($parametr, ''));

        if ($wartosc === '') {
            return null;
        }

        try {
            $dzien = CarbonImmutable::createFromFormat('Y-m-d', $wartosc, Czas::strefa());

            return $dzien instanceof CarbonImmutable ? $dzien->startOfDay()->utc() : null;
        } catch (\Throwable) {
            // Data nie do odczytania = brak filtra. Świadomie bez błędu
            // walidacji: to jest parametr z adresu, nie pole, które człowiek
            // wypełnił źle — a ekran ma się otworzyć, nie nakrzyczeć.
            return null;
        }
    }

    /**
     * Fraza znormalizowana tak samo jak kolumna po stronie bazy.
     *
     * Ta sama normalizacja co w `App\Domain\Search\SearchQuery`: `Str::ascii`
     * odpowiada temu, co `unaccent` robi z polskimi znakami, więc „Żaneta"
     * znajduje „zaneta".
     */
    private function normalizuj(string $fraza): string
    {
        return mb_strtolower(Str::ascii($fraza));
    }
}
