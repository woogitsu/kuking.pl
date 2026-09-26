## D-152 · Powód naszej decyzji nie stoi przy kontrolce, której dotyczy

**Data:** 11 września 2026 · PR #396 · Status: **obowiązuje**

### Dwa zdania z ekranu „Twoje dane"

Na ekranie usuwania konta, przy haczyku wybierającym zakres usunięcia, stało:

> „Skasowanie tego zabrałoby coś ludziom, którzy o nic nie prosili."

> „Tego nie da się odwrócić. **Dlatego haczyk jest domyślnie pusty** —
> skasowanego tekstu nikt już nie przywróci."

### Co z nimi było nie tak

Pierwsze zdanie mówiło człowiekowi, **co byłoby nie w porządku, gdyby wybrał
drugą opcję** — na ekranie, na którym ma właśnie wybrać. To nie jest informacja
o skutku; to ocena wyboru przed jego dokonaniem.

Drugie mówiło, **czemu tak zrobiliśmy**. Że haczyk jest pusty, człowiek widzi
w formularzu dwa akapity niżej — a powód nie jest jego sprawą w tej sekundzie.

### Zasada

> **Uzasadnienie decyzji produktowej mieszka w `docs/DECISIONS.md`, nie przy
> kontrolce.** Przy kontrolce stoi: co się stanie, czego nie da się odwrócić
> i co zrobić, jeśli człowiek chce inaczej.

Powód domyślnego zakresu usunięcia jest decyzją **D-022** i stoi tam, gdzie ma
stać. Ekran ma wykonać wybór, nie obronić go.

### Co zostało

Wszystkie fakty: trzy listy mówiące, co dokładnie zostaje, a co znika; „Tego nie
da się odwrócić"; „usuń je samodzielnie, zanim skasujesz konto: później nie
będzie już jak, bo do usuniętego konta nie da się zalogować"; „warto najpierw
pobrać swoje dane". Że przepis może być w cudzym zeszycie, mówi lista niżej —
czyli ta sama informacja co w usuniętym kazaniu, tylko jako fakt.

Usunięte zdania stoją w komentarzu Blade razem z powodem usunięcia, żeby nie
wróciły jako „brakowało czegoś ciepłego".

### Dlaczego to nie jest ta sama reguła co D-149

D-149 dotyczy zdania mówiącego o **sobie** („napisaliśmy to tak, bo…"). Ta
dotyczy zdania mówiącego o **naszej decyzji produktowej** w miejscu, w którym
człowiek podejmuje **swoją**. Można złamać jedną, nie łamiąc drugiej, i dlatego
stoją osobno.

📄 `resources/views/pages/settings/data.blade.php` · D-022 · D-149
