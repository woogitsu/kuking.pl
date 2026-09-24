/*
 * „Pokaż więcej” naprawdę DOKŁADA kolejną porcję do listy (issue #986).
 *
 * CO JEST BEZ SKRYPTU
 * `<x-show-more>` przychodzi z serwera jako zwykły odnośnik do następnej
 * strony i tak też się nazywa: „Następna strona wpisów”. Kliknięcie ładuje
 * nowy dokument z samą kolejną porcją — to uczciwa paginacja, a etykieta
 * mówi dokładnie to, co się stanie.
 *
 * CO DOKŁADA TEN MODUŁ (D-053: z wygody, nie z obowiązku)
 * Dopiero gdy wiemy, że dokładanie zadziała — jest lista o podanym `id`,
 * jest `fetch` i `DOMParser` — odnośnik zamieniamy na przycisk „Pokaż więcej
 * wpisów”. Kliknięcie pobiera TĘ SAMĄ stronę, którą otworzyłby odnośnik,
 * wyjmuje z niej listę o tym samym `id` i dokleja jej elementy na końcu.
 * Nic nie jest wymyślane po stronie przeglądarki: kolejność, widoczność
 * i granice porcji zostają w rękach kontrolera i paginatora.
 *
 *  - Wcześniejsze elementy zostają. Doklejamy tylko elementy z tożsamością
 *    (`data-klucz` albo `id`); duplikat — np. po przesunięciu OFFSET-u, gdy
 *    między żądaniami ktoś coś opublikował — nie jest doklejany drugi raz.
 *  - Fokus trafia na pierwszy doklejony element — stoi dokładnie tam, gdzie
 *    był przycisk, więc strona nie skacze, a Tab idzie dalej po NOWYCH
 *    kartach, nie przeskakuje ich (WCAG 2.4.3, technika SCR26).
 *  - Region `aria-live="polite"` podaje prawdziwą liczbę doklejonych
 *    elementów, raz na porcję. Przy błędzie milczy — błąd ma własny komunikat
 *    i przycisk zostaje, żeby dało się spróbować jeszcze raz.
 *  - Adres dostaje `porcje_<nazwa-paginatora>=N`. Odświeżenie albo powrót
 *    z otwartej karty dokłada te N porcji od nowa (świeże dane, nie kopia
 *    starego HTML-u) i przewija tam, gdzie człowiek był.
 *  - Po ostatniej porcji przycisk znika — bez pustego dodatkowego żądania,
 *    bo o końcu mówi brak kolejnego odnośnika w pobranej stronie.
 */

const MAKS_PORCJI = 20;
const STAN_PRZEWINIECIA = 'pokazWiecejY';

function parametr(klucz) {
    return `porcje_${klucz}`;
}

function bezParametru(adres, klucz) {
    const url = new URL(adres, location.href);
    url.searchParams.delete(parametr(klucz));

    return url.toString();
}

function odczytajPorcje(klucz) {
    const wartosc = Number.parseInt(new URL(location.href).searchParams.get(parametr(klucz)) ?? '', 10);

    return Number.isFinite(wartosc) && wartosc > 0 ? Math.min(wartosc, MAKS_PORCJI) : 0;
}

function zapiszPorcje(klucz, liczba) {
    const url = new URL(location.href);

    if (liczba > 0) {
        url.searchParams.set(parametr(klucz), String(liczba));
    } else {
        url.searchParams.delete(parametr(klucz));
    }

    try {
        history.replaceState(history.state, '', url.toString());
    } catch {
        // Adres to wygoda na odświeżenie; lista i tak już jest na ekranie.
    }
}

/* Tożsamość elementu listy — tylko jawna, nigdy zgadywana. */
function kluczElementu(element) {
    if (element.dataset?.klucz) return element.dataset.klucz;
    if (element.id) return element.id;

    return element.querySelector?.('[data-klucz]')?.dataset.klucz ?? null;
}

