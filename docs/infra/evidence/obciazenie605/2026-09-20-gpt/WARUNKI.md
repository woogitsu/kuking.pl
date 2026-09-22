# Warunki ustalone przed rampą — 20.09.2026

- Aplikacja: niezmieniony obraz `kuking:ci-4c811cc7bff365fb8f86d87eabac93b7738a45cd`,
  ID `sha256:fef7803ef940c01006de266aef5ecc63082ae22c7650ca3216d692f9415d42b3`.
- Kontener własny `kuking-gpt-obciazenie-app`, topologia `all`, 2 CPU,
  1 GiB RAM, bez dodatkowego swapu, 4 wątki FrankenPHP Classic, PHP 8.4.25.
- Baza własna `kuking_b605_gpt_obciazenie`, właściciel `kuking`,
  `127.0.0.1:55439`; PostgreSQL współdzielony, bez osobnego limitu CPU/RAM.
- 200 tys. wpisów, 20 tys. przepisów, 200 tys. komentarzy. Dokładne liczniki
  zostaną zapisane obok jako `dane.json`. 24 zalogowane konta pomiarowe.
- Dysk lokalny, loopback HTTP. Nie jest to pomiar R2 ani cache Cloudflare.
- Mieszanka istniejącego generatora: 14 scenariuszy, upload 1%.
  Korpus 8 plików: 5 fotografii Nikon D750/iPhone 15 Pro i 3 jawne mutacje
  fotografii (ucięcie, uszkodzony EXIF, orientation=6). Żadnych prostokątów GD.
- Rampa: 5 → 15 → 30 → 60 → 120 żądań/s, stopień 120 s, wybieg 60 s.
  Jeżeli ostatni stopień nie pokaże nasycenia, dalsze zwiększenie wymaga
  sprawdzenia, czy ograniczeniem nie jest liczba sesji i rate limiting.
- Bramka jak w istniejącym przyrządzie: obce CPU ≤18 rdzeni, PSI ≤25%,
  60 s ciągłego okna, brak pracujących runnerów Kuking. Maksymalnie 300 s
  oczekiwania, najwyżej 2 próby stopnia. Budżet całej próby 45 minut.
- Reguła skażenia pozostaje w `seria-obciazenia-605.sh`. Przebieg skażony
  zostaje w dowodach i nie służy do ustalenia pojemności.
- Szukamy rzeczywistej degradacji: błędów/timeoutów, nieobsłużonego napływu,
  rosnącej zaległości kolejki albo plateau przepustowości z rosnącym czasem.
  Sam wzrost p95 bez tych danych nie ustala granicy. 429 raportujemy osobno.
- Po zaobserwowaniu degradacji: powrót do 5 żądań/s i sprawdzenie kolejki,
  mediów i błędów. Brak czystych stopni oznacza niewykonany pomiar, nie zapas.
- #612/#613 pozostają warunkowe: najpierw dowód, która warstwa ogranicza.
  Nie instalujemy Octane, Imagick ani integracji libvips przed tym dowodem.

Własny pomiar przygotowania: bramka otworzyła się po 59 s (60 próbek);
nie jest to dowód, że przepuści późniejszą serię. Późniejszy próbnik kontrolny
pokazał także obce obciążenie przekraczające 18 rdzeni.