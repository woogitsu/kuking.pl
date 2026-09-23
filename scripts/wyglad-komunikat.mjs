/*
 * Składanie komunikatu porażki pomiarów szybkiego wyglądu.
 *
 * DLACZEGO OSOBNY PLIK
 * Z tego samego powodu, dla którego osobnym plikiem jest
 * `scripts/panel-komunikat.mjs`: `szybki-wyglad.mjs` potrzebuje przeglądarki
 * i serwera, więc składania komunikatu nie da się w nim zapytać testem.
 * A to jest ta część, przy której pomyłka kosztuje najwięcej — albo wyda
 * treść strony, albo nie powie nic. Nietestowane składanie komunikatu
 * zjadało w tym projekcie przyczynę przez tydzień.
 *
 * CO TU JEST PILNOWANE
 *
 * 1. KOMUNIKAT MA DOJŚĆ W CAŁOŚCI. `komunikatBledu` z `panel-komunikat.mjs`
 *    przepuszcza bez skrótu tylko wiadomości pasujące do `^P581_[A-Z_]+(?::|$)`
 *    — wszystko inne zwija do jednego zdania o „bezpiecznym raporcie
 *    miernika". Kody bez tego przedrostka (`WYGLAD_ZASLANIA_BLAD: {…}`) były
 *    więc zwijane RAZEM z liczbami, choć wyglądały na własne. Stąd wymóg
 *    przedrostka egzekwowany tutaj, a nie „pamiętany" w miejscu wywołania.
 *
 * 2. DO DIAGNOSTYKI NIE WCHODZI TREŚĆ STRONY. Liczby — `scrollY`, wysokość
 *    dokumentu, prostokąty — nie są treścią strony i wolno im iść w całości.
 *    Napis w diagnostyce jest natomiast podejrzany ZAWSZE: `textContent`
 *    komunikatu walidacji albo `outerHTML` pola niosą to, co człowiek wpisał,
 *    a w tych pomiarach bywa to hasło z fixture. Dlatego każdy liść musi być
 *    liczbą, wartością logiczną albo `null`, a napis kończy się wyjątkiem
 *    ZANIM cokolwiek pójdzie na wyjście. Sam wyjątek podaje ŚCIEŻKĘ liścia,
 *    nigdy jego wartość.
 */

const KOD = /^P581_[A-Z_]+$/;

/** Rzuca, jeśli w drzewie diagnostyki siedzi cokolwiek poza liczbą, wartością logiczną i `null`. */
export function sprawdzLiscie(dane, sciezka = 'dane') {
  if (dane === null || typeof dane === 'number' || typeof dane === 'boolean') {
    if (typeof dane === 'number' && !Number.isFinite(dane)) {
      throw new Error(`P581_DIAGNOSTYKA_NIE_LICZBOWA: ${sciezka} nie jest skończoną liczbą`);
    }

    return;
  }

  if (Array.isArray(dane)) {
    dane.forEach((element, i) => sprawdzLiscie(element, `${sciezka}[${i}]`));

    return;
  }

  /* Tylko zwykły obiekt. Instancja klasy mogłaby mieć `toJSON`, które
     dorzuciłoby do wyjścia pole nieobejrzane przez ten strażnik. */
  if (typeof dane === 'object' && Object.getPrototypeOf(dane) === Object.prototype) {
    Object.entries(dane).forEach(([klucz, wartosc]) => sprawdzLiscie(wartosc, `${sciezka}.${klucz}`));

    return;
  }

  throw new Error(`P581_DIAGNOSTYKA_NIE_LICZBOWA: do diagnostyki wszedł nie-liczbowy liść w ${sciezka} (typ ${typeof dane})`);
}

/** `P581_KOD: {…liczby…}` — gotowe do `assert` i do `komunikatBledu`. */
export function komunikatPomiaru(kod, dane) {
  if (!KOD.test(kod)) {
    throw new Error(`P581_ZLY_KOD_POMIARU: ${JSON.stringify(String(kod).slice(0, 40))} nie pasuje do ^P581_[A-Z_]+$`);
  }

  sprawdzLiscie(dane);

  return `${kod}: ${JSON.stringify(dane)}`;
}
