<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Czy analityka Plausible NAPRAWDĘ działa — i pod jakim adresem.
 *
 * PO CO TA KLASA ISTNIEJE
 * Z tego samego powodu, dla którego istnieje `App\Support\Turnstile`: jedno
 * pytanie („czy analityka jest włączona") ma mieć jedną odpowiedź, z której
 * korzystają wszyscy — widok wstawiający znacznik `<script>` i polityka
 * bezpieczeństwa, która ten sam host musi dopuścić w nagłówku CSP.
 *
 * Gdyby te dwa miejsca pytały osobno, dałoby się dojść do stanu, w którym
 * skrypt JEST w HTML-u, a CSP go nie przepuszcza. To jest najgorszy możliwy
 * kształt tej awarii: strona wygląda normalnie, w dzienniku serwera nie ma
 * nic, przeglądarka odmawia po cichu w konsoli, a właściciel widzi w panelu
 * Plausible zero odwiedzin i nie ma jak zgadnąć dlaczego. Ten projekt złapał
 * już kilka wariantów „narzędzie melduje sukces, nie robiąc nic"
 * (`MAIL_MAILER=log`, martwy `kuking.media_disk`) — ten byłby kolejnym.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie sprawdza, czy domena jest ZAREJESTROWANA w panelu Plausible. Tego
 * z naszej strony zmierzyć się nie da: Plausible przyjmuje zdarzenie i po
 * cichu je odrzuca, gdy nie zna nazwy serwisu. Literówkę w `PLAUSIBLE_DOMENA`
 * widać wyłącznie po tym, że w panelu nic nie przybywa.
 */
final class Plausible
{
    /**
     * Czy w HTML-u ma się w ogóle pojawić znacznik analityki.
     *
     * Pusta domena znaczy „nie ma analityki" — i to jest stan domyślny
     * lokalnie, w testach i w CI (patrz komentarz przy
     * `kuking.analytics.plausible` w `config/kuking.php`).
     */
    public static function wlaczona(): bool
    {
        return self::domena() !== '' && self::host() !== '';
    }

    /** Nazwa serwisu, którą skrypt podaje Plausible — dokładnie jak w panelu. */
    public static function domena(): string
    {
        return trim((string) config('kuking.analytics.plausible.domena'));
    }

    /**
     * Adres źródłowy Plausible, BEZ ukośnika na końcu.
     *
     * To jest jednocześnie host, z którego pobieramy skrypt, i host, do
     * którego skrypt wysyła zdarzenia — dlatego trafia do CSP w DWÓCH
     * dyrektywach naraz (`script-src` i `connect-src`), a nie w jednej.
     * Sam `script-src` wystarczyłby do POBRANIA pliku i do niczego więcej:
     * `connect-src` nie dziedziczy z `default-src 'self'` tylko dlatego, że
     * jest w naszej polityce wypisany osobno, więc bez dopisania hosta
     * przeglądarka pobrałaby skrypt i zablokowała każde wysłane przez niego
     * zdarzenie. Panel byłby pusty przy poprawnie wyglądającej stronie.
     */
    public static function host(): string
    {
        return rtrim(trim((string) config('kuking.analytics.plausible.host')), '/');
    }

    /**
     * Pełny adres pliku ze skryptem.
     *
     * Wariant `script.js`, czyli PODSTAWOWY: odsłona strony i źródło wejścia.
     * Świadomie NIE bierzemy wariantów `script.hash.js`,
     * `script.outbound-links.js` ani `script.pageleave.js` — każdy z nich
     * dokłada nasłuch zdarzeń w przeglądarce, a na oba pytania, po które
     * właściciel sięgnął po analitykę („skąd ludzie przychodzą", „które
     * strony oglądają"), odpowiada już wariant podstawowy.
     */
    public static function adresSkryptu(): string
    {
        return self::host().'/js/script.js';
    }
}
