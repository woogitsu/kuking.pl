# Audyt 19 — feed, wyszukiwarka, widoczność i poprawność zapytań

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot:** `e3cf6ab58e71ed444a4bfa30fde3b003eaab9104`  
**Data:** 10.09.2026

## Wniosek

Repo ma dużo pracy włożonej w spójność widoczności (`widoczneDla`, `publiclyVisible`, `dostepnyJakoAutor`, Policy), ale jedna ważna reguła rozjechała się między wyszukiwarką a modelem sankcji. To nie jest luka poufności — raczej **ciche znikanie legalnej publicznej treści**.

## Ustalenia

### SEARCH-01 — P2 — `suspended` ma niespójną widoczność w search/discovery

**Pliki:**
- `app/Domain/Search/SearchQuery.php`
- `app/Models/User.php`
- `app/Policies/UserPolicy.php`
- `app/Domain/Feed/FollowingFeed.php`
- `app/Models/Post.php`
- `docs/legal/MODERATION_PLAYBOOK.md`

Model użytkownika i playbook rozróżniają:
- `suspended`: zakaz/ograniczenie aktywności; wcześniejsza treść nie jest automatycznie kasowana,
- `banned/pending_delete`: treść/autorstwo może być ukrywane,
- `erased`: osoba znika, tekst może pozostać.

`User::jestDostepnyJakoAutor()` dopuszcza suspended, a profil suspended nie jest traktowany jak zamknięte konto.

Jednocześnie `SearchQuery::recipes()` filtruje autora do statusu `active`. Oznacza to, że publiczny przepis osoby zawieszonej może:
- otwierać się bezpośrednio,
- być dostępny z profilu/innych powierzchni,
- ale zniknąć z wyszukiwarki.

Komentarz w search sugerujący, że suspended prowadziłby do 403, nie zgadza się z obecną semantyką modelu/policy.

**Do rozstrzygnięcia produktowo, nie przez przypadkowy warunek SQL:**

**Wariant A — sankcja nie ukrywa starej treści.**
Wtedy search/feed powinny używać kanonicznego zakresu autora i dopuścić suspended.

**Wariant B — suspended degraduje discovery, ale direct URL zostaje.**
To też może być sensowna polityka, ale trzeba ją zapisać jawnie w playbooku/regulaminie jako skutek sankcji i stosować konsekwentnie we wszystkich discovery surfaces.

Obecny stan to wariant C: zachowanie wynika z różnych warunków w różnych klasach.

---

### SEARCH-02 — P2 — powielanie reguł widoczności nadal jest ryzykiem regresji

Repo słusznie ma komentarze o tym, że `widoczneDla()` i `dostepnyJakoAutor()` są dwiema granicami. W praktyce różne ekrany wciąż ręcznie składają te warunki.

Przykłady powierzchni:
- search,
- sitemap,
- collections,
- feed,
- profile,
- tags.

**Rekomendacja architektoniczna:** nazwać kanoniczne scope’y dla typowych intencji:
- `discoverableBy($viewer)`,
- `publiclyIndexable()`,
- `visibleInContainerTo($viewer)`,

które wewnętrznie składają widoczność treści i dostępność autora. Nie robić jednego gigantycznego scope’a do wszystkiego — intencje SEO, direct-view i discovery są różne.

## Co jest dobre

- sitemapa filtruje treści po publiczności i dostępności autora;
- kolekcje ponownie filtrują cudzą treść w momencie odczytu, a nie ufają stanowi z chwili zapisania;
- pagination i osobne nazwy paginatorów ograniczają wcześniejsze problemy z nieograniczonym `get()`;
- wyszukiwanie korzysta z PostgreSQL FTS/`pg_trgm` zamiast zewnętrznego silnika bez potrzeby.

## Testy, które warto dopisać

Jedna tabela stanów autora (`active/suspended/banned/pending_delete/erased`) × powierzchnie:
- direct recipe/post,
- profile,
- search,
- following feed,
- discover,
- tag,
- collection,
- sitemap.

Test powinien wymagać jawnego oczekiwania dla każdej komórki. To jest tańsze niż kolejne incydentalne poprawki w pojedynczych kontrolerach.

