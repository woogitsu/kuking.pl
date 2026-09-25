/*
 * Kuking — JavaScript aplikacji.
 *
 * ZASADA: JavaScript wyłącznie jako ULEPSZENIE. Każda ważna funkcja —
 * rejestracja, publikacja wpisu, przepis, komentarz, „Ugotowałem” —
 * musi działać przy wyłączonym albo niewczytanym JS.
 *
 * Powód nie jest ideologiczny: przy słabym zasięgu skrypt potrafi się nie
 * dociągnąć, a użytkownik zostaje z formularzem, który nic nie robi po
 * kliknięciu. Dla osoby, która i tak nie jest pewna, czy „dobrze klika”,
 * to koniec korzystania z serwisu.
 */

// --- Service worker (PWA) -------------------------------------------------

import './service-worker.js';
import './pwa-install.js';
import './landing-wpisy.js';
import './pasek-przewijany.js';
import './szybki-wyglad.js';
import './panel-tabela.js';
import './panel-menu.js';
import './tagi-w-opisie.js';
import './licznik-znakow.js';
import './pokaz-haslo.js';
import {pozostaloSekund, formatMinutySekundy, kluczStanu, zapiszStan, odczytajTermin, krokZKlucza} from './minutnik-krok.js';
import {utworzKontrolerWakeLock} from './wake-lock-gotowania.js';

// --- Podgląd wybranych zdjęć ---------------------------------------------

/*
 * ZA CZYM DORYSOWUJEMY COKOLWIEK POD POLEM PLIKU.
 *
 * Po decyzji D-035 natywne pole pliku jest schowane dla oka i stoi
 * BEZPOŚREDNIO PRZED swoją etykietą — dużym obszarem „Dodaj zdjęcie”.
 * Tej kolejności wymaga reguła fokusu
 * `.pole-zdjecia-input:focus-visible + .pole-zdjecia`
 * (resources/css/ekran-dodawania.css).
 *
 * Wstawienie podglądu albo komunikatu tuż za `<input>` położyłoby je NAD
 * obszarem wyboru i do środka `<label>` — a klik w miniaturę otwierałby wtedy
 * okno wyboru pliku jeszcze raz, bo tak działa etykieta. Dlatego kotwicą jest
 * etykieta, o ile stoi zaraz za polem; w każdym innym układzie zostaje samo
 * pole i zachowanie jest takie jak przed tą zmianą.
 */
function kotwicaPodPolem(input) {
    const nastepny = input.nextElementSibling;

    return nastepny instanceof HTMLLabelElement && nastepny.htmlFor === input.id
        ? nastepny
        : input;
}

/*
 * ZWALNIANIE `object URL` PODGLĄDU (issue #742).
 *
 * `URL.createObjectURL(plik)` rezerwuje adres, który żyje aż do
 * `URL.revokeObjectURL()` albo zamknięcia dokumentu — cokolwiek nastąpi
 * pierwsze. Miniatura zwalniała go WYŁĄCZNIE po zdarzeniu `load`: obraz
 * z poprawnym nagłówkiem MIME, którego przeglądarka nie potrafi
 * zdekodować, kończy się `error`, dla którego nie było sprzątania —
 * i kolejny wybór albo wyczyszczenie pola (`pojemnik.replaceChildren()`
 * niżej) usuwał dzieci z DOM-u, ale nie zwalniał ich adresów.
 *
 * Jedna funkcja, wywoływana PRZED każdym `replaceChildren()`, żeby żaden
 * z dwóch miejsc czyszczących ten kontener nie mógł o tym zapomnieć osobno.
 * Revoke na już zwolnionym albo nieistniejącym blobie jest w przeglądarce
 * bezpiecznym no-opem — nie trzeba pilnować, czy `load`/`error` już się
 * zdążyło odpalić.
 */
function zwolnijPodgladObjectUrls(pojemnik) {
    for (const img of pojemnik.querySelectorAll('img.podglad-wyboru-zdjecie')) {
        URL.revokeObjectURL(img.src);
    }
}

/*
 * Po wybraniu pliku pokazujemy miniaturę i nazwę. Bez tego użytkownik nie ma
 * żadnego potwierdzenia, że zdjęcie zostało wybrane — a to jest najczęstszy
 * moment porzucenia formularza „dodaj zdjęcie”.
 */
document.addEventListener('change', (event) => {
    const input = event.target;

    if (! (input instanceof HTMLInputElement) || input.type !== 'file') {
        return;
    }

    const pojemnikId = `${input.id}-podglad`;
    let pojemnik = document.getElementById(pojemnikId);

    if (! pojemnik) {
        pojemnik = document.createElement('div');
        pojemnik.id = pojemnikId;
        pojemnik.className = 'podglad-wyboru';
        pojemnik.setAttribute('aria-live', 'polite');
        kotwicaPodPolem(input).insertAdjacentElement('afterend', pojemnik);
    }

    zwolnijPodgladObjectUrls(pojemnik);
    pojemnik.replaceChildren();

    const pliki = Array.from(input.files ?? []);

    if (pliki.length === 0) {
        return;
    }

    /*
     * LICZBA ZDJĘĆ STERUJE UKŁADEM Z ARKUSZA, nie stylami wpisywanymi tutaj.
     *
     * Dopóki siatka była ustawiana w skrypcie na `repeat(auto-fill,
     * minmax(120px, 1fr))`, JEDNO wybrane zdjęcie dostawało jedną kolumnę
     * z dwóch — ZMIERZONE: 150 px z 390 px okna. Człowiek, który właśnie
     * wybrał zdjęcie swojego obiadu, widział znaczek mniejszy niż połowa
     * ekranu. To ta sama usterka, którą właściciel zgłosił przy bloku
     * zastępczym w kolażu (#432), tylko o jeden ekran wcześniej.
     *
     * Wartości idą teraz z `ekran-dodawania.css`, przez `[data-ile]` — czyli
     * z jednego miejsca, w którym odstępy i promienie są tokenami.
     */
    pojemnik.dataset.ile = String(pliki.length);

    const info = document.createElement('p');
    info.className = 'podglad-wyboru-info';
    info.textContent = pliki.length === 1
        ? 'Wybrano 1 zdjęcie.'
        : `Wybrano ${pliki.length} zdjęcia.`;
    pojemnik.appendChild(info);

    for (const plik of pliki) {
        if (! plik.type.startsWith('image/')) {
            continue;
        }

        const img = document.createElement('img');
        img.alt = '';
        img.className = 'podglad-wyboru-zdjecie';
        img.src = URL.createObjectURL(plik);
        // `error`, NIE TYLKO `load` — plik z nagłówkiem image/jpeg, którego
        // treść jest uszkodzona, nie ładuje się nigdy, a adres bez tego
        // zostawałby zarezerwowany aż do zamknięcia dokumentu.
        img.addEventListener('load', () => URL.revokeObjectURL(img.src), { once: true });
        img.addEventListener('error', () => URL.revokeObjectURL(img.src), { once: true });
        pojemnik.appendChild(img);
    }
});

// --- Nieudana wysyłka zdjęcia w kreatorze (Livewire) ----------------------

/*
 * Kreator przepisu wysyła zdjęcie na wewnętrzny endpoint Livewire OD RAZU
 * po wyborze pliku — zanim komponent Kuking cokolwiek o nim wie. Gdy tamten
 * endpoint odrzuci plik (za duży, przerwane połączenie), Livewire ogłasza
 * `livewire-upload-error`, ale w `detail` ma tylko `{id, property}`: treści
 * błędu tam nie ma i nie będzie.
 *
 * Bez tej obsługi pasek postępu po prostu ZNIKA, pole zostaje puste i nikt
 * nie mówi człowiekowi, co się stało ani co zrobić (issue #111). Tekst bierzemy
 * z atrybutu `data-blad-wysylki`, który renderuje PHP — dzięki temu liczba
 * megabajtów ma jedno źródło (`LimityZdjec`), a nie kopię w skrypcie.
 */
