## D-148 · Rejestr tekstów zmienia się z tłumaczącego się na zapraszający — zdań nie wycinamy, przepisujemy

**Data:** 11 września 2026 · **Decyzja właściciela** (issue #392, grupa C1–C7) ·
PR #398 · Status: **obowiązuje**

### Co audyt chciał zrobić, a co robimy

Audyt copy (`docs/research/audyt-copy-2026-09-11/`) wskazał zdania uspokajające
i chciał je **wycinać**. Rozstrzygnięcie jest inne: **zamieniamy rejestr**, bo
problemem nie jest objętość tych zdań, tylko to, że tłumaczą się zamiast
zapraszać.

```text
❌ Wrzucasz zdjęcie i kilka słów. Nic więcej nie musisz.
✅ Wrzuć zdjęcie i kilka słów, a pokażesz je komuś, kto dziś też gotował.
```

Uwaga na drugą stronę tego samego kija: **zapraszający to nie sprzedażowy.**

### Najmocniejszy przypadek: cztery zapewnienia, że odpisuje człowiek (C2)

Na `/napisz-do-nas` to samo mówiły cztery miejsca naraz: prawa szyna („Lepiej
dwa razy niż wcale"), pierwszy akapit („Po drugiej stronie jest człowiek, nie
automat"), ramka o zgłaszaniu („To jest inna droga i prowadzi do innej kolejki")
i dół strony („nie mamy całodobowego dyżuru i nie będziemy go udawać").

Właściciel: *„nie ma co naciskać że to człowiek, bo wtedy ludzie będą mieć
odwrotne odczucie"*.

**Zapewnianie czterokrotnie, że po drugiej stronie jest człowiek, brzmi jak
zaprzeczanie zarzutowi, którego nikt nie postawił** — i uruchamia dokładnie to
podejrzenie, które miało uśpić. Ten sam mechanizm co w zdaniu „to naprawdę nie
jest oszustwo": raz powiedziane jest ciepłe, cztery razy jest tłumaczeniem się.
Zostaje **jedno** miejsce — to na dole, bo tam za zdaniem stoi konkret: jedna
osoba, brak całodobowego dyżuru, odpowiedź czasem po weekendzie.

To spostrzeżenie jest cenniejsze niż zarzut o powtórzenia, od którego audyt
zaczynał: powtórzenie da się policzyć, a **efekt odwrotny do zamierzonego trzeba
zrozumieć**.

### C6 — piszemy o realnym życiu tej grupy, nie o abstrakcji

```text
❌ na wspólnym albo cudzym urządzeniu     abstrakcja, brzmi podejrzliwie
✅ u rodziny czy znajomych                realny scenariusz tej grupy
```

**„Cudze urządzenie" to język regulaminu.** Rodzina jest głównym przewodnikiem
po technologii w tej grupie (`docs/research/AUDIENCE_50_PLUS.md` §3 i §6) —
nazwanie tego po imieniu nie jest protekcjonalne. Protekcjonalne jest pisanie
*o* starszej osobie zamiast *do* niej.

### Pozostałe pięć reguł

- **C1 — zapraszaj, nie uspokajaj.** Marka nie jest kosztem do zmniejszenia.
- **C3 — instrukcja zamiast stylu autora.** „Klikasz — i jesteś w środku" →
  „Kliknij go, żeby wejść na konto". Sprzedaż wychodzi z tekstu szybciej, niż
  się ją tam wkłada.
- **C4 — nie tłumacz, czym ten krok NIE jest.** Jeśli krok jest opcjonalny,
  **postaw „Pomiń"** — przycisk powie to lepiej niż zdanie o przycisku.
  Konstrukcja „jedno i drugie jest w porządku" / „to też jest w porządku" stała
  w serwisie **trzy razy** (`/pomoc`, `/dodaj`, koniec onboardingu); zostaje
  w **jednym**, tym wskazanym przez właściciela jako wzór.
- **C5 — mniej szczegółu, więcej luzu.** „po kolei, od najnowszego. Bez żadnego
  układania przez komputer" → „po kolei, od najnowszego". Antytechnologiczny
  wtręt tłumaczy technologię komuś, kto o nią nie pytał, i sugeruje, że gdzieś
  indziej jest wróg.
- **C7 — „nic nie" zostaje, jeśli niesie informację.** Audyt policzył
  **36 wystąpień w 27 widokach ze 137** — to maniera, nie przypadek. Ale
  kasowanie hurtem jest błędem w drugą stronę:

| Zostaje | Idzie |
|---|---|
| „nic nie zginie" przy autozapisie — mówi, co robi mechanizm | „Nic nie musisz robić dalej" — nie mówi nic |
| „jeśli nic nie zaznaczysz" — opisuje skutek wyboru | „nic nie zostało zamknięte na stałe" obok zdania, które to już powiedziało |
| „nigdy nic nie napiszemy na Twojej tablicy" — konkretne zobowiązanie | „albo nic nie pisz, to też jest w porządku" — trzecia kopia tej samej konstrukcji |

### Wzór, do którego się odwołujemy

```text
Choćby jedno zdanie. Pytanie do autora też jest w porządku.
```

Pierwsze zdanie zdejmuje presję **objętości**, drugie presję **treści**,
i **żadne nie mówi, JAK pisać** — a poprzednia wersja („Napisz normalnie, po
ludzku") mówiła, i powtarzała słowo z etykiety pola.

```text
❌ Napisz normalnie, po ludzku.        mówi, JAK pisać
✅ Choćby jedno zdanie.                mówi, ILE wystarczy
```

📄 `docs/brand/GLOS_MARKI.md` §5 · `docs/research/audyt-copy-2026-09-11/` ·
D-145 · D-149
