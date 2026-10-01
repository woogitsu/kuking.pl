## D-147 · Czasownik od `kuKING` wolno użyć tylko tam, gdzie obok stoi zdanie, które go tłumaczy

**Data:** 11 września 2026 · **Decyzja właściciela** (issue #392) · PR #398 ·
Status: **obowiązuje** · zmienia D-009 w części odrzucającej czasownik

### Co się zmienia

D-009 odrzuciło `kuKINGujesz` z uzasadnieniem „nowy czasownik wymaga
zrozumienia, a nasz odbiorca nie lubi zgadywać". Właściciel tę część odwrócił:
**czasownika wolno używać.** Odrzucony argument nie znika — przestaje być
zakazem, a staje się warunkiem.

| Gdzie | Czasownik | Dlaczego |
|---|---|---|
| hasło, nagłówek sekcji, digest, zaproszenie | ✅ „Dziś kuKINGujemy z resztek" | obok stoi zdanie, które tłumaczy; nic nie zależy od zrozumienia słowa |
| nawigacja | ❌ | nawigacja ma być przewidywalna, nie dowcipna (D-009, ta część zostaje) |
| jedyny przycisk realizujący akcję | ❌ „kuKINGuj to" zamiast „Opublikuj" | przycisk mówi, co robi — `AGENTS.md` §5 |
| błąd, moderacja, prawo, bezpieczeństwo | ❌ | hierarchia tonu, zakaz bezwarunkowy |

Warunek jest oparty na liczbie: **w grupie 65–74 lata tylko 12,3% osób ma według
unijnej metodologii podstawowe umiejętności cyfrowe**
(`docs/research/AUDIENCE_50_PLUS.md`). Taki czytelnik potrafi przejść ścieżkę
wyuczoną, a nie poradzić sobie z nową — i nie ma zgadywać, co znaczy słowo
stojące na **jedynej** drodze do celu.

### Granica, która się nie rusza: żart jest o nazwie serwisu, nigdy o użytkowniku

| | O czym jest zdanie | Ocena |
|---|---|---|
| „Zostań kuKINGiem" | o nazwie | ✅ |
| „Witaj w gronie kuKINGów" | o przynależności | ✅ |
| „2 431 kuKINGów" | o liczbie ludzi tutaj | ✅ |
| „Jesteś prawdziwym kuKINGiem!" | o użytkowniku, komplementem | ❌ |
| „Top kuKINGi tygodnia" | o hierarchii | ❌ |
| „Zdobądź poziom kuKING" | o nagrodzie za coś | ❌ |

### Powód trzech ostatnich jest produktowy, nie estetyczny — i jest zmierzony

Własne zdjęcie lub film zamieściło w ostatnim miesiącu **17% internautów 55–64
i 13% z 65+**, przy 70% i 61% rozmawiających przez komunikator
(`docs/research/AUDIENCE_50_PLUS.md`). `COPY_STYLE.md` §2 dokłada, że **ponad
połowa osób 50+ w mediach społecznościowych nigdy nic nie publikuje**.

Ci ludzie zdjęcia **wysyłają**, tylko ich nie **publikują** — i to jest jedyny
nawyk, który ten produkt ma zmienić. Stąd asymetria, o której cała ta decyzja:
**komplement za publikację podnosi poprzeczkę u ludzi, którzy jej nie
przeskakują. Nazwa przynależności ją obniża — wystarczy tu być.** „Top kuKINGi
tygodnia" dokłada do tego ranking, którego `AGENTS.md` §12 zabrania, a nazwa
przynależności użyta jako wyróżnienie dzieli ludzi na dwie klasy.

Formy żeńskiej nie tworzymy i to się nie zmienia; zdanie wymagające formy,
której nie używamy (`kuKINGowi`, `kuKINGu`, `kuKINGowie`), **przepisujemy**
zamiast odmieniać słowo na siłę.

**Zmiana wymaga:** reakcji prawdziwych użytkowników w testach (#15) — ten sam
warunek, który postawiło D-009.

📄 `docs/brand/GLOS_MARKI.md` §1 · `docs/brand/MASCOT_CONCEPT.md` ·
`docs/brand/COPY_STYLE.md` §8 · D-009 · D-145
