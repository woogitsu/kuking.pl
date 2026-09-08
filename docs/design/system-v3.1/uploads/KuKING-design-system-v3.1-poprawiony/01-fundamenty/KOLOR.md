# Kolor

Charakter zostaje ten sam co dziś: **ciepła kuchnia, drewniany stół,
pomidorowa, poranne światło**. Terakota nawiązuje do pomidorów i przypraw, a nie
do aplikacji bankowej. To nie jest „beż dla starszych” — szarawy, bezpłciowy —
ani infantylny pastel.

Paleta jest w tej wersji **niezmieniona co do wartości**. Zmieniło się to, jak
się jej używa, i doszły trzy tokeny, które wcześniej trzeba było improwizować.

---

## 1. Jak liczymy kontrast

Nie szacujemy. `node 01-fundamenty/kontrast.mjs` liczy **70 par** (35 w każdym
motywie) formułą luminancji WCAG 2.x i kończy się kodem błędu, jeśli choć jedna
nie przechodzi. Pełna tabela: `01-fundamenty/kontrast-wynik.md`.

Stan na dziś: **70 par, wszystkie przechodzą.** Progi: 4.5:1 dla tekstu, 3:1 dla
tekstu dużego i elementów interfejsu.

Przy tej grupie odbiorców 4.5:1 to minimum, nie cel — dlatego większość par
w tabeli ma 6:1 i więcej. Trzy pary są blisko progu i to są jedyne miejsca,
w których zmiana odcienia wymaga ponownego przeliczenia **obu** par naraz:

| Para | Kontrast | Dlaczego blisko |
|---|---|---|
| biały / `brand-solid` (ciemny) | 4.72:1 | dalsze przyciemnianie zbliżyłoby przycisk do tła strony i zepsuło próg 3:1 z otoczeniem |
| `danger-solid` / `surface` (ciemny) | 3.14:1 | to samo w drugą stronę |
| `border-strong` / `surface-sunken` (jasny) | 3.51:1 | obwódka pola musi być widoczna, ale nie może wyglądać na ramkę błędu |

---

## 2. Role, nie nazwy kolorów

Token nazywa **rolę**, nie barwę. Dlatego tryb ciemny nie jest odwróceniem
jasnego, tylko osobną paletą o tej samej strukturze ról — i dlatego zmiana
motywu nigdy nie wymaga dotknięcia ani jednej reguły komponentu.

| Rola | Token | Do czego |
|---|---|---|
| tło strony | `--color-surface` | wszystko, co nie jest kartą |
| tło uniesione | `--color-surface-raised` | karta, belka, stopka, moduł szyny |
| tło wgłębione | `--color-surface-sunken` | pole formularza, plakietka spokojna, dane przepisu |
| tło marki | `--color-surface-brand-wash` | **nowy** — hero, cytat „Skąd ten przepis”, wpis bez zdjęcia |
| linia | `--color-border` | podział dekoracyjny; nie niesie informacji, więc nie ma progu kontrastu |
| obwódka | `--color-border-strong` | obwódka pola, przycisku wtórnego, chipa — element interfejsu, próg 3:1 |
| atrament | `--color-ink` | tekst podstawowy |
| atrament cichy | `--color-ink-muted` | metadane, pomoc, plakietka cicha |
| marka | `--color-brand` | link w tekście, znak, akcent |
| marka pełna | `--color-brand-solid` | **tło** przycisku głównego |
| akcent | `--color-accent*` | wyłącznie „Ugotowałem” |
| błąd / sukces | `--color-danger*`, `--color-success*` | stany |
| fokus | `--color-focus` | pierścień klawiatury |
| podkład | `--scrim-*` | **nowy** — pod tekstem na zdjęciu |

### Dlaczego `brand` i `brand-solid` to dwa różne tokeny

W trybie jasnym mają tę samą wartość, więc wyglądają na duplikat. W ciemnym
rozjeżdżają się i **muszą**: `--color-brand` jest jasną terakotą, żeby działać
jako tekst na ciemnym tle; ten sam jasny odcień jako tło przycisku z białym
napisem daje 2.3:1. Ta sama historia dotyczy `--color-danger` i
`--color-danger-solid` — brak tego rozdziału sprawiał, że przycisk „Usuń” był
w trybie ciemnym praktycznie nieczytelny.

Wniosek ogólny: **kolor tekstu i kolor tła to nigdy nie jest ten sam token**,
nawet gdy w jednym motywie mają tę samą wartość.

---

## 3. Jeden akcent na powierzchnię (D-110)

