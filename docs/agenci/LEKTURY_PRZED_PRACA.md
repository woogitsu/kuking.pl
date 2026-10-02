# Lektury przed pracą — pełna lista dokumentów projektu

Przeniesione z `AGENTS.md` §2. Treść listy zachowana; dwa zdania o nowych decyzjach poprawione zgodnie z regułą zbiorczych plików decyzji właściciela (D-333, wiersze tabeli bez nowego numeru D). W `AGENTS.md` obowiązuje wersja skrócona: zawsze `AGENTS.md`, potem tylko dokumenty obszaru zmiany; dużych plików nie czytaj w całości.

Poniżej dawna lista „przeczytaj w tej kolejności” — używaj jej jako **mapy obszarów**, nie jako obowiązkowej lektury wszystkiego.

Przeczytaj w tej kolejności:

1. ten plik,
2. `docs/PRODUCT.md` — czym jest produkt,
3. `docs/FEATURES.md` — co jest w MVP, a co świadomie NIE,
4. `docs/UX_50_PLUS.md` — twardy standard interfejsu,
5. `docs/ARCHITECTURE.md` — jak to jest zbudowane,
6. `docs/DATABASE.md` — indeks modelu danych; opis tabel w `docs/baza/` (szukaj tabeli w indeksie),
7. `docs/ROADMAP.md` i `docs/FEATURES.md` (lista V2 jest w sekcji „V2” tego
   drugiego) — **od D-282 (26 września 2026) V2 wolno budować**; sprawdź
   tę sekcję, żeby wiedzieć, co to jest, i pracować po kolei (P0 → P1 → P2,
   §10), nie po to, żeby tego unikać. Lista „Nie wcześnie” w tym samym pliku
   pozostaje zakazana bez zmian,
8. **`docs/DECISIONS.md` — dziennik decyzji już podjętych.** Czytaj go, zanim
   zaproponujesz zmianę architektury, pakiet albo inny sposób pisania tekstów.
   Połowa „dobrych pomysłów" jest tam już rozstrzygnięta wraz z uzasadnieniem.
   Od 30.09.2026 to **indeks**: każda decyzja jest osobnym plikiem
   `docs/decyzje/D-NNN-krotki-slug.md`. **Duża, osobna decyzja = nowy plik**
   (numer: `php scripts/decyzje-indeks.php --nastepny`; drobne decyzje właściciela to wiersze tabeli aktualnego zbiorczego pliku, bez nowego numeru D), potem
   `php scripts/decyzje-indeks.php` odświeża tabelę — nigdy nie dopisuj treści
   decyzji do `docs/DECISIONS.md`. Pilnuje tego `DziennikDecyzjiZgodnyZIndeksemTest`.
   Gałąź sprzed podziału z nowym wpisem w starym dzienniku przenosi go według
   [`docs/flota/PRZENIESIENIE_PO_PODZIALE.md`](../flota/PRZENIESIENIE_PO_PODZIALE.md);
9. dokument dotyczący obszaru, który zmieniasz (`docs/` ma katalogi tematyczne).

Jeśli pracujesz nad marką lub wyglądem: najpierw
[`docs/brand/KONSTYTUCJA_MARKI.md`](../brand/KONSTYTUCJA_MARKI.md), potem
`docs/brand/COPY_STYLE.md`, `docs/brand/GLOS_MARKI.md` oraz
`docs/design/DESIGN_SYSTEM.md`. Konstytucja wyznacza kierunek marki;
nie zastępuje nadrzędnych zasad tego pliku ani jawnych decyzji właściciela
w `docs/DECISIONS.md`. Historyczna makieta nie unieważnia tych zasad.
Jeśli nad moderacją lub prawem: `docs/legal/`.
Jeśli nad wdrożeniem: `docs/infra/`.
