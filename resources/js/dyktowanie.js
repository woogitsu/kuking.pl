/*
 * DYKTOWANIE WŁASNEGO PRZEPISU (issue #2377, decyzja właściciela z 1.10.2026
 * „Budujemy z ostrzeżeniem”, D-333).
 *
 * CO TO JEST. Przycisk „Dyktuj” przy polu składnika albo kroku w kreatorze
 * przepisu. Rozpoznawanie mowy robi PRZEGLĄDARKA (Web Speech API) i to ona
 * może wysyłać dźwięk do swojego dostawcy (np. Google albo Apple). Kuking
 * nie nagrywa dźwięku, nie dostaje go i nie ma serwera, który by go
 * przyjął — do serwisu trafia wyłącznie tekst, który człowiek sam wstawi.
 *
 * D-053: BEZ SKRYPTU NIE MA MARTWEGO PRZYCISKU. W HTML-u stoi tylko pusty
 * hosting (`<div data-dyktowanie data-cel="…" wire:ignore>`). Przycisk,
 * podgląd i zdanie o dostawcy dorysowuje ten plik — i tylko wtedy, gdy
 * przeglądarka ma `SpeechRecognition` albo `webkitSpeechRecognition`.
 * `wire:ignore` chroni dorysowaną zawartość przed przerysowaniem przez
 * Livewire (kreator), a `MutationObserver` obsługuje wiersze dodane później.
 *
 * TRANSKRYPCJA NIE WCHODZI DO POLA PRZEPISU. Ląduje w osobnym podglądzie
 * (`aria-live="polite"`). Dopiero „Wstaw do przepisu” dopisuje ją do pola,
 * a „Anuluj” czyści podgląd. Tekst już wpisany w polu nigdy nie jest ruszany
 * poza dopisaniem na końcu — żadna droga tego pliku nie zastępuje wartości.
 *
 * Maszyna stanów jest oddzielona od DOM i eksportowana, żeby `dyktowanie.test.mjs`
 * sprawdzał ją w Node z atrapą rozpoznawania.
 */

export const JEZYK = 'pl-PL';

/** Ile milisekund bez żadnego wyniku uznajemy za ciszę. */
export const LIMIT_CISZY_MS = 10000;

export const ZDANIE_O_DOSTAWCY = 'Dyktowanie obsługuje Twoja przeglądarka i może wysyłać dźwięk do swojego dostawcy (np. Google lub Apple). Kuking nie nagrywa dźwięku i nie dostaje go — tylko tekst, który wstawisz do przepisu.';

export const KOMUNIKATY = {
    odmowa: 'Przeglądarka nie dostała zgody na mikrofon. Zezwól na mikrofon w ustawieniach tej strony (kłódka przy adresie), a potem naciśnij „Dyktuj” jeszcze raz. Możesz też wpisać tekst klawiaturą.',
    siec: 'Brak połączenia z usługą rozpoznawania mowy. Sprawdź internet i naciśnij „Dyktuj” jeszcze raz. Możesz też wpisać tekst klawiaturą.',
    cisza: 'Nic nie usłyszeliśmy. Naciśnij „Dyktuj”, a potem powiedz zdanie blisko mikrofonu.',
    brakMikrofonu: 'Nie znaleziono mikrofonu. Podłącz mikrofon i naciśnij „Dyktuj” jeszcze raz. Możesz też wpisać tekst klawiaturą.',
    inny: 'Dyktowanie się nie udało. Naciśnij „Dyktuj” jeszcze raz albo wpisz tekst klawiaturą.',
};

