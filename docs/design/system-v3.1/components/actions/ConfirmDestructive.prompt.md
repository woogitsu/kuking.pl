Potwierdzenie akcji nieodwracalnej — dwa kliknięcia, bez JavaScriptu.

```jsx
<ConfirmDestructive
  etykieta="Usuń wpis"
  pytanie="Na pewno usunąć ten wpis? Tej operacji nie da się cofnąć samodzielnie."
  action="/dania/123"
  hrefAnuluj="/dania/123"
/>
```

- Zbudowane na `<details>`: działa bez skryptu i **jest widoczne**. Systemowe
  okno `confirm()` pojawia się tam, gdzie nikt nie patrzy.
- Pytanie ma czerwoną obwódkę — to nie jest karta, tylko ostatni moment na
  zawrócenie.
- Pytanie jest pełnym zdaniem mówiącym, **co zniknie**. Przy przepisie także to,
  że znikną cudze wykonania i komentarze.
- „Zostaw” zawsze jest i nigdy nie jest samym „×”.
- Nie łagodź nazwy: „Archiwizuj” zamiast „Usuń” jest eufemizmem, po którym
  ludzie są zdziwieni skutkiem.