window.addEventListener('livewire-upload-error', (zdarzenie) => {
    const input = zdarzenie.target;

    if (! (input instanceof HTMLInputElement) || input.type !== 'file') {
        return;
    }

    const komunikat = input.dataset.bladWysylki;

    if (! komunikat) {
        return;
    }

    const id = `${input.id}-blad-wysylki`;
    let pole = document.getElementById(id);

    if (! pole) {
        pole = document.createElement('span');
        pole.id = id;
        pole.className = 'field-error';
        // `alert`, nie `polite`: to jest odpowiedź na czynność, którą człowiek
        // przed chwilą wykonał, i musi zostać przeczytana od razu.
        pole.setAttribute('role', 'alert');
        kotwicaPodPolem(input).insertAdjacentElement('afterend', pole);
    }

    pole.textContent = komunikat;

    // Podgląd miniatury dorysowany przy wyborze pliku kłamałby: zdjęcia
    // na serwerze nie ma. Usuwamy go razem z pokazaniem błędu — i zwalniamy
    // jego object URL, z tego samego powodu co przy zwykłym polu plików
    // wyżej (issue #742): usunięcie z DOM-u samo z siebie niczego nie
    // zwalnia.
    const pojemnikBladu = document.getElementById(`${input.id}-podglad`);

    if (pojemnikBladu) {
        zwolnijPodgladObjectUrls(pojemnikBladu);
        pojemnikBladu.replaceChildren();
    }
});

// Kolejna udana wysyłka sprząta po poprzednim błędzie — inaczej czerwony
// komunikat zostaje pod polem, w którym zdjęcie już się udało.
window.addEventListener('livewire-upload-finish', (zdarzenie) => {
    const input = zdarzenie.target;

    if (input instanceof HTMLInputElement) {
        document.getElementById(`${input.id}-blad-wysylki`)?.remove();
    }
});

// --- Fokus na podsumowaniu błędów ----------------------------------------

/*
 * Po nieudanej walidacji przenosimy fokus na podsumowanie błędów, żeby
 * czytnik ekranu je przeczytał, a osoba korzystająca z klawiatury nie
 * musiała szukać, co poszło nie tak.
 */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelector('.error-summary')?.focus();
});

// --- Powiększanie zdjęć ---------------------------------------------------

/*
 * Kliknięcie w zdjęcie otwiera je w nakładce, bez opuszczania feedu.
 *
 * ZASADA TEGO PLIKU OBOWIĄZUJE I TUTAJ: bez skryptu link działa normalnie
 * i otwiera duży wariant na osobnej stronie. Dlatego w Blade jest `<a href>`,
 * a nie `<button onclick>` — nie odbieramy nikomu działającego zachowania,
 * tylko dokładamy wygodniejsze.
 *
 * Używamy natywnego `<dialog>`, bo `showModal()` daje za darmo pułapkę
 * focusu, zamykanie Escape'em i poprawną semantykę dla czytników ekranu.
 * Ręczna nakładka z div-ów wymagałaby napisania tego wszystkiego od nowa —
 * i zwykle jest napisana źle.
 */

(() => {
    const okno = document.getElementById('powiekszenie');

    if (!okno || typeof okno.showModal !== 'function') {
        // Stara przeglądarka bez <dialog>. Zostawiamy linki w spokoju —
        // klikanie nadal otwiera duże zdjęcie na osobnej stronie.
        return;
    }

    const obraz = okno.querySelector('.lightbox-obraz');
    const status = okno.querySelector('.lightbox-status');
    const ponowPrzycisk = okno.querySelector('.lightbox-ponow');

    /*
     * ADRES, O KTÓRY WŁAŚNIE „WALCZYMY" (issue #743).
     *
     * Zawsze wartość PO przypisaniu do `obraz.src` — przeglądarka rozwija ją
     * do pełnego adresu, więc porównanie `obraz.src === biezacyAdres` w
     * handlerach `load`/`error` działa niezależnie od tego, czy link miał
     * adres względny czy bezwzględny.
     *
     * PO CO TO W OGÓLE: jedno `<img>` obsługuje KAŻDE kolejne otwarte
     * zdjęcie. Błąd albo dokończone wczytanie poprzedniego żądania, które
     * dojdzie z opóźnieniem PO otwarciu następnego zdjęcia (albo po
     * zamknięciu i ponownym otwarciu tego samego), nie może nadpisać stanu
     * zdjęcia, które człowiek widzi teraz — dlatego każdy handler sprawdza
     * `obraz.src === biezacyAdres`, zanim cokolwiek zmieni.
     */
    let biezacyAdres = null;

    function pokazWczytywanie() {
        obraz.hidden = true;
        ponowPrzycisk.hidden = true;
        status.hidden = false;
        status.textContent = 'Wczytywanie zdjęcia…';
    }

    function pokazBlad() {
        obraz.hidden = true;
        status.hidden = false;
        status.textContent = 'Nie udało się wczytać zdjęcia. Spróbuj ponownie.';
        ponowPrzycisk.hidden = false;
    }

    function pokazGotowe() {
        obraz.hidden = false;
        ponowPrzycisk.hidden = true;
        status.hidden = true;
        status.textContent = '';
    }

    document.addEventListener('click', (zdarzenie) => {
        const link = zdarzenie.target.closest('a[data-powieksz]');

        if (!link) {
            return;
        }

        // Nie przechwytujemy kliknięć, które użytkownik ŚWIADOMIE kieruje
        // gdzie indziej: nowa karta (Ctrl/Cmd), nowe okno (Shift), środkowy
        // przycisk myszy. Odbieranie tego jest jednym z najbardziej
        // irytujących zachowań w internecie.
        if (zdarzenie.metaKey || zdarzenie.ctrlKey || zdarzenie.shiftKey || zdarzenie.button !== 0) {
            return;
        }

        zdarzenie.preventDefault();

        pokazWczytywanie();
        obraz.alt = link.dataset.alt || '';
        obraz.src = link.getAttribute('href');
        biezacyAdres = obraz.src;

        okno.showModal();
    });

    obraz.addEventListener('load', () => {
        if (obraz.src !== biezacyAdres) {
            return;
        }

        pokazGotowe();
    });

    obraz.addEventListener('error', () => {
        // `obraz.src` startuje pusty w HTML źródłowym — samo usunięcie
        // atrybutu przy zamknięciu (niżej) też potrafi odpalić `error`
        // w niektórych przeglądarkach. Bez `biezacyAdres` nie ma czego
        // pokazywać: dialog jest wtedy i tak zamknięty.
        if (!biezacyAdres || obraz.src !== biezacyAdres) {
            return;
        }

        pokazBlad();
    });

    ponowPrzycisk.addEventListener('click', () => {
        if (!biezacyAdres) {
            return;
        }

        // Ponowienie ma WYKONAĆ PRÓBĘ FAKTYCZNIE, nie tylko pokazać
        // wczytywanie: samo przypisanie tego samego `src` przeglądarka
        // czasem traktuje jako no-op i nie wysyła nowego żądania. Doklejony
        // znacznik czasu wymusza prawdziwe kolejne pobranie za każdym razem.
        const bazowyAdres = biezacyAdres.split('#')[0].split('?')[0];
        const laczik = bazowyAdres.includes('?') ? '&' : '?';

        pokazWczytywanie();
        obraz.src = bazowyAdres + laczik + '_ponow=' + Date.now();
        biezacyAdres = obraz.src;
    });

    // Kliknięcie w tło zamyka. To jest DODATEK do przycisku „Zamknij”,
    // nie jedyna droga — samo tło nie jest oczywiste dla nikogo, kto nie
    // korzystał wcześniej z takich nakładek (UX_50_PLUS).
    okno.addEventListener('click', (zdarzenie) => {
        if (zdarzenie.target === okno) {
            okno.close();
        }
    });

    // Po zamknięciu zwalniamy zdjęcie z pamięci. Przy przeglądaniu feedu
    // z wieloma dużymi zdjęciami inaczej zostają wszystkie naraz.
    okno.addEventListener('close', () => {
        // Zdarzenie close jest kolejkowane. Jeśli w tym czasie otwarto
        // następne zdjęcie, poprzednie zamknięcie nie może go wyczyścić.
        if (okno.open) {
            return;
        }

        biezacyAdres = null;
        obraz.removeAttribute('src');
        obraz.alt = '';
        pokazGotowe();
    });
})();

