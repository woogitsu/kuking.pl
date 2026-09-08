Karta przepisu — karta wpisu plus pasek danych sprzed gotowania.

```jsx
<RecipeCard
  tytul="Pizza z pieca w ogrodzie"
  tytulHref="/przepisy/456"
  zdjecie="/zdjecia/456.jpg"
  alt="Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce"
  czasPrzygotowania="90 minut"
  porcje="6 porcji"
  poziom="Średnie"
  akcje={<Button waga="primary" type="submit" aria-pressed="false">Zapisz</Button>}
/>
```

- Przyjmuje wszystkie właściwości `PostCard` i dokłada `czasPrzygotowania`,
  `porcje`, `poziom`. `czas` zostaje datą wpisu, tak jak w karcie wpisu.
- W wariancie `zwarta` pasek danych **zostaje**: to właśnie ta informacja, dla
  której ktoś przegląda listę.
- **Maksimum trzy dane.** Czwarta zaczyna wyglądać jak tabela.
- Każda dana ma ikonę **i podpis**: „90 minut”, nie zegarek z liczbą 90.
  Jednostki pełnym słowem, liczby cyframi.
- **Nigdy gwiazdki ani kropki zamiast poziomu.** „Średnie” każdy rozumie tak
  samo; trzy z pięciu gwiazdek nie.
- „Zapisz” ma `aria-pressed` **i zmienia napis** na „Zapisano” — sam stan ARIA
  nie jest widoczny dla oka.
- Nie chowaj czasu i porcji do środka przepisu: to informacja, na podstawie
  której ktoś decyduje, czy w ogóle wchodzić.
