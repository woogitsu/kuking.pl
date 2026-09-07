# Znaleziska czekające na założenie jako issues

Ten plik istnieje, bo limit API GitHuba wyczerpał się w trakcie pracy.
Każda pozycja jest gotowa do przeklejenia jako issue.
**Po założeniu issue — usuń pozycję stąd.**

---

## 1. Zbanowane konto działa do końca sesji · P0 · bezpieczeństwo

`User::ban()` i `suspend()` zmieniają `status`, ale **nie unieważniają sesji**.
`isActive()` jest sprawdzane przy logowaniu i w części Policies, ale nie przy
każdym żądaniu.

**Skutek:** osoba zbanowana za nękanie działa dalej, dopóki nie wyloguje się
sama. Przy `SESSION_LIFETIME=10080` (7 dni) to jest tydzień.

**Do zrobienia:**

- [ ] Middleware sprawdzający `status` przy **każdym** żądaniu uwierzytelnionym
- [ ] `banned` i `pending_delete` → natychmiastowe wylogowanie z komunikatem
- [ ] `suspended` → dostęp tylko do odczytu i do ścieżki odwoławczej (#10)
- [ ] `ban()` i `suspend()` kasują istniejące sesje użytkownika z tabeli `sessions`
- [ ] Test: zbanowanie w trakcie aktywnej sesji odcina przy następnym żądaniu

Źródło: `docs/research/repos/discourse-discourse.md`

---

## 2. Kara bez terminu wygaśnięcia jest karą dożywotnią · P0 · moderacja

`docs/legal/MODERATION_PLAYBOOK.md` przewiduje blokady czasowe („7 dni"),
ale w bazie nie ma gdzie zapisać, kiedy kara mija. Przy jednym moderatorze
nikt tego nie odklika ręcznie — czyli każda blokada czasowa staje się trwała.

**Do zrobienia:**

- [ ] Kolumna `users.status_expires_at`
- [ ] Zadanie w harmonogramie przywracające `active` po terminie
- [ ] Panel moderacji: wybór długości kary zamiast samego „zawieś"
- [ ] Użytkownik widzi datę końca kary, nie tylko fakt
- [ ] Test: konto wraca do `active` po upływie terminu

Trzy niezależne projekty (Discourse, Pixelfed, Fresns) trzymają to jako datę.
Źródło: `docs/research/repos/discourse-discourse.md`

---

## 3. Brakuje testów widoczności: każdy stan × każdy typ obserwatora · P1 · testy

Najcenniejsza brakująca klasa testów. Wyciek prywatnej treści **nie wywala
testu** — cicho pokazuje za dużo, więc zwykłe testy tego nie łapią.

**Do zrobienia:**

- [ ] Klasa bazowa generująca macierz: `public` / `followers` / `private`
      × autor / obserwujący / obcy / zablokowany / niezalogowany
- [ ] Zastosowana do `Post`, `Recipe`, `CookedEvent`, `Collection`, `Comment`
- [ ] Osobno dla widoku, listy i wyszukiwarki — treść może wyciec przez każdą z trzech dróg

Źródło: `docs/research/repos/laravelio-laravel.io.md`

---

## 4. Lista zastrzeżonych nazw użytkownika · P1 · bezpieczeństwo

Dziś `basia_z_podkarpacia` i `moderacja` są tak samo dostępne. Konto o nazwie
sugerującej obsługę serwisu to gotowe narzędzie phishingu — a nasza grupa
jest na to szczególnie podatna.

**Do zrobienia:**

- [ ] Lista zastrzeżonych: `admin`, `administrator`, `moderacja`, `moderator`,
      `kuking`, `pomoc`, `support`, `obsluga`, `kontakt`, `zespol`, `oficjalne`,
      `redakcja`, `bezpieczenstwo`, `platnosci`
- [ ] Walidacja przy rejestracji i przy zmianie nazwy w ustawieniach
- [ ] Komunikat: „Ta nazwa jest zarezerwowana. Wybierz inną."
- [ ] Test dla każdej nazwy z listy

Źródło: `docs/research/repos/pixelfed-pixelfed.md`

---

## 5. `recipe_ingredients.no_amount` · P2 · model danych

Skalowania porcji (V2) nie wolno stosować do „soli do smaku" ani do
„mleka — ile weźmie". Bez flagi przepis skalowany ×3 poprosi o trzy szczypty
soli, co jest śmieszne, i o trzy razy „ile weźmie", co jest bez sensu.

Tanie teraz, drogie później — kolumna wchodzi przy najbliższej migracji
na tej tabeli.

Źródło: `docs/research/repos/TandoorRecipes-recipes.md`

---

## 6. Brakujące ograniczenia `UNIQUE` · P1 · model danych

- [ ] `collection_items` — ten sam przepis dwa razy w jednym zeszycie
- [ ] nazwy zeszytów w obrębie właściciela — dwa zeszyty „Na święta"
- [ ] `post_media` ma już `UNIQUE (post_id, position)`, ale nie na samej parze

Źródło: `docs/research/repos/mealie-recipes-mealie.md`
