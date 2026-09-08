Tabela w długim dokumencie prawnym.

```jsx
<DataTable
  wstep="Co zbieramy, po co i jak długo to trzymamy."
  naglowki={["Dane", "Po co", "Jak długo"]}
  wiersze={[
    ["Adres e-mail", "Logowanie i odzyskanie hasła", "Do usunięcia konta"],
    ["Adres IP", "Zabezpieczenie przed nadużyciami", "90 dni"],
  ]}
/>
```

- `<th scope="col">` w `<thead>` — bez tego czytnik nie powie, do której kolumny
  należy komórka.
- Tabela przewija się **we własnym pudełku**, nigdy razem ze stroną. To drugi
  (po karuzeli zdjęć) świadomy wyjątek od zakazu przewijania w poziomie.
- Nad tabelą stoi zdanie mówiące, co w niej jest.
- Tekst w komórce ma `vertical-align: top`.
- **Nigdy tabela do układu strony** i nigdy lista definicji zamiast tabeli.
