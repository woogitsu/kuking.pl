/*
 * DYKTOWANIE WŁASNEGO TEKSTU (issue #2377, decyzja właściciela z 1.10.2026
 * „Budujemy z ostrzeżeniem”, D-333).
 *
 * CO TO JEST. Przycisk „Dyktuj” przy polu tekstowym. Rozpoznawanie mowy robi
 * PRZEGLĄDARKA (Web Speech API) i to ona może wysyłać dźwięk do swojego
 * dostawcy (np. Google albo Apple). Kuking nie nagrywa dźwięku, nie dostaje
 * go i nie ma serwera, który by go przyjął — do serwisu trafia wyłącznie
 * tekst, który człowiek sam wstawi.
 *
 * D-053: BEZ SKRYPTU NIE MA MARTWEGO PRZYCISKU. W HTML-u stoi tylko pusty
 * host (`<div data-dyktowanie data-cel="…" wire:ignore>`). Przycisk, podgląd
 * i zdanie o dostawcy dorysowuje ten plik — i tylko wtedy, gdy przeglądarka
 * ma `SpeechRecognition` albo `webkitSpeechRecognition`. `wire:ignore` chroni
 * dorysowaną zawartość przed przerysowaniem przez Livewire (kreator),
 * a `MutationObserver` obsługuje wiersze dodane później i sprząta po tych,
 * które zniknęły.
 *
 * TRANSKRYPCJA NIE WCHODZI DO POLA. Ląduje w osobnym podglądzie. Dopiero
 * „Wstaw” dopisuje ją na końcu pola, a „Anuluj” czyści podgląd. Żadna droga
 * tego pliku nie zastępuje wartości pola i żadna nie kasuje tekstu bez słowa:
 * gdy podyktowany tekst nie mieści się w limicie pola, NIE jest wstawiany,
 * tylko zostaje w podglądzie razem z komunikatem.
 *
 * CZYTNIK EKRANU. Podgląd NIE jest obszarem `aria-live` — przy wynikach
 * tymczasowych („mąk”, „mąka”, „mąka i”) zasypałby czytnik. Zmiany faz
 * ogłasza osobny, krótki status.
 *
 * Maszyna stanów jest oddzielona od DOM i eksportowana; `dyktowanie.test.mjs`
 * sprawdza ją i warstwę DOM w Node z atrapami.
 */

export const JEZYK = 'pl-PL';

/** Ile milisekund bez żadnego wyniku uznajemy za ciszę. */
export const LIMIT_CISZY_MS = 10000;

/** Ile czekamy na `onend` po „Zakończ”, zanim sami zakończymy (Safari/iOS bywa, że go nie wyśle). */
export const LIMIT_ZAKONCZENIA_MS = 3000;

export const ZDANIE_O_DOSTAWCY = 'Dyktowanie obsługuje Twoja przeglądarka i może wysyłać dźwięk do swojego dostawcy (np. Google lub Apple). Kuking nie nagrywa dźwięku i nie dostaje go — tylko tekst, który wstawisz do przepisu.';

export const KOMUNIKATY = {
    odmowa: 'Przeglądarka nie dostała zgody na mikrofon. Zezwól na mikrofon w ustawieniach tej strony (kłódka przy adresie), a potem naciśnij „Dyktuj” jeszcze raz. Możesz też wpisać tekst klawiaturą.',
    usluga: 'Dyktowanie jest wyłączone w ustawieniach telefonu albo przeglądarki. Włącz rozpoznawanie mowy w ustawieniach urządzenia, a potem naciśnij „Dyktuj” jeszcze raz. Możesz też wpisać tekst klawiaturą.',
    siec: 'Brak połączenia z usługą rozpoznawania mowy. Sprawdź internet i naciśnij „Dyktuj” jeszcze raz. Możesz też wpisać tekst klawiaturą.',
    cisza: 'Nic nie usłyszeliśmy. Naciśnij „Dyktuj”, a potem powiedz zdanie blisko mikrofonu.',
    brakMikrofonu: 'Nie znaleziono mikrofonu. Podłącz mikrofon i naciśnij „Dyktuj” jeszcze raz. Możesz też wpisać tekst klawiaturą.',
    inny: 'Dyktowanie się nie udało. Naciśnij „Dyktuj” jeszcze raz albo wpisz tekst klawiaturą.',
};

