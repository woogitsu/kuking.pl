Wiersz powiadomienia w liście wierszy.

```jsx
<RowList>
  <NotificationRow
    imie="Halina"
    avatarSrc="/zdjecia/halina.jpg"
    tresc="Halina ugotowała Twój rosół."
    czas="2 godziny temu"
    nieprzeczytane
  />
</RowList>
```

- Nieprzeczytany poznaje się po tle **i po słowie „nowe”** — kolor nigdy nie
  jest jedynym nośnikiem.
- Powiadomienie jest **pełnym zdaniem z imieniem i rzeczą**, nie „Nowa aktywność”.
- Czas jest tekstem względnym; pełną datę wolno dołożyć w `<time datetime>`.
- **Nie dawaj powiadomieniom kształtu karty** — to krótkie, jednorodne wiersze.
- **Nie wkładaj do wiersza dwóch akcji.** Cały wiersz prowadzi w jedno miejsce.
