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

// Adresy zwalniamy również przy zmianie wyboru, zanim obraz zdąży się wczytać.
const previewUrls = new Map();
function releasePreview(input) {
    for (const url of previewUrls.get(input) ?? []) URL.revokeObjectURL(url);
    previewUrls.delete(input);
}

function selectedFiles(files) {
    const transfer = new DataTransfer();
    for (const file of files) transfer.items.add(file);
    return transfer.files;
}

function renderPhotoPreview(input, focusIndex = null) {
    const pojemnikId = `${input.id}-podglad`;
    let pojemnik = document.getElementById(pojemnikId);
    if (!pojemnik) {
        pojemnik = document.createElement('div');
        pojemnik.id = pojemnikId;
        pojemnik.className = 'podglad-wyboru';
        kotwicaPodPolem(input).insertAdjacentElement('afterend', pojemnik);
    }
    releasePreview(input);
    pojemnik.replaceChildren();
    const pliki = Array.from(input.files ?? []);
    pojemnik.dataset.ile = String(pliki.length);

    // Osobny, trwały region ogłasza zmianę, a nie całą listę przycisków.
    let status = document.getElementById(`${input.id}-wybor-status`);
    if (!status) {
        status = document.createElement('p');
        status.id = `${input.id}-wybor-status`;
        status.className = 'podglad-wyboru-info';
        status.setAttribute('role', 'status');
        pojemnik.insertAdjacentElement('beforebegin', status);
    }
    status.textContent = pliki.length ? `Wybrane zdjęcia: ${pliki.length}.` : 'Nie wybrano nowych zdjęć.';
    const limit = Number(input.dataset.photoLimit);
    const saved = input.form?.querySelectorAll('input[name="media_ids[]"]').length ?? 0;
    if (limit && saved + pliki.length > limit) {
        status.textContent += ` Łącznie ze zdjęciami zachowanymi: ${saved + pliki.length}. Limit: ${limit}. Usuń nadmiarowe zdjęcia lub wybierz je ponownie.`;
    }

    let removable = input.hasAttribute('data-remove-photos');
    if (removable && pliki.length) {
        try {
            // Próba na odłączonym polu nie zmienia prawdziwego wyboru.
            const probe = document.createElement('input');
            probe.type = 'file';
            probe.files = selectedFiles(pliki);
            removable = probe.files.length === pliki.length;
        } catch {
            removable = false;
        }
        if (!removable) status.textContent += ' Aby zmienić zestaw, kliknij „Dodaj zdjęcie” i wybierz zdjęcia ponownie.';
    }
    const urls = new Set();
    if (pliki.length) previewUrls.set(input, urls);
    pliki.forEach((file, index) => {
        const card = document.createElement('div');
        card.className = 'podglad-wyboru-plik';
        if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.alt = '';
            img.className = 'podglad-wyboru-zdjecie';
            const url = URL.createObjectURL(file);
            urls.add(url);
            const release = () => {
                URL.revokeObjectURL(url);
                urls.delete(url);
                if (!urls.size && previewUrls.get(input) === urls) previewUrls.delete(input);
            };
            img.addEventListener('load', release, {once: true});
            img.addEventListener('error', release, {once: true});
            img.src = url;
            card.appendChild(img);
        }
        if (input.hasAttribute('data-remove-photos')) {
            const name = document.createElement('p');
            name.className = 'podglad-wyboru-nazwa';
            name.textContent = file.name;
            card.appendChild(name);
        }
        if (removable) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary';
            button.textContent = 'Usuń zdjęcie';
            button.setAttribute('aria-label', `Usuń zdjęcie ${index + 1}: ${file.name}`);
            button.addEventListener('click', () => {
                const remaining = pliki.filter((_, position) => position !== index);
                try {
                    input.files = selectedFiles(remaining);
                    if (input.files.length !== remaining.length || remaining.some((file, position) => input.files[position] !== file)) throw new Error('FileList');
                } catch {
                    status.textContent = 'Nie udało się usunąć zdjęcia z wyboru. Kliknij „Dodaj zdjęcie” i wybierz zdjęcia ponownie.';
                    return;
                }
                renderPhotoPreview(input, index);
            });
            card.appendChild(button);
        }
        pojemnik.appendChild(card);
    });
    if (focusIndex !== null) {
        const buttons = pojemnik.querySelectorAll('button');
        (buttons[Math.min(focusIndex, buttons.length - 1)] ?? input).focus();
    }
}