function ulepsz(blok) {
    if (blok.dataset.pokazWiecejGotowe !== undefined) return null;

    const klucz = blok.dataset.pokazWiecej;
    const lista = document.getElementById(blok.dataset.pokazWiecejLista ?? '');
    const odnosnik = blok.querySelector('a[href]');
    const ogloszenie = blok.querySelector('[data-pokaz-wiecej-ogloszenie]');
    const blad = blok.querySelector('[data-pokaz-wiecej-blad]');
    const czego = blok.dataset.pokazWiecejCzego ?? 'wpisów';

    if (! klucz || ! lista || ! odnosnik || ! ogloszenie || ! blad
        || typeof fetch !== 'function' || typeof DOMParser !== 'function') {
        return null;
    }

    blok.dataset.pokazWiecejGotowe = '';

    const etykieta = blok.dataset.pokazWiecejEtykieta ?? `Pokaż więcej ${czego}`;
    const przycisk = document.createElement('button');
    przycisk.type = 'button';
    przycisk.className = odnosnik.className;
    przycisk.textContent = etykieta;
    odnosnik.replaceWith(przycisk);

    let nastepny = bezParametru(odnosnik.href, klucz);
    let porcje = 0;
    let zajety = false;

    function pokazBlad() {
        ogloszenie.textContent = '';
        blad.textContent = `Nie udało się wczytać kolejnych ${czego}. Lista wyżej zostaje bez zmian. `
            + `Sprawdź połączenie z internetem i naciśnij „${etykieta}” jeszcze raz.`;
        blad.hidden = false;
    }

    async function doloz({ogloszenieWyniku = true, fokus = true} = {}) {
        if (zajety || ! nastepny) return false;

        zajety = true;
        przycisk.setAttribute('aria-disabled', 'true');
        przycisk.textContent = `Wczytuję kolejne ${czego}…`;
        blad.hidden = true;
        blad.textContent = '';

        try {
            const odpowiedz = await fetch(nastepny, {
                credentials: 'same-origin',
                headers: {Accept: 'text/html'},
            });

            if (! odpowiedz.ok) throw new Error(`HTTP ${odpowiedz.status}`);

            const dokument = new DOMParser().parseFromString(await odpowiedz.text(), 'text/html');
            const nowaLista = dokument.getElementById(lista.id);

            // Przekierowanie na logowanie albo inna strona bez tej listy to
            // błąd, nie „pusta porcja” — inaczej przycisk zniknąłby, udając
            // koniec listy.
            if (! nowaLista) throw new Error('Brak listy w odpowiedzi');

            const znane = new Set([...lista.children].map(kluczElementu).filter(Boolean));
            const doklejone = [];

            for (const element of [...nowaLista.children]) {
                const kluczNowego = kluczElementu(element);

                // Element bez tożsamości to np. pusty stan z `@forelse`, gdy
                // dalsza strona zdążyła opustoszeć — nie jest pozycją listy
                // i nie ma czego po nim doklejać.
                if (kluczNowego === null || znane.has(kluczNowego)) continue;
                znane.add(kluczNowego);

                const kopia = document.importNode(element, true);
                lista.append(kopia);
                doklejone.push(kopia);
            }

            const dalej = dokument.querySelector(`[data-pokaz-wiecej="${CSS.escape(klucz)}"] a[href]`);
            nastepny = dalej ? bezParametru(dalej.getAttribute('href'), klucz) : null;
            porcje++;
            zapiszPorcje(klucz, porcje);

            if (ogloszenieWyniku) {
                const koniec = nastepny ? '' : ' To już koniec listy.';
                // Nagłówki (np. miesiąc na profilu) porządkują listę, ale nie
                // są jej pozycjami — liczba ma mówić o kartach.
                const nowych = doklejone.filter((e) => ! /^H[1-6]$/.test(e.tagName)).length;
                ogloszenie.textContent = nowych > 0
                    ? `Załadowano więcej ${czego}: ${nowych}.${koniec}`
                    : `Nie ma nowych ${czego} do pokazania.${koniec}`;
            }

            if (! nastepny) {
                // Przycisk znika — fokus nie może zostać na usuniętym elemencie.
                przycisk.remove();
            }

            const cel = doklejone[0];

            if (cel && fokus) {
                if (! cel.hasAttribute('tabindex')) cel.setAttribute('tabindex', '-1');
                cel.focus({preventScroll: true});
            }

            return Boolean(nastepny);
        } catch {
            pokazBlad();

            return false;
        } finally {
            zajety = false;
            if (przycisk.isConnected) {
                przycisk.removeAttribute('aria-disabled');
                przycisk.textContent = etykieta;
            }
        }
    }

    przycisk.addEventListener('click', () => {
        doloz();
    });

    return {
        async odtworz(ile) {
            for (let i = 0; i < ile; i++) {
                if (! await doloz({ogloszenieWyniku: false, fokus: false})) break;
            }
        },
    };
}

function zapamietajPrzewiniecie() {
    try {
        history.replaceState({...(history.state ?? {}), [STAN_PRZEWINIECIA]: window.scrollY}, '', location.href);
    } catch {
        // Bez zapamiętanej pozycji powrót zaczyna od góry — lista i tak wraca cała.
    }
}

export function uruchom() {
    const odtworzenia = [];
    let ulepszone = 0;

    for (const blok of document.querySelectorAll('[data-pokaz-wiecej][data-pokaz-wiecej-lista]')) {
        const sterownik = ulepsz(blok);

        if (! sterownik) continue;

        ulepszone++;
        const ile = odczytajPorcje(blok.dataset.pokazWiecej);

        if (ile > 0) odtworzenia.push(sterownik.odtworz(ile));
    }

    if (ulepszone === 0) return Promise.resolve();

    // Pozycję pamiętamy przy każdym wyjściu ze strony. Przeglądarka sama
    // odtwarza przewinięcie tylko wtedy, gdy treść jest od razu na miejscu —
    // tu dokładamy ją dopiero po wczytaniu, więc robimy to sami.
    let czasomierz = null;
    addEventListener('pagehide', zapamietajPrzewiniecie);
    addEventListener('scroll', () => {
        clearTimeout(czasomierz);
        czasomierz = setTimeout(zapamietajPrzewiniecie, 300);
    }, {passive: true});

    if (odtworzenia.length === 0) return Promise.resolve();

    const y = history.state?.[STAN_PRZEWINIECIA];

    if ('scrollRestoration' in history) history.scrollRestoration = 'manual';

    return Promise.all(odtworzenia).then(() => {
        if (typeof y === 'number') window.scrollTo(0, y);
    });
}

if (typeof document !== 'undefined' && document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', uruchom, {once: true});
} else if (typeof document !== 'undefined') {
    uruchom();
}
