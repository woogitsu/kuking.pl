<?php

declare(strict_types=1);

namespace App\Support\Zdrowie;

/**
 * Zamknięty zbiór kodów powodów, które wolno pokazać w polu `error` odpowiedzi
 * `/health` (wyjęty z `HealthController`, issue #2212). Sondy zgłaszają powód
 * przez `KontrolaZdrowiaNieprzeszla`; kontroler wystawia ten zbiór jako
 * `HealthController::POWODY`.
 */
final class Powody
{
    /**
     * Zamknięty zbiór powodów, które WOLNO pokazać publicznie w polu `error`.
     *
     * Każdy z nich mówi operatorowi, gdzie szukać, i nie mówi nikomu innemu
     * nic o infrastrukturze: żadnego hosta, portu, nazwy bazy, ścieżki na
     * dysku ani kodu SQLSTATE. Reszta — z pełnym komunikatem wyjątku — idzie
     * do logu pod tym samym kodem, więc jedno da się połączyć z drugim.
     *
     * `HealthNieZdradzaSzczegolowTest` pilnuje, że w odpowiedzi nie pojawi
     * się nic spoza tego zbioru.
     */
    public const WSZYSTKIE = [
        self::POWOD_BAZA,
        self::POWOD_BRAK_MIGRACJI,
        self::POWOD_ZDJECIA,
        self::POWOD_ZAPIS_NIEMOZLIWY,
        self::POWOD_ODCZYT_NIEZGODNY,
        self::POWOD_BRAK_DROGI_PUBLICZNEJ,
        self::POWOD_DROGA_GDZIE_INDZIEJ,
        self::POWOD_TURNSTILE_BEZ_KLUCZY,
        self::POWOD_GOOGLE_BEZ_KLUCZY,
        self::POWOD_FACEBOOK_BEZ_KLUCZY,
        self::POWOD_ANALITYKA_BEZ_TOKENU,
        self::POWOD_POCZTA_NIE_WYSYLA,
        self::POWOD_ZADANIA_NIEUDANE,
        self::POWOD_WARIANTY_DOWODU_ZALEGLE,
        self::POWOD_CZYSZCZENIE_CDN_ZALEGLE,
        self::POWOD_LISTY_PRZEPADAJA,
        self::POWOD_LIMIT_POCZTY_WYCZERPANY,
        self::POWOD_SLAD_LISTOW_NIESPRAWDZALNY,
        self::POWOD_CZYSZCZENIE_CDN_WYLACZONE,
        self::POWOD_CZYSZCZENIE_CDN_ZLY_ADRES,
        self::POWOD_PILNY_ALARM_NIE_DOTARL,
        self::POWOD_KANAL_ALARMOWY_WYLACZONY,
        self::POWOD_SLAD_ALARMOW_NIESPRAWDZALNY,
        self::POWOD_MAGAZYN_ZLY_HOST,
        self::POWOD_DEBUG_WLACZONY,
        self::POWOD_SESJA_BEZ_SECURE,
    ];

    /**
     * `APP_DEBUG=true` na produkcji (audyt B10-04): strona błędu pokazuje
     * ślad stosu razem ze zmiennymi środowiska, czyli sekrety każdemu, kto
     * wywoła 500. `ENV APP_DEBUG=false` w `Dockerfile` przegrywa ze zmienną
     * z panelu, a poprawną wartość ustawia wyłącznie `.railway/railway.ts`.
     */
    public const POWOD_DEBUG_WLACZONY = 'debug_wlaczony';

    /**
     * Ciasteczko sesji bez `Secure` na produkcji (audyt B10-04) — przeglądarka
     * wyśle je także po HTTP, gdzie da się je podsłuchać.
     */
    public const POWOD_SESJA_BEZ_SECURE = 'sesja_bez_secure';

    /** Baza nie odpowiada albo odpowiada błędem — powód domyślny obu krytycznych sprawdzeń. */
    public const POWOD_BAZA = 'baza_nie_odpowiada';

    /** Połączenie z bazą jest, ale co najmniej jedna migracja z bieżącego obrazu aplikacji nie została wykonana (tabela pusta albo częściowy deploy). */
    public const POWOD_BRAK_MIGRACJI = 'brak_migracji';

    /** Worek na resztę awarii dysku ze zdjęciami — powód domyślny sprawdzenia `media`. */
    public const POWOD_ZDJECIA = 'zdjecia_niedostepne';

    /** Nie udało się zapisać pliku próbnego na dysku ze zdjęciami. */
    public const POWOD_ZAPIS_NIEMOZLIWY = 'zapis_niemozliwy';

    /** Zapis się udał, a odczyt zwrócił co innego albo się nie udał. */
    public const POWOD_ODCZYT_NIEZGODNY = 'odczyt_niezgodny';

