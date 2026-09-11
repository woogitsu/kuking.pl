<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Czy wejście kontem Facebooka NAPRAWDĘ działa — jedno pytanie, jedna odpowiedź.
 *
 * PO CO TA KLASA ISTNIEJE
 * Dokładnie z tego powodu, dla którego istnieje `App\Support\Google`
 * i `App\Support\Turnstile`: bez kluczy ta funkcja ma NIE ISTNIEĆ —
 * przycisku nie ma na ekranie, trasy odsyłają na logowanie, nic się nie
 * psuje. To jest stan domyślny lokalnie, w CI, w testach i we WSZYSTKICH
 * środowiskach preview, bo adresów powrotu z `*.up.railway.app` nie da się
 * wpisać u Meta (są losowe, a Meta dopasowuje adres znak w znak —
 * `docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md` §4.4).
 *
 * Gdyby na to pytanie odpowiadały dwa miejsca osobno (widok i kontroler),
 * dałoby się dojść do stanu „przycisk jest, droga nie działa" — czyli do
 * MARTWEGO PRZYCISKU, którego D-053 zakazuje wprost.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie sprawdza, czy klucze są PRAWIDŁOWE — tego bez odpytania Facebooka
 * prawdziwym kodem autoryzacyjnym zmierzyć się nie da. Zły identyfikator
 * aplikacji poznajemy dopiero z odpowiedzi Facebooka przy wymianie kodu;
 * wtedy — świadomie — odsyłamy człowieka na logowanie hasłem i krzyczymy
 * w dzienniku, zamiast zostawiać go przed ekranem, na którym nic nie działa.
 */
final class Facebook
{
    /**
     * ZAKRES: TYLE I ANI SŁOWA WIĘCEJ (issue #259, runbook §8).
     *
     * `public_profile` — identyfikator konta i imię (imię służy RAZ, jako
     * podpowiedź nazwy na ekranie domknięcia konta); `email` — adres e-mail.
     *
     * Powód jest ten sam co przy `Google::ZAKRES`, tylko mocniejszy, bo
     * działa tu podwójnie:
     *
     *  1. Każde dodatkowe uprawnienie to punkt na ekranie zgody Facebooka,
     *     na którym osoba 60+ ma prawo się wystraszyć i wyjść — i słusznie.
     *  2. Te dwa uprawnienia są JEDYNYMI, których Meta nie każe uzasadniać
     *     w przeglądzie aplikacji (App Review). Poproszenie o cokolwiek
     *     więcej — listę znajomych, zdjęcia, strony — zamienia tę funkcję
     *     z jednodniowej w tygodniową, a dokumentacja Meta mówi wprost:
     *     „Selecting unneeded permissions is a common reason for rejection
     *     during app review".
     *
     * Zdjęcia profilowego NIE bierzemy wcale (D-061: każde zdjęcie u nas
     * przechodzi przez moderację i przekodowanie, zdjęcie z zewnątrz weszłoby
     * POZA tę drogę). Tokenu długoterminowego nie bierzemy i nie zapisujemy:
     * po zalogowaniu nie wołamy żadnego API Facebooka, ani razu.
     */
    public const ZAKRES = 'public_profile,email';

    /**
     * Pola, o które pytamy węzeł `me` — i nic poza nimi.
     *
     * `email` MOŻE NIE PRZYJŚĆ i to nie jest awaria: dokumentacja Graph API
     * mówi o tym polu „This field will not be returned if no valid email
     * address is available" (konto założone na numer telefonu, odebrana
     * zgoda). Obsługą tego przypadku jest osobny ekran, nie wyjątek.
     */
    public const POLA = 'id,name,email';

    /**
     * DOMYŚLNA WERSJA GRAPH API — jedna stała, bo ma TERMIN WAŻNOŚCI.
     *
     * To jest różnica, której przy Google nie było i którą trzeba nosić
     * świadomie (runbook §7.3). Google ma dwa adresy niezmienne od lat;
     * Meta wersjonuje interfejs i dokumentacja mówi: „Each version will
     * remain for at least 2 years from release", a po wygaśnięciu „any calls
     * made to it will be defaulted to the next oldest, usable version".
     *
     * NAJGORSZE JEST TO, ŻE WYGAŚNIĘCIE NIE ZGŁASZA SIĘ SAMO: wywołania nie
     * zaczynają padać, tylko cicho spadają na starszą wersję — czyli działa
     * do dnia, w którym przestanie, i nikt nie wie którego. Dlatego numer
     * stoi TUTAJ (jedna stała, jedno miejsce do podniesienia, nadpisywalne
     * zmienną `FACEBOOK_GRAPH_WERSJA` bez wdrożenia kodu), a do przeglądu
     * kwartalnego w `docs/infra/DEPLOYMENT_RUNBOOK.md` doszła pozycja
     * „sprawdź, czy nasza wersja Graph API jeszcze żyje".
     *
     * `v25.0` — numer, który dokumentacja Meta pokazywała w przykładach
     * 10 września 2026 (runbook §13 odnotowuje, że aktualności tego numeru
     * nie dało się potwierdzić inaczej niż z tamtych przykładów).
     */
    public const WERSJA_GRAFU_DOMYSLNA = 'v25.0';

