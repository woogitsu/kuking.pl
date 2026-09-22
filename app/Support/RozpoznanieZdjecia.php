<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Jedna odpowiedź na pytanie „czy to jest zdjęcie, które umiemy otworzyć".
 *
 * PO CO OSOBNA KLASA
 * Ta sama odpowiedź jest potrzebna w DWÓCH momentach i dwa razy z innego
 * powodu:
 *
 *   * przy WALIDACJI FORMULARZA — żeby człowiek dostał zrozumiały komunikat
 *     przy polu, razem z resztą błędów, a wpisany tekst nie zniknął;
 *   * w `StoreUploadedImage` — bo to jest prawdziwa granica i musi trzymać
 *     także wtedy, gdy ktoś ominie formularz.
 *
 * Zanim to powstało, pierwszy moment używał laravelowej reguły `image`, a drugi
 * własnego sprawdzenia po magic bytes. `image` to w Laravelu
 * `mimes:jpg,jpeg,png,gif,bmp,svg,webp` — czyli lista, która NIE ZAWIERA AVIF,
 * choć `config/kuking.php` go dopuszcza, a pole wyboru pliku go podpowiada.
 * Zdjęcie AVIF odpadało więc w formularzu, zanim potok mediów zdążył
 * powiedzieć, że umie je przetworzyć — a komunikat mówił „JPG, PNG lub WebP",
 * czyli listę wziętą z trzeciego miejsca (audyt W3-08).
 *
 * Jedna lista, jedno sprawdzenie, jedne komunikaty.
 *
 * OD ISSUE #115: TO SAMO SPRAWDZENIE, DRUGI KSZTAŁT ODPOWIEDZI
 * Sygnał `photo_upload_failed` potrzebuje KODU powodu (`not_an_image`,
 * `unsupported_format`, `too_many_megapixels`…), nie tylko komunikatu po
 * polsku — a te trzy kody odpowiadają trzem gałęziom WEWNĄTRZ tego jednego
 * sprawdzenia, które wcześniej znały tylko swój komunikat. `rozpoznaj()`
 * zwraca oba kształty naraz (patrz `WynikRozpoznania`); `coJestNieTak()`
 * zostaje tym, czym była — cienkim dostępem do samego komunikatu, używanym
 * przez `ObslugiwaneZdjecie` — żeby nie trzeba było przepisywać reguły
 * walidacji formularza.
 *
 * OD ISSUE #119: HEIC DOSTAJE WŁASNY KOD POWODU, NIE `not_an_image`
 * Kryterium akceptacji #119 pyta „ile zdjęć odpada dziś na HEIC w praktyce".
 * Dopóki HEIC dzielił kod powodu z każdym innym nieczytelnym plikiem (literą
 * zamiast zdjęcia, uciętym uploadem, uszkodzonym JPEG-iem), na to pytanie nie
 * dało się odpowiedzieć zapytaniem do `product_signals` — `not_an_image`
 * mieszał telefon z iPhone'em i plik, który w ogóle nie jest obrazem. Osobny
 * kod (`heic_unsupported`) rozdziela te dwa zupełnie różne zdarzenia: jedno
 * jest błędem człowieka (albo atakiem), drugie jest luką produktu opisaną
 * w `docs/DECISIONS.md`, D-064.
 */
final class RozpoznanieZdjecia
{
    public const POWOD_NIECZYTELNY = 'not_an_image';

    public const POWOD_NIEOBSLUGIWANY_FORMAT = 'unsupported_format';

    public const POWOD_ZA_DUZO_MEGAPIKSELI = 'too_many_megapixels';

    /**
     * HEIC/HEIF: rozpoznany po magic bytes (`mime_content_type()`), ale
     * nieobsługiwany przez GD — patrz D-064. Osobny kod od `POWOD_NIECZYTELNY`
     * (patrz komentarz klasy wyżej, „OD ISSUE #119").
     */
    public const POWOD_HEIC_NIEOBSLUGIWANY = 'heic_unsupported';

