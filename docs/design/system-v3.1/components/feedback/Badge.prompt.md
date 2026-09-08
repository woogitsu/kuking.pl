Plakietka — jedno słowo o stanie albo pochodzeniu elementu.

```jsx
<Badge waga="cichy">konto przykładowe</Badge>
<Badge waga="spokojny">Szkic</Badge>
<Badge waga="cooked" ikona="garnek">Ugotowano 12 razy</Badge>
```

- **Głośną plakietkę wolno użyć raz na ekran** (D-103). Jeśli coś powtarza się
  w każdym elemencie listy, to z definicji jest ciche: „konto przykładowe”
  powtórzone piętnaście razy w kolorze marki było najgłośniejszym elementem
  strony.
- Plakietka **nie ma stanów**: nie da się jej nacisnąć ani sfokusować. Jeśli
  musi być klikalna — to jest `Chip`, nie plakietka.
- Nie stawiaj dwóch głośnych plakietek obok siebie. Druga kasuje pierwszą.
- Treść musi być zrozumiała bez koloru.
- `waga="cichy"` używa 15 px — jedynego rozmiaru poniżej 16 px w systemie,
  dopuszczonego wyłącznie tutaj.
- Nie dokładaj `role="status"`: to nie jest komunikat, który się pojawia.
