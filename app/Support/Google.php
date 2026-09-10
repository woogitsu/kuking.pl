<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Czy wejście kontem Google NAPRAWDĘ działa — jedno pytanie, jedna odpowiedź.
 *
 * PO CO TA KLASA ISTNIEJE
 * Dokładnie z tego powodu, dla którego istnieje `App\Support\Turnstile`:
 * bez kluczy ta funkcja ma NIE ISTNIEĆ — przycisku nie ma na ekranie, trasy
 * odsyłają na logowanie, nic się nie psuje. To jest stan domyślny lokalnie,
 * w CI i w testach.
 *
 * Gdyby na to pytanie odpowiadały dwa miejsca osobno (widok i kontroler),
 * dałoby się dojść do stanu „przycisk jest, droga nie działa" — czyli do
 * MARTWEGO PRZYCISKU, którego D-053 zakazuje wprost. Dlatego pyta o to
 * widok, pyta kontroler i pyta `/health`, a odpowiedź jest jedna.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie sprawdza, czy klucze są PRAWIDŁOWE — tego bez odpytania Google
 * prawdziwym kodem autoryzacyjnym zmierzyć się nie da. Zły identyfikator
 * klienta poznajemy dopiero z odpowiedzi Google przy wymianie kodu; wtedy —
 * świadomie — odsyłamy człowieka na logowanie hasłem i krzyczymy
 * w dzienniku, zamiast zostawiać go przed ekranem, na którym nic nie działa.
 */
final class Google
{
    /**
     * Adres, na który wysyłamy człowieka po zgodę.
     *
     * Adresy są WPISANE, a nie pobierane z dokumentu konfiguracyjnego
     * (`/.well-known/openid-configuration`). Odkrywanie ich w czasie
     * działania znaczyłoby jedno cudze żądanie HTTP więcej na ścieżce
     * logowania — i awarię logowania, gdyby ten jeden dokument był
     * nieosiągalny. Te trzy adresy nie zmieniły się u Google od lat, a gdy
     * się zmienią, jest to zmiana jednej stałej w jednym pliku.
     *
     * Sprawdzone 10 września 2026:
     * https://developers.google.com/identity/openid-connect/openid-connect
     */
    public const ADRES_AUTORYZACJI = 'https://accounts.google.com/o/oauth2/v2/auth';

    /** Wymiana kodu na token tożsamości — żądanie serwer-serwer, po TLS. */
    public const ADRES_TOKENU = 'https://oauth2.googleapis.com/token';

    /**
     * Wydawca tokenu tożsamości. Google wystawia jeden z dwóch zapisów
     * i oba są poprawne — dokumentacja wymienia je razem, więc sprawdzamy oba.
     */
    public const WYDAWCY = ['https://accounts.google.com', 'accounts.google.com'];

    /**
     * ZAKRES: TYLE I ANI SŁOWA WIĘCEJ (issue #258 pkt 5).
     *
     * `openid` — sam mechanizm tożsamości; `email` — adres i informacja
     * o tym, czy Google go potwierdziło (bez tego drugiego ta funkcja nie
     * ma prawa istnieć, patrz D-069); `profile` — imię, JEDYNIE jako
     * podpowiedź na ekranie domknięcia konta.
     *
     * Nie prosimy o kontakty, kalendarz ani nic z „Google API": każdy
     * dodatkowy zakres to ekran zgody, na którym człowiek 60+ ma prawo się
     * wystraszyć i wyjść — i słusznie.
     */
    public const ZAKRES = 'openid email profile';

    /** Czy w ogóle mamy czym się przedstawić: OBA klucze muszą być ustawione. */
    public static function skonfigurowany(): bool
    {
        return self::identyfikatorKlienta() !== '' && self::sekretKlienta() !== '';
    }

    /**
     * Czy droga przez Google jest dziś otwarta: mamy klucze ORAZ funkcja nie
     * jest wyłączona zmienną środowiskową.
     */
    public static function dziala(): bool
    {
        return self::skonfigurowany() && (bool) config('kuking.google.wlaczone', true);
    }

    public static function identyfikatorKlienta(): string
    {
        return trim((string) config('kuking.google.identyfikator_klienta'));
    }

    /**
     * NIE POKAZUJ TEGO NIGDZIE. Sekret wychodzi wyłącznie w ciele żądania
     * do adresu tokenu — nie do widoku, nie do logu, nie do komunikatu
     * błędu (AGENTS.md §7).
     */
    public static function sekretKlienta(): string
    {
        return trim((string) config('kuking.google.sekret_klienta'));
    }

    public static function limitCzasu(): int
    {
        return max(1, (int) config('kuking.google.limit_czasu', 6));
    }

    /**
     * Ile minut wolno stać na ekranie domknięcia konta, zanim rozpoznana
     * tożsamość z Google przestanie się liczyć.
     */
    public static function waznoscDomknieciaMinut(): int
    {
        return max(1, (int) config('kuking.google.waznosc_domkniecia_minut', 30));
    }

    /**
     * Zdanie DLA WŁAŚCICIELA (dziennik, `/health`, runbook) o tym, czego
     * brakuje. Użytkownika to nie dotyczy — on po prostu nie widzi przycisku.
     */
    public static function komunikatBrakuKluczy(): string
    {
        return 'Wejście kontem Google jest włączone w `config/kuking.php`, ale nie ma kluczy: ustaw '
            .'GOOGLE_CLIENT_ID i GOOGLE_CLIENT_SECRET (Google Cloud Console → Credentials → '
            .'OAuth client ID). Do tego czasu przycisku „Wejdź kontem Google" nie ma na ekranie, '
            .'a hasło i link e-mail działają normalnie. Instrukcja: '
            .'docs/infra/DEPLOYMENT_RUNBOOK.md, krok 8D.';
    }
}