    /** Brak `public/storage` — zdjęcia są na dysku, ale przeglądarka dostaje 404. */
    public const POWOD_BRAK_DROGI_PUBLICZNEJ = 'brak_drogi_publicznej';

    /** `public/storage` istnieje, ale prowadzi do innego katalogu niż dysk ze zdjęciami. */
    public const POWOD_DROGA_GDZIE_INDZIEJ = 'droga_publiczna_gdzie_indziej';

    /**
     * Turnstile jest włączony w konfiguracji, ale nie ma kluczy — na produkcji
     * to znaczy, że formularze publiczne stoją bez zapowiedzianej ochrony
     * (D-050). Kod bez nazwy zmiennej i bez fragmentu klucza: ta odpowiedź
     * jest publiczna.
     */
    public const POWOD_TURNSTILE_BEZ_KLUCZY = 'turnstile_bez_kluczy';

    /**
     * Wejście kontem Google jest włączone w konfiguracji, ale nie ma kluczy —
     * na produkcji to znaczy, że obiecanej drogi wejścia NIE MA, a nikt się
     * o tym nie dowie (D-069, issue #258). Kod bez nazwy zmiennej i bez
     * fragmentu klucza: ta odpowiedź jest publiczna.
     */
    public const POWOD_GOOGLE_BEZ_KLUCZY = 'google_bez_kluczy';

    /**
     * To samo dla Facebooka (issue #259). Osobny kod, bo osobna czynność
     * człowieka i osobny panel: Google Cloud Console to nie jest panel Meta,
     * a jeden wspólny powód „oauth_bez_kluczy" kazałby operatorowi zgadywać,
     * którego z dwóch dostawców szukać.
     */
    public const POWOD_FACEBOOK_BEZ_KLUCZY = 'facebook_bez_kluczy';

    /**
     * Polityka prywatności obiecuje analitykę odwiedzin, a tokenu nie ma —
     * czyli dokument PRAWNY opisuje przetwarzanie, którego nie ma (D-092).
     * Kod bez nazwy zmiennej i bez fragmentu tokenu: ta odpowiedź jest
     * publiczna. Sam token nie jest sekretem (stoi w HTML-u każdej strony),
     * ale nazwa zmiennej i odnośnik do runbooka to wskazówka dla kogoś, kto
     * szuka, po czym uderzyć — zostają w logu.
     */
    public const POWOD_ANALITYKA_BEZ_TOKENU = 'analityka_bez_tokenu';

    /**
     * `MAIL_MAILER` na produkcji to `log`/`array`/pusty, albo Laravel nie
     * potrafi w ogóle zbudować transportu — dokładnie to, o czym mówi
     * `App\Support\Poczta` (issue #234 obok liczy `failed_jobs`; to
     * sprawdzenie pyta o coś wcześniejszego: czy wysyłka ma w ogóle czym
     * ruszyć). Ten kod nigdy nie niesie treści wyjątku dostawcy — patrz
     * `sprawdzPoczte()`.
     */
    public const POWOD_POCZTA_NIE_WYSYLA = 'poczta_nie_wysyla';

    /** W `failed_jobs` leżą nieudane zadania kolejki, a nikt sam z siebie się o tym nie dowiaduje (D-057 §4). */
    public const POWOD_ZADANIA_NIEUDANE = 'zadania_nieudane';

    /** Zabezpieczony dowód bez zakończonego przeniesienia wariantów (#2437). */
    public const POWOD_WARIANTY_DOWODU_ZALEGLE = 'warianty_dowodu_zalegle';

    /**
     * W `mail_failures` leży co najmniej jeden nieodhaczony list, czyli
     * wiadomość do człowieka, która nie wyszła i nie wyjdzie (issue #234).
     */
    public const POWOD_LISTY_PRZEPADAJA = 'listy_przepadaja';

    /**
     * To samo, ale z powodu wyczerpanego dobowego limitu u dostawcy — osobny
     * kod, bo osobna czynność człowieka: nie ma czego naprawiać w kodzie,
     * trzeba poczekać do północy albo zmienić plan. Monitoring zewnętrzny
     * odróżnia więc „coś się psuje" od „skończyła się pula".
     */
    public const POWOD_LIMIT_POCZTY_WYCZERPANY = 'limit_poczty_wyczerpany';

    /**
     * Nie dało się sprawdzić śladu — najczęściej tabeli `mail_failures`
     * jeszcze nie ma, bo kod wdrożył się przed migracją. Osobny kod, żeby
     * brak tabeli nie meldował się jako „listy przepadają": alarm o awarii,
     * której nie ma, jest tak samo szkodliwy jak cisza o awarii, która jest.
     */
    public const POWOD_SLAD_LISTOW_NIESPRAWDZALNY = 'slad_listow_niesprawdzalny';