// --- Tryb gotowania: Wake Lock (issue #24) --------------------------------

/*
 * Wake Lock jest ULEPSZENIEM, nie warunkiem działania trybu gotowania
 * (AGENTS.md). Blok w Blade ma z góry `hidden` — to jest jedyne miejsce,
 * które go odkrywa, i robi to WYŁĄCZNIE, gdy `navigator.wakeLock` istnieje.
 * Bez tego API kontrolka po prostu nigdy się nie pojawia: brak wsparcia nie
 * może wyglądać jak zepsuty przełącznik (issue: „brak wsparcia nie psuje
 * trybu”).
 */
(() => {
    const kontener = document.getElementById('cook-wakelock-wrap');
    const checkbox = document.getElementById('cook-wakelock-checkbox');
    const status = document.getElementById('cook-wakelock-status');

    if (!kontener || !checkbox || !status || !('wakeLock' in navigator)) {
        return;
    }

    kontener.hidden = false;

    // Stan blokady (issue #739: zerowanie po automatycznym zwolnieniu)
    // mieszka w ./wake-lock-gotowania.js, osobno testowalnym module bez
    // DOM-u i bez prawdziwego navigator.wakeLock. Tu zostaje wyłącznie
    // okablowanie DOM-u i treść komunikatów.
    const kontroler = utworzKontrolerWakeLock(
        () => navigator.wakeLock.request('screen'),
        (aktywna) => {
            if (aktywna) {
                // Jawny komunikat, że tak się dzieje — issue wymaga tego
                // wprost, nie samego działającego przełącznika bez
                // wyjaśnienia.
                status.textContent = 'Ekran nie zgaśnie, dopóki jesteś na tej stronie.';

                return;
            }

            // Zdarzenie „nieaktywna” ma dwa różne powody, więc dwa różne
            // teksty: zwykłe wyłączenie przełącznikiem milczy (checkbox
            // sam pokazuje swój stan), a zwolnienie, którego człowiek NIE
            // zażądał (automatyczne albo odmowa API), mówi prawdę
            // o TERAŹNIEJSZYM stanie ekranu, żeby nikt nie wrócił do
            // kuchni ufając zgaszonemu ekranowi mimo zaznaczonego
            // przełącznika.
            status.textContent = checkbox.checked
                ? 'Ekran może teraz zgasnąć — ta karta była przez chwilę w tle.'
                : '';

            if (!checkbox.checked) {
                return;
            }

            // Awaria `request()` (np. oszczędzanie baterii): przełącznik
            // wygląda na zaznaczony, ale blokady nie ma — odznaczamy go,
            // żeby stan na ekranie mówił prawdę.
            checkbox.checked = false;
            status.textContent = 'Nie udało się wyłączyć usypiania ekranu w tej przeglądarce.';
        },
    );

    // Możliwość wyłączenia (issue) — ten sam checkbox włącza i wyłącza.
    checkbox.addEventListener('change', () => {
        if (checkbox.checked) {
            kontroler.wlacz();
        } else {
            kontroler.wylacz();
        }
    });

    /*
     * POWRÓT Z TŁA (issue #739). Przeglądarka zdążyła zwolnić blokadę
     * automatycznie (np. zmiana karty), ale checkbox wciąż jest
     * zaznaczony — odzyskujemy ją, żeby nie trzeba było odznaczać
     * i zaznaczać ręcznie po każdym zerknięciu w inną kartę.
     *
     * `kontroler.jestAktywna()` MUSI wrócić do `false` po automatycznym
     * zwolnieniu, żeby ten warunek kiedykolwiek był prawdziwy — to
     * dokładnie ta własność, której brakowało przed poprawką (stara
     * zmienna nigdy nie wracała do `null` po zdarzeniu `release`, więc to
     * odzyskanie nigdy się nie uruchamiało).
     */
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && checkbox.checked && !kontroler.jestAktywna()) {
            kontroler.wlacz();
        }
    });
})();

// --- Tryb gotowania: minutniki przy krokach (issue #24, #751, #740, #755) --

/*
 * Baza bez JS to samo zdanie w Blade (ustaw sobie kuchenny minutnik na...).
 * To tutaj jest DOKLADKA: licznik w tej samej karcie, z dzwiekiem i wibracja
 * na koniec, zeby nie trzeba bylo siegac po osobny minutnik.
 *
 * Cala arytmetyka (zegar monotoniczny -- issue #751; zapis/odczyt stanu
 * w sessionStorage na przetrwanie przeladowania -- issue #740) mieszka
 * w ./minutnik-krok.js, osobno testowalnym module bez DOM-u. Tu zostaje
 * wylacznie okablowanie DOM-u.
 */
/*
 * Krotki sygnal przez Web Audio API zamiast pliku dzwiekowego -- ten
 * artefakt musi dzialac bez dodatkowego zasobu do pobrania, a "beep"
 * z oscylatora kosztuje zero bajtow transferu. Deklaracje funkcji (nie
 * `const`), bo korzystaja z nich oba miejsca nizej: minutnik widocznego
 * kroku i pas alarmow innych krokow. Cala sekcja stoi PRZED nimi: minutnik
 * widocznego kroku potrafi zagrac alarm juz przy ladowaniu strony (termin
 * minal, gdy karta lezala w tle -- przeglad #1301), a `let kontekstAlarmu`
 * zadeklarowane nizej bylby wtedy jeszcze w martwej strefie (TDZ) --
 * ReferenceError polkniety przez `catch` oznaczalby alarm bez dzwieku.
 *
 * DZWIEK NA TELEFONIE (przeglad #1301). Kazdy krok to swiezo zaladowana
 * strona, a przegladarki (iOS Safari, Chrome na Androidzie) startuja
 * AudioContext utworzony bez gestu czlowieka jako ZAWIESZONY -- sygnal
 * alarmu z pasa innych krokow po prostu by nie zagral, a `vibrate` bez
 * gestu bywa ignorowane. Dlatego:
 *  - jeden wspolny kontekst na strone (a nie nowy na kazdy sygnal --
 *    przegladarki limituja liczbe kontekstow, a 12 powtorzen alarmu to
 *    12 kontekstow);
 *  - pierwsze dotkniecie albo klawisz gdziekolwiek na stronie trybu
 *    gotowania odblokowuje go (`resume()` + cichy bufor dla starszego
 *    Safari) -- jesli czlowiek dotknal ekranu, zanim minutnik skonczyl,
 *    pierwszy sygnal zagra;
 *  - jesli alarm przychodzi przed jakimkolwiek gestem (typowo: termin
 *    minal, gdy telefon byl zablokowany, a iOS przeladowal karte po
 *    powrocie), pierwszy sygnal PRZEMILCZY, a wibracja zwykle tez --
 *    dlatego alarm w pasie (pokazAlarmWPasie) powtarza sygnal co kilka
 *    sekund: pierwsze dotkniecie odblokowuje dzwiek, zagra kolejne
 *    powtorzenie;
 *  - zamykamy go przy `pagehide`, a nie po zdarzeniu `ended` oscylatora,
 *    ktore przy zawieszonym kontekscie nigdy nie przychodzi.
 * Nawet tak nic nie gwarantuje dzwieku (wyciszony telefon, brak gestu),
 * wiec glownym sygnalem jest WIDOCZNY komunikat w pasie `.cook-alarmy`
 * (`role="alert"`) -- takze dla spoznionego minutnika widocznego kroku --
 * a UI nie obiecuje, ze cos zabrzmi.
 */