export const STATUSY = {
    slucham: 'Słucham… powiedz, co dopisać.',
    fragment: 'Rozpoznano fragment. Mów dalej albo naciśnij „Zakończ dyktowanie”.',
    podglad: 'Gotowe — sprawdź tekst i wstaw go do pola albo anuluj.',
};

/** Komunikat, gdy podyktowany tekst nie mieści się w limicie pola. */
export function komunikatLimitu(brakuje, limit) {
    return `Ten tekst nie mieści się w polu — brakuje miejsca na ${brakuje} ${brakuje === 1 ? 'znak' : 'znaków'} (limit pola to ${limit}). Nic nie wstawiliśmy. Skróć pole albo naciśnij „Anuluj” i podyktuj krócej.`;
}

/** Kod błędu Web Speech API → komunikat po polsku (albo null, gdy to nie błąd dla człowieka). */
export function komunikatBledu(kod) {
    switch (kod) {
    case 'not-allowed':
        return KOMUNIKATY.odmowa;
    case 'service-not-allowed':
        return KOMUNIKATY.usluga;
    case 'network':
        return KOMUNIKATY.siec;
    case 'no-speech':
        return KOMUNIKATY.cisza;
    case 'audio-capture':
        return KOMUNIKATY.brakMikrofonu;
    case 'aborted':
        // Przerwaliśmy sami („Anuluj”, „Zakończ”) — człowiek o tym wie.
        return null;
    default:
        return KOMUNIKATY.inny;
    }
}

/** Konstruktor rozpoznawania z danego `window`, albo null (wtedy przycisku nie ma). */
export function znajdzRozpoznawanie(okno) {
    if (!okno) return null;
    return okno.SpeechRecognition || okno.webkitSpeechRecognition || null;
}

function przerwaMiedzy(separator) {
    return separator === 'akapit' ? '\n\n' : (separator === 'nowa-linia' ? '\n' : ' ');
}

/** Dopisuje tekst na KOŃCU wartości pola; nigdy jej nie zastępuje. */
export function dopiszTekst(obecny, dodany, separator = ' ') {
    const tekst = String(dodany ?? '').trim();
    const stary = String(obecny ?? '');

    if (tekst === '') return stary;
    if (stary.trim() === '') return stary === '' ? tekst : stary + tekst;
    if (/\s$/.test(stary)) return stary + tekst;

    return stary + przerwaMiedzy(separator) + tekst;
}

/**
 * Maszyna stanów jednego pola.
 *
 * Fazy: 'gotowe' (nic się nie dzieje), 'slucham', 'podglad' (jest tekst do
 * zatwierdzenia lub odrzucenia), 'blad' (komunikat, bez tekstu).
 * `naZmiane(stan)` dostaje { faza, podglad, komunikat, fragmenty }.
 *
 * `wstaw(tekst)` może zwrócić { ok: false, komunikat } — wtedy tekst zostaje
 * w podglądzie, a człowiek dostaje komunikat.
 * `czyZyje()` mówi, czy pole nadal jest na stronie; po jego zniknięciu
 * (zmiana kroku kreatora, usunięty wiersz) nasłuch sam się kończy.
 */
