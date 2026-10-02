# Import: równoważne oktety adresu i robots.txt (#2569)

Matcher respektuje RFC 9309 §2.2.2, Figures 4 i 6:
https://www.rfc-editor.org/rfc/rfc9309.html#section-2.2.2.

Przed porównaniem URI i wzorca dekoduje pojedynczo wyłącznie percent-encoded
ASCII unreserved. Pozostałe zakodowane oktety zachowuje z wielkimi literami
hex, a surowe UTF-8 zapisuje jako oktety kodowane. `%2F` pozostaje innym
zapisem niż separator `/`, `%3F` niż separator zapytania, a `%25` nie
uruchamia następnego dekodowania. Surowe `*` i `$` w URI są literalne;
operatorami pozostają wyłącznie w regule. Zakodowany `%2A` w regule nie
staje się wildcardem. Przy wyborze najbardziej szczegółowego wzorca jeden
zakodowany oktet liczy się jako jeden, a nie trzy znaki jego zapisu.

Kontrakt #2325 pozostaje: porównujemy path razem z query. Grupy
KukingImport/*, wildcard, końcowe `$` i Allow przy remisie zostają.
`PobieraczStron` sprawdza decyzję przed żądaniem HTML i na każdym skoku;
test z kontrolowanym DNS/HTTP mierzy odmowę zakodowanej ścieżki na wejściu
i po przekierowaniu. Nie pobiera danych wydawców ani nie kontaktuje się
z OpenAI. To respektowanie robots, nie mechanizm autoryzacji ani zmiana SSRF.

Test regresyjny: `ImportRobotsKodowanieTest`. Trzy kontrole ujemne w pełnym
CI odwracają normalizację, zachowanie reserved i liczenie oktetów.
Bez migracji, nowych zależności i kosztu AI. Rollback samego kodu przywraca
błąd dopasowania; zapisane prywatne szkice pozostają bez zmian.