let kontekstAlarmu = null;

function wspolnyKontekstAudio() {
    if (kontekstAlarmu && kontekstAlarmu.state !== 'closed') {
        return kontekstAlarmu;
    }

    const KlasaAudio = window.AudioContext || window.webkitAudioContext;

    kontekstAlarmu = KlasaAudio ? new KlasaAudio() : null;

    return kontekstAlarmu;
}

function odblokujDzwiek() {
    try {
        const kontekst = wspolnyKontekstAudio();

        if (!kontekst || kontekst.state === 'running') {
            return;
        }

        kontekst.resume().catch(() => {});

        // Starsze iOS Safari odblokowuje dzwiek dopiero po odegraniu
        // czegokolwiek w trakcie gestu -- jedna cicha probka wystarcza.
        const cisza = kontekst.createBufferSource();
        cisza.buffer = kontekst.createBuffer(1, 1, 22050);
        cisza.connect(kontekst.destination);
        cisza.start(0);
    } catch {
        // Bez dzwieku minutnik i tak dziala -- komunikat na ekranie.
    }
}

if (document.querySelector('.cook-timer, .cook-alarmy')) {
    // Aktywacja uzytkownika na dotyku przychodzi dopiero z pointerup,
    // touchend albo click (iOS Safari nie odblokowuje dzwieku na samym
    // pointerdown) -- sluchamy wszystkich, odblokowanie jest idempotentne.
    ['pointerdown', 'pointerup', 'touchend', 'click', 'keydown'].forEach((zdarzenie) => {
        document.addEventListener(zdarzenie, odblokujDzwiek, {capture: true, passive: true});
    });

    window.addEventListener('pagehide', () => {
        if (kontekstAlarmu && kontekstAlarmu.state !== 'closed') {
            kontekstAlarmu.close().catch(() => {});
        }

        kontekstAlarmu = null;
    });
}

function zagrajAlarm() {
    try {
        const kontekst = wspolnyKontekstAudio();

        if (kontekst) {
            const zagraj = () => {
                const oscylator = kontekst.createOscillator();
                const glosnosc = kontekst.createGain();

                oscylator.connect(glosnosc);
                glosnosc.connect(kontekst.destination);
                oscylator.frequency.value = 880;
                glosnosc.gain.value = 0.2;
                oscylator.start();
                oscylator.stop(kontekst.currentTime + 0.6);
            };

            if (kontekst.state === 'running') {
                zagraj();
            } else {
                // Zawieszony kontekst: probujemy go wznowic, ale sygnal gramy
                // tylko, jesli wznowil sie od razu -- spozniony o minute
                // "beep" przy pierwszym dotknieciu ekranu bylby mylacy.
                // Bez gestu ten pojedynczy sygnal zwykle milczy; dlatego
                // alarmy w pasie powtarzaja go (pokazAlarmWPasie).
                const prosba = performance.now();

                kontekst.resume().then(() => {
                    if (kontekst.state === 'running' && performance.now() - prosba < 1000) {
                        zagraj();
                    }
                }).catch(() => {});
            }
        }
    } catch {
        // Brak dzwieku nie moze wywalic reszty minutnika -- wibracja
        // i komunikat tekstowy dzialaja od niego niezaleznie.
    }

    if ('vibrate' in navigator) {
        navigator.vibrate([300, 150, 300, 150, 300]);
    }
}


/*
 * WIDOCZNY ALARM W PASIE `.cook-alarmy` (issue #1301) -- wspolny dla
 * minutnikow innych krokow i dla spoznionego minutnika widocznego kroku.
 * Komunikat z `role="alert"` jest glownym sygnalem; dzwiek powtarza sie co
 * POWTORZENIA_CO_MS, bo pierwszy sygnal bez gestu zwykle milczy (zawieszony
 * AudioContext) -- dopiero pierwsze dotkniecie ekranu go odblokowuje,
 * a zagra KOLEJNE powtorzenie. Najwyzej minute, zeby zapomniana karta nie
 * piszczala bez konca.
 */
const POWTORZENIA_CO_MS = 5000;
const POWTORZENIA_NAJWYZEJ = 12;

function pokazAlarmWPasie(pas, tresc, przejscie = null) {
    const alarm = document.createElement('div');
    alarm.className = 'cook-alarm';
    alarm.setAttribute('role', 'alert');

    const tekst = document.createElement('p');
    tekst.className = 'cook-alarm-tekst';
    tekst.textContent = tresc;

    const wylacz = document.createElement('button');
    wylacz.type = 'button';
    wylacz.className = 'btn btn-primary btn-cook cook-alarm-wylacz';
    wylacz.textContent = 'Wyłącz alarm';

    alarm.append(tekst, wylacz);

    if (przejscie) {
        const przejdz = document.createElement('a');
        przejdz.className = 'btn btn-secondary btn-cook';
        przejdz.href = przejscie.href;
        przejdz.textContent = przejscie.tekst;
        alarm.append(przejdz);
    }

    pas.append(alarm);
    pas.hidden = false;

    zagrajAlarm();
    let powtorzenia = 1;
    const powtarzanie = window.setInterval(() => {
        zagrajAlarm();
        powtorzenia += 1;

        if (powtorzenia >= POWTORZENIA_NAJWYZEJ) {
            window.clearInterval(powtarzanie);
        }
    }, POWTORZENIA_CO_MS);

    // Wylaczenie bez ruszania fokusu -- woła je tez blok minutnika, gdy
    // czlowiek uruchamia odliczanie jeszcze raz (przeglad #1301: nowe
    // odliczanie obok piszczacego "skonczyl odliczanie" to sprzeczny
    // sygnal). Drugie wywolanie nic nie robi.
    let wylaczony = false;
    const wylaczAlarm = () => {
        if (wylaczony) {
            return;
        }

        wylaczony = true;
        window.clearInterval(powtarzanie);

        if ('vibrate' in navigator) {
            navigator.vibrate(0);
        }

        alarm.remove();
        pas.hidden = pas.childElementCount === 0;
    };

    wylacz.addEventListener('click', () => {
        wylaczAlarm();

        // Fokus nie moze zostac na usunietym przycisku -- przechodzi
        // na nastepny alarm albo na postep krokow u gory ekranu.
        const nastepny = pas.querySelector('.cook-alarm-wylacz');
        const postep = document.querySelector('.cook-progress');

        if (nastepny) {
            nastepny.focus();
        } else if (postep) {
            postep.setAttribute('tabindex', '-1');
            postep.focus();
        }
    });

    return wylaczAlarm;
}

