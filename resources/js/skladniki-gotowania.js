/*
 * Checklista przygotowania składników w trybie „Gotuję” (issue #2069).
 *
 * ZNACZENIE: „mam już odmierzone / odłożone” (mise en place), nie „już
 * dodane do garnka” — dlatego stan pokazuje słowo „Przygotowane”, a nie
 * przekreślenie, które sugerowałoby zużycie składnika.
 *
 * GDZIE ŻYJE STAN. W `sessionStorage` tej karty, pod kluczem przepisu i
 * efektywnej liczby porcji. Zmiana ilości usuwa poprzednie odhaczenia
 * tego przepisu, także przy powrocie do dawnej ilości —
 * tak samo jak zapamiętany przełącznik „Nie usypiaj ekranu” (#1302).
 * Każdy krok to osobny dokument (`?krok=N`), więc stan musi przeżyć
 * przeładowanie; nie może za to udawać danych konta ani synchronizacji
 * między urządzeniami. Druga karta zaczyna od zera. Serwer o tych odhaczeniach
 * nic nie wie — chyba że osoba włączyła zapamiętywanie na koncie (#2016):
 * wtedy widok renderuje zwykły formularz i ten moduł nie jest podłączany
 * (brak `data-przygotowanie`).
 *
 * KLUCZ TO ID SKŁADNIKA, nie pozycja ani nazwa: dwie „sole” w grupach
 * „Ciasto” i „Farsz” to dwa niezależne wiersze, a zmiana kolejności nie
 * przenosi odhaczenia na inny składnik. ID nieobecne na stronie (autor
 * zmienił listę) jest ignorowane i znika przy najbliższym zapisie, bo zapis
 * bierze tylko pola obecne w dokumencie.
 *
 * NIEZALEŻNE OD KROKÓW. „Wyczyść zaznaczenie składników” kasuje wyłącznie
 * własny klucz; odhaczenia kroków siedzą w sesji serwera i czyści je tylko
 * „Zacznij od początku”. Ten moduł nie wysyła niczego na serwer.
 *
 * BEZ SKRYPTU znacznik (cooking.blade.php) ma wszystkie kontrolki pod
 * `hidden` — odkrywa je dopiero `podlaczChecklisteSkladnikow()`.
 */

/** Klucz stanu w `sessionStorage` — osobny dla każdego przepisu. */
export function kluczPrzygotowania(recipeId, porcje) {
    return `kuking.skladniki.${recipeId}.${porcje}`;
}

/**
 * Zapisane ID z pamięci; wszystko, czego nie da się odczytać (brak pamięci,
 * zepsuty JSON, obcy kształt), znaczy „nic nie zaznaczono”.
 *
 * @param {{getItem(k: string): string|null}|null} pamiec
 * @returns {Set<string>}
 */
export function odczytajPrzygotowane(pamiec, klucz) {
    try {
        const surowe = JSON.parse(pamiec?.getItem(klucz) ?? '[]');

        return new Set(Array.isArray(surowe) ? surowe.filter((id) => typeof id === 'string' && id !== '') : []);
    } catch {
        return new Set();
    }
}

/**
 * Zapisuje dokładnie podane ID; pusta lista usuwa klucz, żeby po
 * wyczyszczeniu w pamięci karty nie zostawał pusty ślad.
 *
 * @returns {boolean} czy zapis się udał
 */
export function zapiszPrzygotowane(pamiec, klucz, ids) {
    try {
        if (!pamiec) return false;

        if (ids.length === 0) {
            pamiec.removeItem(klucz);
        } else {
            pamiec.setItem(klucz, JSON.stringify(ids));
        }

        return true;
    } catch {
        return false;
    }
}

/** Tekst licznika pod listą — słowami, nie samym paskiem postępu. */
export function opisLicznika(zaznaczone, wszystkie) {
    if (zaznaczone === 0) return 'Nie zaznaczono jeszcze żadnego składnika.';
    if (zaznaczone === wszystkie) return `Przygotowane wszystkie składniki: ${wszystkie} z ${wszystkie}.`;

    return `Przygotowane: ${zaznaczone} z ${wszystkie}. Zostało: ${wszystkie - zaznaczone}.`;
}

/** Dopisek w zwiniętym nagłówku sekcji — widać postęp bez rozwijania. */
export function opisSkrotu(zaznaczone, wszystkie) {
    return zaznaczone === 0 ? '' : ` · przygotowane ${zaznaczone} z ${wszystkie}`;
}

/**
 * Okablowanie jednej sekcji `<details data-przygotowanie>`.
 *
 * @param {HTMLElement} sekcja
 * @param {Storage|null} pamiec zwykle `window.sessionStorage`; bywa
 *        niedostępna (zablokowane dane witryny) — checklista działa wtedy
 *        w bieżącym dokumencie, a wstęp mówi uczciwie, że zniknie przy
 *        zmianie kroku.
 */
