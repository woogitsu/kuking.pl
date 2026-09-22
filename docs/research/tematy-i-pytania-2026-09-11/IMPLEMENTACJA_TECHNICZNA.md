# Kuking — plan techniczny: Tematy jako miejsca + Pytania

**Baza:** `main` sprawdzony 11.09.2026, head `2ec3788d8465e5841b43c93c65fc519caa4fa6c2`.

## 1. Stan obecny istotny dla projektu

### `TagController`
Już posiada `index()` i `show()`, promowane Tematy, A–Z, publiczne count wpisów i chronologiczny strumień.

### `Tag`
Aktualnie to **otwarta taksonomia**: użytkownik może utworzyć tag z wolnego tekstu; są statusy active/hidden/merged, aliasy, merge i promotion.

### `Post`
Już posiada body, media, tags, comments, visibility, moderation states i recipe relation. Nie ma dziś `kind` ani `title`.

### `PublishPost`
Już pozwala publikować zdjęcie bez tekstu, tekst bez zdjęcia, zdjęcia + tekst, tagi; obsługuje audyt i anti-spam.

### `Comment`
Już ma top-level + replies, parent, Post/Recipe/CookedEvent jako rodzic, status, block filtering i powiadomienia.

To bardzo dobry fundament.

---

# 2. Model danych — rekomendacja

Dodać do `posts`:

```text
kind varchar(20) NOT NULL DEFAULT 'dish'
title varchar(180) NULL
```

Dozwolone `kind`:

```text
dish
question
```

Reguła domenowa:

```text
kind=dish     -> title=null
kind=question -> title wymagany, 10–180 znaków
```

Constraint DB można dodać po bezpiecznym wdrożeniu aplikacji, jeśli rollout wymaga etapowania.

---

# 3. Nie tworzyć tabeli `questions`

Powody:
- Comment ma dziś CHECK na dokładnie jeden z 3 rodzajów rodzica;
- osobne Question oznacza czwarty FK i zmianę CHECK;
- trzeba byłoby powielać visibility, moderation, block, report, media, tags, audit, anti-spam i polityki;
- wzrasta ryzyko rozjazdu reguł dostępu.

**Pytanie jest semantycznym rodzajem Post, nie nowym kontenerem danych.**

---

# 4. `Post`

Dodać:

```php
public const KIND_DISH = 'dish';
public const KIND_QUESTION = 'question';

public function isQuestion(): bool;
public function isDish(): bool;
```

`url()`:
- dish → `posts.show`;
- question → `questions.show`.

---

# 5. Reguły Question

```text
title:      required, 10..180
body:       nullable, max 4000
photos:     nullable, obecne limity
Tematy:     0..3 rekomendowane
visibility: public w MVP
```

Pytanie `private` nie ma sensu, bo nikt nie może na nie odpowiedzieć. `followers-only` można rozważyć później.

Nie używać obecnego `recipe_id` jako „kontekstu pytania” w MVP — dziś ma inną semantykę. Powiązanie pytania z przepisem można dodać osobno w V2.

---

# 6. Publikacja

Dwa dopuszczalne warianty:

## A — rekomendowany
Mały `PublishQuestion`, który korzysta ze wspólnych niższych usług publikacji/media/tags.

## B
Rozszerzyć `PublishPost::handle(... kind, title ...)`.

Wybrać B tylko jeśli sygnatura i walidacja pozostaną czytelne. Nie wolno zrobić z `PublishPost` wielkiego warunkowego kontrolera dwóch produktów.

---

# 7. Trasy

Public:

```php
GET /pytania                 questions.index
GET /pytania/{post}          questions.show
```

Auth:

```php
GET  /pytania/zadaj          questions.create
POST /pytania                questions.store
```

Uwaga na kolejność `/pytania/zadaj` vs `{post}`.

---

# 8. Lista Pytania

Filtry GET:

```text
?widok=najnowsze
?widok=bez-odpowiedzi
?temat=pierogi     # opcjonalnie
```

`bez-odpowiedzi` = brak widocznego komentarza top-level.

Sortowanie:

```text
published_at DESC, id DESC
```

Żadnego score/rankingu.

---

# 9. `TagPublicStats`

Nowa domena/agregat:

```text
App\Domain\Tags\TagPublicStats
```

Dane:

```text
photosCount
contributorsCount
questionsCount
answeredQuestionsCount
latestActivityAt
```

Zasady:
- public only;
- active/dostępni autorzy;
- ready media;
- wspólne definicje z tym, co rzeczywiście widzi gość.

Cache np. 10 minut.

---

# 10. Liczenie „X zdjęć od Y osób”

`photosCount`:
- media `ready`;
- należące do publicznego opublikowanego Post;
- Post ma dany Tag;
- autor spełnia publiczne reguły dostępności.

`contributorsCount`:

```sql
COUNT(DISTINCT posts.author_id)
```

ale tylko dla wpisów z co najmniej jednym zdjęciem wchodzącym do `photosCount`.

Test obowiązkowy:
- 2 zdjęcia od jednej osoby = 2 zdjęcia / 1 osoba;
- kolejne zdjęcie od drugiej = +1 osoba;
- text-only question = nie zwiększa „zdjęć od osób”.

---

# 11. Kolaż Tematu

