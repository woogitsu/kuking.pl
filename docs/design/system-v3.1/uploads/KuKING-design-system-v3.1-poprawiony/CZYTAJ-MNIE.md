> **Aktualizacja v3.1 · 7 września 2026.** Zmiany z audytu opisuje [AUDYT-V3.1.md](./AUDYT-V3.1.md). W sprawach motywu, stopki, pozycjonowania nagłówka i skali 200% ten dokument oraz `07-wdrozenie/MOTYW-V3.1.md` mają pierwszeństwo przed poniższym opisem v3.0. Paleta i znak pozostają bez zmian.

# System projektowy Kuking.pl — wersja 3

Odpowiedź na paczkę „kuking.pl — materiał do zrobienia systemu projektowego
i nowego szablonu” z 7 września 2026.

Nie są to ładne obrazki. To jest **zestaw decyzji, z których da się złożyć każdy
z piętnastu ekranów i szesnasty, którego jeszcze nie ma** — plus makiety, które
dowodzą, że te decyzje się składają, plus rachunek, ile ich wdrożenie kosztuje.

---

## Zacznij tutaj — pięć minut

**Najprościej: otwórz `index.html` w przeglądarce.** To jest spis całej paczki
z linkami do wszystkiego, co da się obejrzeć bez instalowania czegokolwiek.

1. **`00-decyzje/DECYZJE.md`** — dwanaście rozstrzygnięć (D-101…D-112), w tym
   cztery pytania, które paczka zostawiła otwarte.
2. **`00-decyzje/ODPOWIEDZI-NA-PROBLEMY.md`** — jedno zdanie odpowiedzi na każdy
   z sześciu problemów z komputera i pięciu z telefonu.
3. **`00-decyzje/CZEGO-NIE-ZMIENIAM.md`** — piętnaście rzeczy, które zostają, i
   dlaczego. Równie ważne jak reszta.
4. **`02-komponenty/galeria.html`** — otwórz w przeglądarce. Każdy komponent,
   każdy stan, oba motywy, na jednej stronie.
5. **`07-wdrozenie/WDROZENIE.md`** — ile to kosztuje, w godzinach, komponent po
   komponencie.

---

## Co jest w której teczce

| Teczka | Co tam jest |
|---|---|
| `00-decyzje/` | rozstrzygnięcia, odpowiedzi na jedenaście problemów, lista rzeczy nietykanych |
| `01-fundamenty/` | tokeny (`tokens.json`, `tokens.css`), skrypt liczący kontrasty i jego wynik, dokumenty: typografia, kolor, siatka, ruch i stany |
| `02-komponenty/` | specyfikacja 28 komponentów ze stanami (`KOMPONENTY.md`) + żywa galeria, 34 sekcje (`galeria.html`) + arkusz (`komponenty.css`) |
| `03-szablony/` | makiety trzech kształtów treści: strumień kart, długi dokument z panelem, długi formularz |
| `04-strona-www/` | publiczna twarz serwisu: strona powitalna, „O Kuking”, strona przepisu dla gościa z Google |
| `05-social-media/` | jak Kuking mówi poza własną stroną: strategia, gotowe wpisy, szablony graficzne, materiały drukowane |
| `06-marka/` | znak, zasady jego używania, trzy zapisy nazwy |
| `07-wdrozenie/` | rachunek kosztów, zmiany tokenów, lista kontrolna dostępności |
| `podglad/` | zbudowany arkusz do makiet, sprite ikon, zdjęcia, `urzadzenia.html` — makiety na telefonie i na komputerze obok siebie |
| `index.html` | spis całej paczki do otwarcia w przeglądarce |

---

## Jak to otworzyć

Makiety otwierają się **podwójnym kliknięciem**, bez instalowania czegokolwiek.
Nie potrzebują serwera ani Node'a.

Jeśli zmienisz `01-fundamenty/tokens.css` albo `02-komponenty/komponenty.css`,
przebuduj arkusz podglądu:

```bash
node podglad/zbuduj-podglad.mjs
```

Skrypt robi trzy rzeczy: usuwa `@import "tailwindcss"`, zamienia `@theme` na
`:root` i skleja oba arkusze. **Wartości nie są nigdzie przepisywane ręcznie**,
więc podgląd nie może rozjechać się z arkuszem produkcyjnym — a rozjechanie się
makiety z kodem jest w tym projekcie znaną przyczyną zmarnowanej pracy.

