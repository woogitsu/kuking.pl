## D-205 · Przyrząd kontroli ma sprawdzać źródło i udowadniać wykrycie regresji

**Data:** 12 września 2026 · PR #483 · Status: **obowiązuje**

### Decyzja

Pomiar kontrastu czyta produkcyjne tokeny i jest częścią builda Vite oraz Docker.
Nie zastępuje pomiaru kaskady ani reflow. Kontrole negatywne w izolowanym CI
najpierw wymagają dodatniego testu, potem rzeczywistej porażki po mutacji,
przywrócenia z kopii poza repo i zgodności md5. Pełna suita idzie na przywróconym kodzie.
Raport i kod wyjścia nie są synonimami; wykrytą lukę starego pomiaru zapisano w #484.

📄 `scripts/kontrast-marki.mjs` · `scripts/kontrole-negatywne-alfa08.py` ·
`docs/design/WERYFIKACJA_ALFA_08.md`
