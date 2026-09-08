Komunikat nad treścią, której dotyczy.

```jsx
<Alert odmiana="sukces">Przepis opublikowany. Teraz ktoś może z niego ugotować.</Alert>

<Alert odmiana="blad">
  Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie.
  Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.
</Alert>
```

- `odmiana="blad"` dostaje `role="alert"` (przerywa czytnik, bo człowiek musi to
  usłyszeć teraz); sukces i info dostają `role="status"`.
- Błąd trzyma schemat **co się stało → dlaczego → co zrobić**.
- **Nigdy kodu HTTP.** Zawsze zdanie po polsku mówiące, co zrobić.
- **Nigdy żartu w błędzie** — i nigdy gry słowem „kuKING”. Człowiek ma wtedy
  problem, nie nastrój na żarty.
- Komunikat nie znika sam po dwóch sekundach: minimum tyle, ile trwa
  przeczytanie przy 150% skali.
- Komunikat stoi **nad** treścią, której dotyczy.
