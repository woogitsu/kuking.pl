/*
 * Usunięcie JEDNEGO nowego zdjęcia z wyboru przed wysłaniem (issue #884).
 *
 * Kto wybrał z galerii trzy zdjęcia, a jedno z nich jest pomyłką, musiał
 * dotąd wybierać wszystkie od nowa. Przycisk „Usuń” przy miniaturze zmienia
 * FAKTYCZNY zestaw plików w polu — nie tylko obrazek na ekranie. Sam
 * znikający element podglądu byłby kłamstwem: formularz i tak wysłałby plik.
 *
 * JAK: przeglądarka nie pozwala zmienić `FileList` wprost, ale pozwala
 * podstawić nową listę zbudowaną przez `DataTransfer` (Chrome, Firefox,
 * Safari od 14.1). Starsza przeglądarka tego nie umie — wtedy przycisków
 * w ogóle nie pokazujemy, bo nie obiecujemy działania, którego nie ma.
 * Po podstawieniu SPRAWDZAMY, czy pole naprawdę ma o jeden plik mniej;
 * jeśli nie, mówimy to wprost i nie udajemy sukcesu.
 *
 * Bez JavaScriptu nic się tu nie dzieje: formularz działa jak dotąd.
 */

/** Polska odmiana rzeczownika po liczebniku — te same reguły co `App\Support\Odmiana::rzeczownik()`. */
function odmiana(n, jeden, kilka, wiele) {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (n === 1) return jeden;
    if (mod10 >= 2 && mod10 <= 4 && !(mod100 >= 12 && mod100 <= 14)) return kilka;
    return wiele;
}

/** Zdanie licznika pod polem: „Wybrano 5 zdjęć.” (dotąd było „5 zdjęcia”). */
export function komunikatWyboru(ile) {
    return `Wybrano ${ile} ${odmiana(ile, 'zdjęcie', 'zdjęcia', 'zdjęć')}.`;
}

/** Czy ta przeglądarka umie zbudować nową listę plików. */
export function moznaUsuwacZWyboru(KlasaDataTransfer = globalThis.DataTransfer) {
    if (typeof KlasaDataTransfer !== 'function') {
        return false;
    }

    try {
        const proba = new KlasaDataTransfer();

        return typeof proba.items?.add === 'function' && proba.files !== undefined;
    } catch {
        return false;
    }
}

/**
 * Podstawia w polu listę plików bez pliku o numerze `indeks`.
 * Zwraca `true` WYŁĄCZNIE wtedy, gdy pole po zmianie ma dokładnie pozostałe
 * pliki w tej samej kolejności — inaczej `false` i pole zostaje nietknięte
 * (albo tak, jak zostawiła je przeglądarka; sprawdza to wołający).
 */
export function usunPlikZWyboru(input, indeks, KlasaDataTransfer = globalThis.DataTransfer) {
    if (!moznaUsuwacZWyboru(KlasaDataTransfer)) {
        return false;
    }

    const przed = Array.from(input.files ?? []);

    if (!Number.isInteger(indeks) || indeks < 0 || indeks >= przed.length) {
        return false;
    }

    const zostaja = przed.filter((_, i) => i !== indeks);

    try {
        const lista = new KlasaDataTransfer();
        for (const plik of zostaja) {
            lista.items.add(plik);
        }
        input.files = lista.files;
    } catch {
        return false;
    }

    const po = Array.from(input.files ?? []);

    // Porównanie po cechach, nie po tożsamości obiektu: przeglądarka może
    // oddać w nowej liście inne obiekty `File` dla tych samych plików.
    const tenSam = (a, b) => a === b
        || (a.name === b.name && a.size === b.size && a.lastModified === b.lastModified);

    return po.length === zostaja.length && po.every((plik, i) => tenSam(plik, zostaja[i]));
}
