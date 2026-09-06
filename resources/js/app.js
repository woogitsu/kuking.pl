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

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Brak service workera nie może niczego zepsuć — aplikacja działa dalej.
        });
    });
}

// --- Podgląd wybranych zdjęć ---------------------------------------------

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
        pojemnik.setAttribute('aria-live', 'polite');
        pojemnik.style.marginTop = '12px';
        pojemnik.style.display = 'grid';
        pojemnik.style.gap = '8px';
        pojemnik.style.gridTemplateColumns = 'repeat(auto-fill, minmax(120px, 1fr))';
        input.insertAdjacentElement('afterend', pojemnik);
    }

    pojemnik.replaceChildren();

    const pliki = Array.from(input.files ?? []);

    if (pliki.length === 0) {
        return;
    }

    const info = document.createElement('p');
    info.style.gridColumn = '1 / -1';
    info.style.margin = '0';
    info.style.fontWeight = '700';
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
        img.style.width = '100%';
        img.style.height = 'auto';
        img.style.borderRadius = '12px';
        img.src = URL.createObjectURL(plik);
        img.addEventListener('load', () => URL.revokeObjectURL(img.src), { once: true });
        pojemnik.appendChild(img);
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
        obraz.removeAttribute('src');
        obraz.alt = '';
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

(() => {
    const pole = document.getElementById('f-photos');
    const wybor = document.querySelector('[data-wybor-wygladu]');

    if (! pole || ! wybor) {
        return;
    }

    pole.addEventListener('change', () => {
        const kilka = (pole.files?.length ?? 0) >= 2;

        wybor.hidden = ! kilka;

        // Wybór, który znika z ekranu, wraca do „zwykle”. Inaczej ktoś
        // zaznaczyłby kolaż przy trzech zdjęciach, zmienił wybór plików na
        // jedno — i wysłałby ustawienie, którego już nie widzi.
        if (! kilka) {
            const zwykle = wybor.querySelector('input[value="normal"]');

            if (zwykle) {
                zwykle.checked = true;
            }
        }
    });
})();
