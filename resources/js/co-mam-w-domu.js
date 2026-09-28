/*
 * Podpowiedzi pod polem „Co masz w domu?” (D-285).
 *
 * Skrypt jest DODATKIEM: pole i przycisk „Dodaj do listy” działają bez niego
 * zwykłym POST-em. Tu tylko pobieramy nazwy ze słownika składników i rysujemy
 * je jako przyciski z napisem (≥ 48 px, 18 px — AGENTS.md §5). Dotknięcie
 * podpowiedzi WPISUJE ją w pole, nie wysyła formularza: człowiek widzi, co
 * zostanie dodane, i sam naciska „Dodaj do listy”.
 */

/**
 * Odpowiedź serwera → lista nazw do pokazania. Wszystko, co nie jest
 * niepustym tekstem, odpada; powtórzenia też.
 *
 * @param {unknown} dane
 * @param {number} [maks]
 * @returns {string[]}
 */
export function podpowiedziDoPokazania(dane, maks = 8) {
    if (!dane || typeof dane !== 'object' || !Array.isArray(dane.podpowiedzi)) return [];
    const wynik = [];
    for (const nazwa of dane.podpowiedzi) {
        if (typeof nazwa !== 'string') continue;
        const czysta = nazwa.trim();
        if (czysta === '' || wynik.includes(czysta)) continue;
        wynik.push(czysta);
        if (wynik.length >= maks) break;
    }
    return wynik;
}

/** @param {number} ile */
export function komunikatPodpowiedzi(ile) {
    if (ile === 0) return 'Brak podpowiedzi. Możesz dodać produkt tak, jak go wpisano.';
    if (ile === 1) return 'Jest 1 podpowiedź pod polem.';
    const reszta10 = ile % 10, reszta100 = ile % 100;
    const kilka = reszta10 >= 2 && reszta10 <= 4 && (reszta100 < 12 || reszta100 > 14);
    return `${kilka ? 'Są' : 'Jest'} ${ile} podpowiedzi pod polem.`;
}

function uruchom(korzen) {
    const pole = korzen.querySelector('#f-nazwa');
    const kontener = korzen.querySelector('[data-podpowiedzi]');
    const lista = korzen.querySelector('[data-podpowiedzi-lista]');
    const status = korzen.querySelector('[data-podpowiedzi-status]');
    if (!pole || !kontener || !lista || !status) return;

    let zegar = null, kontroler = null, numer = 0;

    const schowaj = () => { kontener.hidden = true; lista.replaceChildren(); };

    async function pobierz(fraza, moj) {
        kontroler?.abort();
        kontroler = new AbortController();
        try {
            const url = new URL(korzen.dataset.podpowiedziUrl, location.href);
            if (url.origin !== location.origin) return;
            url.searchParams.set('q', fraza);
            const odpowiedz = await fetch(url, { signal: kontroler.signal, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!odpowiedz.ok) throw new Error('transport');
            const nazwy = podpowiedziDoPokazania(await odpowiedz.json());
            if (moj !== numer) return;
            lista.replaceChildren(...nazwy.map(nazwa => {
                const li = document.createElement('li');
                const przycisk = document.createElement('button');
                przycisk.type = 'button';
                przycisk.className = 'btn btn-secondary';
                przycisk.textContent = nazwa;
                przycisk.addEventListener('click', () => {
                    pole.value = nazwa;
                    schowaj();
                    pole.focus();
                    status.textContent = `Wpisano „${nazwa}”. Naciśnij „Dodaj do listy”.`;
                });
                li.append(przycisk);
                return li;
            }));
            kontener.hidden = nazwy.length === 0;
            status.textContent = komunikatPodpowiedzi(nazwy.length);
        } catch (blad) {
            if (blad?.name === 'AbortError') return;
            schowaj();
            status.textContent = 'Nie udało się pobrać podpowiedzi. Wpisz nazwę sam i naciśnij „Dodaj do listy”.';
        }
    }

    pole.addEventListener('input', () => {
        clearTimeout(zegar);
        numer++;
        const fraza = pole.value.trim();
        if ([...fraza].length < 2 || [...fraza].length > 60) { schowaj(); return; }
        const moj = numer;
        zegar = setTimeout(() => pobierz(fraza, moj), 250);
    });
}

if (typeof document !== 'undefined') {
    document.querySelectorAll('[data-co-mam-w-domu]').forEach(uruchom);
}