export function utworzDyktowanie({
    Rozpoznawanie, wstaw, naZmiane,
    ustawCzas = setTimeout, zdejmijCzas = clearTimeout, czyZyje = () => true,
}) {
    let rozpoznawanie = null;
    let czasomierz = null;
    let faza = 'gotowe';
    let potwierdzone = ''; // wyniki końcowe z tego i poprzednich nasłuchów
    let chwilowe = ''; // wynik jeszcze niepotwierdzony
    let komunikat = '';
    let fragmenty = 0; // ile wyników końcowych w TYM nasłuchu
    let numer = 0; // odcina spóźnione zdarzenia z poprzedniego nasłuchu
    let konczenie = false; // po „Zakończ” czekamy już tylko na ostatni wynik i `onend`

    const podglad = () => (potwierdzone + (chwilowe ? (potwierdzone ? ' ' : '') + chwilowe : '')).trim();

    function ogloszenie() {
        naZmiane({ faza, podglad: podglad(), komunikat, fragmenty });
    }

    function zdejmijLicznik() {
        if (czasomierz !== null) {
            zdejmijCzas(czasomierz);
            czasomierz = null;
        }
    }

    /** Wynik tymczasowy, którego silnik nie zdążył potwierdzić, trafia do podglądu — nie ginie. */
    function awansujChwilowe() {
        if (chwilowe !== '') {
            potwierdzone = (potwierdzone + ' ' + chwilowe).trim();
            chwilowe = '';
        }
    }

    function zatrzymajSilnik() {
        numer += 1; // zdarzenia starego silnika przestają się liczyć
        konczenie = false;
        const stary = rozpoznawanie;
        rozpoznawanie = null;
        zdejmijLicznik();
        if (stary) {
            try {
                stary.abort();
            } catch (e) {
                // przeglądarka mogła już zakończyć nasłuch — nic do zrobienia
            }
        }
    }

    function porzucone() {
        if (czyZyje()) return false;
        anuluj();
        return true;
    }

    function ustawCisze() {
        zdejmijLicznik();
        const ten = numer;
        czasomierz = ustawCzas(() => {
            czasomierz = null;
            if (ten !== numer || faza !== 'slucham') return;
            // Cisza: kończymy nasłuch i mówimy, co zrobić.
            zatrzymajSilnik();
            awansujChwilowe();
            faza = podglad() ? 'podglad' : 'blad';
            komunikat = KOMUNIKATY.cisza;
            ogloszenie();
        }, LIMIT_CISZY_MS);
    }

    function start() {
        if (faza === 'slucham') return;

        zatrzymajSilnik();
        chwilowe = '';
        komunikat = '';
        fragmenty = 0;

        let silnik;
        try {
            silnik = new Rozpoznawanie();
        } catch (e) {
            faza = podglad() ? 'podglad' : 'blad';
            komunikat = KOMUNIKATY.inny;
            ogloszenie();
            return;
        }

        const ten = numer;
        silnik.lang = JEZYK;
        silnik.continuous = false;
        silnik.interimResults = true;
        silnik.maxAlternatives = 1;

        silnik.onresult = (zdarzenie) => {
            if (ten !== numer || porzucone()) return;
            let koncowy = '';
            let tymczasowy = '';
            const wyniki = zdarzenie.results || [];
            for (let i = zdarzenie.resultIndex || 0; i < wyniki.length; i += 1) {
                const tekst = (wyniki[i][0] && wyniki[i][0].transcript) || '';
                if (wyniki[i].isFinal) koncowy += tekst;
                else tymczasowy += tekst;
            }
            if (koncowy.trim() !== '') {
                potwierdzone = (potwierdzone + ' ' + koncowy.trim()).trim();
                fragmenty += 1;
            }
            chwilowe = tymczasowy.trim();
            if (faza === 'slucham' && !konczenie) ustawCisze();
            ogloszenie();
        };

        silnik.onerror = (zdarzenie) => {
            if (ten !== numer || porzucone()) return;
            const tresc = komunikatBledu(zdarzenie && zdarzenie.error);
            if (tresc === null) return;
            zatrzymajSilnik();
            awansujChwilowe();
            faza = podglad() ? 'podglad' : 'blad';
            komunikat = tresc;
            ogloszenie();
        };

        silnik.onend = () => {
            if (ten !== numer || porzucone()) return;
            // Nasłuch skończył się sam (pauza w mowie) albo po „Zakończ”.
            rozpoznawanie = null;
            zdejmijLicznik();
            numer += 1;
            konczenie = false;
            awansujChwilowe();
            if (podglad()) {
                faza = 'podglad';
                komunikat = '';
            } else {
                faza = 'blad';
                komunikat = KOMUNIKATY.cisza;
            }
            ogloszenie();
        };

        rozpoznawanie = silnik;
        faza = 'slucham';
        try {
            silnik.start();
        } catch (e) {
            zatrzymajSilnik();
            faza = podglad() ? 'podglad' : 'blad';
            komunikat = KOMUNIKATY.inny;
            ogloszenie();
            return;
        }
        ustawCisze();
        ogloszenie();
    }

    /** Kończy nasłuch bez komunikatu o ciszy, zostawia rozpoznany tekst w podglądzie. */
    function przerwij() {
        if (faza !== 'slucham') return;
        zatrzymajSilnik();
        awansujChwilowe();
        komunikat = '';
        faza = podglad() ? 'podglad' : 'gotowe';
        ogloszenie();
    }

    /** „Zakończ”: przestań słuchać, zostaw to, co już rozpoznano. */
    function zakoncz() {
        if (faza !== 'slucham') return;
        const stary = rozpoznawanie;
        const ten = numer;
        zdejmijLicznik();
        if (stary) {
            try {
                // `stop()` (nie `abort()`) pozwala silnikowi oddać ostatni wynik.
                stary.stop();
            } catch (e) {
                przerwij();
                return;
            }
            if (ten !== numer) return; // `onend` przyszedł od razu
            konczenie = true;
            // Safari/iOS bywa, że nie wyśle `onend` — wtedy kończymy sami.
            czasomierz = ustawCzas(() => {
                czasomierz = null;
                if (ten === numer && faza === 'slucham') przerwij();
            }, LIMIT_ZAKONCZENIA_MS);
            return;
        }
        przerwij();
    }

    /** „Wstaw”: oddaje tekst i czyści podgląd. Pusty podgląd — nic nie robi. */
    function wstawDoPola() {
        const tekst = podglad();
        if (tekst === '') return false;
        zatrzymajSilnik();
        awansujChwilowe();
        const wynik = wstaw(tekst);
        if (wynik && wynik.ok === false) {
            faza = 'podglad';
            komunikat = wynik.komunikat || KOMUNIKATY.inny;
            ogloszenie();
            return false;
        }
        potwierdzone = '';
        chwilowe = '';
        komunikat = '';
        fragmenty = 0;
        faza = 'gotowe';
        ogloszenie();
        return true;
    }

    /** „Anuluj”: czyści podgląd i niczego nie wstawia. */
    function anuluj() {
        zatrzymajSilnik();
        potwierdzone = '';
        chwilowe = '';
        komunikat = '';
        fragmenty = 0;
        faza = 'gotowe';
        ogloszenie();
    }

    return { start, zakoncz, przerwij, wstaw: wstawDoPola, anuluj, stan: () => ({ faza, podglad: podglad(), komunikat, fragmenty }) };
}

