## D-098 · Powiązania z dostawcami tożsamości mieszkają w tabeli `tozsamosci_zewnetrzne`, a nie w kolumnach na `users` — bo drugi dostawca jest zamówiony, nie wyobrażony

**Data:** 10 września 2026 · Issue #258 · **Prośba właściciela wprost:**
„trzeba pilnie dać logowanie przez Gmail i Facebook i łączenie konta z nimi"
· Status: **obowiązuje**

### Co ta decyzja zmienia w D-069, a czego nie rusza

**D-069 zostaje w całości** — łącznie z trzema regułami łączenia kont,
`email_verified` jako warunkiem, brakiem Turnstile na tej drodze i zakresem
`openid email profile`. Zmienia się jedno: **gdzie leży powiązanie.**

D-069 rozstrzygnęło: dwie kolumny na `users` (`google_sub`,
`google_connected_at`), a tabelę `tozsamosci_zewnetrzne` wskazało jako
„właściwy kształt **przy drugim dostawcy**". To rozstrzygnięcie było wtedy
poprawne i nie jest tu podważane: przy jednym dostawcy tabela byłaby
budowaniem „na przyszłość", czego zabrania `AGENTS.md` §3.

**Drugi dostawca został w tym czasie ZAMÓWIONY.** Właściciel poprosił wprost
o Google **oraz** Facebooka. To przestaje być „na przyszłość" i staje się
„na jutro", więc próg powrotu z D-069 jest przekroczony jego własnym
kryterium — a nie moim domysłem, jak może kiedyś będzie.

### Dlaczego TERAZ, a nie po scaleniu Google'a

Bo teraz jest to darmowe, a potem nie będzie. Gałąź z Google nie jest
scalona, migracja **nigdy nie chodziła na produkcji**, więc wolno ją
przepisać. Alternatywa to migracja przenosząca dane na żywej tabeli `users`
w kilka dni: nowa tabela, `INSERT ... SELECT`, kod czytający chwilowo z dwóch
miejsc, drugie wdrożenie kasujące kolumny — cztery kroki i jedno okno, w którym
droga wejścia na konto działa „w połowie". Przepisanie dziś to jeden plik.

### Kształt

```sql
CREATE TABLE tozsamosci_zewnetrzne (
    id            bigserial PRIMARY KEY,
    user_id       uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    dostawca      varchar(20)  NOT NULL,
    identyfikator varchar(255) NOT NULL,
    connected_at  timestamptz  NOT NULL DEFAULT now()
);

ALTER TABLE tozsamosci_zewnetrzne
    ADD CONSTRAINT tozsamosci_dostawca_check CHECK (dostawca IN ('google')),
    ADD CONSTRAINT tozsamosci_identyfikator_check CHECK (identyfikator ~ '^\S{1,255}$'),
    ADD CONSTRAINT tozsamosci_dostawca_identyfikator_unique UNIQUE (dostawca, identyfikator),
    ADD CONSTRAINT tozsamosci_dostawca_konto_unique UNIQUE (dostawca, user_id);
```

**Cztery rzeczy pilnuje BAZA, nie PHP** (`AGENTS.md` §6 — walidacja w PHP
jest dodatkiem, nie zamiennikiem):

1. **`UNIQUE (dostawca, identyfikator)` — jedno konto u dostawcy prowadzi
   do najwyżej jednego konta Kuking.** Bez tego dwa nasze konta mogłyby
   wskazywać ten sam `sub`, a „wejdź kontem Google" wpuszczałoby na to,
   które baza akurat poda pierwsze. To jest odpowiednik indeksu częściowego
   `users_google_sub_unique` z D-069 — z tą różnicą, że nie trzeba go
   powtarzać dla każdego kolejnego dostawcy.
