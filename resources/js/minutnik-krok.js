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

/*
 * PRZETRWANIE PRZEŁADOWANIA STRONY (issue #740).
 *
 * Nawigacja „Poprzedni/Następny krok” i oznaczenie kroku jako zrobiony to
 * pełne przeładowania strony (patrz `CookingModeController` — pierwsze to
 * GET, drugie to POST z przekierowaniem). Oba zerują cały stan
 * JavaScriptu, łącznie z `performance.now()`, który liczy od załadowania
 * BIEŻĄCEGO dokumentu. Żeby aktywny minutnik przeżył taki powrót do tego
 * samego kroku, jego stan musi wyjść poza pamięć strony — stąd
 * `sessionStorage`, który przeżywa przeładowanie tej samej karty.
 */

/**
 * Klucz w `sessionStorage` dla minutnika JEDNEGO kroku JEDNEGO przepisu —
 * inaczej minutnik jednego kroku pokazywałby się jako aktywny na innym
 * kroku albo w innym przepisie otwartym w tej samej karcie.
 */
export function kluczStanu(recipeSlug, krok) {
    return `kuking.minutnik.${recipeSlug}.${krok}`;
}

/**
 * Stan minutnika do zapisania PRZED nawigacją: termin jako czas ŚCIENNY
 * (epoka, `Date.now()`), bo `performance.now()` nie przetrwa przeładowania
 * strony — zeruje się przy każdym nowym dokumencie. To jedyne miejsce
 * w tym module, gdzie zegar ścienny jest celowo używany: różnica dwóch
 * jego odczytów sprzed i po przeładowaniu jest z natury krótka (sekundy,
 * nie godziny), więc ryzyko, że akurat W TĘ CHWILĘ nastąpi korekta zegara
 * z issue #751, jest znikome — a bez zegara ściennego stan w ogóle nie
 * przetrwałby przeładowania strony.
 */
export function zapiszStan(sekundyCalkiem, terminEpoka) {
    return JSON.stringify({sekundyCalkiem, terminEpoka});
}

/**
 * Odczytuje zapisany stan i zamienia go z powrotem na termin na ŚWIEŻYM
 * zegarze monotonicznym tej strony — `performance.now()` po przeładowaniu
 * zaczyna liczyć od zera, więc stary termin monotoniczny nie ma już
 * znaczenia i trzeba go przeliczyć na nowo względem NOWEGO punktu zerowego.
 *
 * Zwraca `null`, gdy zapis jest uszkodzony, pusty albo termin już minął —
 * w tym ostatnim przypadku nie ma czego przywracać: krok nie miał otwartej
 * karty przez cały czas odliczania, więc pokazujemy stan sprzed startu
 * minutnika, nie zero.
 */
export function odczytajStan(zapisany, terazEpoka, terazMonotoniczny) {
    if (!zapisany) return null;

    let dane;

    try {
        dane = JSON.parse(zapisany);
    } catch {
        return null;
    }

    const {sekundyCalkiem, terminEpoka} = dane;

    if (!Number.isFinite(sekundyCalkiem) || !Number.isFinite(terminEpoka)) {
        return null;
    }

    const pozostaleMs = terminEpoka - terazEpoka;

    if (pozostaleMs <= 0) {
        return null;
    }

    return {
        sekundyCalkiem,
        terminMonotoniczny: terazMonotoniczny + pozostaleMs,
    };
}