    /**
     * W `zalegle_czyszczenia_cdn` leżą adresy skasowanych zdjęć, których
     * cache CDN jeszcze nie wyczyszczono (#959): odłożone bez konfiguracji
     * Cloudflare albo po wyczerpaniu prób zadania. Każdy taki adres może się
     * nadal otwierać. Gaśnie sam, gdy `kuking:wyczysc-zalegle-cdn` je wyśle.
     */
    public const POWOD_CZYSZCZENIE_CDN_ZALEGLE = 'czyszczenie_cdn_zalegle';

    /**
     * `CLOUDFLARE_ZONE_ID` albo `CLOUDFLARE_PURGE_TOKEN` jest pusty, więc
     * `App\Jobs\PurgePublicMediaCache` NIC nie czyści (na produkcji odkłada
     * adresy do `zalegle_czyszczenia_cdn`, #959 — patrz `cdn_zalegle`).
     *
     * DLACZEGO TO MUSI STAĆ TUTAJ, A NIE TYLKO W LOGU ZADANIA
     * Zadanie zapisuje wtedy `Log::warning` i kończy się SUKCESEM: nie ma
     * wpisu w `failed_jobs`, nic nie jest czerwone, a kanał alarmowy
     * (`blad_webhook`) ma poziom ustawiony na sztywno na `error`, więc
     * ostrzeżenia w ogóle nie przyjmuje. Czyszczenie wyłączone wygląda więc
     * DOKŁADNIE tak samo jak czyszczenie, które działa — a to jest ten sam
     * rodzaj cichej porażki, co `turnstile_bez_kluczy` i `analityka_bez_tokenu`
     * (D-050): konfiguracja nie kłamie o awarii, tylko o tym, że coś JEST.
     *
     * ŚWIADOMIE `degraded`, A NIE PORAŻKA ZADANIA. Wyłącznik jest legalny
     * (`config/kuking.php`, sekcja `cdn_purge`), a kasowanie zdjęcia nie ma
     * prawa się nie udać dlatego, że nie ma czym wyczyścić cudzego cache'u.
     * Jedno zdanie, które nie gaśnie samo, jest tu właściwą ceną — nie
     * wywrócone wymazywanie konta.
     */
    public const POWOD_CZYSZCZENIE_CDN_WYLACZONE = 'czyszczenie_cdn_wylaczone';

    /**
     * Strefa i token są, ale `CLOUDFLARE_PURGE_ENDPOINT` (albo strefa
     * podstawiona w adres) nie daje adresu czyszczenia Cloudflare (#991,
     * D-250). Zadanie odmawia wysłania tokenu i pada — czyszczenie nie działa
     * NIGDY, więc to ten sam rodzaj cichej porażki co brak zmiennych, tylko
     * z inną naprawą.
     */
    public const POWOD_CZYSZCZENIE_CDN_ZLY_ADRES = 'czyszczenie_cdn_zly_adres';

    /**
     * W `reports` leży sprawa PILNA (treść seksualna albo cokolwiek
     * dotyczącego dziecka), o której nie poszedł alarm — issue #1051.
     * Kod nie mówi ani którą, ani czego dotyczy: ta odpowiedź jest publiczna.
     */
    public const POWOD_PILNY_ALARM_NIE_DOTARL = 'pilny_alarm_nie_dotarl';

    /**
     * To samo, ale z powodu pustego `KUKING_MODEL_ALARM_EMAIL` — osobny kod,
     * bo osobna czynność człowieka: nie ma czego naprawiać w kodzie i nie
     * pomoże ponowienie, trzeba wpisać adres. Ten sam podział, co między
     * `listy_przepadaja` a `limit_poczty_wyczerpany`.
     */
    public const POWOD_KANAL_ALARMOWY_WYLACZONY = 'kanal_alarmowy_wylaczony';

    /**
     * Nie dało się sprawdzić śladu alarmów — najczęściej kolumn
     * `reports.alarm_pilny_*` jeszcze nie ma, bo kod wdrożył się przed
     * migracją. Osobny kod z tego samego powodu co
     * `slad_listow_niesprawdzalny`: brak kolumny nie ma prawa meldować się
     * jako „pilna sprawa nie dotarła".
     */
    public const POWOD_SLAD_ALARMOW_NIESPRAWDZALNY = 'slad_alarmow_niesprawdzalny';

    /**
     * `AWS_ENDPOINT` któregoś dysku R2/S3 nie ma postaci
     * `https://<konto>.eu.r2.cloudflarestorage.com` (D-255). Dysk odmawia
     * wtedy budowy, więc zdjęcia, eksporty albo czujka kopii nie działają —
     * a tu widać DLACZEGO, zanim ktoś zacznie czytać ślady wyjątków. Kod bez
     * hosta: host niesie identyfikator konta Cloudflare, a `/health` czyta każdy.
     */
    public const POWOD_MAGAZYN_ZLY_HOST = 'magazyn_r2_zly_host';
}
