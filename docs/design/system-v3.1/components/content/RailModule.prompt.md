Moduł szyny — trzecia kolumna od 1280 px.

```jsx
<RailModule
  tytul="kuKINGi na dziś"
  podtytul="Kilka osób i kilka dań, które dziś warto zobaczyć."
  stopka="Jutro będzie tu ktoś inny."
>
  <RailPerson imie="Halina" opis="Zupy i pierogi, Podkarpacie" avatarSrc="/zdjecia/halina.png" />
  <RailDish tytul="Pomidorowa z własnych pomidorów" opis="Halina z Mazowsza · 40 minut" zdjecie="/zdjecia/soup.png" href="/przepisy/12" />
</RailModule>
```

- `<section aria-labelledby>` — czytnik nazwie sekcję, zanim wejdzie do środka.
- **Szyna nigdy nie powtarza kolumny głównej.** Ten sam wpis dwa razy na jednym
  ekranie to problem nr 4.
- **Nie rób z tego rankingu**: bez „Top”, bez liczników pozycji, bez „najlepsi
  w tym tygodniu”.
- Zdanie „Jutro będzie tu ktoś inny.” jest **treścią**, nie ozdobą: mówi wprost,
  że to nie jest tabela wyników.
- „Obserwujesz” zmienia **napis**, nie tylko `aria-pressed` i kolor.
- Szyny nie ma poniżej 1280 px. Treść potrzebna niżej ma drugą kopię
  w `tylko-waskie`; **nigdy obie widoczne naraz**.
- Zdjęcie w `RailDish` jest małe celowo — szyna wskazuje, gdzie zajrzeć, a nie
  pokazuje dania.