    /** Ekran zgody Facebooka — wersję wstawia `wersjaGrafu()`. */
    public static function adresAutoryzacji(): string
    {
        return 'https://www.facebook.com/'.self::wersjaGrafu().'/dialog/oauth';
    }

    /** Wymiana kodu na token dostępu — żądanie serwer-serwer, po TLS. */
    public static function adresTokenu(): string
    {
        return 'https://graph.facebook.com/'.self::wersjaGrafu().'/oauth/access_token';
    }

    /** Odczyt tożsamości: identyfikator, imię i (jeśli jest) adres e-mail. */
    public static function adresTozsamosci(): string
    {
        return 'https://graph.facebook.com/'.self::wersjaGrafu().'/me';
    }

    /**
     * Wersja Graph API, w której rozmawiamy z Facebookiem.
     *
     * Pusta wartość w środowisku NIE oznacza „bez wersji": adres bez wersji
     * jest u Meta dopuszczalny, ale wtedy wywołania idą na wersję
     * NAJSTARSZĄ Z ŻYWYCH, czyli tę, która wygaśnie najszybciej. Dlatego
     * pusta wartość wraca do stałej wyżej.
     */
    public static function wersjaGrafu(): string
    {
        $wersja = trim((string) config('kuking.facebook.wersja_grafu'));

        return $wersja === '' ? self::WERSJA_GRAFU_DOMYSLNA : $wersja;
    }

    /** Czy w ogóle mamy czym się przedstawić: OBA klucze muszą być ustawione. */
    public static function skonfigurowany(): bool
    {
        return self::identyfikatorKlienta() !== '' && self::sekretKlienta() !== '';
    }

    /**
     * Czy droga przez Facebooka jest dziś otwarta: mamy klucze ORAZ funkcja
     * nie jest wyłączona zmienną środowiskową.
     */
    public static function dziala(): bool
    {
        return self::skonfigurowany() && (bool) config('kuking.facebook.wlaczone', true);
    }

    /**
     * Identyfikator aplikacji — u Meta nazywa się **App ID** i jest
     * PUBLICZNY (wchodzi do adresu, na który odsyłamy człowieka).
     */
    public static function identyfikatorKlienta(): string
    {
        return trim((string) config('kuking.facebook.identyfikator_klienta'));
    }

    /**
     * NIE POKAZUJ TEGO NIGDZIE. U Meta nazywa się **App Secret**.
     *
     * Wychodzi wyłącznie w żądaniu serwer-serwer o token oraz jako klucz
     * HMAC-a w `appsecret_proof` — nie do widoku, nie do logu, nie do
     * komunikatu błędu (AGENTS.md §7). Ten sam sekret weryfikuje podpis
     * żądania usunięcia danych od Meta (runbook §9.4), więc jego wyciek to
     * nie tylko cudze logowanie. Rotacja u Meta jest jednym przyciskiem
     * („Reset"), więc w razie wątpliwości rotuj bez wahania.
     */
    public static function sekretKlienta(): string
    {
        return trim((string) config('kuking.facebook.sekret_klienta'));
    }

    public static function limitCzasu(): int
    {
        return max(1, (int) config('kuking.facebook.limit_czasu', 6));
    }

    /**
     * Ile minut wolno stać na ekranie domknięcia konta albo na ekranie
     * połączenia, zanim rozpoznana tożsamość z Facebooka przestanie się liczyć.
     */
    public static function waznoscDomknieciaMinut(): int
    {
        return max(1, (int) config('kuking.facebook.waznosc_domkniecia_minut', 30));
    }

    /**
     * Zdanie DLA WŁAŚCICIELA (dziennik, runbook) o tym, czego brakuje.
     * Użytkownika to nie dotyczy — on po prostu nie widzi przycisku.
     *
     * Sygnału w `/health` tu jeszcze nie ma, dokładnie tak jak przy Google
     * i z tego samego powodu (`HealthController` jest w rękach innego
     * zlecenia). Do tego czasu sprawdza się to okiem: wejdź na `/login`
     * i zobacz, czy jest przycisk „Wejdź kontem Facebooka".
     */
    public static function komunikatBrakuKluczy(): string
    {
        return 'Wejście kontem Facebooka jest włączone w `config/kuking.php`, ale nie ma kluczy: ustaw '
            .'FACEBOOK_CLIENT_ID i FACEBOOK_CLIENT_SECRET (w panelu Meta nazywają się App ID i App Secret: '
            .'Settings → Basic). Do tego czasu przycisku „Wejdź kontem Facebooka" nie ma na ekranie, '
            .'a hasło i link e-mail działają normalnie. Instrukcja krok po kroku: '
            .'docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md.';
    }
}
