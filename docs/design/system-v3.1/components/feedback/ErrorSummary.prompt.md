Podsumowanie błędów na górze formularza.

```jsx
<ErrorSummary
  bledy={[
    { id: "haslo", tekst: "Hasło musi mieć co najmniej 10 znaków." },
    { id: "nazwa", tekst: "Nazwa przepisu jest pusta." },
  ]}
/>
```

- Nagłówek zmienia się z liczbą: „Jednej rzeczy jeszcze brakuje” / „Kilku rzeczy
  jeszcze brakuje”. Liczba mnoga przy jednym błędzie jest kłamstwem, które
  ludzie zauważają.
- `role="alert"` — czytnik ogłasza podsumowanie od razu po przeładowaniu.
  `tabindex="-1"` pozwala serwerowi przenieść tu fokus.
- Skok do pola to zwykły `#id` — działa bez skryptu, a pole dostaje po skoku tę
  samą obwódkę co przy fokusie.
- Kolejność błędów jest **kolejnością pól**, nie ważności.
- **Nigdy „Formularz zawiera błędy”** — to nie mówi ani co, ani gdzie.
- Nie zostawiaj podsumowania bez błędów przy polach ani odwrotnie.
