Kroki kreatora — „Krok 2 z 3 — składniki”.

```jsx
<WizardSteps krok={2} ile={3} nazwa="składniki" />
```

- **Postęp jest napisany słowami.** Kropki są dodatkiem z `role="presentation"`.
- Kropki **nie są klikalne**: skok do kroku 3 z pominięciem 2 zostawia szkic
  w stanie, którego serwer nie umie zapisać.
- Nagłówek strony musi mówić to samo co krok. Jeśli nagłówek mówi „Krok 1 z 3”,
  a strona pokazuje wszystko naraz, to nagłówek kłamie.
- Trzy kroki to trzy adresy (D-108): `/dodaj/przepis`,
  `/dodaj/przepis/skladniki`, `/dodaj/przepis/kroki`. Każdy kończy się POST-em,
  który zapisuje szkic.
- „Wstecz” jest zwykłym linkiem — działa przycisk „wstecz” przeglądarki.
