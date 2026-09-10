# Kuking.pl — audyt autoryzacji, IDOR i zakresu uprawnień

**Repozytorium:** `woogitsu/kuking.pl`  
**Snapshot trzeciej warstwy:** `fd164ad3a91185d1969a109fd692a269ad9710e3`  
**Data:** 10.09.2026  
**Charakter:** przegląd ścieżek bezpośredniego dostępu, Policy, moderatora i obiektów media

## Werdykt

Nie znalazłem klasycznego, szerokiego IDOR typu „zmień UUID i edytuj cudzy wpis”. Mutacje są w większości poprawnie otoczone `auth`, `account.active`, `verified`, Policy, a bardziej wrażliwe operacje używają `password.confirm`; panel moderacji dodatkowo wymaga 2FA.

Nowe ryzyko leży w **zbyt szerokich wyjątkach uprzywilejowanych**. Moderator dostaje dostęp do zwykłych szkiców przepisów oraz — jeszcze szerzej — do każdego gotowego `Media`, nawet jeśli zdjęcie nie ma rodzica objętego sprawą moderacyjną.

## AUTHZ-01 — moderator widzi każdy nieopublikowany przepis, w tym zwykły `draft` — P2

### Dowód

`app/Models/Recipe.php` rozróżnia stany co najmniej `draft`, `published`, `hidden` i `removed`.  
`app/Policies/RecipePolicy.php::view()` traktuje wszystkie przepisy inne niż opublikowane jedną regułą i wpuszcza autora **lub moderatora**.

Analogiczny `PostPolicy` nie daje moderatorowi automatycznego wglądu do zwykłego nieopublikowanego wpisu właściciela.

### Dlaczego to ma znaczenie

Dostęp moderatora do `hidden/removed` jest logiczny: te stany wynikają z moderacji i moderator musi móc je ocenić. Zwykły `draft` jest jednak prywatnym warsztatem użytkownika. Konto moderatora ma większą powierzchnię ataku niż zwykłe konto, więc zasada least privilege powinna ograniczać blast radius przejęcia takiego konta.

To nie jest klasyczny IDOR — moderator jest uwierzytelniony i uprzywilejowany — ale jest to nadmiarowy zakres uprawnienia.

### Naprawa

Rozdzielić semantykę statusów w Policy:

- `draft` → wyłącznie autor;
- `published` → normalne reguły public/followers + blokady/status autora;
- `hidden/removed` → autor + moderator zgodnie z workflow odwołań/moderacji;
- soft-deleted → osobna, jawna decyzja, bez przypadkowego `withTrashed()`.

Dodać tabelaryczny test Policy: `status × owner × moderator × stranger × blocked`.

## AUTHZ-02 — moderator ma blanket access do każdego `ready` Media — P2

### Dowód

`app/Domain/Media/DostepDoZdjecia.php::moze()` kończy się sukcesem przed wyszukaniem rodziców, gdy widz jest właścicielem zdjęcia **lub moderatorem**.

Oznacza to, że znając UUID zdjęcia moderator może pobrać każde `ready` media, także:

- upload jeszcze nieprzypięty do treści;
- zdjęcie używane wyłącznie w prywatnym szkicu;
- `source_scan_media_id` zawierające potencjalnie prywatny skan;
- plik, którego żaden rodzic nie dawałby moderatorowi w normalnej Policy.

UUID-y nie są praktycznie enumerowalne, więc nie kwalifikuję tego jako P1. Nadal jest to niepotrzebny blast radius uprawnień.

### Naprawa

- zachować skrót właściciela dla preview uploadu;
- moderatora przepuszczać przez rzeczywiste Policy rodzica;
- dla osieroconego media bez rodzica moderator powinien dostać dostęp tylko przez konkretny workflow administracyjny/moderacyjny, jeżeli taki przypadek naprawdę istnieje;
- szczególnie potraktować `source_scan_media_id`.

## AUTHZ-03 — routing i ochrona mutacji są ogólnie spójne — pozytywne

Przegląd `routes/web.php`, kontrolerów ustawień i Policy nie pokazał masowego braku `authorize()`/middleware. Istotne właściwości:

- operacje na koncie wymagają aktywnego konta;
- operacje o podwyższonym ryzyku są chronione ponownym potwierdzeniem hasła;
- panel moderatora ma dodatkową barierę 2FA;
- odmowa dostępu do media używa 404, ograniczając oracle „obiekt istnieje, ale nie dla ciebie”.

## AUTHZ-04 — blokada jako granica prywatności jest implementowana konsekwentnie, ale ma race w zapisie — odsyłacz

Same Policy w wielu miejscach poprawnie sprawdzają blokadę w obie strony. Trzecia warstwa wykryła jednak, że zapis `follow` może wygrać wyścig z `block` i odtworzyć relację po sprawdzeniu blokady. To osobny P1 opisany w `27_SCENARIUSZE_NADUZYC_I_INWARIANTY_SPOLECZNOSCIOWE.md`.

## Testy, które powinny zostać

1. Każdy publiczny endpoint z UUID: stranger/owner/moderator/blocked/banned/pending_delete.
2. Każdy status przepisu: draft/published/hidden/removed.
3. Media: orphan, avatar, post, recipe hero, source scan, recipe step, cooked event.
4. Test „moderator nie widzi zwykłego draftu, ale widzi moderacyjnie hidden/removed”.
5. Test „moderator nie dostaje orphan/source-scan wyłącznie dzięki roli”.

## Priorytet

To nie jest obecnie największy blocker startu. Poprawić przed publiczną betą, najlepiej przy jednym refaktorze macierzy widoczności. Nie budować osobnego ACL frameworka — obecne Policy wystarczą, trzeba tylko zawęzić dwa wyjątki.