// ---------------------------------------------------------------------------
// DOM
// ---------------------------------------------------------------------------

const NS_SVG = 'http://www.w3.org/2000/svg';

/** Jedna aktywna sesja na stronę: start drugiej kończy pierwszą neutralnie. */
export function utworzRejestr() {
    return { aktywna: null, hosty: new Set(), licznik: 0 };
}

const rejestrStrony = utworzRejestr();

function ikonaMikrofonu(dokument) {
    const svg = dokument.createElementNS(NS_SVG, 'svg');
    svg.setAttribute('class', 'ikona');
    svg.setAttribute('width', '24');
    svg.setAttribute('height', '24');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '1.8');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    const kapsula = dokument.createElementNS(NS_SVG, 'rect');
    kapsula.setAttribute('x', '9');
    kapsula.setAttribute('y', '3');
    kapsula.setAttribute('width', '6');
    kapsula.setAttribute('height', '11');
    kapsula.setAttribute('rx', '3');
    const luk = dokument.createElementNS(NS_SVG, 'path');
    luk.setAttribute('d', 'M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21M9 21h6');
    svg.append(kapsula, luk);
    return svg;
}

function przycisk(dokument, napis, klasa, naKlik) {
    const el = dokument.createElement('button');
    el.type = 'button';
    el.className = 'btn ' + klasa;
    el.textContent = napis;
    el.addEventListener('click', naKlik);
    return el;
}

/** Limit znaków pola: `maxlength` albo `data-licznik` (limit, który serwer i tak egzekwuje). */
function limitPola(cel) {
    const maks = Number(cel.getAttribute && cel.getAttribute('maxlength'));
    if (maks > 0) return maks;
    const licznik = Number(cel.dataset && cel.dataset.licznik);
    return licznik > 0 ? licznik : 0;
}

/**
 * Dorysowuje cały interfejs w hoście. Zwraca false (i nic nie rysuje), gdy
 * przeglądarka nie umie rozpoznawać mowy albo host już jest podłączony.
 */
