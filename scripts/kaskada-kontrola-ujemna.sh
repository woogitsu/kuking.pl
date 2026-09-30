#!/usr/bin/env bash
# =============================================================================
#  KONTROLA UJEMNA strażnika kaskady (D-223).
# =============================================================================
#
#  CO TU JEST DOWODZONE
#  Że `scripts/kaskada-martwe-reguly.mjs` pyta o WYNIK KASKADY, a nie o tekst
#  arkusza. Mutacja NIE DOTYKA pliku, w którym stoi pilnowana wartość:
#
#      resources/css/app.css   (warstwa `components`, BEZ ZMIAN)
#        .przepis-liczba svg { color: var(--color-brand); }
#
#  Dokładamy regułę o WYŻSZEJ SWOISTOŚCI w `marka-przepis.css` — arkuszu BEZ
#  WARSTWY, a kod spoza warstw bije każdą warstwę nazwaną:
#
#      [data-marka] .marka-przepis-tekst .przepis-liczba svg { color: … }
#
#  Strażnik czytający TREŚĆ `app.css` po tej mutacji zostaje zielony: w pliku
#  dalej stoi dokładnie to, czego pilnuje. Strażnik pytający przeglądarkę
#  o `getComputedStyle` MUSI oblać, bo deklaracja przestała cokolwiek zmieniać.
#  Na tym polega różnica, dla której ten strażnik w ogóle powstał.
#
#  UŻYCIE (w runtime WSL, z własną bazą i portem 55439):
#      bash scripts/kaskada-kontrola-ujemna.sh
#
#  WYMAGANIE WSTĘPNE — JUŻ SPEŁNIONE W TYM DRZEWIE
#  `scripts/kontrola-ujemna.sh` musi mieć poprawkę SIGPIPE (`grep -qE … <<<`
#  zamiast `printf … | grep -q`), inaczej przy dużym wyjściu strażnika
#  przyrząd melduje fałszywe `ZLA_PRZYCZYNA`. Do 20.09.2026 poprawka żyła
#  wyłącznie na gałęzi `narzedzia/kontrola-ujemna-v2` i każdy nakładał ją sobie
#  na runtime. Została PRZENIESIONA TUTAJ — ta kontrola ujemna rusza z samej
#  tej gałęzi, bez cudzego drzewa pod spodem.
#
#  Wynik: storage/kontrola-ujemna-kaskada.json, kod wyjścia jak w przyrządzie.
# =============================================================================
set -uo pipefail
cd "$(dirname "$0")/.."

exec bash scripts/kontrola-ujemna.sh \
  --plik resources/css/marka-przepis.css \
  --nazwa 'strażnik kaskady: ikona kafla liczb przykryta z arkusza bez warstwy' \
  --zamien '[data-marka] .marka-przepis-tekst .przepis-liczba {
  min-width: 0;
  max-width: 100%;
}' \
  --na '[data-marka] .marka-przepis-tekst .przepis-liczba {
  min-width: 0;
  max-width: 100%;
}
[data-marka] .marka-przepis-tekst .przepis-liczba svg {
  color: var(--color-ink-muted);
}' \
  --oczekuj 'martwe własności: color' \
  --json storage/kontrola-ujemna-kaskada.json \
  -- bash scripts/kaskada-kontrola-polecenie.sh '.przepis-liczba'