document.addEventListener('change', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.type === 'file') renderPhotoPreview(input);
});
document.addEventListener('reset', (event) => {
    queueMicrotask(() => {
        for (const input of event.target.querySelectorAll('input[type="file"]')) {
            if (document.getElementById(`${input.id}-podglad`)) renderPhotoPreview(input);
        }
    });
});
window.addEventListener('pagehide', () => {
    for (const input of previewUrls.keys()) releasePreview(input);
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
    // na serwerze nie ma. Usuwamy go razem z pokazaniem błędu.
    releasePreview(input);
    document.getElementById(`${input.id}-podglad`)?.replaceChildren();
    document.getElementById(`${input.id}-wybor-status`)?.remove();
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

        obraz.src = link.getAttribute('href');
        obraz.alt = link.dataset.alt || '';

        okno.showModal();
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

        obraz.removeAttribute('src');
        obraz.alt = '';
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

    let blokada = null;

    const wlacz = async () => {
        try {
            blokada = await navigator.wakeLock.request('screen');

            // Jawny komunikat, że tak się dzieje — issue wymaga tego wprost,
            // nie samego działającego przełącznika bez wyjaśnienia.
            status.textContent = 'Ekran nie zgaśnie, dopóki jesteś na tej stronie.';

            blokada.addEventListener('release', () => {
                // Przeglądarka sama zwalnia blokadę (np. zmiana karty) —
                // komunikat ma mówić prawdę o TERAZNIEJSZYM stanie, żeby
                // nikt nie wrócił do kuchni ufając zgaszonemu ekranowi.
                status.textContent = checkbox.checked
                    ? 'Ekran może teraz zgasnąć — ta karta była przez chwilę w tle.'
                    : '';
            });
        } catch {
            // Np. system oszczędza baterię i odmawia blokady. Cicho
            // odznaczamy checkbox zamiast straszyć komunikatem o czymś,
            // na co nie ma wpływu z tego miejsca.
            checkbox.checked = false;
            status.textContent = 'Nie udało się wyłączyć usypiania ekranu w tej przeglądarce.';
        }
    };

    const wylacz = () => {
        blokada?.release();
        blokada = null;
        status.textContent = '';
    };

    // Możliwość wyłączenia (issue) — ten sam checkbox włącza i wyłącza.
    checkbox.addEventListener('change', () => {
        if (checkbox.checked) {
            wlacz();
        } else {
            wylacz();
        }
    });

    // Powrót z tła: przeglądarka zdążyła zwolnić blokadę, ale checkbox
    // wciąż jest zaznaczony — odzyskujemy ją automatycznie, żeby nie trzeba
    // było odznaczać i zaznaczać ręcznie po każdym zerknięciu w inną kartę.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && checkbox.checked && blokada === null) {
            wlacz();
        }
    });
})();

// --- Tryb gotowania: minutniki przy krokach (issue #24) --------------------

/*
 * Baza bez JS to samo zdanie w Blade („ustaw sobie kuchenny minutnik na…”).
 * To tutaj jest DOKŁADKA: licznik w tej samej karcie, z dźwiękiem i wibracją
 * na koniec, żeby nie trzeba było sięgać po osobny minutnik.
 */
document.querySelectorAll('.cook-timer').forEach((blok) => {
    const przycisk = blok.querySelector('.cook-timer-start');
    const odliczanie = blok.querySelector('.cook-timer-odliczanie');
    const komunikat = blok.querySelector('.cook-timer-komunikat');
    const etykieta = blok.dataset.timerEtykieta ?? '';
    const sekundyCalkiem = parseInt(blok.dataset.timerSekundy ?? '', 10);

    if (!przycisk || !odliczanie || !komunikat || !Number.isFinite(sekundyCalkiem) || sekundyCalkiem <= 0) {
        return;
    }

    przycisk.hidden = false;

    // Callback może wrócić z opóźnieniem po uśpieniu karty. Liczymy czas do
    // terminu, zamiast zakładać, że każde wywołanie oznacza jedną sekundę.
    let termin = null;
    let interwal = null;

    const pokaz = (sekundy) => {
        const minuty = Math.floor(sekundy / 60);
        const reszta = sekundy % 60;
        odliczanie.textContent = `${minuty}:${String(reszta).padStart(2, '0')}`;
    };

    /*
     * Krótki sygnał przez Web Audio API zamiast pliku dźwiękowego — ten
     * artefakt musi działać bez dodatkowego zasobu do pobrania, a „beep”
     * z oscylatora kosztuje zero bajtów transferu.
     */
    const zagraj = () => {
        try {
            const KlasaAudio = window.AudioContext || window.webkitAudioContext;
            const kontekst = new KlasaAudio();
            const oscylator = kontekst.createOscillator();
            const glosnosc = kontekst.createGain();

            oscylator.connect(glosnosc);
            glosnosc.connect(kontekst.destination);
            oscylator.frequency.value = 880;
            glosnosc.gain.value = 0.2;
            oscylator.start();
            oscylator.stop(kontekst.currentTime + 0.6);
            oscylator.addEventListener('ended', () => kontekst.close());
        } catch {
            // Brak dźwięku nie może wywalić reszty minutnika — wibracja
            // i komunikat tekstowy niżej działają od niego niezależnie.
        }
    };

    przycisk.addEventListener('click', () => {
        if (interwal !== null) {
            return;
        }

        przycisk.disabled = true;
        odliczanie.hidden = false;
        termin = Date.now() + sekundyCalkiem * 1000;
        pokaz(sekundyCalkiem);
        komunikat.textContent = `Minutnik ustawiony na ${etykieta}.`;

        interwal = window.setInterval(() => {
            const pozostalo = Math.ceil((termin - Date.now()) / 1000);
            pokaz(Math.max(pozostalo, 0));

            if (pozostalo <= 0) {
                window.clearInterval(interwal);
                interwal = null;
                zagraj();

                if ('vibrate' in navigator) {
                    navigator.vibrate([300, 150, 300, 150, 300]);
                }

                komunikat.textContent = 'Czas minął!';
                przycisk.textContent = 'Uruchom minutnik jeszcze raz';
                przycisk.disabled = false;
                termin = null;
            }
        }, 1000);
    });
});
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
                    : 'Adres jest zaznaczony. Skopiuj go teraz: Ctrl+C, a na Macu Cmd+C.';
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
