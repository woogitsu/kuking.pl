/*
 * Minutnik kroku w trybie gotowania — czysta arytmetyka, bez DOM-u,
 * osobno testowalna.
 *
 * DLACZEGO NIE `Date.now()` DO LICZENIA POZOSTAŁEGO CZASU (issue #751)
 *
 * Poprzedni kod liczył termin jako `Date.now() + sekundy * 1000`, a potem
 * w każdym kroku odliczania odejmował `Date.now()` od tego terminu.
 * `Date.now()` to zegar ŚCIENNY systemu — telefon albo komputer potrafi go
 * skorygować w trakcie gotowania (synchronizacja NTP, ręczna zmiana strefy
 * czasowej, przejście czasu letni/zimowy). Korekta w przód SKRACAŁA
 * odliczanie, korekta w tył je WYDŁUŻAŁA — minutnik na 5 minut potrafił
 * zadzwonić po trzech minutach albo wcale, w zależności od tego, co
 * akurat zrobił zegar systemowy, a nie od tego, ile czasu naprawdę minęło.
 *
 * `performance.now()` jest MONOTONICZNY: rośnie w stałym tempie, niezależnie
 * od korekt zegara ściennego. Różnica dwóch odczytów `performance.now()`
 * to naprawdę tyle czasu, ile minęło — dokładnie to, czego potrzebuje
 * odliczanie.
 */

/**
 * Sekundy pozostałe do terminu, liczone na zegarze monotonicznym.
 * Nigdy nie schodzi poniżej zera.
 */
export function pozostaloSekund(terminMonotoniczny, terazMonotoniczny) {
    return Math.max(0, Math.ceil((terminMonotoniczny - terazMonotoniczny) / 1000));
}

/** `125` → `"2:05"`. Sekundy zawsze na dwóch cyfrach. */
export function formatMinutySekundy(sekundy) {
    const calkowite = Math.max(0, Math.trunc(sekundy));
    const minuty = Math.floor(calkowite / 60);
    const reszta = calkowite % 60;

    return `${minuty}:${String(reszta).padStart(2, '0')}`;
}
