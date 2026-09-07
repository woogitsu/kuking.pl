<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Klucze koszyków limitera logowania — trzy osobne, żadnego z danymi
 * osobowymi w postaci jawnej (W7-01, R3 §5 i §10).
 *
 * DLACZEGO TO ISTNIEJE JAKO OSOBNA KLASA, A NIE JAKO SKLEJANIE STRINGÓW
 * W KONTROLERZE
 * Bo tak było, i to była luka. `LoginController` budował klucz jako
 * `mb_strtolower($login).'|'.$request->ip()`, a `RateLimiter` przekazuje go
 * do cache'u praktycznie nietkniętego — `cleanRateLimiterKey()` usuwa tylko
 * kilka znaków, NIE hashuje. Przy `CACHE_STORE=database` para adres e-mail
 * plus adres IP lądowała więc w tabeli `cache` w postaci jawnej. Zmierzone:
 *
 *     cache.key => kuking-cache-jan.kowalski@example.com|203.0.113.7
 *
 * To trzecie miejsce z surowym IP w bazie, obok `sessions.ip_address`
 * i `sessions.user_agent` — i jedyne NIEWIDOCZNE po nazwie kolumny, więc
 * żaden przegląd schematu by go nie znalazł.
 *
 * Standardowy middleware `throttle` hashuje swoje klucze; ten jeden ręczny
 * nie hashował. Wzorzec do naśladowania jest w repozytorium od dawna:
 * `AuditLogEntry::record()` soli adres kluczem aplikacji, zanim go zapisze.
 *
 * TRZY KOSZYKI, LICZBY Z `config/kuking.php`
 *   A. `para()`   — konto + adres: normalna pomyłka człowieka,
 *   B. `konto()`  — samo konto: atak rozproszony po wielu adresach, którego
 *                   koszyk przywiązany do adresu STRUKTURALNIE nie widzi,
 *   C. `adres()`  — sam adres: rozpylanie po wielu kontach z jednego miejsca.
 *
 * PRZY POPRAWNYM LOGOWANIU CZYŚCIMY A I B, NIGDY C. Gdyby czyściło się C,
 * napastnik zalogowałby się na WŁASNE, jednorazowe konto z tego samego
 * adresu, żeby zresetować licznik adresowy i wrócić do ataku na cudze konto.
 * To jest jednozdaniowa reguła, którą bardzo łatwo pominąć przy
 * implementacji — dlatego stoi tu, obok kodu, a nie tylko w raporcie.
 *
 * LOGIN NORMALIZUJEMY DO MAŁYCH LITER przed policzeniem skrótu, bo
 * `@Basia` i `@basia` to jedno konto (`WielkoscLiterWLoginieTest`).
 * Bez tego napastnik obchodziłby koszyk B samą zmianą wielkości liter.
 */
final class KluczeLimitow
{
    /** Koszyk A: ta osoba z tego miejsca. */
    public function para(string $login, string $adres): string
    {
        return 'logowanie:para:'.$this->skrot(mb_strtolower($login).'|'.$adres);
    }

    /** Koszyk B: to konto, skądkolwiek. */
    public function konto(string $login): string
    {
        return 'logowanie:konto:'.$this->skrot(mb_strtolower($login));
    }

    /** Koszyk C: to miejsce, na jakiekolwiek konto. */
    public function adres(string $adres): string
    {
        return 'logowanie:adres:'.$this->skrot($adres);
    }

    /**
     * HMAC, nie samo `hash()` z doklejoną solą.
     *
     * Różnica ma tu znaczenie praktyczne: `hash('sha256', $wartosc.$sekret)`
     * jest podatny na rozszerzanie wiadomości, a `hash_hmac` nie. Przy
     * kluczu limitera nie jest to atak o dużych konsekwencjach, ale nie ma
     * powodu wybierać słabszej konstrukcji, skoro obie są jednolinijkowe.
     *
     * `APP_KEY` żyje jako zmienna środowiskowa, nie w bazie — więc ktoś, kto
     * dostanie sam zrzut tabeli `cache`, nie odtworzy z tych skrótów ani
     * adresu e-mail, ani adresu IP.
     */
    private function skrot(string $wartosc): string
    {
        return Skrot::hmac($wartosc);
    }
}
