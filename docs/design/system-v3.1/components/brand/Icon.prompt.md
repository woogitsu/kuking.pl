Ikona z zestawu Kuking — zawsze obok tekstu, nigdy zamiast tekstu.

```jsx
<span className="dana-przepisu">
  <Icon name="zegar" className="dana-przepisu-ikona" />90 minut
</span>
```

- 18 ikon konturowych (kreska 2 px, `currentColor`) plus `name="znak"` — znak
  marki, garnek w koronie z **wyciętym** uśmiechem, więc działa też na tle marki.
- Rozmiar bierze się z klasy arkusza: `side-nav-ikona`, `bottom-nav-ikona`,
  `alert-ikona`, `dana-przepisu-ikona`, `wordmark-znak`, `empty-state-znak`.
- Domyślnie `aria-hidden="true"`. `label` podawaj **tylko** wtedy, gdy ikona
  stoi sama i jest jedynym nośnikiem nazwy.
- Nazwy: dom, lupa, plus, zeszyt, osoba, dzwonek, zegar, porcje, czapka,
  zakladka, komentarz, garnek, aparat, ostrzezenie, ptaszek, strzalka,
  ustawienia, znak.
- Ikona nigdy nie jest jedyną treścią przycisku. Jeśli coś się nie mieści, ma
  zniknąć całe, a nie stracić napis.