2. **`UNIQUE (dostawca, user_id)` — jedno konto Kuking nie ma DWÓCH
   Google'i.** Tego ograniczenia wersja na kolumnach **nie potrzebowała**
   (kolumna jest jedna) i właśnie dlatego trzeba je było napisać wprost:
   bez niego „połącz" wołane dwa razy dokłada drugi wiersz. Pilnuje go test
   `test_jedno_konto_kuking_nie_ma_dwoch_polaczen_z_google`.
3. **Zamknięta lista dostawców.** Literówka („googel") nie ma prawa cicho
   założyć nowego rodzaju powiązania, a `INSERT` z nazwą `facebook` ma się
   ODBIĆ, dopóki Facebooka nie dopuści osobna migracja — patrz sekcja
   o Facebooku niżej, bo to jest cała jej pointa.
4. **Kształt identyfikatora** — niepusty, bez znaków białych, do 255 znaków.
   Nie zawężamy do samych cyfr, choć dziś Google nadaje wartości
   21-cyfrowe: zawężenie do dzisiejszego kształtu CUDZEGO identyfikatora
   zamknęłoby logowanie w dniu, w którym Google go zmieni, i nie chroniłoby
   przed niczym.

**Czego w tabeli NIE MA:** tokenu dostępu, tokenu odświeżania, tokenu
tożsamości, zdjęcia z dostawcy i **adresu e-mail** (mamy go na `users`;
dwie kopie rozjechałyby się przy pierwszej zmianie adresu, a adres
z dostawcy służy dokładnie raz — przy pierwszym połączeniu). Zakres jest ten
sam co w D-069 i pilnuje go test na liście kolumn, żeby dołożenie piątej
było decyzją, a nie refaktorem.

**Zysk uboczny, który jest ważniejszy, niż wygląda:** CHECK „obie kolumny
albo żadna" (`num_nonnulls(google_sub, google_connected_at) IN (0, 2)`)
**znika całkiem**. Nie dlatego, że przestał być potrzebny — dlatego, że
stan, którego pilnował, przestał być wyrażalny. Wiersz istnieje albo nie
istnieje. To jest jedyny rodzaj naprawy ograniczenia, który nie może się
zepsuć.

### `$fillable` jest PUSTE i to jest zabezpieczenie, nie przeoczenie

`TozsamoscZewnetrzna` nie ma ani jednego pola do masowego przypisania.
Wiersz w tej tabeli **JEST drogą wejścia na konto** dokładnie tak samo jak
hasło: kto go założy, wchodzi jednym kliknięciem. Gdyby te pola stały na
liście, dowolny dzisiejszy i przyszły `create($request->all())` — także
taki, który o dostawcach tożsamości w ogóle nie myśli — byłby przejęciem
konta. Ta sama zasada co `status`, `role` i `email` (`AGENTS.md` §7).
Wiersze powstają wyłącznie przez `User::connectGoogle()`.

### Łączenie idzie POD BLOKADĄ WIERSZA KONTA

`GoogleLoginController::link()` sprawdzał warunki i zapisywał powiązanie bez
blokady. Między jednym i drugim mieści się cała klasa wyścigów, które kończą
się wejściem na konto: nadanie roli moderatora, blokada konta, zmiana adresu,
zdjęcie potwierdzenia adresu, drugie takie samo żądanie z sąsiedniej karty.
Rewalidacja i zapis idą teraz przez `ZamekKonta::zablokuj()` (**D-079**), więc
o dostępie nie rozstrzyga stan sprzed sprawdzenia. Bez blokady zabezpieczenie
„moderator tą drogą nie wchodzi" byłoby prawdziwe tylko przez chwilę.

### Kasowanie konta — dwie drogi i obie są potrzebne

- **`ON DELETE CASCADE`** dla realnego `DELETE` na `users`
  (`migrate:fresh`, sprzątanie danych zasianych, przyszłe twarde usunięcie).
  Wiersz-sierota trzymałby identyfikator konta Google wskazujący w pustkę
  i **BLOKOWAŁBY** ponowne połączenie tego konta Google z czymkolwiek — czyli
  jedna osierocona linijka zamykałaby człowiekowi drogę wejścia na zawsze.
- **Jawne `$fresh->tozsamosciZewnetrzne()->delete()`** w `EraseAccountData`
  (D-022), bo **kont z Kuking się nie kasuje, tylko anonimizuje** — kaskada
  nigdy by tu nie zadziałała. To jest dokładnie ten sam wywód, dla którego
  jawnie kasuje się `pending_email_changes`. Bez tej linii nadpisanie hasła
  wartością losową nie chroniłoby niczego: kto miał to konto Google,
  wchodziłby dalej jednym kliknięciem.

### ROLLBACK

`down()` **odmawia**, gdy w tabeli jest choć jeden wiersz, i mówi, ilu kont
to dotyczy oraz co zrobić zamiast tego. Powód jest ten sam co w D-069 i nie
osłabł, tylko wzmocnił się: konto założone tą drogą **nigdy nie miało hasła**
(w `password` leży skrót wartości losowej, której nie zna nikt, także my),
a `DROP TABLE` jest ostrzejszy od dawnego `dropColumn` — razem z tabelą
znikają identyfikatory, więc powiązań nie da się potem odtworzyć.

```bash
# WŁAŚCIWA DROGA WYCOFANIA — bez migracji, bez utraty powiązań:
KUKING_WEJSCIE_GOOGLE=false     # + restart serwisu

# JEŚLI NAPRAWDĘ trzeba skasować powiązania — powiedz to wprost:
KUKING_ROLLBACK_KASUJ_TOZSAMOSCI_ZEWNETRZNE=true php artisan migrate:rollback --step=1
```

Zmienna nazywa się inaczej niż w D-069
(`KUKING_ROLLBACK_KASUJ_POWIAZANIA_GOOGLE`), bo cofnięcie kasuje teraz
powiązania **wszystkich** dostawców, nie tylko Google. Stara nazwa nie
istnieje w żadnym środowisku — migracja nigdy nie była scalona.

Kolejność przy wycofywaniu kodu i migracji razem: **NAJPIERW KOD, POTEM
MIGRACJA**, inaczej trasy `/wejdz/google` odwołują się do nieistniejącej
tabeli.

### CO BĘDZIE POTRZEBOWAŁ FACEBOOK — I CZEGO U NIEGO NIE DA SIĘ POWTÓRZYĆ

To jest najważniejsza sekcja tego wpisu i jedyny powód, dla którego jest
osobnym wpisem, a nie akapitem w D-069.

**Co Facebook dostanie za darmo:** tabelę (jeden nowy wiersz na osobę,
zero migracji przenoszącej dane), `UNIQUE` na obu parach, puste `$fillable`,
kasowanie razem z kontem, rewalidację pod `ZamekKonta`, odmowę cofnięcia
migracji, `state` i PKCE, dwa koszyki limitów, brak Turnstile na tej drodze,
2FA i blokadę konta po drodze przez `wpusc()`.

**Co Facebook będzie musiał dołożyć:** jedną migrację dopisującą `'facebook'`
do CHECK-a `tozsamosci_dostawca_check` (`DROP CONSTRAINT` + `ADD CONSTRAINT`
— tabela jest mała, blokada trwa milisekundy), własny klient
(inny punkt tokenu, inny format odpowiedzi), własne klucze w `.env`
i własny wpis w polityce prywatności.

**CZEGO U FACEBOOKA NIE DA SIĘ POWTÓRZYĆ — przeczytaj to, zanim skopiujesz
kod Google'a:**

> **Facebook NIE ODDAJE `email_verified`.** Jego Graph API oddaje pole
> `email` albo go nie oddaje wcale (człowiek mógł zarejestrować się numerem
> telefonu), ale **nigdy nie mówi, czy ten adres został potwierdzony**.
> Warunek z D-069 — „bez potwierdzenia adresu nie robimy nic" — jest dla
> Facebooka **niespełnialny**. Nie „trudny": niespełnialny, bo danych, na
> których stoi, po prostu nie ma.
>
> Wniosek, który z tego wynika i którego nie wolno obejść:
> **łączenie konta po adresie e-mail musi być dla Facebooka ZAKAZANE.**
> Nie „ostrożne", nie „za dodatkowym potwierdzeniem" — zakazane. Reguła 3
> z D-069 (adres się zgadza, adres potwierdzony u nas, jedno kliknięcie
> człowieka) opiera się CAŁYM ciężarem na tym, że przejście weryfikacji
> Google dla tego adresu dowodzi kontroli nad skrzynką. U Facebooka ten
> dowód nie istnieje, więc ta sama ścieżka staje się przejęciem konta na
> życzenie: zakładam konto na Facebooku, podaję cudzy adres, klikam
> „to moje konto" i wchodzę.
>
> **Bezpieczna droga dla Facebooka jest tylko jedna:** powiązanie powstaje,
> gdy człowiek jest JUŻ ZALOGOWANY na swoje konto Kuking (hasłem albo
> linkiem e-mail) i dopiero wtedy klika „połącz z Facebookiem" —
> czyli dowodzi, że konto jest jego, czynnością, a nie twierdzeniem. Wejście
> Facebookiem dla kogoś NIEZALOGOWANEGO wolno wtedy potraktować tylko dwoma
> sposobami: rozpoznaniem po `identyfikator` (powiązanie już istnieje) albo
> założeniem NOWEGO konta z adresem **niepotwierdzonym**, jak przy zwykłej
> rejestracji hasłem. Adres z Facebooka nie ma prawa nigdy trafić do bazy
> jako potwierdzony.
>
> Dlatego `'facebook'` NIE JEST dziś na liście CHECK-a. Baza ma odbić
> `INSERT` z tą nazwą, dopóki ktoś nie napisze migracji — a napisanie
> migracji zmusza do przeczytania tego akapitu.

### Czego ta decyzja świadomie NIE robi

- **Nie dokłada ekranu „połącz / odłącz" w ustawieniach.** D-069 odłożyło go
  świadomie i to zostaje (`AGENTS.md` §3, jedna funkcja na raz). Uwaga na
  wtedy jest w D-069 i nadal obowiązuje: odłączenie konta, które nie ma
  innej drogi wejścia, musi odmawiać albo najpierw poprosić o hasło.
- **Nie implementuje Facebooka.** Ta decyzja jest o modelu danych, w który
  Facebook wejdzie bez migracji przenoszącej dane — i o jednym zdaniu wyżej,
  którego nie wolno przy tym pominąć.
- **Nie rusza `laravel/socialite`.** Próg powrotu z D-069 („drugi dostawca")
  jest formalnie przekroczony, ale to jest osobne rozstrzygnięcie i osobny
  PR: dziś nie ma jeszcze ani jednej linijki kodu Facebooka, więc nie ma
  czego porównywać. Kto będzie pisał Facebooka, ma ten próg przeczytać
  w D-069 i rozstrzygnąć jawnie.

📄 `database/migrations/2026_09_10_500000_create_tozsamosci_zewnetrzne_table.php` ·
`app/Models/TozsamoscZewnetrzna.php` ·
`app/Models/User.php` (`tozsamosciZewnetrzne()`, `connectGoogle()`,
`hasGoogleConnected()`, `findByGoogleSub()`) ·
`app/Domain/Users/Actions/EraseAccountData.php` ·
`app/Http/Controllers/Auth/GoogleLoginController.php` (`link()` pod `ZamekKonta`) ·
`docs/DATABASE.md` (`tozsamosci_zewnetrzne`) ·
`docs/infra/DEPLOYMENT_RUNBOOK.md` krok 8D ·
`tests/Feature/LogowanieKontemGoogleTest.php` ·
`tests/Feature/CofniecieMigracjiGoogleOdmawiaTest.php` ·
issue #258, D-069, D-079 (`ZamekKonta`), D-022 (anonimizacja)
