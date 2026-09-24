/*
 * =============================================================================
 *  STRONA NIEAKTUALNA — odpowiedź 419 na żądanie Livewire (issue #977)
 * =============================================================================
 *
 *  Od #977 `livewire.release_token` idzie za SHA wdrożenia. Karta otwarta
 *  przed wdrożeniem dostaje na pierwszy ruch 419 (`LivewireReleaseToken-
 *  MismatchException`). Ten sam status daje wygasła sesja (CSRF).
 *
 *  Livewire 4.4.3 pokazuje wtedy SWOJE okienko: angielskie
 *  `confirm("This page has expired.\nWould you like to refresh the page?")`.
 *  Dla naszej grupy to jest ślepa uliczka — obcy język, systemowe okno,
 *  dwa przyciski bez kontekstu i żadnej informacji, co z tym, co już
 *  wpisane. Dlatego przechwytujemy błąd żądania (`Livewire.interceptRequest`
 *  → `onError` → `preventDefault()`) i pokazujemy własny komunikat w treści
 *  strony: po polsku, z przyciskiem „Odśwież stronę” i BEZ `window.confirm`.
 *
 *  TEKST NIE OBIECUJE WIĘCEJ, NIŻ WIEMY.
 *  Żądanie, które dostało 419, nie zostało wykonane — nic, co niosło, nie
 *  zapisało się. Kreator przepisu zapisuje szkic po kroku i po ~3 s przerwy
 *  w pisaniu, więc „szkic zostaje” jest prawdą tylko o szkicu zapisanym
 *  WCZEŚNIEJ; ostatnia zmiana mogła przepaść. Gdy szkicu nie ma wcale —
 *  mówimy wprost, że po odświeżeniu formularz będzie pusty. Zdjęcie
 *  w trakcie przesyłania nie dotarło do nas nigdy — mówimy, żeby dodać je
 *  jeszcze raz. Stan zapisu czytamy z `data-kreator-zapis`, który renderuje
 *  PHP przy każdym udanym renderze kreatora.
 */

const TEKST_ZDJECIA = 'Zdjęcie wybrane przed chwilą nie zostało przesłane. Po odświeżeniu dodaj je jeszcze raz.';

/**
 * Treść komunikatu dla danej sytuacji. Czysta funkcja — testowana wprost
 * w `strona-nieaktualna.test.mjs`.
 *
 * @param {{ zapis: 'szkic'|'opublikowany'|'brak'|null, zdjecieWToku: boolean }} stan
 *   `zapis` — `null`, gdy na stronie nie ma kreatora przepisu.
 * @returns {{ naglowek: string, akapity: string[] }}
 */
export function trescKomunikatu({ zapis, zdjecieWToku }) {
    const akapity = [
        'W międzyczasie wprowadziliśmy nową wersję Kukinga albo strona była otwarta bardzo długo. Ostatnia czynność na tej stronie nie została wykonana.',
    ];

    if (zapis === 'szkic') {
        akapity.push('Szkic zapisany wcześniej zostaje. Ostatnia zmiana w formularzu mogła się jednak nie zapisać — po odświeżeniu sprawdź ostatnio wpisane pole. Dłuższy tekst wpisany przed chwilą skopiuj, zanim odświeżysz stronę.');
    } else if (zapis === 'opublikowany') {
        akapity.push('Zmiany zapisane wcześniej zostają. Ostatnia zmiana w formularzu mogła się jednak nie zapisać — po odświeżeniu sprawdź ostatnio wpisane pole. Dłuższy tekst wpisany przed chwilą skopiuj, zanim odświeżysz stronę.');
    } else if (zapis === 'brak') {
        akapity.push('Ten przepis nie jest jeszcze zapisany. Po odświeżeniu formularz będzie pusty — skopiuj wpisany tekst, zanim odświeżysz stronę.');
    }

    if (zdjecieWToku) {
        akapity.push(TEKST_ZDJECIA);
    }

    akapity.push(zapis === null
        ? 'Odśwież stronę i zrób to jeszcze raz.'
        : 'Odśwież stronę, żeby pisać dalej.');

    return { naglowek: 'Ta strona jest nieaktualna', akapity };
}

/** Nazwy wewnętrznych akcji Livewire, którymi idzie przesyłanie pliku. */
const AKCJE_PRZESYLANIA = new Set(['_startUpload', '_finishUpload']);

/** Czy żądanie niosło przesyłanie zdjęcia (Livewire 4: `request.messages[].actions[].name`). */
export function niesiePrzesylanie(request) {
    try {
        return Array.from(request?.messages ?? []).some(
            (wiadomosc) => Array.from(wiadomosc?.actions ?? []).some((akcja) => AKCJE_PRZESYLANIA.has(akcja?.name)),
        );
    } catch {
        return false;
    }
}

