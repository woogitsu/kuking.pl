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
export function kluczStanu(recipeSlug, krok, stepId = null, fingerprint = null) {
    return `kuking.minutnik.${recipeSlug}.${stepId && fingerprint ? `id_${stepId}_${fingerprint}` : krok}`;
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
export function zapiszStan(sekundyCalkiem, terminEpoka, tozsamosc = null) {
    return JSON.stringify({sekundyCalkiem, terminEpoka, ...(tozsamosc ?? {})});
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
    const stan = odczytajTermin(zapisany, terazEpoka, terazMonotoniczny);

    if (!stan || stan.terminMonotoniczny <= terazMonotoniczny) {
        return null;
    }

    return stan;
}

/**
 * Jak długo po terminie zapis minutnika wciąż zasługuje na alarm.
 *
 * Minutnik, który skończył się podczas przeładowania albo gdy karta była
 * w tle, ma zadzwonić — to jego sens. Ale zapis sprzed godzin to minutnik
 * porzucony: człowiek kliknął „Zakończ gotowanie” bez „Anuluj”, a potem
 * wrócił do trybu gotowania tego samego przepisu w tej samej karcie.
 * Alarm „Minutnik kroku 3 skończył odliczanie.” byłby wtedy fałszywy —
 * garnka dawno nie ma na ogniu (przegląd #1301). 15 minut to dużo więcej
 * niż jakiekolwiek przeładowanie i mniej niż powrót do przepisu „na
 * później”.
 */
export const PRZETERMINOWANIE_NAJWYZEJ_MS = 15 * 60 * 1000;

/**
 * Jak `odczytajStan`, ale termin, który JUŻ minął, nie znika — wraca
 * z terminem w przeszłości. Potrzebne minutnikowi INNEGO kroku niż
 * widoczny (issue #1301): jeśli skończył się akurat w trakcie
 * przeładowania strony, alarm i tak musi zabrzmieć, a nie przepaść.
 * `null` dla zapisu pustego, uszkodzonego albo przeterminowanego o więcej
 * niż `PRZETERMINOWANIE_NAJWYZEJ_MS` — taki minutnik jest porzucony,
 * nie spóźniony.
 */
export function odczytajTermin(zapisany, terazEpoka, terazMonotoniczny) {
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

    if (terazEpoka - terminEpoka > PRZETERMINOWANIE_NAJWYZEJ_MS) {
        return null;
    }

    return {
        sekundyCalkiem,
        terminMonotoniczny: terazMonotoniczny + (terminEpoka - terazEpoka),
        stepId: typeof dane.stepId === 'string' ? dane.stepId : null,
        fingerprint: typeof dane.fingerprint === 'string' ? dane.fingerprint : null,
        krokPierwotny: Number.isInteger(dane.krokPierwotny) && dane.krokPierwotny > 0 ? dane.krokPierwotny : null,
    };
}

/** Aktualny numer tego samego, niezmienionego kroku; nigdy nie zgaduje po pozycji. */
export function aktualnyKrokMinutnika(stan, kroki) {
    if (!stan?.stepId || !stan.fingerprint || !Array.isArray(kroki)) return null;

    const indeks = kroki.findIndex((krok) => krok.id === stan.stepId);
    if (indeks < 0 || kroki[indeks].fingerprint !== stan.fingerprint) return null;

    return indeks + 1;
}

/**
 * Numer kroku z klucza `kluczStanu(recipeSlug, krok)` albo `null`, gdy
 * klucz należy do innego przepisu (albo w ogóle nie jest kluczem
 * minutnika). Odwrotność `kluczStanu` — po niej tryb gotowania znajduje
 * minutniki uruchomione w krokach, których teraz nie widać (issue #1301).
 */
export function krokZKlucza(klucz, recipeSlug) {
    const przedrostek = kluczStanu(recipeSlug, '');

    if (typeof klucz !== 'string' || !klucz.startsWith(przedrostek)) {
        return null;
    }

    const krok = klucz.slice(przedrostek.length);

    return /^[1-9]\d*$/.test(krok) || /^id_[0-9a-f-]{36}_[0-9a-f]{64}$/i.test(krok) ? krok : null;
}

/** Najdłuższy własny minutnik: 24 godziny (issue #2595). */
export const WLASNY_MINUTNIK_MAX_MINUT = 24 * 60;

/**
 * Sprawdza minuty wpisane albo wybrane przy kroku BEZ czasu autora.
 * Zwraca `{sekundy}` albo `{blad}` -- komunikat po polsku, mówiący, co zrobić.
 * Tylko pełne dodatnie minuty: puste, tekst, ułamki, zero i liczby ujemne
 * nie uruchamiają odliczania.
 */
export function sprawdzMinutyWlasne(tekst) {
    const wpisane = String(tekst ?? '').trim();

    if (wpisane === '') {
        return {blad: 'Wpisz, ile minut ma odliczać minutnik, na przykład 7.'};
    }

    if (/^-\s*\d/.test(wpisane)) {
        return {blad: 'Liczba minut nie może być ujemna. Wpisz liczbę większą od zera, na przykład 7.'};
    }

    if (!/^\d+$/.test(wpisane)) {
        return {blad: 'Wpisz pełną liczbę minut cyframi, na przykład 7.'};
    }

    const minuty = Number(wpisane);

    if (minuty < 1) {
        return {blad: 'Wpisz liczbę minut większą od zera, na przykład 7.'};
    }

    if (minuty > WLASNY_MINUTNIK_MAX_MINUT) {
        return {blad: `Najdłuższy minutnik to ${WLASNY_MINUTNIK_MAX_MINUT} minut (24 godziny). Wpisz mniejszą liczbę.`};
    }

    return {sekundy: minuty * 60};
}

/** `420` → `"7 min"`; podpis własnego minutnika w komunikacie. */
export function etykietaMinut(sekundy) {
    return `${Math.round(sekundy / 60)} min`;
}

/*
 * DODATKOWY CZAS PRZY MINUTNIKU KROKU (#2458, decyzja właściciela z 2.10.2026).
 *
 * Po 40 minutach garnek trzeba sprawdzić i dopiec jeszcze 5 — a „Uruchom
 * jeszcze raz” odlicza od nowa pełne 40. Dodatkowy czas zmienia WYŁĄCZNIE
 * lokalny termin tego jednego minutnika; czas autora (`timer_seconds`),
 * postęp, odhaczenia, porcje i minutniki innych kroków zostają bez zmian.
 * Arytmetyka jest tu, bez DOM-u, żeby widok pojedynczego kroku i kolejka
 * czytały ten sam zapis i ten sam termin.
 */

/** Najwięcej, co można dodać za jednym razem: 3 godziny. */
export const DODATKOWY_MINUTNIK_MAX_MINUT = 180;

/**
 * Sprawdza minuty wpisane w polu „Ile dodatkowych minut?”.
 * Zwraca `{sekundy}` albo `{blad}` — komunikat po polsku, mówiący, co zrobić.
 * Tylko pełne dodatnie minuty w limicie: puste, tekst, ułamki, zapis
 * naukowy, zero, liczby ujemne i zbyt duże nie zmieniają terminu.
 */
export function sprawdzDodatkoweMinuty(tekst) {
    const wpisane = String(tekst ?? '').trim();

    if (wpisane === '') {
        return {blad: 'Wpisz, ile dodatkowych minut dodać do minutnika, na przykład 5.'};
    }

    if (/^-\s*\d/.test(wpisane)) {
        return {blad: 'Liczba dodatkowych minut nie może być ujemna. Wpisz liczbę większą od zera, na przykład 5.'};
    }

    if (!/^\d+$/.test(wpisane)) {
        return {blad: 'Wpisz pełną liczbę minut cyframi, na przykład 5.'};
    }

    const minuty = Number(wpisane);

    if (!Number.isFinite(minuty) || minuty < 1) {
        return {blad: 'Wpisz liczbę minut większą od zera, na przykład 5.'};
    }

    if (minuty > DODATKOWY_MINUTNIK_MAX_MINUT) {
        return {blad: `Naraz można dodać najwyżej ${DODATKOWY_MINUTNIK_MAX_MINUT} minut (3 godziny). Wpisz mniejszą liczbę.`};
    }

    return {sekundy: minuty * 60};
}

/**
 * Nowy termin monotoniczny po dodaniu czasu: do POZOSTAŁEGO czasu, a jeśli
 * termin już minął (albo mija w tej chwili) — liczony od teraz. Nigdy od
 * pełnego czasu autora.
 */
export function nowyTerminPoDodaniu(terminMonotoniczny, terazMonotoniczny, dodatkoweSekundy) {
    return Math.max(terminMonotoniczny, terazMonotoniczny) + dodatkoweSekundy * 1000;
}
