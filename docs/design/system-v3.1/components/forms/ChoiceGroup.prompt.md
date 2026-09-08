Grupa wyboru „Kto to widzi” — trzy duże karty, wszystkie widoczne naraz.

```jsx
<ChoiceGroup wybrana="wszyscy" onZmiana={setWidocznosc} />
<ChoiceGroup wybrana="wszyscy" />
```

- Z `onZmiana` wyborem steruje strona. **Bez `onZmiana` grupa trzyma wybór
  u siebie**, a `wybrana` jest tylko wartością początkową — tak samo jak
  przy przyłączniku motywu w `SiteFooter`.

- **Nigdy lista rozwijana.** Lista chowa dwie z trzech możliwości i wymaga
  kliknięcia, żeby dowiedzieć się, co się traci. Kit v2 rozbił się o to.
- Wybór widać po **trzech** rzeczach naraz: kółku, obwódce i tle.
- Fokus obejmuje **całą kartę**, nie samo kółko.
- Kółko ma 24 px, ale klikalny jest cały `<label>`.
- **Maksimum trzy kolumny.** Liczba kolumn bierze się z miejsca, które komponent
  naprawdę ma, a nie z szerokości okna (D-112).
- Nie skracaj opisów do jednego słowa: „Tylko ja” bez „Twój prywatny zeszyt”
  brzmi jak ukrycie wpisu przed samym sobą.
- Grupa nie ma stanu wyłączonego ani błędu — zawsze jedna z trzech możliwości
  jest zaznaczona.
