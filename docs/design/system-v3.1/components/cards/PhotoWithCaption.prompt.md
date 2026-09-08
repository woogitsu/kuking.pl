Tytuł na zdjęciu — zawsze na podkładzie.

```jsx
<PhotoWithCaption
  href="/przepisy/456"
  src="/zdjecia/456.jpg"
  alt="Pizza z mozzarellą, pomidorkami i świeżą bazylią na drewnianej desce"
  napis="Pizza z pieca w ogrodzie"
  podpis="Piotr · 90 minut · 6 porcji"
/>
```

- **Podkład jest zawsze**, także wtedy, gdy zdjęcie akurat jest ciemne — bo
  następne nie będzie (reguła „nigdy” nr 7).
- Podkład jest pełny u dołu i przechodzi w przezroczystość ku górze, więc działa
  także na białym talerzu.
- **Nie rozjaśniaj podkładu, żeby było „ładniej”.** Kontrast jest tu policzony:
  18.37:1 w motywie jasnym, 21.00:1 w ciemnym.
- Napis i podpis są `<p>` — w `<span>` sklejają się w jeden wiersz.
- Alt opisuje zdjęcie, a nie powtarza napisu na nim.
- **Nie kładź na zdjęciu przycisków.** Akcja stoi pod kadrem.
