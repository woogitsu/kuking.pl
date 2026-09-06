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

    let pozostalo = sekundyCalkiem;
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
        pokaz(pozostalo);
        komunikat.textContent = `Minutnik ustawiony na ${etykieta}.`;

        interwal = window.setInterval(() => {
            pozostalo -= 1;
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
                pozostalo = sekundyCalkiem;
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