Zasady query:
1. public;
2. active/dostępny autor;
3. ready media;
4. najnowsze;
5. maks. 1 media od jednego autora;
6. limit 4–5.

Nie sortować po reakcjach/zapisach/followersach.

Dopuszczalna ręczna korekta gospodarza, jeśli aktualny zestaw wygląda źle technicznie.

---

# 12. Rich cards na `/tagi`

Na start bogate karty tylko dla `Tag::promowane()`; A–Z pozostaje dla całego long-tail.

Nie trzeba od razu dodawać `is_place`.

Jeśli później potrzebna większa kontrola nad opisem/prezentacją, rozważyć `tag_presentations` z `tag_id`, `description`, opcjonalną kuracją hero. Nie wdrażać nowej tabeli bez potrzeby.

---

# 13. `TagController::show()`

Dodać parametr:

```text
rodzaj = wszystko | dania | pytania
```

Warunki:
- wszystko → `dish + question`;
- dania → `kind=dish`;
- pytania → `kind=question`.

Domyślnie `wszystko`.

---

# 14. Answers = top-level Comments

Na `question`:
- top-level Comment = **Odpowiedź**;
- reply = dopowiedzenie/rozmowa pod odpowiedzią.

UI:
- główny formularz: `Napisz odpowiedź`;
- nested: `Odpowiedz`.

To nie wymaga nowej tabeli odpowiedzi.

---

# 15. Powiadomienia

Obecny `Comment::notifiableUserId()` już potrafi wskazać autora Post.

Zmienić copy zależnie od `post.kind`:

```text
dish:     Anna skomentowała Twój wpis...
question: Anna odpowiedziała na Twoje pytanie...
```

Nie trzeba zmieniać modelu odbiorcy.

---

# 16. Moderacja

Question jako Post dziedziczy większość istniejącej infrastruktury.

Dodać:
- kolejkę `questions_without_answers`;
- ewentualny sygnał off-topic;
- oznaczenie typu w panelu.

Kolejność kolejki:
1. pierwszy question nowego użytkownika;
2. najstarsze unanswered;
3. promowane Tematy;
4. reszta.

---

# 17. Search

Rozszerzyć indeks/search o `posts.title`.

Wyniki mogą mieć sekcję `Pytania` albo unified cards z badge `Pytanie`.

Zero-result:

```text
/Zadaj pytanie?tytul=<query>
```

Prefill jest sugestią, użytkownik zatwierdza.

---

# 18. SEO / QAPage

Tylko publiczne `kind=question`.

JSON-LD:

```text
QAPage
└── Question
    ├── name
    ├── text
    ├── answerCount
    └── suggestedAnswer[]
        └── Answer
```

Nested replies → `Comment`, nie `Answer`.

Bez `acceptedAnswer`, dopóki nie istnieje realna funkcja „Ta odpowiedź pomogła”/akceptacji.

Unanswered:
- `answerCount=0`;
- zero fałszywych Answer.

---

# 19. Sitemap

Dodać tylko publiczne, opublikowane Questions od dostępnych autorów.

---

# 20. Feature flag

Na rollout:

```env
KUKING_QUESTIONS_ENABLED=false
```

Kolejność:
1. schema;
2. read paths;
3. admin queue;
4. create za flagą;
5. beta;
6. public.

Po stabilizacji flagę usunąć.

---

# 21. Testy obowiązkowe

## DB/domain
- istniejący Post po migracji = dish;
- question wymaga title;
- question public-only w MVP;
- rollback nie traci treści.

## Privacy
- private/followers nie liczą się do public stats;
- kolaż nie pokazuje niepublicznych;
- zbanowany autor nie jest promowany.

## Counts
- zdjęcia/osoby jak opisano wyżej;
- question tekstowy nie fałszuje photo count.

## Q&A
- top-level = answer;
- nested reply nie zwiększa answerCount;
- hidden answer wykluczony;
- unanswered query działa.

## Search/SEO
- QAPage tylko na question;
- no QAPage na dish;
- `answerCount=0` poprawnie;
- nested reply nie jest Answer.

## A11y
- create/answer bez JS;
- 320 px;
- 200% font;
- visible labels;
- focus;
- zachowanie danych po walidacji.

## Performance
- `/tagi` bez N+1;
- stats cached;
- collage nie pobiera oryginałów;
- eager loading Questions.

---

# 22. Sugerowane pliki

```text
database/migrations/
app/Models/Post.php
app/Http/Controllers/QuestionController.php
app/Domain/Posts/Actions/
app/Domain/Tags/TagPublicStats.php
resources/views/pages/questions/
resources/views/components/question-card.blade.php
resources/views/pages/tags/index.blade.php
resources/views/pages/tags/show.blade.php
resources/css/
routes/web.php
app/Http/Controllers/SearchController.php
app/Http/Controllers/SitemapController.php
tests/Feature/
tests/Unit/
docs/brand/BRAND_EXTENDED.md
docs/DECISIONS.md
docs/DATABASE.md
```

---

# 23. Kolejność PR-ów

1. `topics/public-stats`
2. `topics/place-header`
3. `topics/index-places`
4. `questions/schema`
5. `questions/publish-show`
6. `questions/answers-notifications`
7. `questions/index-unanswered`
8. `questions/topics`
9. `questions/search-seo`
10. `questions/moderation-metrics`

Każdy PR deployowalny osobno.
