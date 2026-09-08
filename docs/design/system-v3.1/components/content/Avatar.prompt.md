Awatar — zdjęcie profilowe albo pierwsza litera imienia.

```jsx
<Avatar src="/zdjecia/halina.jpg" imie="Halina" />
<Avatar imie="Halina" rozmiar="sm" />
<Avatar src="/zdjecia/basia.jpg" alt="" rozmiar="sm" />
```

- Rozmiary: `sm` 40 px (wiersz, komentarz), `md` 48 px (karta), `lg` 80 px
  (główka profilu). **Nigdy poniżej 40 px.**
- Domyślne `alt` to „Zdjęcie profilowe: Imię”. Kiedy imię stoi obok w tekście,
  `alt=""` też jest w porządku. **Nigdy `alt="awatar"` i nigdy brak `alt`.**
- Wariant z literą ma `aria-hidden="true"` — pojedyncza litera przeczytana przez
  czytnik jest szumem.
- Nie wstawiaj sylwetki z ikony zamiast litery: wygląda jak zdjęcie, które się
  nie wczytało.
- Awatar nie ma stanów. Kiedy jest odnośnikiem, stany należą do odnośnika.
