Pole zaznaczenia — jedna rzecz włączona albo wyłączona, cały wiersz klikalny.

```jsx
<Checkbox id="zgoda" value="1">
  Rozumiem, że po 30 dniach moje wpisy, przepisy i zdjęcia zostaną usunięte na stałe
</Checkbox>
```

- Klikalny jest **cały wiersz** (input w `<label>`), minimum 44 px wysokości,
  kwadracik 24 px.
- Zaznaczenie ogłasza czytnik sam — nie dokładaj `aria-checked`.
- Etykieta jest **pełnym zdaniem**. Przy rzeczy nieodwracalnej mówi wprost, co
  się stanie.
- Nie używaj jako przełącznika, który działa natychmiast bez „Zapisz”: bez
  skryptu nic się nie zapisze, a człowiek uwierzy, że zapisał.
- Stan błędu nie należy do samego pola — niezaznaczony obowiązkowy regulamin
  zgłasza błąd pod grupą.
