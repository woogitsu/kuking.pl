/*
 * Kontrola unikalności ekranów `scripts/dostepnosc.mjs` (#611, etap 4).
 *
 * Po co. Raport „zbadane ekrany" i pliki zrzutów są kluczowane NAZWĄ ekranu,
 * a numer pliku bierze `indexOf`. Dwa wpisy o tej samej nazwie (tak było
 * z `/otworz-link`, wpisanym raz w `EKRANY` i drugi raz nad `...EKRANY`
 * w `EKRANY_UKLADU`) dają fałszywy obraz: jeden pomiar „zalicza" oba,
 * a licznik „zbadane 10/10" liczy ekran dwa razy. Zamiast po cichu ufać,
 * skrypt przy starcie wywraca się z nazwą duplikatu.
 */

/**
 * @param {Array<{nazwa: string}>} lista
 * @returns {string[]} nazwy występujące więcej niż raz (bez powtórzeń)
 */
export function duplikatyEkranow(lista) {
  const widziane = new Set();
  const powtorzone = new Set();

  for (const ekran of lista) {
    if (widziane.has(ekran.nazwa)) {
      powtorzone.add(ekran.nazwa);
    }
    widziane.add(ekran.nazwa);
  }

  return [...powtorzone];
}

/** Rzuca błędem po polsku, gdy lista ma powtórzoną nazwę ekranu. */
export function wymagajUnikalnychEkranow(lista, opis) {
  const duplikaty = duplikatyEkranow(lista);

  if (duplikaty.length > 0) {
    throw new Error(
      `BŁĄD: lista ${opis} ma powtórzone ekrany: ${duplikaty.map((n) => `„${n}"`).join(', ')}. `
      + 'Usuń duplikat — każdy ekran ma być zadeklarowany raz.',
    );
  }
}
