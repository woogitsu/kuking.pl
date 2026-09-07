<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\OdzyskiwalneDane;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * To, co człowiek zdążył wpisać, zanim sesja zdążyła wygasnąć (issue #81).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  GDZIE ODKŁADAMY TREŚĆ PO 419 I CZEGO NIE WYBRALIŚMY
 * ────────────────────────────────────────────────────────────────────────
 *
 * WYBRANE: wystawiamy formularz jeszcze raz, z treścią wprost z żądania,
 * które właśnie odbiliśmy. Dane nigdzie nie wędrują — wracają w tej samej
 * odpowiedzi HTTP, w polach formularza, ze świeżym tokenem CSRF. Jedno
 * kliknięcie „Wyślij jeszcze raz" i wpis idzie tam, gdzie miał iść.
 * Działa bez jednej linijki JavaScriptu, bo to zwykły `<form method=POST>`.
 *
 * ODRZUCONE 1 — `back()->withInput()` i `old()` na formularzu źródłowym
 * (to sugerowały kryteria akceptacji issue #81). Wygląda naturalnie, ale
 * opiera się na trzech rzeczach, z których każda potrafi zawieść dokładnie
 * wtedy, gdy jest potrzebna:
 *
 *   a) `back()` bierze adres z nagłówka `Referer`, a w drugiej kolejności
 *      z sesji — czyli z tego, co właśnie wygasło. Bez `Referer` (część
 *      przeglądarek, część ustawień prywatności) człowiek ląduje na stronie
 *      głównej;
 *   b) `withInput()` wkłada dane do flasha NOWEJ sesji. Jeśli sesja wygasła
 *      naprawdę, to znaczy również WYLOGOWANIE — a wtedy przekierowanie
 *      na `/dodaj/przepis` odbija się o `auth`, leci na `/login`, i to
 *      właśnie ten skok zjada flasha. Tekst przepada w momencie, w którym
 *      miał być uratowany;
 *   c) odzyskanie działa tylko wtedy, gdy formularz docelowy czyta `old()`
 *      dla KAŻDEGO pola. Nasze czytają, ale to jest umowa, o której następny
 *      formularz może zapomnieć — i nikt się o tym nie dowie.
 *
 * ODRZUCONE 2 — odłożenie treści do sesji (albo do cache'u) pod kluczem
 * i odesłanie po nią później. Sesja jest dokładnie tą rzeczą, która przed
 * chwilą wygasła; nowa sesja by to udźwignęła, ale wtedy treść wpisu ląduje
 * w tabeli `sessions` w bazie i żyje dłużej niż jedno żądanie. Za to nie ma
 * żadnego zysku: całą treść mamy pod ręką, w żądaniu, na które właśnie
 * odpowiadamy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  BEZPIECZEŃSTWO
 * ────────────────────────────────────────────────────────────────────────
 *
 *  • Ochrona CSRF zostaje nietknięta. Formularz odzyskiwania dostaje ŚWIEŻY
 *    token i wysyła się normalną drogą — przez `ValidateCsrfToken`. Żądanie
 *    bez tokenu dalej odbija się o 419. Żadna trasa nie została wyjęta.
 *  • Nikogo nie logujemy i niczego nie zapisujemy w cudzym imieniu. Ta
 *    odpowiedź tylko rysuje formularz; wpis powstaje dopiero przy ponownym
 *    wysłaniu, z pełną autoryzacją.
 *  • ODZYSKUJEMY TYLKO NA TRASACH TREŚCI — patrz `App\Support\OdzyskiwalneDane`.
 *
 *    Wcześniej stała tu czarna lista fragmentów nazw pól (`password`,
 *    `haslo`, `token`, `secret`, `otp`, `cvv`) i komentarz „hasła i pola
 *    wrażliwe nie wracają". Kod znaczył co innego: „nie wracają pola,
 *    których nazwa zawiera jedną z sześciu cząstek". Ekran drugiego
 *    składnika nazywa swoje pola `code` i `backup_code` — żadne z nich nie
 *    zawiera którejkolwiek z nich, więc KOD ZAPASOWY do 2FA wracał
 *    do HTML-a w ukrytym polu (audyt W7-04). Kod jednorazowy żyje
 *    30 sekund; zapasowy jest ważny do użycia.
 *
 *    Dlatego decyduje teraz NAZWA TRASY, nie nazwa pola: logowanie, 2FA,
 *    hasła i usuwanie konta nie odzyskują niczego, a każda nowa trasa
 *    domyślnie też nie. Utrata hasła przy 419 jest kosztem, który godzimy
 *    się ponieść.
 *  • Nic z tego nie trafia do logów: `TokenMismatchException` jest wyjęty
 *    ze zgłaszania (`dontReport` w bootstrap/app.php), a treść żyje wyłącznie
 *    w wygenerowanym HTML-u.
 */
final class OdzyskanyFormularz
{
    /**
     * Bezpiecznik na wielkość odpowiedzi. Przy formularzu przepisu pól bywa
     * kilkadziesiąt, ale gdyby ktoś wysłał żądanie z tysiącami kluczy,
     * strona odzyskiwania nie ma prawa urosnąć do megabajtów.
     */
    private const LIMIT_ZNAKOW = 200_000;

    /**
     * @param  list<array{nazwa: string, wartosc: string, dlugi: bool}>  $pola
     * @param  list<array{nazwa: string, wiele: bool}>  $pliki
     */
    private function __construct(
        public readonly string $akcja,
        public readonly string $metoda,
        public readonly array $pola,
        public readonly array $pliki,
        public readonly bool $obciete,
    ) {}

    public static function zZadania(Request $request): self
    {
        $pola = [];
        $znakow = 0;
        $obciete = false;

        // Deny-by-default: poza trasami treści `zZadania()` zwraca pustą
        // tablicę, `maCoOdzyskac()` daje `false`, a ekran 419 pokazuje samą
        // informację o wygasłej sesji. Tak ma być na logowaniu i na 2FA.
        foreach (Arr::dot(OdzyskiwalneDane::zZadania($request)) as $klucz => $wartosc) {
            $klucz = (string) $klucz;

            if (OdzyskiwalneDane::jestWrazliwe($klucz) || ! self::daSieOdlozyc($wartosc)) {
                continue;
            }

            $tekst = is_bool($wartosc) ? ($wartosc ? '1' : '0') : (string) $wartosc;

            if ($tekst === '') {
                continue;
            }

            $znakow += mb_strlen($tekst);

            if ($znakow > self::LIMIT_ZNAKOW) {
                $obciete = true;
                break;
            }

            $pola[] = [
                'nazwa' => self::nazwaPolaHtml($klucz),
                'wartosc' => $tekst,
                // „Długi" znaczy: to jest tekst, który człowiek pisał, a nie
                // ustawienie z listy. Tylko taki pokazujemy na wierzchu —
                // po to, żeby było WIDAĆ, że nic nie zginęło.
                'dlugi' => mb_strlen($tekst) >= 60 || str_contains($tekst, "\n"),
            ];
        }

        $pliki = [];

        foreach ($request->allFiles() as $nazwa => $plik) {
            $pliki[] = [
                'nazwa' => is_array($plik) ? $nazwa.'[]' : (string) $nazwa,
                'wiele' => is_array($plik),
            ];
        }

        return new self(
            akcja: $request->fullUrl(),
            metoda: mb_strtoupper($request->method()),
            pola: $pola,
            pliki: $pliki,
            obciete: $obciete,
        );
    }

    public function maCoOdzyskac(): bool
    {
        return $this->pola !== [];
    }

    /**
     * Czy formularz musi iść jako `multipart/form-data`.
     *
     * Samych plików odzyskać się nie da — przeglądarka nie pozwala wpisać
     * wartości do `<input type="file">` i dobrze, bo inaczej strona mogłaby
     * podkraść plik z dysku. Dajemy więc puste pole i mówimy wprost,
     * że zdjęcie trzeba wybrać jeszcze raz.
     */
    public function maPliki(): bool
    {
        return $this->pliki !== [];
    }

    /**
     * Metoda formularza HTML i ewentualne pole `_method`.
     *
     * HTML zna tylko GET i POST. PUT/PATCH/DELETE Laravel czyta z pola
     * `_method`, więc odtwarzamy je ręcznie — bez tego edycja przepisu
     * (PUT) wróciłaby po 419 jako zwykły POST i trafiła w złą trasę.
     */
    public function metodaUdawana(): ?string
    {
        return in_array($this->metoda, ['PUT', 'PATCH', 'DELETE'], strict: true)
            ? $this->metoda
            : null;
    }

    private static function daSieOdlozyc(mixed $wartosc): bool
    {
        return is_string($wartosc) || is_int($wartosc) || is_float($wartosc) || is_bool($wartosc);
    }

    /** `ingredients.0.text` → `ingredients[0][text]` */
    private static function nazwaPolaHtml(string $kluczZKropkami): string
    {
        $czesci = explode('.', $kluczZKropkami);
        $nazwa = array_shift($czesci) ?? '';

        foreach ($czesci as $czesc) {
            $nazwa .= '['.$czesc.']';
        }

        return $nazwa;
    }
}
