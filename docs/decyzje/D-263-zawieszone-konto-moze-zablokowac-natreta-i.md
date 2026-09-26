## D-263 — Zawieszone konto może zablokować natręta i zgłosić treść (audyt B2-03, 25 września 2026)

**Data:** 25 września 2026 · **Decyzja zespołu** wynikająca z audytu B2 (DSA
art. 16, bezpieczeństwo ludzi) · Status: **obowiązuje** · Uzupełnia D-253

### Co było
Zawieszona osoba czyta serwis (D-253), więc widzi też komentarze i profil
osoby, która ją nęka. `POST /@{login}/blokuj`, `DELETE /@{login}/blokuj`,
`POST /zglos/{typ}/{id}` i `POST /zglos-nielegalna-tresc` odbijał jednak
`EnsureAccountIsActive` komunikatem o zawieszeniu. Przyciski „Zablokuj”
i „Zgłoś” stały na ekranie i były martwe. D-253 tych czynności nie rozstrzygał.

### Decyzja
Trasy `social.block`, `social.unblock`, `reports.store`
i `zglos.nielegalna.store` są na liście `DOZWOLONE_MIMO_ZAWIESZENIA`.

- **Blokada chroni, a nie publikuje.** Zmienia wyłącznie to, co widzi
  blokujący i blokowany. Zawieszenie jest karą za pisanie — nie może
  zostawiać człowieka bezbronnym wobec nękania.
- **Zgłoszenie treści to prawo z DSA art. 16**, które nie zależy od stanu
  konta zgłaszającego. Zgłoszenia bez konta i tak przyjmujemy, więc
  odmowa zawieszonemu byłaby tylko przeszkodą, nie ochroną.
- Obserwowanie, komentarze i publikacja zostają zablokowane (D-253).

### Dowody
`tests/Feature/ZawieszonyBlokujeIZglaszaTest.php` — z kontrolą dodatnią, że
obserwowanie i komentarz dalej są odbijane.

### Wycofanie
Usunąć cztery nazwy tras z listy w `EnsureAccountIsActive`. Schemat bazy się
nie zmienia. Blokady i zgłoszenia złożone w czasie zawieszenia zostają.