export function podlacz(host, okno, dokument, rejestr = rejestrStrony) {
    if (!host || host.dataset.dyktowanieGotowe) return false;

    const Rozpoznawanie = znajdzRozpoznawanie(okno);
    if (!Rozpoznawanie) return false;

    const cel = dokument.getElementById(host.dataset.cel || '');
    if (!cel) return false;

    host.dataset.dyktowanieGotowe = '1';
    host.classList.add('dyktowanie');

    // Identyfikatory z globalnego licznika, NIE z `data-cel`: host ma
    // `wire:ignore`, więc po usunięciu i dodaniu wiersza ten sam indeks
    // wróciłby z tym samym id.
    rejestr.licznik += 1;
    const numerHosta = rejestr.licznik;
    const idPodgladu = `dyktowanie-${numerHosta}-podglad`;
    const idZdania = `dyktowanie-${numerHosta}-zdanie`;
    const idEtykiety = `dyktowanie-${numerHosta}-etykieta`;

    const dyktuj = przycisk(dokument, '', 'btn-secondary dyktowanie-start', () => uruchom());
    dyktuj.setAttribute('aria-describedby', idZdania);
    const napisStartu = dokument.createElement('span');
    napisStartu.textContent = 'Dyktuj';
    dyktuj.append(ikonaMikrofonu(dokument), napisStartu);

    const zakoncz = przycisk(dokument, 'Zakończ dyktowanie', 'btn-secondary', () => maszyna.zakoncz());
    const wstaw = przycisk(dokument, host.dataset.wstawNapis || 'Wstaw do przepisu', 'btn-primary', () => maszyna.wstaw());
    const anuluj = przycisk(dokument, 'Anuluj', 'btn-secondary', () => maszyna.anuluj());

    const zdanie = dokument.createElement('p');
    zdanie.className = 'dyktowanie-zdanie';
    zdanie.id = idZdania;
    zdanie.textContent = host.dataset.zdanie || ZDANIE_O_DOSTAWCY;

    // Krótki status dla czytnika ekranu: zmiana fazy i wynik końcowy, nigdy wyniki tymczasowe.
    const status = dokument.createElement('p');
    status.className = 'visually-hidden';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');

    const grupa = dokument.createElement('div');
    grupa.className = 'dyktowanie-grupa';
    grupa.setAttribute('role', 'group');
    grupa.setAttribute('aria-labelledby', idEtykiety);
    grupa.hidden = true;

    const etykieta = dokument.createElement('p');
    etykieta.className = 'dyktowanie-etykieta';
    etykieta.id = idEtykiety;
    etykieta.textContent = 'Podyktowany tekst (jeszcze nie w polu)';

    const tekstPodgladu = dokument.createElement('p');
    tekstPodgladu.className = 'dyktowanie-podglad';
    tekstPodgladu.id = idPodgladu;

    grupa.append(etykieta, tekstPodgladu);

    const blad = dokument.createElement('p');
    blad.className = 'field-error dyktowanie-blad';
    blad.setAttribute('role', 'alert');
    blad.hidden = true;

    const akcje = dokument.createElement('div');
    akcje.className = 'dyktowanie-akcje';
    akcje.append(dyktuj, zakoncz, wstaw, anuluj);

    host.append(akcje, zdanie, status, grupa, blad);

    let poprzedniaFaza = 'gotowe';
    let ostatniStatus = '';

    function ustawStatus(tresc) {
        if (tresc === ostatniStatus) return;
        ostatniStatus = tresc;
        status.textContent = tresc;
    }

    function pokaz(stan) {
        // Fokus: przycisk, który właśnie klikamy, za chwilę będzie ukryty,
        // a ukryty element oddaje fokus ciału strony. Zapamiętujemy to PRZED
        // zmianą i przenosimy fokus na widoczny przycisk.
        const aktywny = dokument.activeElement;
        const mialFokus = !!aktywny && host.contains(aktywny);

        const slucham = stan.faza === 'slucham';
        const maTekst = stan.podglad !== '';
        const pokazGrupe = maTekst || slucham;

        dyktuj.hidden = slucham;
        zakoncz.hidden = !slucham;
        wstaw.hidden = !maTekst || slucham;
        anuluj.hidden = !pokazGrupe;
        grupa.hidden = !pokazGrupe;
        tekstPodgladu.textContent = maTekst ? stan.podglad : (slucham ? 'Słucham…' : '');
        blad.hidden = stan.komunikat === '';
        blad.textContent = stan.komunikat;
        napisStartu.textContent = maTekst ? 'Dyktuj dalej' : 'Dyktuj';

        if (slucham) ustawStatus(stan.fragmenty > 0 ? STATUSY.fragment : STATUSY.slucham);
        else if (stan.faza === 'podglad') ustawStatus(STATUSY.podglad);
        else ustawStatus('');

        if (mialFokus && stan.faza !== poprzedniaFaza) {
            const nastepny = slucham ? zakoncz : (stan.faza === 'podglad' ? wstaw : dyktuj);
            if (typeof nastepny.focus === 'function') nastepny.focus();
        }
        poprzedniaFaza = stan.faza;
    }

    const maszyna = utworzDyktowanie({
        Rozpoznawanie,
        naZmiane: pokaz,
        czyZyje: () => host.isConnected !== false,
        ustawCzas: (f, ms) => okno.setTimeout(f, ms),
        zdejmijCzas: (id) => okno.clearTimeout(id),
        wstaw: (tekst) => {
            const nowa = dopiszTekst(cel.value, tekst, host.dataset.separator || 'spacja');
            const limit = limitPola(cel);

            // Nie obcinamy po cichu: nadmiaru nie wstawiamy i mówimy o tym.
            if (limit > 0 && nowa.length > limit) {
                return { ok: false, komunikat: komunikatLimitu(nowa.length - limit, limit) };
            }

            // Dopisujemy na końcu, nigdy nie zastępujemy tego, co już wpisano.
            cel.value = nowa;
            // Livewire (wire:model) i licznik znaków słuchają zdarzenia `input`.
            cel.dispatchEvent(new okno.Event('input', { bubbles: true }));
            cel.dispatchEvent(new okno.Event('change', { bubbles: true }));
            if (typeof cel.focus === 'function') cel.focus();

            return { ok: true };
        },
    });

    function uruchom() {
        if (rejestr.aktywna && rejestr.aktywna !== maszyna) rejestr.aktywna.przerwij();
        rejestr.aktywna = maszyna;
        maszyna.start();
    }

    // Natywne <details> chowa odpowiedź bez odłączania hosta. `toggle` jest
    // wywoływane także przy obsłudze klawiaturą; nie nasłuchujemy kliknięcia.
    const formularzOdpowiedzi = host.closest?.('details[data-dyktowanie-zamkniecie]');
    const poZamknieciu = () => {
        if (formularzOdpowiedzi.open) return;
        maszyna.przerwij(); // abort, zatrzymanie timera i zachowanie podglądu
        if (rejestr.aktywna === maszyna) rejestr.aktywna = null;
    };
    formularzOdpowiedzi?.addEventListener('toggle', poZamknieciu);

    rejestr.hosty.add({
        host, maszyna,
        odczep: () => formularzOdpowiedzi?.removeEventListener('toggle', poZamknieciu),
    });
    pokaz(maszyna.stan());
    return true;
}

