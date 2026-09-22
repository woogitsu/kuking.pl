<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\LimityTekstuPrzepisu;
use App\Support\OdzyskiwalneDane;
use Illuminate\Http\Request;

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
     * Dawny limit sumy nie mieścił poprawnego przepisu (51 × 4000 znaków).
     * Zostaje dla innych tras i pojedynczego pola; przepis ma budżet wyliczony
     * ze swoich granic. Niezależnie ograniczamy pola, nazwy i bajty danych
     * po escapowaniu z rezerwą na markup; nie jest to pomiar całego layoutu.
     */
    private const LIMIT_ZNAKOW = 200_000;

    private const LIMIT_BAJTOW_NAZWY = 256;

    // Rezerwa na markup jednego pola (w tym label/help), poza jego wartością.
    private const NARZUT_POLA = 2048;

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
        $przepis = in_array($request->route()?->getName(), ['recipes.store', 'recipes.update'], true);
        $limitZnakow = $przepis ? LimityTekstuPrzepisu::maksZnakowFormularza() : self::LIMIT_ZNAKOW;
        $limitPol = LimityTekstuPrzepisu::maksPol();
        // Jedna litera daje najwyżej 6 bajtów po e() (np. cudzysłów), także
        // dla UTF-8. Nazwa i narzut są osobne: to nie suma samych wartości.
        // Rozmiar CAŁEJ odpowiedzi (z layoutem) mierzy regresja HTTP.
        $limitBajtow = 6 * $limitZnakow + $limitPol * (self::LIMIT_BAJTOW_NAZWY * 6 + self::NARZUT_POLA);
        $bajtow = strlen(e($request->fullUrl()));
        $odwiedzone = 0;

        // Deny-by-default: poza trasami treści `zZadania()` zwraca pustą
        // tablicę, `maCoOdzyskac()` daje `false`, a ekran 419 pokazuje samą
        // informację o wygasłej sesji. Tak ma być na logowaniu i na 2FA.
        foreach (self::splaszcz(OdzyskiwalneDane::zZadania($request), '', $odwiedzone, $obciete, $limitPol * 2) as $klucz => $wartosc) {
            $klucz = (string) $klucz;

            if (OdzyskiwalneDane::jestWrazliwe($klucz) || ! self::daSieOdlozyc($wartosc)) {
                continue;
            }

            $tekst = is_bool($wartosc) ? ($wartosc ? '1' : '0') : (string) $wartosc;

            if ($tekst === '') {
                continue;
            }

            $dlugosc = mb_strlen($tekst);
            $znakow += $dlugosc;
            $nazwa = self::nazwaPolaHtml($klucz);
            $bajtow += strlen(e($tekst)) + strlen(e($nazwa)) + self::NARZUT_POLA;

            if ($dlugosc > self::LIMIT_ZNAKOW || $znakow > $limitZnakow
                || count($pola) >= $limitPol || strlen($nazwa) > self::LIMIT_BAJTOW_NAZWY
                || $bajtow > $limitBajtow) {
                $obciete = true;
                break;
            }

            $pola[] = [
                'nazwa' => $nazwa,
                'wartosc' => $tekst,
                // „Długi" znaczy: to jest tekst, który człowiek pisał, a nie
                // ustawienie z listy. Tylko taki pokazujemy na wierzchu —
                // po to, żeby było WIDAĆ, że nic nie zginęło.
                'dlugi' => $dlugosc >= 60 || str_contains($tekst, "\n"),
            ];
        }

        $pliki = [];

        foreach ($request->allFiles() as $nazwa => $plik) {
            $nazwa = is_array($plik) ? $nazwa.'[]' : (string) $nazwa;
            $bajtow += strlen(e($nazwa)) + self::NARZUT_POLA;
            if (count($pola) + count($pliki) >= $limitPol
                || strlen($nazwa) > self::LIMIT_BAJTOW_NAZWY || $bajtow > $limitBajtow) {
                $obciete = true;
                break;
            }
            $pliki[] = [
                'nazwa' => $nazwa,
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
     * Czy na odzyskanym formularzu jest cokolwiek WIDAĆ.
     *
     * `maCoOdzyskac()` mówi „coś odłożyliśmy", ale krótkie wartości wracają
     * jako `<input type="hidden">` — człowiek ich nie widzi i nie może ich
     * poprawić. Przy komentarzu „Wygląda pysznie!" (16 znaków) i bez zdjęcia
     * cały odzyskany formularz to ukryte pola plus jeden przycisk.
     *
     * Czyta to warstwa powierzchni w `errors/419` i `errors/429`: mocna
     * obwódka panelu formularza znaczy „tu się coś wpisuje", więc na takim
     * ekranie byłaby obietnicą bez pokrycia — ta sama klasa błędu co martwy
     * przycisk (D-053). Wtedy blok jest sekcją, nie panelem.
     *
     * Próg „długiego" pola ustawia `zZadania()`: 60 znaków albo znak nowej
     * linii. Pliki liczą się zawsze, bo `<input type="file">` jest widoczny
     * (pusty, ale widoczny — wartości do niego wpisać się nie da).
     */
    public function maWidocznePola(): bool
    {
        foreach ($this->pola as $pole) {
            if ($pole['dlugi'] === true) {
                return true;
            }
        }

        return $this->maPliki();
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

    /** Spłaszczamy leniwie: tysiące kluczy i głęboka tablica nie tworzą kopii Arr::dot(). */
    private static function splaszcz(array $dane, string $prefix, int &$odwiedzone, bool &$obciete, int $limit, int $glebokosc = 0): \Generator
    {
        foreach ($dane as $klucz => $wartosc) {
            $nazwa = $prefix.(string) $klucz;
            if (++$odwiedzone > $limit || $glebokosc > 8 || strlen($nazwa) > self::LIMIT_BAJTOW_NAZWY) {
                $obciete = true;

                return;
            }
            if (is_array($wartosc)) {
                yield from self::splaszcz($wartosc, $nazwa.'.', $odwiedzone, $obciete, $limit, $glebokosc + 1);
                if ($obciete) {
                    return;
                }
            } else {
                yield $nazwa => $wartosc;
            }
        }
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
