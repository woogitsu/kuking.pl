Kilka przycisków obok siebie — zawija się przy 320 px, nie wystaje.

```jsx
<ButtonRow>
  <Button waga="primary" type="submit">Opublikuj</Button>
  <Button waga="quiet" href="/dodaj">Anuluj</Button>
</ButtonRow>
```

Kolejność w kodzie jest kolejnością fokusu: główny przed wtórnym, wtórny przed
cichym, destrukcyjny na końcu i w osobnej sekcji.
