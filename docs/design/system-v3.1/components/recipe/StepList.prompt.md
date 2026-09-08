Kroki przepisu — numer rysuje licznik CSS.

```jsx
<StepList kroki={[
  "Wymieszaj mąkę z wodą i odstaw pod ściereczką na pół godziny.",
  "Rozgrzej piec. Poczekaj, aż kamień będzie gorący.",
]} />
```

- **Nie wpisuj numeru w treść** („1. Wymieszaj…”): czytnik przeczyta go dwa razy,
  a przy zmianie kolejności numery kłamią.
- Jeden krok to jedna czynność — łatwiej to czytać przy garnku.
- W serwisie numer jest atramentem stonowanym (kolor marki ma przycisk główny,
  D-110). Na publicznej stronie przepisu numer jest większy i w kolorze marki,
  bo przy garnku szuka się wzrokiem numeru.
