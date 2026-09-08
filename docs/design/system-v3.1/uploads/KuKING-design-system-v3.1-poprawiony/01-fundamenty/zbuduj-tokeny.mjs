#!/usr/bin/env node
/*
 * zbuduj-tokeny.mjs — składa `01-fundamenty/tokens.json` w formacie z paczki
 * właściciela (`05-szablon-wyniku/tokens.json`).
 *
 * Kontrasty NIE są tu przepisane ręcznie: skrypt importuje palety i funkcję
 * z `kontrast.mjs` i liczy je przy każdym uruchomieniu. Dzięki temu plik JSON
 * nie może się rozjechać z arkuszem CSS ani z tabelą w KOLOR.md — a rozjechanie
 * się dokumentu z kodem jest w tym projekcie znaną przyczyną zmarnowanej pracy.
 *
 *   node 01-fundamenty/zbuduj-tokeny.mjs
 */
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { JASNY, CIEMNY, kontrast } from './kontrast.mjs';

const katalog = dirname(fileURLToPath(import.meta.url));
const zaokr = (n) => `${n.toFixed(2)}:1`;

/* Dla każdego koloru: na jakim tle się go mierzy i po co on jest.
   `tlo` to nazwa innego tokenu — mierzymy parę, która NAPRAWDĘ występuje
   w interfejsie, a nie parę wygodną do pochwalenia się liczbą. */
const OPISY = {
  surface:            { tlo: 'ink',            gdzie: 'tło strony' },
  'surface-raised':   { tlo: 'ink',            gdzie: 'tło karty, belki górnej, stopki, modułu szyny' },
  'surface-sunken':   { tlo: 'ink',            gdzie: 'tło pola formularza, plakietki spokojnej, danych przepisu' },
  'surface-brand-wash': { tlo: 'ink',          gdzie: 'sekcja marki: hero strony powitalnej, cytat „Skąd ten przepis”, karta wpisu bez zdjęcia' },
  border:             { tlo: 'surface-raised', prog: 0, gdzie: 'cienka linia podziału — dekoracyjna, nie niesie informacji, więc nie ma progu' },
  'border-strong':    { tlo: 'surface',        prog: 3, gdzie: 'obwódka pola, przycisku wtórnego i chipa' },
  ink:                { tlo: 'surface',        gdzie: 'tekst podstawowy, tytuły' },
  'ink-muted':        { tlo: 'surface',        gdzie: 'metadane, pomoc pod polem, plakietka cicha, znaczniki czasu' },
  'ink-inverse':      { tlo: 'brand-solid',    gdzie: 'napis na przycisku głównym i destrukcyjnym' },
  brand:              { tlo: 'surface',        gdzie: 'link w tekście ciągłym, znak marki, akcent nagłówka' },
  'brand-dark':       { tlo: 'surface',        gdzie: 'najechanie na link; w trybie ciemnym też obrys' },
  'brand-tint':       { tlo: 'brand-tint-ink', gdzie: 'tło bieżącej pozycji nawigacji, wybranej karty „Kto to widzi”, wiersza nieprzeczytanego' },
  'brand-tint-ink':   { tlo: 'brand-tint',     gdzie: 'tekst na brand-tint' },
  'brand-solid':      { tlo: 'ink-inverse',    gdzie: 'tło przycisku głównego, kółko „Dodaj”, wypełniona kropka kroku' },
  'brand-solid-hover':{ tlo: 'ink-inverse',    gdzie: 'najechanie i wciśnięcie przycisku głównego' },
  accent:             { tlo: 'surface',        gdzie: 'tekst akcentu przy „Ugotowałem”' },
  'accent-tint':      { tlo: 'accent-tint-ink',gdzie: 'tło plakietki „Ugotowałem”' },
  'accent-tint-ink':  { tlo: 'accent-tint',    gdzie: 'tekst na plakietce „Ugotowałem”' },
  danger:             { tlo: 'surface',        gdzie: 'tekst błędu, ramka pola z błędem' },
  'danger-tint':      { tlo: 'danger-tint-ink',gdzie: 'tło podsumowania błędów i komunikatu błędu' },
  'danger-tint-ink':  { tlo: 'danger-tint',    gdzie: 'tekst na tle błędu' },
  'danger-solid':     { tlo: 'ink-inverse',    gdzie: 'tło przycisku „Usuń” — OSOBNY token, nie danger' },
  'danger-solid-hover':{ tlo: 'ink-inverse',   gdzie: 'najechanie na przycisk „Usuń”' },
  success:            { tlo: 'surface',        gdzie: 'tekst potwierdzenia' },
  'success-tint':     { tlo: 'success-tint-ink', gdzie: 'tło alertu sukcesu i plakietki „Szkic zapisany”' },
  'success-tint-ink': { tlo: 'success-tint',   gdzie: 'tekst na tle sukcesu' },
  focus:              { tlo: 'surface',        prog: 3, gdzie: 'pierścień fokusu klawiatury — kolor spoza palety, celowo' },
  'scrim-ink':        { tlo: 'ink-inverse',    gdzie: 'podkład pod białym tekstem na zdjęciu' },
};

