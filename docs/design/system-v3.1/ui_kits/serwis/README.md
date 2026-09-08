# UI kit — serwis Kuking po zalogowaniu

Odtworzenie ekranów zalogowanego z paczki `KuKING-design-system-v3.1-poprawiony`
(`03-szablony/` plus zrzuty z `08-audyt/podglady/`). Nic tu nie jest nowym
projektem — to te same układy, złożone z komponentów tego systemu.

## Pliki

| Plik | Co to jest |
|---|---|
| `index.html` | klikalna makieta: nawigacja przełącza ekrany, stopka przełącza motyw |
| `dane.jsx` | dane demonstracyjne (wpisy, przepis, powiadomienia, zeszyt) |
| `Tablica.jsx` | ekran 08 — strumień kart, kompozytor, chipy zakresu, szyna |
| `EkranPrzepisu.jsx` | ekran 07 — zdjęcie 3:2, „Skąd ten przepis”, kroki, „Komu wyszło”, rozmowa, panel w szynie |
| `DodajPrzepis.jsx` | ekran 13 — kreator trzykrokowy, stan błędu, autozapis |
| `Powiadomienia.jsx` | ekran 11 (wiersze) i ekran 10 (zeszyt, karty zwarte) |

## Co ta makieta pokazuje, a czego nie

- **Pokazuje**: trzy kolumny w jednej szerokości (belka, siatka, stopka),
  trzecią kolumnę istniejącą także wtedy, gdy nie ma czym jej wypełnić (D-102),
  panel przepisu przyklejony obok kroków, dwie kopie składników z widoczną
  dokładnie jedną, kreator w trzech krokach, stan błędu z podsumowaniem na górze.
- **Nie pokazuje**: trybu gotowania na cały ekran, karuzeli zdjęć, panelu
  moderatora i ekranów ustawień — w paczce źródłowej nie ma ich makiet, więc
  nie ma czego odtwarzać.

## Dwie rzeczy do rozstrzygnięcia

1. **Zdjęcia.** W paczce są dwa zdjęcia w użytecznym rozmiarze (`pierogi.png`
   520×312 i `pizza.png` 690×316). `soup`, `cake` i `pasta` mają po 92 px
   szerokości i w karcie zwartej są rozmyte — stoją tu jako zaślepki, do
   podmiany na prawdziwe zdjęcia przed betą (D-105 punkt 2).
2. **Pięć pozycji dolnego paska.** `KOMPONENTY.md` §19 podaje Start · Szukaj ·
   Dodaj · Zeszyt · Moje, a `CZEGO-NIE-ZMIENIAM.md` §7 — Start · Szukaj ·
   Dodaj · Moje · Profil. Makieta trzyma się pierwszej wersji.