document.querySelectorAll('.cook-timer').forEach((blok) => {
    const przycisk = blok.querySelector('.cook-timer-start');
    const anuluj = blok.querySelector('.cook-timer-anuluj');
    const odliczanie = blok.querySelector('.cook-timer-odliczanie');
    const komunikat = blok.querySelector('.cook-timer-komunikat');
    const etykieta = blok.dataset.timerEtykieta ?? '';
    const sekundyCalkiem = parseInt(blok.dataset.timerSekundy ?? '', 10);
    const recipeSlug = blok.dataset.timerRecipe ?? '';
    const krok = blok.dataset.timerKrok ?? '';

    if (!przycisk || !anuluj || !odliczanie || !komunikat || !Number.isFinite(sekundyCalkiem) || sekundyCalkiem <= 0) {
        return;
    }

    const klucz = kluczStanu(recipeSlug, krok);

    // Callback moze wrocic z opoznieniem po usnieciu karty. Liczymy czas do
    // terminu na zegarze monotonicznym, zamiast zakladac, ze kazde
    // wywolanie setInterval oznacza dokladnie jedna sekunde.
    let interwal = null;
    // Funkcja wylaczajaca alarm TEGO kroku w pasie (pokazAlarmWPasie) --
    // start nowego odliczania musi go uciszyc.
    let wylaczAlarmKroku = null;

    const alarmKroku = () => {
        const pas = document.querySelector('.cook-alarmy');

        if (pas) {
            wylaczAlarmKroku?.();
            wylaczAlarmKroku = pokazAlarmWPasie(pas, 'Minutnik tego kroku skończył odliczanie.');
        } else {
            zagrajAlarm();
        }
    };

    const pokaz = (sekundy) => {
        odliczanie.textContent = formatMinutySekundy(sekundy);
    };

    const pokazKoniec = () => {
        komunikat.textContent = 'Czas minął!';
        przycisk.textContent = 'Uruchom minutnik jeszcze raz';
        przycisk.hidden = false;
        przycisk.disabled = false;
        anuluj.hidden = true;
    };

    const zatrzymajOdliczanie = () => {
        window.clearInterval(interwal);
        interwal = null;
        sessionStorage.removeItem(klucz);
    };

    const uruchomOdliczanie = (terminMonotoniczny) => {
        pokaz(pozostaloSekund(terminMonotoniczny, performance.now()));

        interwal = window.setInterval(() => {
            const pozostalo = pozostaloSekund(terminMonotoniczny, performance.now());
            pokaz(pozostalo);

            if (pozostalo <= 0) {
                zatrzymajOdliczanie();
                pokazKoniec();
                // Widoczny alarm z powtarzanym sygnalem, nie jedno "beep"
                // (przeglad #1301): "Czas minął!" jest tylko dla czytnika
                // ekranu, a gdy iOS zamrozil karte, interwal odpala sie po
                // terminie bez gestu i pojedynczy sygnal milczy.
                alarmKroku();
            }
        }, 1000);
    };

    przycisk.addEventListener('click', () => {
        if (interwal !== null) {
            return;
        }

        // Bez przenoszenia fokusu -- czlowiek wlasnie kliknal ten przycisk.
        wylaczAlarmKroku?.();
        wylaczAlarmKroku = null;

        przycisk.hidden = true;
        anuluj.hidden = false;
        odliczanie.hidden = false;
        komunikat.textContent = `Minutnik ustawiony na ${etykieta}.`;

        const terminMonotoniczny = performance.now() + sekundyCalkiem * 1000;
        // Zapis PRZED startem -- zeby nawigacja albo oznaczenie kroku
        // (oba przeladowuja strone -- issue #740) mialy co odczytac,
        // nawet jesli czlowiek kliknął "Nastepny krok" sekunde po starcie.
        sessionStorage.setItem(klucz, zapiszStan(sekundyCalkiem, Date.now() + sekundyCalkiem * 1000));
        uruchomOdliczanie(terminMonotoniczny);
    });

    // Swiadome anulowanie (issue #755) -- ten sam odliczany krok da sie
    // zatrzymac, zamiast czekac na dzwiek albo opuszczac tryb gotowania.
    anuluj.addEventListener('click', () => {
        if (interwal === null) {
            return;
        }

        zatrzymajOdliczanie();
        odliczanie.hidden = true;
        anuluj.hidden = true;
        przycisk.hidden = false;
        przycisk.disabled = false;
        przycisk.textContent = 'Uruchom minutnik w tej przeglądarce';
        komunikat.textContent = 'Minutnik anulowany.';
    });

    /*
     * PRZETRWANIE PRZELADOWANIA (issue #740). Nawigacja "Poprzedni/
     * Nastepny krok" i oznaczenie kroku jako zrobiony to pelne
     * przeladowania strony (patrz CookingModeController -- pierwsze to
     * GET, drugie to POST z przekierowaniem). Oba zeruja caly stan
     * JavaScriptu, laczenie z performance.now(). Jesli w sessionStorage
     * czeka nieprzeterminowany termin TEGO kroku, wracamy do odliczania
     * od razu, zamiast pokazywac przycisk startowy, jakby minutnik
     * nigdy nie ruszyl.
     */
    const przywrocZapis = () => {
        if (interwal !== null) {
            return true;
        }

        const zapis = sessionStorage.getItem(klucz);

        if (zapis === null) {
            return false;
        }

        // odczytajTermin, nie odczytajStan (przeglad #1301): termin, ktory
        // minal, gdy karta lezala w tle (telefon zablokowany, iOS wyrzucil
        // karte z pamieci i przeladowal ja po powrocie), tez musi dac
        // alarm -- inaczej zapis znikal po cichu, a razem z nim jedyny
        // sygnal, ze garnek juz czeka.
        const stan = odczytajTermin(zapis, Date.now(), performance.now());

        if (!stan) {
            // Uszkodzony albo porzucony dawno po terminie (ponad
            // PRZETERMINOWANIE_NAJWYZEJ_MS) -- znika po cichu, bez alarmu.
            sessionStorage.removeItem(klucz);
            return false;
        }

        odliczanie.hidden = false;

        if (stan.terminMonotoniczny > performance.now()) {
            przycisk.hidden = true;
            anuluj.hidden = false;
            komunikat.textContent = `Minutnik ustawiony na ${etykieta}.`;
            uruchomOdliczanie(stan.terminMonotoniczny);
            return true;
        }

        // Spozniony, ale nie porzucony: alarm, a DOPIERO POTEM zapis
        // znika -- zeby pas alarmow innych krokow (nizej) nie zadzwonil
        // drugi raz za ten sam minutnik po przejsciu do kolejnego kroku.
        //
        // Sam komunikat w bloku minutnika tu nie wystarcza (przeglad #1301):
        // `.cook-timer-komunikat` jest tylko dla czytnika ekranu, a jego
        // aria-live przy ladowaniu strony zwykle nie jest odczytywane;
        // sygnal bez gestu milczy. Dlatego widoczny alarm w pasie -- ten sam,
        // co dla innych krokow -- z powtarzanym sygnalem.
        pokaz(0);
        pokazKoniec();
        alarmKroku();

        sessionStorage.removeItem(klucz);
        return true;
    };

    if (!przywrocZapis()) {
        przycisk.hidden = false;
    }

    // Powrot "Wstecz" z pamieci podrecznej przegladarki (bfcache) nie
    // uruchamia skryptu od nowa -- zapis tego kroku mogl sie w miedzyczasie
    // pojawic albo przeterminowac, wiec czytamy go jeszcze raz.
    window.addEventListener('pageshow', (zdarzenie) => {
        if (zdarzenie.persisted) {
            przywrocZapis();
        }
    });
});
/*
 * MINUTNIKI INNYCH KROKOW (issue #1301).
 *
 * Kazdy krok to osobne przeladowanie strony, a blok wyzej obsluguje
 * wylacznie `.cook-timer` WIDOCZNEGO kroku. Minutnik uruchomiony w kroku 1
 * zostawal po przejsciu do kroku 2 tylko zapisem w sessionStorage, ktorego
 * nikt nie odliczal -- czyli nie dzwonil, a potrawa sie przypalala.
 *
 * Tu odliczamy wszystkie zapisane minutniki TEGO przepisu z POZA
 * widocznego kroku (widoczny ma swoj blok -- dwa zegary na jeden minutnik
 * oznaczalyby dwa alarmy). Koniec: zapis znika PRZED alarmem, wiec kazdy
 * minutnik dzwoni najwyzej raz, a powrot do jego kroku pokazuje zwykly
 * przycisk startu. Alarm powtarza sygnal, dopoki czlowiek go nie wylaczy
 * duzym przyciskiem (garnek bywa w drugim koncu kuchni) -- ale najwyzej
 * minute, zeby zapomniana karta nie piszczala bez konca.
 */
