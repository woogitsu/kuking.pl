Plakietka autozapisu w długim formularzu.

```jsx
<AutosaveBadge />
```

- `aria-live="polite"`, **nigdy `assertive`**: człowiek właśnie pisze, czytnik ma
  dokończyć zdanie.
- Element musi być w drzewie **zanim** zmieni się jego treść — wstawiony razem
  z tekstem nie zostanie ogłoszony.
- Nazwa funkcji to „Szkic zapisany.”, nie „Autosave aktywny”.
- Pojawia się przy zapisie i **zostaje** do następnego. Nie miga.
