## D-220 — Zwijanie narzędzi panelu na telefonie (#581, 17 września 2026)

Odbiór produkcyjny wykazał, że pełny spis narzędzi odsuwa kolejkę poza pierwszy
ekran telefonu. Poniżej 64rem JavaScript początkowo zwija tę samą listę;
przycisk „Nawigacja panelu” pozwala ją rozwinąć. Powrót do Kuking pozostaje
poza zwijanym obszarem. Bez JavaScriptu i na komputerze lista jest widoczna.
Nie zmieniamy linków, uprawnień ani 2FA. Escape zamyka menu i oddaje fokus
przyciskowi; zmiana szerokości nie chowa skupionego linku. To uzupełnia
D-218, a nie zmienia zakresu uprawnień moderacji.