/** Kod błędu Web Speech API → komunikat po polsku (albo null, gdy to nie błąd dla człowieka). */
export function komunikatBledu(kod) {
    switch (kod) {
    case 'not-allowed':
    case 'service-not-allowed':
        return KOMUNIKATY.odmowa;
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

/** Dopisuje tekst na KOŃCU wartości pola; nigdy jej nie zastępuje. */
export function dopiszTekst(obecny, dodany, separator = ' ') {
    const tekst = String(dodany ?? '').trim();
    const stary = String(obecny ?? '');

    if (tekst === '') return stary;
    if (stary.trim() === '') return stary === '' ? tekst : stary + tekst;
    if (/\s$/.test(stary)) return stary + tekst;

    const przerwa = separator === 'akapit' ? '\n\n' : (separator === 'nowa-linia' ? '\n' : ' ');

    return stary + przerwa + tekst;
}

/**
 * Maszyna stanów jednego pola.
 *
 * Fazy: 'gotowe' (nic się nie dzieje), 'slucham', 'podglad' (jest tekst do
 * zatwierdzenia lub odrzucenia), 'blad' (komunikat; podgląd, jeśli był,
 * zostaje). `naZmiane(stan)` dostaje { faza, podglad, komunikat }.
 */
export function utworzDyktowanie({ Rozpoznawanie, wstaw, naZmiane, ustawCzas = setTimeout, zdejmijCzas = clearTimeout }) {
    let rozpoznawanie = null;
    let czasCiszy = null;
    let faza = 'gotowe';
    let potwierdzone = ''; // wyniki końcowe z tego i poprzednich nasłuchów
    let chwilowe = ''; // wynik jeszcze niepotwierdzony
    let komunikat = '';
    let numer = 0; // odcina spóźnione zdarzenia z poprzedniego nasłuchu

    const podglad = () => (potwierdzone + (chwilowe ? (potwierdzone ? ' ' : '') + chwilowe : '')).trim();

    function ogloszenie() {
        naZmiane({ faza, podglad: podglad(), komunikat });
    }

    function zdejmijLicznik() {
        if (czasCiszy !== null) {
            zdejmijCzas(czasCiszy);
            czasCiszy = null;
        }
    }

    function ustawLicznik() {
        zdejmijLicznik();
        const ten = numer;
        czasCiszy = ustawCzas(() => {
            czasCiszy = null;
            if (ten !== numer || faza !== 'slucham') return;
            // Cisza: kończymy nasłuch i mówimy, co zrobić.
            zatrzymajSilnik();
            faza = podglad() ? 'podglad' : 'blad';
            komunikat = KOMUNIKATY.cisza;
            ogloszenie();
        }, LIMIT_CISZY_MS);
    }

    function zatrzymajSilnik() {
        numer += 1; // zdarzenia starego silnika przestają się liczyć
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

    function start() {
        if (faza === 'slucham') return;

        zatrzymajSilnik();
        chwilowe = '';
        komunikat = '';

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
            if (ten !== numer) return;
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
            }
            chwilowe = tymczasowy.trim();
            ustawLicznik();
            ogloszenie();
        };

        silnik.onerror = (zdarzenie) => {
            if (ten !== numer) return;
            const tresc = komunikatBledu(zdarzenie && zdarzenie.error);
            if (tresc === null) return;
            zatrzymajSilnik();
            chwilowe = '';
            faza = podglad() ? 'podglad' : 'blad';
            komunikat = tresc;
            ogloszenie();
        };

        silnik.onend = () => {
            if (ten !== numer) return;
            // Nasłuch skończył się sam (pauza w mowie).
            rozpoznawanie = null;
            zdejmijLicznik();
            numer += 1;
            chwilowe = '';
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
        ustawLicznik();
        ogloszenie();
    }

    /** „Zakończ”: przestań słuchać, zostaw to, co już rozpoznano. */
    function zakoncz() {
        if (faza !== 'slucham') return;
        const stary = rozpoznawanie;
        const ten = numer;
        zdejmijLicznik();
        // `stop()` (nie `abort()`) pozwala silnikowi oddać ostatni wynik.
        if (stary) {
            try {
                stary.stop();
                return;
            } catch (e) {
                // spadamy do twardego zakończenia niżej
            }
        }
        if (ten === numer) {
            zatrzymajSilnik();
            faza = podglad() ? 'podglad' : 'gotowe';
            ogloszenie();
        }
    }

    /** „Wstaw do przepisu”: oddaje tekst i czyści podgląd. Pusty podgląd — nic nie robi. */
    function wstawDoPola() {
        const tekst = podglad();
        if (tekst === '') return false;
        zatrzymajSilnik();
        wstaw(tekst);
        potwierdzone = '';
        chwilowe = '';
        komunikat = '';
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
        faza = 'gotowe';
        ogloszenie();
    }

    return { start, zakoncz, wstaw: wstawDoPola, anuluj, stan: () => ({ faza, podglad: podglad(), komunikat }) };
}

// ---------------------------------------------------------------------------
// DOM
// ---------------------------------------------------------------------------

const NS_SVG = 'http://www.w3.org/2000/svg';

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

/**
 * Dorysowuje cały interfejs w hoście. Zwraca false (i nic nie rysuje), gdy
 * przeglądarka nie umie rozpoznawać mowy albo host już jest podłączony.
 */
export function podlacz(host, okno, dokument) {
    if (!host || host.dataset.dyktowanieGotowe) return false;

    const Rozpoznawanie = znajdzRozpoznawanie(okno);
    if (!Rozpoznawanie) return false;

    const cel = dokument.getElementById(host.dataset.cel || '');
    if (!cel) return false;

    host.dataset.dyktowanieGotowe = '1';
    host.classList.add('dyktowanie');

    const idPodgladu = (cel.id || 'pole') + '-dyktowanie-podglad';
    const idZdania = (cel.id || 'pole') + '-dyktowanie-zdanie';

    const dyktuj = przycisk(dokument, '', 'btn-secondary dyktowanie-start', () => maszyna.start());
    dyktuj.setAttribute('aria-describedby', idZdania);
    const napisStartu = dokument.createElement('span');
    napisStartu.textContent = 'Dyktuj';
    dyktuj.append(ikonaMikrofonu(dokument), napisStartu);

    const zakoncz = przycisk(dokument, 'Zakończ dyktowanie', 'btn-secondary', () => maszyna.zakoncz());
    const wstaw = przycisk(dokument, 'Wstaw do przepisu', 'btn-primary', () => maszyna.wstaw());
    const anuluj = przycisk(dokument, 'Anuluj', 'btn-secondary', () => maszyna.anuluj());

    const zdanie = dokument.createElement('p');
    zdanie.className = 'meta dyktowanie-zdanie';
    zdanie.id = idZdania;
    zdanie.textContent = ZDANIE_O_DOSTAWCY;

    const etykieta = dokument.createElement('p');
    etykieta.className = 'dyktowanie-etykieta';
    etykieta.id = idPodgladu + '-etykieta';
    etykieta.textContent = 'Podyktowany tekst (jeszcze nie w przepisie)';
    etykieta.hidden = true;

    const tekstPodgladu = dokument.createElement('p');
    tekstPodgladu.className = 'dyktowanie-podglad';
    tekstPodgladu.id = idPodgladu;
    tekstPodgladu.setAttribute('aria-live', 'polite');
    tekstPodgladu.setAttribute('aria-labelledby', etykieta.id);
    tekstPodgladu.hidden = true;

    const blad = dokument.createElement('p');
    blad.className = 'field-error dyktowanie-blad';
    blad.setAttribute('role', 'alert');
    blad.hidden = true;

    const akcje = dokument.createElement('div');
    akcje.className = 'dyktowanie-akcje';
    akcje.append(dyktuj, zakoncz, wstaw, anuluj);

    host.append(akcje, zdanie, etykieta, tekstPodgladu, blad);

    function pokaz(stan) {
        const slucham = stan.faza === 'slucham';
        const maTekst = stan.podglad !== '';

        dyktuj.hidden = slucham;
        zakoncz.hidden = !slucham;
        wstaw.hidden = !maTekst || slucham;
        anuluj.hidden = !maTekst && !slucham;
        etykieta.hidden = !maTekst && !slucham;
        tekstPodgladu.hidden = !maTekst && !slucham;
        tekstPodgladu.textContent = maTekst ? stan.podglad : (slucham ? 'Słucham… powiedz, co dopisać.' : '');
        blad.hidden = stan.komunikat === '';
        blad.textContent = stan.komunikat;
        napisStartu.textContent = maTekst ? 'Dyktuj dalej' : 'Dyktuj';
    }

    const maszyna = utworzDyktowanie({
        Rozpoznawanie,
        naZmiane: pokaz,
        ustawCzas: (f, ms) => okno.setTimeout(f, ms),
        zdejmijCzas: (id) => okno.clearTimeout(id),
        wstaw: (tekst) => {
            // Dopisujemy na końcu, nigdy nie zastępujemy tego, co już wpisano.
            cel.value = dopiszTekst(cel.value, tekst, host.dataset.separator || 'spacja');
            // Livewire (wire:model) i licznik znaków słuchają zdarzenia `input`.
            cel.dispatchEvent(new okno.Event('input', { bubbles: true }));
            cel.dispatchEvent(new okno.Event('change', { bubbles: true }));
            if (typeof cel.focus === 'function') cel.focus();
        },
    });

    pokaz(maszyna.stan());
    return true;
}

function podlaczWszystkie(okno, dokument) {
    dokument.querySelectorAll('[data-dyktowanie]').forEach((host) => podlacz(host, okno, dokument));
}

if (typeof document !== 'undefined' && typeof window !== 'undefined' && znajdzRozpoznawanie(window)) {
    const uruchom = () => {
        podlaczWszystkie(window, document);
        // Kreator dokłada wiersze (Livewire) już po załadowaniu strony.
        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver(() => podlaczWszystkie(window, document))
                .observe(document.body, { childList: true, subtree: true });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', uruchom, { once: true });
    } else {
        uruchom();
    }
}