(() => {
    const pas = document.querySelector('.cook-alarmy');

    if (!pas) {
        return;
    }

    const recipeSlug = pas.dataset.alarmyRecipe ?? '';
    const widocznyKrok = pas.dataset.alarmyKrok ?? '';
    const adres = pas.dataset.alarmyAdres ?? '';

    const pokazAlarm = (krok) => {
        pokazAlarmWPasie(
            pas,
            `Minutnik kroku ${krok} skończył odliczanie.`,
            adres ? {href: `${adres}?krok=${encodeURIComponent(krok)}`, tekst: `Przejdź do kroku ${krok}`} : null,
        );
    };

    const odliczaj = (klucz, krok, zapis, terminMonotoniczny) => {
        const sprawdz = () => {
            if (pozostaloSekund(terminMonotoniczny, performance.now()) > 0) {
                return false;
            }

            // Tylko jesli to wciaz TEN SAM minutnik -- nikt go w miedzyczasie
            // nie anulowal ani nie uruchomil od nowa.
            if (sessionStorage.getItem(klucz) === zapis) {
                sessionStorage.removeItem(klucz);
                pokazAlarm(krok);
            }

            return true;
        };

        if (sprawdz()) {
            return;
        }

        const interwal = window.setInterval(() => {
            if (sprawdz()) {
                window.clearInterval(interwal);
            }
        }, 1000);
    };

    const kluczeTegoPrzepisu = () => {
        const klucze = [];

        for (let i = 0; i < sessionStorage.length; i += 1) {
            const klucz = sessionStorage.key(i);

            if (krokZKlucza(klucz, recipeSlug) !== null) {
                klucze.push(klucz);
            }
        }

        return klucze;
    };

    // Zapisy juz odliczane na tej stronie -- ponowny przeglad (pageshow
    // nizej) nie moze uruchomic drugiego zegara dla tego samego minutnika.
    const odliczane = new Set();

    const przejrzyjZapisy = () => {
        kluczeTegoPrzepisu().forEach((klucz) => {
            const krok = krokZKlucza(klucz, recipeSlug);

            if (krok === widocznyKrok) {
                return;
            }

            const zapis = sessionStorage.getItem(klucz);
            // Uszkodzony albo porzucony dawno po terminie (przeglad #1301):
            // znika po cichu, bez alarmu.
            const stan = odczytajTermin(zapis, Date.now(), performance.now());

            if (!stan) {
                sessionStorage.removeItem(klucz);
                return;
            }

            if (odliczane.has(`${klucz}|${zapis}`)) {
                return;
            }

            odliczane.add(`${klucz}|${zapis}`);
            odliczaj(klucz, krok, zapis, stan.terminMonotoniczny);
        });
    };

    przejrzyjZapisy();

    // Powrot "Wstecz" z pamieci podrecznej przegladarki (bfcache) nie
    // uruchamia skryptu od nowa, a w innym kroku mogl w miedzyczasie
    // ruszyc nowy minutnik -- przegladamy zapisy jeszcze raz.
    window.addEventListener('pageshow', (zdarzenie) => {
        if (zdarzenie.persisted) {
            przejrzyjZapisy();
        }
    });

    /*
     * "Zakoncz gotowanie" i "Ugotowalem" to zwykle linki i dzialaja bez
     * JavaScriptu. Tu tylko DOKLADKA: wychodzac z trybu gotowania czlowiek
     * konczy tez minutniki tego przepisu, wiec ich zapisy znikaja -- inaczej
     * powrot do przepisu w tej samej karcie zaczynalby sie od alarmu za
     * garnek, ktorego dawno nie ma na ogniu (przeglad #1301).
     */
    document.querySelectorAll('[data-minutniki-koniec]').forEach((link) => {
        link.addEventListener('click', () => {
            kluczeTegoPrzepisu().forEach((klucz) => sessionStorage.removeItem(klucz));
        });
    });
})();
// --- Karuzela zdjęć i wybór wyglądu (issue #92) ----------------------------

/*
 * WSZYSTKO PONIŻEJ JEST DODATKIEM, NIE WARUNKIEM.
 *
 * Karuzela przewija się sama: taśma to kontener z `overflow-x: auto`
 * i `scroll-snap`, a „Poprzednie / Następne” to odnośniki do `#id` sąsiedniego
 * slajdu. Przy wyłączonym skrypcie nie działają tylko dwie rzeczy, których bez
 * skryptu zrobić się nie da — i obie są tutaj:
 *
 *  1. ogłoszenie zmiany slajdu w obszarze `aria-live` (bez skryptu numer
 *     slajdu i tak jest widoczny pod zdjęciem oraz w jego tekście
 *     alternatywnym, więc nikt nie gubi się w kolejności);
 *  2. odsłonięcie wyboru wyglądu w formularzu publikacji po wybraniu drugiego
 *     zdjęcia — serwer nie zna liczby plików, dopóki formularz nie zostanie
 *     wysłany (bez skryptu ten sam wybór stoi na ekranie „Zdjęcia w tym
 *     wpisie”, pod opublikowanym wpisem).
 */

for (const tasma of document.querySelectorAll('[data-karuzela-tasma]')) {
    const ogloszenie = tasma.closest('.karuzela')?.querySelector('[data-karuzela-ogloszenie]');
    const slajdy = [...tasma.querySelectorAll('.karuzela-slajd')];

    if (! ogloszenie || slajdy.length < 2 || typeof IntersectionObserver !== 'function') {
        continue;
    }

    // Pierwsze wywołanie obserwatora przychodzi zaraz po wczytaniu strony
    // i dotyczy slajdu, który i tak jest na wierzchu. Wpisanie go do obszaru
    // `aria-live` kazałoby czytnikowi ekranu ogłosić „Zdjęcie 1 z 3" komuś,
    // kto niczego jeszcze nie zrobił. Ogłaszamy dopiero ZMIANĘ.
    let pierwszeWywolanie = true;

    const obserwator = new IntersectionObserver((wpisy) => {
        if (pierwszeWywolanie) {
            pierwszeWywolanie = false;

            return;
        }

        for (const wpis of wpisy) {
            // Ogłaszamy dopiero slajd, który zajmuje WIĘKSZOŚĆ taśmy. Przy
            // niższym progu czytnik ekranu dostawałby dwa komunikaty na każde
            // przewinięcie — także o slajdzie schodzącym z ekranu.
            if (wpis.isIntersecting && wpis.intersectionRatio > 0.6) {
                ogloszenie.textContent = `Zdjęcie ${wpis.target.dataset.karuzelaNumer} z ${slajdy.length}`;
            }
        }
    }, { root: tasma, threshold: [0.6] });

    for (const slajd of slajdy) {
        obserwator.observe(slajd);
    }
}


