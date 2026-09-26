## D-195 · Trasy z identyfikatorem sprawdzamy żądaniem, również poza wiązaniem modelu

**Data:** 12 września 2026 · PR #471 · Status: **obowiązuje**

### Decyzja

Skan sygnatur kontrolerów nie obejmuje wszystkich sposobów wczytania obiektu. Obchód musi uwzględniać gościa, właściciela, obcą osobę i blokadę oraz jawnie rozliczać każdą nową trasę z parametrem. Podpisane adresy i prywatne pliki wymagają osobnych przypadków. Wyniki pomiarów są w opisie PR; ten wpis ich nie przedstawia jako ponownego pomiaru.

### Stan zapisu

Uzupełnienie dziennika po scaleniu PR #471; opisuje pracę już obecną na `main`.

📄 `tests/Feature/KazdaTrasaZIdentyfikatoremPodPolicyTest.php`