const kolor = {};
for (const [nazwa, meta] of Object.entries(OPISY)) {
  const prog = meta.prog === undefined ? 4.5 : meta.prog;
  kolor[nazwa] = {
    jasny: JASNY[nazwa],
    ciemny: CIEMNY[nazwa],
    kontrast_na_tle:
      prog === 0
        ? 'nie dotyczy — element dekoracyjny'
        : `jasny ${zaokr(kontrast(JASNY[nazwa], JASNY[meta.tlo]))} / ciemny ${zaokr(kontrast(CIEMNY[nazwa], CIEMNY[meta.tlo]))} — wobec --color-${meta.tlo}, próg ${prog.toFixed(1)}:1`,
    gdzie_uzywany: meta.gdzie,
  };
}

const wynik = {
  wersja: '3.1',
  data: '2026-09-07',
  uwagi:
    'Nazwy tokenów są zgodne z 03-kod-wygladu/tokens.css — żaden istniejący token nie zmienił nazwy, więc kilkaset użyć var(--…) w arkuszach serwisu działa dalej. Tokeny NOWE są wypisane w polu „nowe_w_tej_wersji”. Kontrasty w tym pliku są LICZONE przy każdym zbudowaniu (node 01-fundamenty/zbuduj-tokeny.mjs), nie przepisywane ręcznie.',
  zrodlo_kontrastow: '01-fundamenty/kontrast.mjs — formuła luminancji WCAG 2.x, (L1+0.05)/(L2+0.05). Pełny wynik: 01-fundamenty/kontrast-wynik.md, 70 par, wszystkie przechodzą.',

  nowe_w_tej_wersji: [
    '--color-surface-brand-wash — tło sekcji marki; wcześniej takie miejsca brały surface-sunken, czyli tło pola formularza',
    '--scrim-ink i --scrim-gradient — podkład pod tekstem na zdjęciu; reguła „nigdy” nr 7 nie miała dotąd tokenu',
    '--text-meta (15px) — jedyny rozmiar poniżej 16px, wyłącznie dla plakietki cichej; warunki w TYPOGRAFIA.md §4',
    '--text-title-xl (48px) — wyłącznie hero strony powitalnej',
    '--leading-title-wiele (1.3) — tytuł, który się zawija; decyzja D-101',
    '--container-czytanie (38rem) — długi dokument prawny',
    '--container-strona-szeroka — jedna szerokość dla wszystkich ekranów zalogowanego, także bez szyny; decyzja D-102',
    '--shadow-card-hover — karta pod kursorem; wcześniej nie miała stanu najechania',
    '--spacing-24 — rytm strony powitalnej',
    '--czas-szybki, --czas-zwykly — czasy przejść były dotąd wpisywane w regułach',
  ],

  kolor,

  typografia: {
    rozmiar_podstawowy_px: 18,
    uwaga:
      '18 px, nie 16 — decyzja dla odbiorcy 50+. Zmiana na 16 wymaga uzasadnienia. Wszystkie rozmiary są zapisane jako calc(<baza> * var(--user-text-scale)), więc ustawienie „Powiększ tekst” z konta skaluje WYŁĄCZNIE tekst: nie odstępy, nie promienie, nie wysokości kontrolek.',
    font: '"Inter Variable" jako docelowy font lokalny; paczka nie zawiera pliku fontu. Podgląd i audyt używają stosu systemowego. Po wdrożeniu fontu powtórzyć testy układu.',
    skala: [
      { token: '--text-meta',     px: 15, gdzie: 'tylko plakietka cicha; metadane karty od v3.1 mają 16 px', leading: 1.4 },
      { token: '--text-help',     px: 16, gdzie: 'pomoc pod polem, podpis w dolnym pasku, stopka', leading: 1.5 },
      { token: '--text-body',     px: 18, gdzie: 'tekst podstawowy, etykieta pola, napis na przycisku — minimum produktowe', leading: 1.55 },
      { token: '--text-body-lg',  px: 20, gdzie: 'treść wpisu, opis przepisu, tytuł modułu szyny', leading: 1.55 },
      { token: '--text-lead',     px: 22, gdzie: 'zajawka, pierwsze zdanie przepisu, wpis bez zdjęcia', leading: 1.55 },
      { token: '--text-title-sm', px: 24, gdzie: 'tytuł karty wpisu, tytuł sekcji, wordmark w belce', leading: 1.3 },
      { token: '--text-title',    px: 28, gdzie: 'tytuł strony, tytuł przepisu na telefonie', leading: 1.25 },
      { token: '--text-title-lg', px: 36, gdzie: 'tytuł przepisu na komputerze; clamp(28px, 4vw+1rem, 36px)', leading: 1.25 },
      { token: '--text-title-xl', px: 48, gdzie: 'wyłącznie hero strony powitalnej; clamp(28px, 6vw+0.5rem, 48px)', leading: 1.1 },
    ],
    skala_uzytkownika: {
      token: 'data-text-scale na <html>',
      kroki: { '90': 0.9, '100': 1, '112': 1.12, '125': 1.25, '140': 1.4, '150': 1.5, '200': 2 },
      uwaga: 'V3.1: tokeny obsługują także 200%. Testy 100/150/200% opisuje AUDYT-V3.1.md. Zakres ustawienia konta i walidacja po stronie serwera wymagają osobnego wdrożenia.',
    },
  },

  odstepy: {
    baza_px: 4,
    uwaga: 'NIE skalowane ustawieniem tekstu — celowo. Gdyby skalowały się razem z tekstem, przy 150% przyciski przestałyby się mieścić w wierszu.',
    skala: {
      '--spacing-1': '4px', '--spacing-2': '8px', '--spacing-3': '12px', '--spacing-4': '16px',
      '--spacing-5': '20px', '--spacing-6': '24px', '--spacing-8': '32px', '--spacing-10': '40px',
      '--spacing-12': '48px', '--spacing-16': '64px', '--spacing-20': '80px', '--spacing-24': '96px',
    },
    reguly: [
      'Odstęp między akcją zwykłą a destrukcyjną: minimum --spacing-8 (32px) albo osobna sekcja z linią i nagłówkiem.',
      'Odstęp między kartami w strumieniu: --spacing-6 (24px).',
      'Wcięcie treści karty: --spacing-5 (20px); zdjęcie idzie na pełną szerokość, bez wcięcia.',
    ],
  },

  promienie: {
    '--radius-sm': '8px — plakietki, chipy prostokątne, kotwica fokusu',
    '--radius-md': '12px — przyciski, pola formularza, komunikaty',
    '--radius-lg': '16px — karta ogólna',
    '--radius-xl': '24px — karta wpisu, moduł szyny, pusty stan, obszar wyboru zdjęcia',
    '--radius-pill': '999px — awatar, chip zakresu, kółko „Dodaj”',
    uwaga: 'Karta wpisu ma większy promień niż karta ogólna, bo jest największym prostokątem na ekranie i przy 16px wygląda na kanciastą. To jedyne odstępstwo od zasady „większy element, ten sam promień”.',
  },

  cienie: {
    '--shadow-card': '0 1px 2px rgba(43,36,29,.06), 0 6px 16px rgba(43,36,29,.08)',
    '--shadow-card-hover': '0 2px 4px rgba(43,36,29,.08), 0 10px 24px rgba(43,36,29,.12)',
    '--shadow-popover': '0 8px 24px rgba(43,36,29,.16)',
    uwaga: 'Cień jest barwiony w stronę --color-ink, nie czarny — czarny cień na ciepłym beżu wygląda na brudny. W trybie ciemnym cień prawie nie działa, więc karty oddziela tam obwódka --color-border i różnica jasności surface/surface-raised; cienie mają wtedy własne, mocniejsze wartości.',
  },

  kontrolki: {
    '--control-height-min': '48px — przycisk, pole, pozycja nawigacji bocznej',
    '--control-height-touch': '60px — pozycja dolnego paska',
    '--hit-area-min': '44px — obszar klikalny pola zaznaczenia',
    uwaga: 'Zawsze min-height, nigdy height. Przy skali 140% napis zawija się na dwa wiersze i przycisk ma po prostu urosnąć, a nie uciąć tekst. WCAG 2.2 wymaga 24 px; Kuking stosuje wygodniejszą regułę produktową 48 px.',
  },

  kontenery: {
    '--container-content': '720px — kolumna treści, ~65–75 znaków przy 18–20 px',
    '--container-czytanie': '608px — długi dokument bez zdjęć (polityka, regulamin)',
    '--container-sidenav': '240px',
    '--container-rail': '352px',
    '--container-strona': '1040px — dwie kolumny',
    '--container-strona-szeroka': '1424px — trzy kolumny, od 80rem, ZAWSZE (D-102)',
    '--container-strona-solo': '768px — gość, bez nawigacji i bez szyny',
  },

  rozstrzygniete_w_tej_wersji: [
    'D-101 — --leading-title zostaje 1.25; tytuł, który się zawija, dostaje nowy --leading-title-wiele 1.3.',
    'D-102 — sufit kolumny treści zostaje 45rem; trzecia kolumna istnieje zawsze od 80rem, także pusta.',
    'D-103 — plakietka „konto przykładowe” schodzi do wagi cichej; plakietka głośna wolno raz na ekran.',
    'D-104 — karta wpisu dostaje wariant zwarty; dotyka czterech ekranów: profil, tag, szukaj, zeszyt.',
  ],

  do_rozstrzygniecia: [
    'Czy webfont Atkinson Hyperlegible wart jest testu z ludźmi po becie — DESIGN_SYSTEM.md §2.2. Do czasu decyzji zostaje „Inter Variable”.',
    'Czy w Zeszycie obowiązuje „półka”, czy „rozdział” — BRAND_EXTENDED.md §1.1 oznacza to jako [do weryfikacji].',
    'Czy nazwa „kuKINGi na dziś” przechodzi test na ludziach, czy wchodzi alternatywa „Dziś u kuKINGów” — COPY_STYLE.md §5.',
    'Ile kart „Ugotowałem” pokazujemy na ekranie przepisu przed „Pokaż więcej” — projekt zakłada 5.',
    'Uzgodnić limit skali tekstu w bazie z nowym zakresem tokenów do 200%; sam CSS nie zmienia kont użytkowników.',
    'Kto jest gospodarzem podpisanym pod e-mailami — DECISIONS.md D-012.',
  ],
};

writeFileSync(join(katalog, 'tokens.json'), JSON.stringify(wynik, null, 2) + '\n');
console.log(`Zapisano tokens.json — ${Object.keys(kolor).length} kolorów, ${wynik.typografia.skala.length} stopni skali.`);