/** Kończy sesje, których pole zniknęło ze strony (zmiana kroku kreatora, usunięty wiersz). */
export function posprzataj(rejestr = rejestrStrony) {
    for (const wpis of [...rejestr.hosty]) {
        if (wpis.host.isConnected === false) {
            wpis.maszyna.anuluj();
            wpis.odczep?.();
            rejestr.hosty.delete(wpis);
            if (rejestr.aktywna === wpis.maszyna) rejestr.aktywna = null;
        }
    }
}

/** Zatrzymuje nasłuch (zostawia podgląd): ukrycie karty, wyjście ze strony. */
export function przerwijWszystkie(rejestr = rejestrStrony) {
    for (const wpis of rejestr.hosty) wpis.maszyna.przerwij();
}

export function podlaczWszystkie(okno, dokument, rejestr = rejestrStrony) {
    dokument.querySelectorAll('[data-dyktowanie]').forEach((host) => podlacz(host, okno, dokument, rejestr));
}

/** Czy zmiana w DOM dotyczy czegokolwiek poza naszym własnym interfejsem. */
export function mutacjaPozaDyktowaniem(mutacje) {
    return mutacje.some((m) => !(m.target && typeof m.target.closest === 'function' && m.target.closest('.dyktowanie')));
}

if (typeof document !== 'undefined' && typeof window !== 'undefined' && znajdzRozpoznawanie(window)) {
    const uruchomStrone = () => {
        podlaczWszystkie(window, document);
        // Kreator dokłada i usuwa wiersze (Livewire) już po załadowaniu strony.
        // Mutacje wewnątrz naszego interfejsu pomijamy — sami je wywołujemy.
        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver((mutacje) => {
                if (!mutacjaPozaDyktowaniem(mutacje)) return;
                posprzataj();
                podlaczWszystkie(window, document);
            }).observe(document.body, { childList: true, subtree: true });
        }
        window.addEventListener('pagehide', () => przerwijWszystkie());
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') przerwijWszystkie();
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', uruchomStrone, { once: true });
    } else {
        uruchomStrone();
    }
}
