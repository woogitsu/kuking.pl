/* Grupy pomiaru portu marki (`scripts/port-projektu.mjs`).
 *
 * `GRUPY` to grupy „atomowe": każda jest jednym `wykonajGrupe(...)` w skrypcie
 * i jednym elementem CI. `rozszerzenia` to alias na obie części rozszerzeń
 * (#611, etap 9: `port_funkcje` szedł 25 minut w jednym kawałku, więc CI
 * uruchamia część 1 i część 2 równolegle w macierzy). Alias zostaje dla
 * uruchomień lokalnych i dla starych poleceń: `PORT_GRUPA=rozszerzenia`
 * robi dokładnie to samo, co obie części razem.
 */
export const GRUPY = ['baza', 'rozszerzenia-1', 'rozszerzenia-2'];
export const ALIASY = { rozszerzenia: ['rozszerzenia-1', 'rozszerzenia-2'] };

export function wybierzGrupe(value = 'wszystko') {
  if (value !== 'wszystko' && !GRUPY.includes(value) && !(value in ALIASY)) {
    throw new Error(`Nieznana PORT_GRUPA: ${value}`);
  }
  return value;
}

export async function wykonajGrupe(wybor, nazwa, pomiar) {
  wybierzGrupe(wybor);
  /* Literówka w nazwie grupy w skrypcie nie może po cichu wyłączyć pomiaru. */
  if (!GRUPY.includes(nazwa)) throw new Error(`Nieznana grupa pomiaru: ${nazwa}`);
  if (wybor !== 'wszystko' && wybor !== nazwa && !(ALIASY[wybor] ?? []).includes(nazwa)) return;
  const start = performance.now();
  try {
    await pomiar();
  } finally {
    console.log(`PORT_CZAS grupa=${nazwa} sekundy=${((performance.now() - start) / 1000).toFixed(2)}`);
  }
}