// --- „Podziel się": arkusz systemowy i kopiowanie adresu -------------------

/*
 * WSZYSTKO PONIŻEJ JEST DODATKIEM, NIE WARUNKIEM.
 *
 * Blok „Podziel się” przychodzi z serwera KOMPLETNY: rozwijany przycisk
 * (`<details>`, czysty HTML), jawna lista dróg — WhatsApp, e-mail, Facebook —
 * i widoczny, zaznaczalny adres. Przy wyłączonym skrypcie działa to wszystko
 * bez zmian. Tutaj dokładamy dokładnie dwie rzeczy, których bez skryptu
 * zrobić się nie da:
 *
 *  1. ARKUSZ SYSTEMOWY (`navigator.share`). Na telefonie to jest ta lista,
 *     na której człowiek widzi SWOJEGO Messengera, WhatsAppa i SMS-y —
 *     czyli aplikacje, których my z poziomu strony nie znamy i znać nie
 *     możemy. Przejmujemy kliknięcie w ten sam przycisk, zamiast dokładać
 *     drugi: dwa przyciski „Podziel się” obok siebie to pytanie, na które
 *     nikt nie umie odpowiedzieć.
 *
 *     Gdy arkusz zawiedzie z innego powodu niż anulowanie przez człowieka,
 *     ROZWIJAMY jawną listę. Kliknięcie nie może skończyć się niczym.
 *
 *  2. „Skopiuj adres”. Przycisk stoi w HTML-u z atrybutem `hidden` i odsłania
 *     go dopiero ten kod — bez schowka nie miałby czego zrobić, a martwy
 *     przycisk jest gorszy niż jego brak. Adres i tak jest widoczny w polu
 *     obok, więc bez skryptu zaznacza się go myszą jak każdy inny tekst.
 */

for (const blok of document.querySelectorAll('[data-podziel-sie]')) {
    const przycisk = blok.querySelector('summary');
    const pole = blok.querySelector('[data-podziel-pole]');
    const kopiuj = blok.querySelector('[data-podziel-kopiuj]');
    const echo = blok.querySelector('[data-podziel-echo]');

    const dane = {
        title: blok.dataset.podzielTytul,
        text: blok.dataset.podzielTekst,
        url: blok.dataset.podzielAdres,
    };

    // --- 1. Arkusz systemowy ---------------------------------------------

    // `canShare` pytamy TYLKO wtedy, gdy przeglądarka je ma. Odpowiedź „nie”
    // znaczy, że tego zestawu danych nie da się wysłać — wtedy zostawiamy
    // przycisk w spokoju i człowiek dostaje jawną listę, tak jak bez skryptu.
    const arkuszDziala = typeof navigator.share === 'function'
        && (typeof navigator.canShare !== 'function' || navigator.canShare(dane));

    if (przycisk && arkuszDziala) {
        przycisk.addEventListener('click', async (zdarzenie) => {
            // Bez tego `<details>` rozwinęłoby się JEDNOCZEŚNIE z arkuszem
            // i po jego zamknięciu pod spodem czekałaby otwarta lista,
            // o którą nikt nie prosił.
            zdarzenie.preventDefault();

            try {
                await navigator.share(dane);
            } catch (blad) {
                // Anulowanie arkusza to świadoma decyzja człowieka, nie
                // awaria — nie ma go za co karać rozwijaniem listy.
                if (blad && blad.name === 'AbortError') {
                    return;
                }

                blok.open = true;
            }
        });
    }

    // --- 2. Kopiowanie adresu --------------------------------------------

    if (kopiuj && pole) {
        kopiuj.hidden = false;

        kopiuj.addEventListener('click', async () => {
            let skopiowane = false;

            try {
                await navigator.clipboard.writeText(pole.value);
                skopiowane = true;
            } catch {
                // Schowka nie ma albo strona nie chodzi po HTTPS. Zostaje
                // droga starsza, ale działająca w tych właśnie warunkach.
                pole.focus();
                pole.select();

                try {
                    skopiowane = document.execCommand('copy');
                } catch {
                    skopiowane = false;
                }
            }

            if (! skopiowane) {
                pole.focus();
                pole.select();
            }

            if (echo) {
                echo.textContent = skopiowane
                    ? 'Skopiowano adres.'
                    : 'Na telefonie przytrzymaj palcem adres w polu i wybierz „Kopiuj” z menu zaznaczenia. Na komputerze naciśnij Ctrl+C, a na Macu Cmd+C.';
            }
        });
    }
}

/* ==========================================================================
   BŁĄD FORMULARZA MA BYĆ WIDOCZNY OD RAZU — bez szukania go wzrokiem.
   ==========================================================================

   ZDARZENIE, KTÓRE TO WYWOŁAŁO (10 września 2026)
   63-letnia osoba wypełniała rejestrację na komputerze. Formularz odbił jej
   nazwę użytkownika. Podsumowanie błędów stoi na górze formularza — ale ona
   po wysłaniu została w tym samym miejscu, w którym kliknęła przycisk, czyli
   na dole. Nie zobaczyła ani podsumowania, ani czerwonego tekstu przy polu
   wyżej; zobaczyła dwa duże czerwone pudła obok siebie (zaznaczone zgody)
   i uznała, że problem jest tam.

   DWIE RZECZY, KTÓRE TU ROBIMY:
   1. po wysłaniu z błędem przewijamy do podsumowania i ustawiamy na nim
      fokus — czytnik ekranu przeczyta je od razu (`role="alert"`,
      `tabindex="-1"` są już w komponencie);
   2. zaznaczenie wyboru NATYCHMIAST zdejmuje z niego czerwień i chowa
      komunikat — bez tego człowiek poprawia błąd i nadal widzi czerwone
      pudło, dopóki nie wyśle formularza jeszcze raz.

   JavaScript jest tu dodatkiem, nie warunkiem: bez niego podsumowanie stoi
   na górze, a przy przycisku „Załóż konto" jest zdanie mówiące, że coś
   zostało do poprawienia (AGENTS.md §5 pkt 3 — żadnego martwego przycisku).
   Przewijanie szanuje `prefers-reduced-motion`.
   ========================================================================== */
(function bledyFormularzaWidoczne() {
    const podsumowanie = document.querySelector('.error-summary');

    if (podsumowanie) {
        const bezRuchu = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        podsumowanie.scrollIntoView({ behavior: bezRuchu ? 'auto' : 'smooth', block: 'center' });

        // Fokus PO przewinięciu: `focus()` sam przewija skokowo, a przy
        // `block: 'center'` chcemy, żeby człowiek zobaczył też to, co jest
        // nad podsumowaniem — czyli że jest na górze formularza.
        window.setTimeout(() => podsumowanie.focus({ preventScroll: true }), bezRuchu ? 0 : 400);
    }

    document.querySelectorAll('.field.has-error .choice input[type="checkbox"]').forEach((pole) => {
        pole.addEventListener('change', () => {
            if (!pole.checked) {
                return;
            }

            const grupa = pole.closest('.field');

            if (grupa === null) {
                return;
            }

            grupa.classList.remove('has-error');
            grupa.querySelectorAll('.field-error').forEach((komunikat) => {
                komunikat.hidden = true;
            });
        });
    });
})();

