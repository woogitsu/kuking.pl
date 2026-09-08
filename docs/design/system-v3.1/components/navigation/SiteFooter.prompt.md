Stopka — linki prawne, zdanie o serwisie, przełącznik motywu.

```jsx
<SiteFooter motyw="jasny" onMotyw={setMotyw} />
<SiteFooter className="stopka-www" />
```

- **Bez `motyw` i `onMotyw` stopka radzi sobie sama** — trzyma wybór u siebie
  i przestawia `data-theme` na `<html>`. Dzięki temu podgląd motywu działa
  w każdym szablonie i na każdej karcie, bez dopisywania obsługi. Obie
  właściwości podaj dopiero wtedy, gdy wybór trzyma strona.
- **Jedyne miejsce, w którym przełącza się motyw.** Nie chowaj przełącznika  w ustawieniach i nigdzie indziej — ma być tam, gdzie człowiek go szuka po
  pierwszym zetknięciu z ciemnym ekranem.
- Przełącznik jest **formularzem**, nie skryptem: działa bez JavaScriptu, a wybór
  zapisuje się na koncie.
- Wybrany motyw ma `aria-pressed="true"` **i inną wagę przycisku**.
- Motyw bierze się wyłącznie z jawnego wyboru człowieka (D-019). Reguły
  `prefers-color-scheme` w arkuszu nie ma i nie będzie.
- **Nigdy ikonka słońca i księżyca.** Dwa przyciski z napisami „Jasny”
  i „Ciemny” mówią, co się stanie.
- Nie wkładaj do stopki nawigacji serwisu — od tego są dwie nawigacje wyżej.
- Stopka ma zapas na dole, żeby nie chowała się pod dolnym paskiem;
  `className="stopka-www"` zdejmuje ten zapas na stronie publicznej.