> Na jednej karcie dokładnie jedna rzecz ma kolor marki.
> Na jednym ekranie dokładnie jedna akcja jest przyciskiem głównym.

Kolor marki wolno użyć na: przycisk główny, bieżącą pozycję nawigacji, kółko
„Dodaj”, link w tekście ciągłym, znak marki. **Nigdzie indziej.**

Z tej reguły wynikają dwie zmiany widoczne gołym okiem:

- plakietka „konto przykładowe” traci tło i schodzi do wagi cichej (D-103) —
  powtórzona piętnaście razy pod rząd przestawała cokolwiek znaczyć, a zjadała
  cały budżet uwagi, którym miały dysponować zdjęcia;
- w stopce karty tylko jedna akcja ma wagę; „Napisz komentarz” i „Zapisz”
  przestają wyglądać identycznie.

---

## 4. Fokus i technika „halo”

Pierścień fokusu jest **niebieski, celowo spoza palety** — ma się wyróżniać
zawsze, także na terakocie i na czerwieni.

Problem: niebieski na terakocie daje 1.0–1.2:1. To nie jest zły dobór koloru,
to fizyka — niebieski i czerwono-pomarańczowy mają zbliżoną jasność.

Rozwiązanie, takie samo jak w GOV.UK Design System i U.S. Web Design System:
**2 px halo w kolorze tła** między przyciskiem a pierścieniem. Pierścień nigdy
nie styka się z kolorem przycisku — styka się z tłem, wobec którego jego kontrast
jest policzony i wynosi 5.03:1 (jasny) i 7.17:1 (ciemny).

```css
.btn:focus-visible {
  outline: none;
  box-shadow:
    0 0 0 2px var(--color-surface),  /* halo */
    0 0 0 5px var(--color-focus);    /* pierścień, 3px */
}
```

Halo musi być w kolorze tego, co jest **pod** przyciskiem. Przycisk na karcie ma
własną regułę z `--color-surface-raised` — bez niej widać obwódkę w kolorze tła
strony na białej karcie.

---

## 5. Tryb ciemny ma jedno wejście

Motyw bierze się **wyłącznie z jawnego wyboru człowieka**: atrybut
`data-theme="dark"` na `<html>`, ustawiany z konta albo z przełącznika w stopce.
Reguła `@media (prefers-color-scheme: dark)` została z arkusza usunięta i **nie
wraca** — włączała ciemny motyw sama, gdy telefon miał własny harmonogram „tryb
nocny”, a część osób z tej grupy nie kojarzy, że to własne urządzenie zmieniło
wygląd strony. Wygląda to wtedy na awarię serwisu.

Z tego samego powodu `color-scheme` jest ustawiony jawnie na `light`, a nie
`light dark` — inaczej pola formularza i paski przewijania brałyby się z systemu
i motyw wracałby tylnymi drzwiami.

Nowość w tej wersji: klasa **`.blok-ciemny`**, która daje ten sam zestaw wartości
dowolnemu kontenerowi. Dwa zastosowania: galeria pokazuje oba motywy obok siebie
na jednej stronie bez JavaScriptu, a strona powitalna może mieć ciemny pas bez
ani jednej wartości hex napisanej z ręki.

---

## 6. Kolor nigdy nie jest jedynym nośnikiem informacji

WCAG 1.4.1, ale u nas z mocniejszym powodem: rozróżnianie barw słabnie z wiekiem,
a przy zaćmie i po jej operacji — wyraźnie.

- **link** ma podkreślenie, nie tylko kolor;
- **błąd** ma tekst i ikonę, nie tylko czerwoną ramkę;
- **sukces** ma słowo „Zapisano.”, nie tylko zielone tło;
- **bieżąca pozycja nawigacji** ma `aria-current` i grubszą wagę, nie tylko tło;
- **„Obserwujesz”** zmienia napis, nie tylko kolor.

---

## 7. Czego w tej palecie nie ma i dlaczego

- **Fioletu, granatu, chłodnego szarego.** Nie pasują do stołu i psują zdjęcia
  jedzenia, które są w tym serwisie treścią.
- **Drugiego koloru marki.** Jeden akcent wystarcza, a dwa zaczynają wymagać
  reguły, kiedy który — czyli kolejnej rzeczy do zapamiętania.
- **Gradientów jako tła.** Jedyny gradient w systemie to podkład pod tekstem na
  zdjęciu, i on ma funkcję, nie ozdobę.
- **Przezroczystości jako sposobu na jaśniejszy odcień.** `opacity` na tekście
  zmienia kontrast w sposób, którego skrypt nie policzy. Jaśniejszy odcień to
  osobny token z policzoną wartością.