/* ==========================================================================
   NAZWA KONTA PODPOWIADANA Z IMIENIA — decyzja właściciela z 10 września.
   ==========================================================================

   DLACZEGO DWA POLA ZOSTAJĄ
   Zewnętrzny audyt 60+ wskazał, że rejestracja każe wymyślić dwa podobne
   pojęcia naraz: imię widoczne dla innych i nazwę, która trafia do adresu
   profilu. Właściciel rozstrzygnął, żeby oba pola zostały — adres profilu ma
   być świadomym wyborem, a nie czymś, co człowiek odkrywa po fakcie — ale
   żeby nazwa była PODPOWIADANA z imienia i dała się nadpisać.

   AUTORYTETEM JEST PHP, NIE TEN KOD
   Prawdziwą normalizację robi `App\Support\NazwaUzytkownika::znormalizuj()`
   przy wysłaniu formularza. Tutaj jest tylko podpowiedź w polu, więc
   rozjazd między tą transliteracją a tamtą jest NIESZKODLIWY: serwer i tak
   ułoży nazwę po swojemu. Dlatego świadomie nie przepisuję tu całej tablicy
   znaków — to byłoby drugie źródło prawdy, które rozjedzie się przy pierwszej
   zmianie reguły.

   PRZESTAJEMY PODPOWIADAĆ, GDY CZŁOWIEK RUSZY POLE SAM. Nadpisywanie tego,
   co ktoś wpisał ręcznie, jest gorsze niż brak podpowiedzi — a przy 65-latce
   wyglądałoby jak usterka („kasuje mi to, co piszę").

   Bez JavaScriptu nic się nie psuje: pole zostaje puste, a wpisany w nie
   dowolny zapis („Basia z Podkarpacia") i tak przechodzi, bo serwer wybacza.
   ========================================================================== */
(function podpowiedzNazweKonta() {
    const imie = document.getElementById('f-display_name');
    const nazwa = document.getElementById('f-username');

    if (imie === null || nazwa === null) {
        return;
    }

    // Formularz przyszedł z błędem i pole jest już wypełnione? Nie ruszamy —
    // to jest albo wybór człowieka, albo wartość znormalizowana przez serwer.
    let wlasnyWybor = nazwa.value.trim() !== '';

    nazwa.addEventListener('input', () => {
        wlasnyWybor = true;
    });

    const zImienia = (tekst) => tekst
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')      // ą→a, ć→c, ę→e, ó→o, ś→s, ź→z, ż→z
        .replace(/ł/g, 'l').replace(/Ł/g, 'L') // „ł" nie rozkłada się na znak bazowy
        .toLowerCase()
        .replace(/[\s.\-'’]+/g, '_')
        .replace(/[^a-z0-9_]/g, '')
        .replace(/_+/g, '_')
        .replace(/^_|_$/g, '')
        .slice(0, 40);

    imie.addEventListener('input', () => {
        if (wlasnyWybor) {
            return;
        }

        nazwa.value = zImienia(imie.value);
    });
})();

// --- Menu konta w pasku górnym: Esc i kliknięcie obok ----------------------

/*
 * WSZYSTKO PONIŻEJ JEST DODATKIEM, NIE WARUNKIEM (issue #344, D-053).
 *
 * Menu przy awatarze to `<details>` (`components/layout.blade.php`), więc
 * przychodzi z serwera KOMPLETNE: otwiera je kliknięcie albo dotknięcie
 * w przycisk, zamyka drugie kliknięcie w ten sam przycisk, a klawiatura
 * obsługuje je jak każdy inny `<summary>`. Przy wyłączonym albo
 * niedociągniętym skrypcie działa to bez zmian i nie zostaje tu ani jeden
 * martwy przycisk.
 *
 * Dokładamy dokładnie dwie rzeczy, których `<details>` sam nie robi, a które
 * człowiek zna z każdego innego menu:
 *
 *  1. Esc zamyka otwarte menu i wraca fokusem na przycisk. Bez tego jedyną
 *     drogą powrotu jest przejście Tabem przez trzy pozycje.
 *  2. Kliknięcie albo dotknięcie POZA menu zamyka je. Bez tego otwarte menu
 *     zostaje na ekranie i przykrywa róg strony — a przy mniej pewnej ręce
 *     „trafić z powrotem dokładnie w ten sam przycisk" jest dokładnie tym
 *     wysiłkiem, którego chcemy oszczędzić.
 *
 * Hover świadomie NIE otwiera menu (AGENTS.md §5): drżenie ręki zamyka je
 * w trakcie celowania, a na dotyku hover nie istnieje w ogóle.
 */
for (const menu of document.querySelectorAll('details.topbar-konto')) {
    const przycisk = menu.querySelector('summary');

    document.addEventListener('keydown', (zdarzenie) => {
        if (zdarzenie.key !== 'Escape' || !menu.open) {
            return;
        }

        menu.open = false;
        // Fokus wraca na przycisk, a nie na początek strony — inaczej
        // zamknięcie menu klawiaturą gubi miejsce, w którym się było.
        przycisk?.focus();
    });

    document.addEventListener('click', (zdarzenie) => {
        if (menu.open && !menu.contains(zdarzenie.target)) {
            menu.open = false;
        }
    });
}

/* ==========================================================================
   KREATOR PRZEPISU: FOKUS PO SKOKU DO INNEGO KROKU (issue #747)
   ==========================================================================

   Podsumowanie błędów w kreatorze potrafi wskazywać pole z kroku, którego
   aktualny render w ogóle nie zawiera — `wire:click="jumpToError(...)"`
   w `recipe-wizard.blade.php` przełącza `$step` po stronie serwera i prosi
   o fokus na właściwym polu zdarzeniem `kreator-fokus-pole`. Sam przełącznik
   kroku nie wystarczy: w chwili, w której PHP o tym decyduje, przeglądarka
   jeszcze nie ma nowego DOM-u — element pojawia się dopiero po tym, jak
   Livewire przerenderuje komponent i zdarzenie faktycznie dotrze tutaj.

   `Livewire.on` rejestrujemy dopiero po `livewire:init`, żeby nie zależeć
   od kolejności `@vite` kontra `@livewireScripts` w layoucie — a jeśli
   Livewire zdążył już wystartować (bo ten skrypt wczytał się później),
   `window.Livewire` istnieje i można podłączyć się od razu.
   ========================================================================== */
(function fokusPoSkokuKreatora() {
    const podlacz = () => {
        window.Livewire.on('kreator-fokus-pole', ({ pole }) => {
            // Livewire kończy morph DOM-u przed doręczeniem zdarzenia
            // słuchaczom zarejestrowanym przez `Livewire.on`, ale
            // `requestAnimationFrame` daje przeglądarce jedną klatkę na
            // domalowanie układu — bez tego `scrollIntoView` na elemencie
            // z `display: none` przed przemalowaniem czasem nic nie robi.
            requestAnimationFrame(() => {
                const cel = document.getElementById(pole);

                if (!cel) {
                    return;
                }

                const bezRuchu = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                cel.scrollIntoView({ behavior: bezRuchu ? 'auto' : 'smooth', block: 'center' });
                cel.focus({ preventScroll: true });
            });
        });
    };

    if (window.Livewire) {
        podlacz();
    } else {
        document.addEventListener('livewire:init', podlacz);
    }
})();