    /**
     * `null`, gdy plik jest w porządku. Komunikat po polsku, gdy nie jest.
     *
     * Kolejność sprawdzeń jak w potoku mediów: od najtańszego do najdroższego.
     */
    public static function coJestNieTak(string $sciezka): ?string
    {
        return self::rozpoznaj($sciezka)?->komunikat;
    }

    /**
     * To samo sprawdzenie co `coJestNieTak()`, ale z kodem powodu obok
     * komunikatu — patrz `WynikRozpoznania` i komentarz klasy wyżej.
     */
    public static function rozpoznaj(string $sciezka): ?WynikRozpoznania
    {
        // `getimagesize()` czyta sam nagłówek, więc jest tanie i przy okazji
        // odpowiada na pytanie „czy to na pewno obraz", niezależnie od tego,
        // co mówi rozszerzenie i nagłówek Content-Type od klienta.
        $info = @getimagesize($sciezka);

        if ($info === false) {
            // `mime_content_type()` czyta MAGIC BYTES (nagłówek `ftyp`
            // z marką `heic`/`heif`/`mif1`), NIE rozszerzenie pliku i NIE
            // nagłówek `Content-Type` od przeglądarki — dokładnie to, czego
            // wymaga AGENTS.md §7. Plik `zdjecie.jpg`, w którym leżą bajty
            // HEIC, trafia więc TU, a nie do gałęzi formatów — to jest
            // celowe: `getimagesize()` już zawiódł na treści, rozszerzenie
            // nigdy nie wchodzi do gry.
            $wykryty = @mime_content_type($sciezka);

            if (in_array($wykryty, ['image/heic', 'image/heif'], true)) {
                return new WynikRozpoznania(self::POWOD_HEIC_NIEOBSLUGIWANY, self::komunikatHeic());
            }

            return new WynikRozpoznania(self::POWOD_NIECZYTELNY, self::komunikatNieczytelnegoPliku());
        }

        $wykryty = $info['mime'] ?? null;

        if (! in_array($wykryty, LimityZdjec::dozwoloneTypy(), true)) {
            return new WynikRozpoznania(
                self::POWOD_NIEOBSLUGIWANY_FORMAT,
                'Nie obsługujemy tego formatu zdjęć. Wybierz plik '.LimityZdjec::formatyDlaCzlowieka().'.',
            );
        }

        [$szerokosc, $wysokosc] = $info;

        // Obrona przed „decompression bomb": plik może mieć 2 MB, a obraz
        // 30 000 × 30 000 px i położyć workera przy dekodowaniu.
        $megapiksele = ($szerokosc * $wysokosc) / 1_000_000;

        if ($megapiksele > (int) config('kuking.media.max_megapixels')) {
            return new WynikRozpoznania(
                self::POWOD_ZA_DUZO_MEGAPIKSELI,
                'To zdjęcie ma za duże wymiary. Zmniejsz je i spróbuj ponownie.',
                ['megapixels' => round($megapiksele, 1)],
            );
        }

        return null;
    }

