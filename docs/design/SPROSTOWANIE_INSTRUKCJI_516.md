# Sprostowanie instrukcji modeli — #516

## Aktualny stan — 14 września 2026, po scaleniu PR #521

Pakiet #515–516 jest scalony: PR #521, head
`1cb2ab5f485a0130a992b1cd4e5ba8db2a02b672`, merge
`a3cb64df819351b18450603c1dcabe775aa748f0`.
Obowiązkowy lokalny hook i zwykły push zakończyły się sukcesem.
CI PR `34792102646`: **10 zadań success**, PHP **3726 testów / 75240 asercji**.
Port marki job `103818145082` zakończył się sukcesem po 17 min 54 s;
moduł tagów zaliczył 192 konfiguracje, cztery przejścia bez JS oraz
sześć rzeczywistych negatywów CSS z przywróceniem końcowego źródła.
Wyniki wcześniejszych prób poniżej pozostają zapisem historycznym.

Potwierdzenie wdrożenia i granice odbioru opisuje
[odbiór Alfa 0.22](ODBIOR_PRODUKCJI_ALFA_022.md).
Pełny port marki nadal ma status **CZĘŚCIOWO**; pozytywny CI nie oznacza
osobistego oglądu każdej strony, stanu i klienta poczty.

**Poniższy akapit opisuje etap przed wysyłką; aktualny wynik jest powyżej.**

14 września 2026. Instrukcje i ich lokalna regresja poprawione. Kontrole
całego pakietu, CI, wysyłka i scalenie pozostają do potwierdzenia.

## Co poprawiono

- Historyczna blokada proxy nie jest przedstawiana jako właściwość każdego środowiska. Najpierw bieżąca diagnoza; lokalne testy nie udają odbioru produkcji.
- Usunięto polecenia globalnej konfiguracji Composera i cofania manifestu/locka z gita. Izolowana kopia oraz odtworzenie aktualnych bajtów, MD5 i mtime chronią cudzą pracę.
- Baza wymaga jawnego wyboru hosta, portu i nazwy; klucz generujemy tylko w nowej lokalnej instancji, jeśli go brakuje.
- Skróty Claude/Gemini/Copilot kierują do jawnego wyjątku trzech kropek. Skrót powiadamiania odsyła do istniejących trzech granic.
- Konstytucja oddziela font 200%, tekst aplikacji 140% i prawdziwy zoom 200%; starszych wyników nie przepisuje na zoom.
- Komentarz check.sh opisuje bazowy hook i dodatkowe CI. Nazwa kroku axe nie utrwala historycznej liczby ekranów. Polecenia i progi kontroli pozostają bez zmian.

## Regresja i review

**3 testy / 17 asercji PASS**, bez Laravel i połączenia z bazą. Kontrolują
niebezpieczne polecenia, sprzeczne skróty oraz właściwe sekcje przeglądarki
i typografii. Niezależny review wykrył słabą pierwszą asercję szukającą słowa
„Historyczny” w całym pliku; zastąpiono ją kontrolą konkretnej sekcji.
W samych zmianach instrukcji review nie wskazał blokującej sprzeczności.

Pięć rzeczywistych negatywów przywracało cofanie manifestu, konfigurację
globalną, sprzeczny zakaz ikon, kategoryczną blokadę Chromium i usuwało
oddzielny wymóg zoomu. Każdy oblał test; po każdym dodatni przebieg PASS.
Backup i logi poza repo: `/tmp/kuking-docs516-74xg_yf7`.

| Przywrócone źródło | MD5 |
|---|---|
| AGENTS.md | d2c5928ef35fc3ba534a2e7bb2860e10 |
| CLAUDE.md | 5c6661a970f06ca56be6f2943f41f1a0 |
| docs/brand/KONSTYTUCJA_MARKI.md | 6c0a4c4cdc14e2ec2d984b6644b4675e |

Zweryfikowano również dokładny mtime_ns. Pierwsza próba copy2 przez WSL
obcięła czas pliku Windows do sekund; nie została zaliczona jako pełne
przywrócenie. Lokalny Python Windows odtworzył zapisany czas z dokładnością
systemu plików 100 ns, po czym cały zestaw negatywów powtórzono. Treść
pozostawała zgodna z MD5 także w pierwszej próbie.


Dodatkowy odczyt COPY_STYLE i GLOS_MARKI usunął niepoparte uogólnienie,
że ponad połowa osób 50+ nigdy nic nie publikuje: przywołany pomiar dotyczy
zdjęć i filmów w ostatnim miesiącu. Opis kobiet 60+ oznaczono jako założenie
projektowe, ponieważ udział kobiet wśród słuchaczy UTW nie opisuje składu
społeczności Kuking. Bezwarunkowe „zawsze go znajdziesz” zastąpiono opisem
pojawienia się zapisanego przepisu w zeszycie. Zachowano zasady języka marki;
te sprostowania wynikają z odczytu dokumentów, nie z nowych testów.

## Uzupełnienie wskaźników D-053

CLAUDE.md, GEMINI.md, .cursor/rules/kuking.mdc i .windsurfrules odsyłają do AGENTS.md/D-053: newralgiczne formularze mogą wymagać JS, nie wolno pozostawiać martwych przycisków. Usunięto sprzeczny skrót o wszystkich ważnych funkcjach bez JS. Cursor odsyła również do izolowanej bazy z jawnym hostem i portem zamiast `createdb kuking_test`.

Zakończony niezależny proces PHPUnit (`--no-configuration`, bootstrap vendor, bez Laravel/bazy): **4 testy /35 asercji PASS**. To wynik całej klasy InstrukcjeChroniaSrodowiskoTest po dodaniu jednej metody dla czterech wskaźników i izolacji bazy.

Dodatkowe **5 rzeczywistych negatywów PASS** w źródłach worktree: powrót starej zasady JS osobno w każdym z czterech wskaźników oblewał `D053_ODSYLACZ`; powrót niejawnego createdb w Cursor oblewał `IZOLACJA_BAZY`. Każdy miał kopię poza repo, przywrócenie dokładnych bajtów/MD5 i mtime przez Windows Python oraz dodatni rerun. Pełne logi: `/tmp/kuking-docs516-x5rfyoyq`; szczegóły MD5/mtime i końcowy wynik: `C:\Users\matma\Documents\Codex\kuking.pl\output\negatywy516-d053.json`. Nie modyfikowano native, nie uruchamiano aplikacji, bazy ani przeglądarki. Powyższe pięć kontroli stanowi uzupełnienie wcześniejszych kontroli #516, nie ich ponowny pomiar.
