## D-227 — PostgreSQL 18 jest wymaganiem, nie preferencją

Data: 20 września 2026. Decyzja właściciela.

**Co zdecydowano.** Wymagana wersja PostgreSQL to **18** — lokalnie, w CI
i na produkcji. Wcześniej `AGENTS.md` mówił „lokalnie i w CI wystarczy 16+".

**Dlaczego.** Szesnastka opisywała stan, którego już nigdzie nie ma: CI stawia
`postgres:18-alpine` w sześciu usługach, produkcja ma 18, lokalny klaster
18.6. Reguła, która dopuszcza konfigurację nieistniejącą u nikogo, nie chroni
przed niczym — a przy tym usypia: każdy czyta ją jako „przetestowane na 16".

**Numer.** Ta decyzja nosiła najpierw D-223. Po awarii 20.09 o ten sam
numer stanęły trzy różne rozstrzygnięcia z trzech odzyskanych gałęzi, a
`NumeryDecyzjiMajaWpisyTest` łapie duplikat numeru dopiero PO scaleniu —
czyli wtedy, gdy odnośniki w kodzie już wskazują na dwie decyzje naraz.
Numer przyznano tej pracy, która ma najmniej odnośników z zewnątrz:
tutaj dwa, oba w `DEPLOYMENT_RUNBOOK.md`. Strażnik martwych reguł CSS
zostaje przy D-223, bo jego numer siedzi w jedenastu miejscach i w nazwie
katalogu dowodów `docs/design/evidence/kaskada223/`.

**Kolejność zmiany jest częścią decyzji.** Najpierw reguła w `AGENTS.md`
(`68099722`), dopiero potem próg w strażniku R60 (`d2ffccac`). Odwrotna
kolejność uczyłaby, że regułę wolno wyprzedzić testem — a `AGENTS.md` jest
jedynym źródłem prawdy projektu.

**Zakres.** Zmienione cztery miejsca stawiające wymóg: tabela stacku
w `AGENTS.md` i jej kopia w `README.md`, wymagania uruchomienia w `README.md`
oraz wymagania własnego runnera w `docs/infra/CI_BEZ_ACTIONS.md`.

**Czego świadomie NIE zmieniono.** Zapisów o POMIARACH wykonanych na 16.13
(`SearchQuery`, `ProgPodobienstwa`, migracja z 9 września) ani notek „od
PostgreSQL 17…" w migracjach i `docs/DATABASE.md`. To są fakty o silniku
i cudze pomiary — przepisanie ich na 18 sfałszowałoby czyjś wynik.

**Skutek dla runbooka.** `DEPLOYMENT_RUNBOOK.md` §6.3 zachowuje wariant „weź
17 i zrób upgrade in-place", ale **wyłącznie jako drogę awaryjną odtworzenia
po awarii**, gdy dostawca nie oferuje 18 w danej chwili. Nie jest to
dopuszczalny stan docelowy, a upgrade staje się wtedy zadaniem do domknięcia.
Procedurę trzymamy, bo improwizowanie jej w kryzysie kosztuje więcej niż
zapisanie z góry.

**Dowód, że próg nie jest martwą liczbą.** Podbicie go na chwilę na 19 oblewa
strażnika komunikatem „PostgreSQL 18 jest starszy niż wymagane 19+". Bez tego
„18" byłoby liczbą stojącą obok porównania, które i tak zawsze przechodzi.
