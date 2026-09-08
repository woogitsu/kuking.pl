Nawigacja boczna — sześć miejsc serwisu, widoczna od 1024 px.

```jsx
<SideNav biezaca="start" onPrzejdz={setEkran} />
```

- Sześć pozycji: Start, Szukaj, Dodaj, Zeszyt, Powiadomienia, Moje.
- `<nav aria-label="Nawigacja główna">` — strona ma kilka nawigacji i każda musi
  mieć własną nazwę.
- Lista jest `<ul>`, więc czytnik powie „lista, sześć pozycji”.
- Bieżąca pozycja ma `aria-current="page"` **i grubszy napis** — kolor nie jest
  jedynym nośnikiem.
- Każda pozycja ma 48 px wysokości i **podpis obok ikony**.
- **Nie zwijaj jej do samych ikon** na węższym ekranie: poniżej 1024 px znika
  cała i zastępuje ją `BottomNav`.
- Nie zmieniaj kolejności pozycji między podstronami.
