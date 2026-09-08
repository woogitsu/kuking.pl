Pole formularza — jedna informacja, etykieta nad polem, szerokość dobrana do treści.

```jsx
<Field
  id="nazwa"
  etykieta="Nazwa przepisu"
  wymagane
  szerokosc="srednie"
  podpowiedz="Tak, jak mówisz o nim w domu."
/>

<FieldRow>
  <Field id="minuty" etykieta="Ile minut" typ="number" szerokosc="liczba" />
  <Field id="porcje" etykieta="Ile porcji" typ="number" szerokosc="liczba" />
</FieldRow>

<Field
  id="tresc"
  etykieta="Napisz kilka słów"
  typ="textarea"
  blad="Dodaj zdjęcie albo napisz kilka słów — inaczej nie ma czego opublikować."
/>
```

- **Etykieta zawsze nad polem**, nigdy sam `placeholder` — podpowiedź znika po
  pierwszej literze i człowiek zostaje z pustym prostokątem.
- **Szerokość dobrana do treści** (D-109): `rok` 8ch, `liczba` 10ch, `krotkie`
  22ch, `srednie` 40ch, bez klasy 100%. Nie dawaj wszystkim polom tej samej
  szerokości.
- **Oznaczamy wymagane**, nie „(nieobowiązkowe)”, i nigdy gwiazdką: gwiazdka
  jest umową, której nasz odbiorca nie podpisywał.
- Błąd trzyma schemat **co się stało → dlaczego → co zrobić**. Zdanie bez
  trzeciej części jest niedokończone.
- Poprawne dane po błędzie walidacji **nie znikają**.
- Nigdy `height` na polu — przy 140% skali tekst zostanie ucięty.
- Wyłączone pole zawsze z `powodWylaczenia`: „Nazwy nie da się zmienić po
  założeniu konta.”