/**
 * Podpina obsługę 419 pod Livewire. `okno` i `dokument` są parametrami
 * tylko po to, żeby dało się to wykonać poza przeglądarką.
 */
export function podlaczStronaNieaktualna(livewire, okno = window, dokument = document) {
    // Przesyłania rozpoczęte, a niezakończone: `id:property`. Livewire ogłasza
    // `livewire-upload-start` PRZED pierwszym żądaniem (`_startUpload`), więc
    // zdjęcie wybrane w nieaktualnej karcie jest tu, zanim przyjdzie 419.
    const przesylania = new Set();
    const klucz = (e) => `${e.detail?.id}:${e.detail?.property}`;

    okno.addEventListener('livewire-upload-start', (e) => przesylania.add(klucz(e)));
    for (const koniec of ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
        okno.addEventListener(koniec, (e) => przesylania.delete(klucz(e)));
    }

    // Komunikat już pokazany na tej stronie: `{ ramka, dopisek, zdjecie }`.
    let pokazany = null;

    livewire.interceptRequest(({ request, onError }) => {
        onError(({ response, preventDefault }) => {
            if (response?.status !== 419) {
                return;
            }

            // Bez tego Livewire otwiera angielskie `confirm()`.
            preventDefault();

            const stan = {
                zapis: stanZapisu(dokument),
                zdjecieWToku: przesylania.size > 0 || niesiePrzesylanie(request),
            };

            if (pokazany && dokument.getElementById(ID) === pokazany.ramka) {
                uzupelnijKomunikat(pokazany, stan);
            } else {
                pokazany = pokazKomunikat(dokument, okno, stan);
            }
        });
    });
}

function stanZapisu(dokument) {
    const kreator = dokument.querySelector('[data-kreator-zapis]');

    if (!kreator) {
        return null;
    }

    const zapis = kreator.getAttribute('data-kreator-zapis');

    return ['szkic', 'opublikowany', 'brak'].includes(zapis) ? zapis : 'brak';
}

const ID = 'strona-nieaktualna';

/**
 * Pierwsze 419 na stronie: buduje komunikat i przenosi na niego fokus.
 *
 * Budowa:  ramka [role=alert] — ogłaszana raz, przy wstawieniu
 *            ├─ nagłówek + akapity
 *            ├─ dopisek [aria-live=polite] — pusty, chyba że zdjęcie dojdzie później
 *            └─ przycisk „Odśwież stronę”
 */
function pokazKomunikat(dokument, okno, stan) {
    const { naglowek, akapity } = trescKomunikatu(stan);

    const ramka = dokument.createElement('div');
    ramka.id = ID;
    ramka.className = 'notice strona-nieaktualna';
    // `alert`: to odpowiedź na czynność wykonaną przed chwilą —
    // czytnik ekranu ma to przeczytać od razu, nie „kiedyś”.
    ramka.setAttribute('role', 'alert');

    const h = dokument.createElement('h2');
    h.id = `${ID}-naglowek`;
    h.tabIndex = -1;
    h.textContent = naglowek;

    // Kolejne 419 przychodzą co ~3 s, dopóki człowiek pisze w kreatorze
    // (`wire:model.live.debounce`). Nie wolno wtedy ani zabierać mu fokusu
    // z pola, ani ogłaszać całości od nowa — jedyna nowa wiadomość, jaka może
    // się pojawić, to zdjęcie, i ta idzie tu, grzecznie.
    const dopisek = dokument.createElement('p');
    dopisek.setAttribute('aria-live', 'polite');

    const przycisk = dokument.createElement('button');
    przycisk.type = 'button';
    przycisk.className = 'btn btn-primary';
    przycisk.textContent = 'Odśwież stronę';
    przycisk.addEventListener('click', () => okno.location.reload());

    ramka.replaceChildren(h, ...akapity.map((tekst) => {
        const p = dokument.createElement('p');
        p.textContent = tekst;
        return p;
    }), dopisek, przycisk);

    const miejsce = dokument.querySelector('main') ?? dokument.body;
    miejsce.prepend(ramka);

    // Komunikat stoi na górze treści — człowiek w połowie kreatora by go nie
    // zobaczył. Fokus na nagłówku przewija do niego i ustawia klawiaturę
    // tuż przed przyciskiem. TYLKO za pierwszym razem.
    h.focus();

    return { ramka, dopisek, zdjecie: stan.zdjecieWToku };
}

/**
 * Kolejne 419: bez fokusu i bez przebudowy. Stan zapisu się nie zmieni
 * (żaden render kreatora już nie przejdzie), więc jedyne, co może dojść,
 * to zdjęcie wybrane po pierwszym komunikacie.
 */
function uzupelnijKomunikat(pokazany, stan) {
    if (stan.zdjecieWToku && !pokazany.zdjecie) {
        pokazany.zdjecie = true;
        pokazany.dopisek.textContent = TEKST_ZDJECIA;
    }
}
