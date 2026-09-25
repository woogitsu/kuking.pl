# Backlog Kuking.pl

Ten plik był kiedyś ręcznie utrzymywaną mapą ✅/🔨/⬜ i tabelą numerów
issues. Rozjechał się z kodem szybciej, niż ktokolwiek go aktualizował —
audyt `docs/audyt/2026-09-25-B6.md` (B6-22) znalazł m.in. wiersze
mówiące „ban działa dopiero po wylogowaniu” i „kara czasowa jest
dożywotnia”, choć obie rzeczy są w kodzie od tygodni (odpowiednio
`EnsureAccountIsActive` w grupie `web` i kolumna `users.status_expires_at`),
a tabela wymieniała jako otwarte issues, które są zamknięte od
2026-09-09. Agent pracujący „po kolei z listy” brał się więc za rzeczy
już zrobione i ufał nieaktualnym opisom bezpieczeństwa.

Źródło prawdy o tym, co zrobione i co zostało, jest jedno:

- **[Issues](https://github.com/woogitsu/kuking.pl/issues)** — stan
  (otwarte/zamknięte), opis, uzasadnienie i kryteria akceptacji każdej
  pozycji. Kolejność pracy: `P0` → `P1` → `P2` (etykiety na issue).
- **[`docs/ROADMAP.md`](./docs/ROADMAP.md)** — kolejność faz i bramki
  wejścia między nimi.

Ten plik nie duplikuje żadnej z tych dwóch list, żeby nie powstał
trzeci, niezależnie starzejący się opis tego samego stanu.
