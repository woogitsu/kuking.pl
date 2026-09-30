/* Podział pomiaru panelu marki (#581) na części joba `port_panelu` (#2299).
 *
 * Do 30.09.2026 cały pomiar szedł w jednym jobie przez 17,5–20 min i był
 * ścieżką krytyczną CI. Części macierzy dzielą między siebie SZEROKOŚCI
 * (obie fazy, pusta i pełna, w każdej części) oraz DODATKI (menu, zoom,
 * rozwijane szczegóły, walidacja). Żadna kontrola nie znika: suma części
 * to dokładnie pełny zestaw, bez powtórzeń — pilnuje tego
 * `scripts/panel-czesci.test.mjs`, a zgodność z macierzą w `ci.yml`
 * `PanelMarkiDzieliSieBezUtratyPomiaruTest`.
 *
 * Bez `PANEL_CZESC` (uruchomienie lokalne) skrypt robi wszystko, jak dawniej.
 */
export const SZEROKOSCI_PANELU = [320, 360, 390, 414, 768, 1440];
export const DODATKI_PANELU = ['menu', 'zoom-menu', 'details', 'zoom-details', 'walidacja'];

export const CZESCI_PANELU = {
  1: { szerokosci: [320, 360, 390], dodatki: ['menu', 'zoom-menu', 'zoom-details'] },
  2: { szerokosci: [414, 768, 1440], dodatki: ['details', 'walidacja'] },
};

export function wybierzCzescPanelu(value) {
  if (value === undefined || value === '') {
    return { czesc: null, szerokosci: [...SZEROKOSCI_PANELU], dodatki: [...DODATKI_PANELU] };
  }
  if (!Object.hasOwn(CZESCI_PANELU, value)) throw new Error(`P581_CZESC: nieznana część panelu ${JSON.stringify(value)}.`);
  const { szerokosci, dodatki } = CZESCI_PANELU[value];
  return { czesc: Number(value), szerokosci: [...szerokosci], dodatki: [...dodatki] };
}