    /**
     * Co powiedzieć o pliku HEIC/HEIF — patrz D-064 w `docs/DECISIONS.md`.
     *
     * HEIC jest domyślnym formatem aparatu w iPhonie od 2017 roku, więc to nie
     * jest przypadek brzegowy — to najzwyklejszy sposób, w jaki nasza grupa
     * robi zdjęcia. Zwykle iOS konwertuje takie zdjęcie do JPEG przy wysyłce
     * sam (patrz D-064 §3), ale nie zawsze: przy udostępnianiu z Plików albo
     * z aplikacji innej niż aparat plik idzie w oryginale.
     *
     * DWA ZDANIA, DWIE RÓŻNE RZECZY — SPRAWDZONE OSOBNO (D-064 §3), NIE ZGADYWANE
     *   1. „Ustawienia → Aparat → Formaty → Najbardziej zgodne" to dosłowna
     *      ścieżka menu z oficjalnej pomocy Apple (support.apple.com/pl-pl/116944) —
     *      dotyczy PRZYSZŁYCH zdjęć, nie tego, które już leży w telefonie.
     *      Etykieta w rodzaju nijakim, zgodnie z polską pomocą — NIE
     *      „Najbardziej zgodny" (poprzednia wersja tego komunikatu miała tu
     *      błędną odmianę, poprawione przy #119 follow-up).
     *   2. Dla TEGO KONKRETNEGO zdjęcia: wysyłka e-mailem, AirDrop albo
     *      Wiadomościami. Ta sama strona Apple mówi, że przy udostępnianiu
     *      odbiorcy bez obsługi HEIC/HEVC, iOS **może** wysłać automatycznie
     *      w formacie zgodnym (JPEG/H.264) — „może", nie „wyśle". Apple wprost
     *      opisuje to jako zależne od SPOSOBU udostępniania i MOŻLIWOŚCI
     *      odbiorcy, nie jako gwarancję.
     *
     * OBIETNICA BEZ POKRYCIA — SPRAWDZONE I POPRAWIONE (#119 follow-up,
     * `docs/research/heic-119/RAPORT.md`, stanowisko `gpt/heic-format`):
     * wcześniejsza wersja tego komunikatu twierdziła bezwarunkowo „wyślij
     * najpierw do siebie e-mailem — przyjdzie jako JPG". To jest coś, czego
     * NIE KONTROLUJEMY — Apple opisuje wynik jako zależny od telefonu i
     * sposobu udostępniania. Człowiek, u którego to nie zadziała, zostawał
     * bez dalszej drogi i z poczuciem, że zrobił coś źle. Nie zastępujemy tej
     * obietnicy inną równie pewną (np. „na pewno zadziała Duplikuj" albo
     * konkretny zewnętrzny konwerter) — mówimy, że wynik zależy od telefonu,
     * dajemy prostą alternatywę (wybierz inne, gotowe zdjęcie) i osobno,
     * jasno, ustawienie na PRZYSZŁOŚĆ, które nie przerabia zdjęcia już
     * zrobionego.
     *
     * WCZEŚNIEJSZA WERSJA TEGO KOMUNIKATU DORADZAŁA TEŻ „otwórz w Zdjęciach
     * i użyj «Duplikuj»" — SPRAWDZONE I USUNIĘTE (issue #119, D-064 §3):
     * wykrywanie duplikatów w Photos porównuje pliki po FORMACIE, nie po
     * treści zdjęcia, co oznacza, że „Duplikuj" tworzy drugą kopię W TYM
     * SAMYM formacie (HEIC), nie konwertuje niczego. Był to zgadywany krok,
     * nie sprawdzony — dokładnie to, przed czym ostrzega zadanie #119
     * („sprawdź, co jest prawdą dla iPhone'a, nie zgaduj"). Lepiej podać
     * jedną drogę, która naprawdę działa, niż dwie, z których jedna nie robi
     * tego, co obiecuje.
     */
    private static function komunikatHeic(): string
    {
        return 'To zdjęcie jest w formacie HEIC, którego jeszcze nie umiemy otworzyć. '
            .'Dla TEGO zdjęcia: spróbuj wysłać je do siebie e-mailem, przez AirDrop albo '
            .'Wiadomości — telefon czasem sam zamienia je wtedy na JPG, ale zależy to od '
            .'modelu telefonu, więc nie zawsze się uda. Jeśli nadal jest HEIC, wybierz na '
            .'razie inne, gotowe zdjęcie w formacie JPG lub PNG. '
            .'Żeby kolejne zdjęcia zapisywały się od razu jako JPG: w iPhonie wejdź '
            .'w Ustawienia → Aparat → Formaty i wybierz „Najbardziej zgodne” — to ustawienie '
            .'nie zmieni zdjęcia, które już masz zrobione, dotyczy tylko nowych zdjęć.';
    }

    /**
     * Co powiedzieć, gdy PHP nie umie odczytać pliku jako obrazu i to NIE jest
     * HEIC/HEIF (ten format ma własny komunikat — `komunikatHeic()` wyżej).
     */
    private static function komunikatNieczytelnegoPliku(): string
    {
        return 'Ten plik nie wygląda na zdjęcie. Wybierz plik '
            .LimityZdjec::formatyDlaCzlowieka().'.';
    }
}