export function podlaczChecklisteSkladnikow(sekcja, pamiec) {
    if (sekcja.dataset.przygotowanieGotowe) return;

    const wiersze = [...sekcja.querySelectorAll('li[data-skladnik]')]
        .map((li) => ({
            id: li.dataset.skladnik ?? '',
            pole: li.querySelector('[data-przygotowanie-pole]'),
            stan: li.querySelector('[data-przygotowanie-stan]'),
        }))
        .filter((w) => w.id !== '' && w.pole instanceof HTMLInputElement);

    if (wiersze.length === 0) return;

    const wstep = sekcja.querySelector('[data-przygotowanie-wstep]');
    const akcje = sekcja.querySelector('[data-przygotowanie-akcje]');
    const licznik = sekcja.querySelector('[data-przygotowanie-licznik]');
    const wyczysc = sekcja.querySelector('[data-przygotowanie-wyczysc]');
    const skrot = sekcja.querySelector('[data-przygotowanie-podsumowanie]');
    const przepis = sekcja.dataset.przygotowanie ?? '';
    const porcje = sekcja.dataset.przygotowaniePorcje ?? 'brak';
    const klucz = kluczPrzygotowania(przepis, porcje);

    // Sprawdzamy pamięć zapisem próbnym — sama obecność obiektu nie znaczy,
    // że da się do niego pisać (Safari w trybie prywatnym, limit miejsca).
    let pamiecDziala = false;
    try {
        if (pamiec) {
            pamiec.setItem(`${klucz}.proba`, '1');
            pamiec.removeItem(`${klucz}.proba`);
            pamiecDziala = true;
        }
    } catch {
        pamiecDziala = false;
    }
    const magazyn = pamiecDziala ? pamiec : null;

    let inneIlosci = false;
    try {
        const prefiks = `kuking.skladniki.${przepis}.`;
        const wskaznik = `kuking.skladniki.kontekst.${przepis}`;
        const poprzedniePorcje = magazyn?.getItem(wskaznik) ?? null;
        const dawneKlucze = [];
        for (let i = 0; i < (magazyn?.length ?? 0); i += 1) {
            const znaleziony = magazyn.key(i);
            if (znaleziony?.startsWith(prefiks) && !znaleziony.endsWith('.proba')) {
                dawneKlucze.push(znaleziony);
            }
        }
        const staryKlucz = `kuking.skladniki.${przepis}`;
        const bylStaryZapis = (magazyn?.getItem(staryKlucz) ?? null) !== null;
        inneIlosci = (poprzedniePorcje !== null && poprzedniePorcje !== porcje)
            || bylStaryZapis || (poprzedniePorcje === null && dawneKlucze.some((k) => k !== klucz));
        if (inneIlosci) {
            // Najpierw lista kluczy, potem kasowanie: indeksy Storage przesuwają się.
            for (const innyKlucz of dawneKlucze) magazyn.removeItem(innyKlucz);
            magazyn.removeItem(staryKlucz);
        }
        magazyn?.setItem(wskaznik, porcje);
    } catch {
        // Zablokowana pamięć nie przeszkadza w bieżącej checkliście.
    }
    const zapisane = odczytajPrzygotowane(magazyn, klucz);

    const odswiez = ({ ogloszenie = null } = {}) => {
        const zaznaczone = wiersze.filter((w) => w.pole.checked).length;

        wiersze.forEach((w) => {
            if (w.stan) w.stan.hidden = !w.pole.checked;
            w.pole.closest('li')?.classList.toggle('cook-skladnik-przygotowany', w.pole.checked);
        });

        if (licznik) licznik.textContent = ogloszenie ?? opisLicznika(zaznaczone, wiersze.length);
        if (wyczysc) wyczysc.hidden = zaznaczone === 0;
        if (skrot) {
            skrot.textContent = opisSkrotu(zaznaczone, wiersze.length);
            skrot.hidden = zaznaczone === 0;
        }
    };

    const zapisz = () => zapiszPrzygotowane(
        magazyn,
        klucz,
        wiersze.filter((w) => w.pole.checked).map((w) => w.id),
    );

    wiersze.forEach((w) => {
        w.pole.checked = zapisane.has(w.id);
        w.pole.hidden = false;
        w.pole.addEventListener('change', () => {
            zapisz();
            odswiez();
        });
    });

    wyczysc?.addEventListener('click', () => {
        wiersze.forEach((w) => { w.pole.checked = false; });
        zapisz();
        // Przycisk zaraz się schowa (nie ma już czego czyścić) — fokus idzie
        // na pierwszy składnik, zamiast przepaść na `<body>`.
        odswiez({ ogloszenie: 'Wyczyszczono zaznaczenie składników. Odhaczenia kroków zostały bez zmian.' });
        wiersze[0].pole.focus();
    });

    if (wstep) {
        if (!magazyn) {
            wstep.textContent = 'Możesz zaznaczyć składniki, które już masz odmierzone. Ta przeglądarka nie pozwala ich zapamiętać — zaznaczenie zniknie po przejściu do innego kroku.';
        } else if (inneIlosci && zapisane.size === 0) {
            wstep.textContent = 'Liczba porcji się zmieniła. Sprawdź nowe ilości i zaznacz ponownie składniki, które masz już odmierzone. Odhaczenia kroków pozostały bez zmian.';
        }
        wstep.hidden = false;
    }
    if (akcje) akcje.hidden = false;

    sekcja.dataset.przygotowanieGotowe = '1';
    odswiez();
}

function init() {
    let pamiec = null;

    try {
        pamiec = window.sessionStorage;
    } catch {
        // Zablokowane dane witryny: checklista działa bez pamięci.
    }

    document.querySelectorAll('details[data-przygotowanie]').forEach((sekcja) => {
        podlaczChecklisteSkladnikow(sekcja, pamiec);
    });
}

if (typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}