Pozostałe skrypty:

```bash
node 01-fundamenty/kontrast.mjs        # 70 par kontrastu; kod 1, jeśli któraś nie przechodzi
node 01-fundamenty/zbuduj-tokeny.mjs   # składa tokens.json z policzonymi kontrastami
```

---

## Co dostajesz, punkt po punkcie z zamówienia

| Zamówione | Gdzie |
|---|---|
| **A. Tokeny** w formacie z `05-szablon-wyniku/tokens.json`, każdy kolor w dwóch wariantach, przy każdym zmierzony kontrast | `01-fundamenty/tokens.json` — kontrasty **liczone przy każdym zbudowaniu**, nie przepisywane |
| **B. Skala typograficzna** z jawnym rozmiarem podstawowym | `01-fundamenty/TYPOGRAFIA.md` — 18 px zostaje, dziewięć stopni skali |
| **C. Komponenty w stanach**: spoczynek, najechanie, fokus, wciśnięty, wyłączony, błąd | `02-komponenty/KOMPONENTY.md` + `galeria.html` |
| **D. Dwa szablony strony** na trzech ekranach: tablica, przepis, formularz | `03-szablony/` — jeden responsywny plik na ekran, sprawdzony przy 320, 390 i 1440 px |
| **E. Odpowiedź na sześć problemów z komputera i pięć z telefonu** | `00-decyzje/ODPOWIEDZI-NA-PROBLEMY.md` |
| **F. Lista rzeczy, których świadomie nie zmieniam** | `00-decyzje/CZEGO-NIE-ZMIENIAM.md` |

Ponad zamówienie, bo właściciel poprosił o to osobno: styl strony publicznej
(`04-strona-www/`), treści na media społecznościowe i materiały drukowane
(`05-social-media/`), zasady znaku (`06-marka/`) i rachunek wdrożenia
(`07-wdrozenie/`).

---

## Trzy rzeczy, które trzeba wiedzieć, zanim się to oceni

**1. Ten projekt stoi na zdjęciach i mówię to wprost** (decyzja D-105), bo paczka
prosiła, żeby powiedzieć to wprost. Zdjęcie potrawy jest tu treścią, nie
ilustracją. Wynika z tego zadanie, które nie jest zadaniem projektowym:
**przed betą trzeba zasiać dane demonstracyjne prawdziwymi zdjęciami**. Dziś
każde „zdjęcie” w bazie demo to znak marki na beżowym tle, więc każda ocena
strumienia jest oceną czegoś innego niż to, co zobaczy człowiek.

**2. Wszystkie napisy w makietach pochodzą z `COPY_STYLE.md` i `BRAND_EXTENDED.md`,
nie z wyobraźni projektanta.** To był powód nr 1 porażki poprzedniej próby:
makieta pisała „Jak wyszło innym?”, gdy dokument mówił „Komu wyszło”, i „Szukaj
i odkrywaj”, gdy „odkrywaj” było na liście słów zakazanych. Za każdym razem
rozstrzygano na rzecz dokumentu.

**3. Nic w tej paczce nie wymaga JavaScriptu ani atrybutu `style=`.** To nie jest
ograniczenie, które obchodzę — to rama, która wymusiła lepsze rozwiązania. Trzy
strony kreatora są bardziej bezskryptowe niż jedna. Duży obszar „Dodaj zdjęcie”
zrobiony z `<label for>` jest lepszy od natywnego przycisku, i przy okazji
przestaje mówić po angielsku.

---

## Czego tu nie ma

- **Nowej architektury.** Serwis stoi na Laravelu, Blade i Tailwindzie 4
  w konfiguracji CSS-first i tak zostaje.
- **Zmiany modelu danych.** Ani jedna decyzja w tej paczce nie wymaga migracji.
- **Rankingów, punktów, poziomów, odznak, liczby obserwujących.** To są wzorce
  zakazane w tym produkcie wprost, a nie przeoczenie.
- **Odpowiedzi na sześć pytań, które nie są moje.** Są wypisane na końcu
  `00-decyzje/DECYZJE.md` i w `01-fundamenty/tokens.json` w polu
  `do_rozstrzygniecia`.
