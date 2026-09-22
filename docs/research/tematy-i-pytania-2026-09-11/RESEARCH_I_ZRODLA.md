# Research i źródła — Kuking: Tematy + Pytania

**Research wykonany:** 11.09.2026

## R01 — Garnek.pl / Forum Kolejowe
**Forumowe fotoforum na Garnek.pl (2010)**  
https://www.forumkolejowe.pl/thread-109.html

Wniosek: konkretna grupa stworzyła własne fotoforum do wspólnego publikowania zdjęć kolejowych; funkcja niosła tożsamość grupy, nie tylko kategoryzację.

## R02 — Samsung Food Communities
https://samsungfood.com/recipe-sharing-app/

Communities organizują ludzi wokół wspólnych food interests, goals, tastes i potrzeb. Wspólne miejsce łączy discovery, publikację i rozmowę.

## R03 — Samsung Food Ask a Question
https://support.samsungfood.com/hc/en-us/articles/18758343292180-Getting-Started-with-Community-Conversations  
https://support.samsungfood.com/hc/en-us/articles/18758725723796-Getting-Started-with-Home-Feed-posting

Mechanika: wybór community, wymagany krótki Question title, opcjonalne szczegóły, możliwość dołączenia zdjęcia/przepisu.

## R04 — Food52 Hotline
https://food52.com/pages/hotline

Wieloletnie miejsce społeczności Food52 do zadawania i odpowiadania na pytania kuchenne. Q&A może być prostą funkcją, bez klasycznego forum.

## R05 — AskCulinary
https://www.reddit.com/r/AskCulinary/comments/qg5y9v/

Skupienie na konkretnych problemach: troubleshooting, technique, equipment, food science; pytania mają być specific, detailed i on-topic.

## R06 — Gotujmy.pl Forum Kulinarne
https://gotujmy.pl/forum/

W 2026 r. nadal widać realne pytania kulinarne i aktywne działy, ale także spam/promocje/off-topic w wielu sekcjach. Potwierdza zarówno potrzebę Q&A, jak i ryzyko szerokiego forum.

## R07 — Seasoned Advice
https://cooking.stackexchange.com/unanswered  
https://cooking.stackexchange.com/questions/tagged/seasoning?tab=Unanswered

Trwałe Q&A wokół konkretnych problemów: baking, bread, equipment, substitutions, food science, fermentation, sauce, temperature itd.

## R08 — Google QAPage
https://developers.google.com/search/docs/appearance/structured-data/qapage

Dokumentacja aktualna 08.09.2026. Model: jedna strona = jedno Question + user-submitted Answers; komentarze do odpowiedzi powinny być `Comment`. Unanswered może mieć `answerCount=0`, ale bez odpowiedzi nie kwalifikuje się do Q&A rich result.

## R09 — Community Identity and User Engagement
Zhang et al. (2017)  
https://ojs.aaai.org/index.php/ICWSM/article/view/14904  
https://pmc.ncbi.nlm.nih.gov/articles/PMC5774974/

Badanie prawie 300 społeczności Reddit. Charakter zbiorowej tożsamości jest systematycznie związany ze wzorcami engagement; wyraziste nisze mogą też tworzyć większy dystans dla nowych osób.

## R10 — What Seniors Value About Online Community
Burmeister (2012)  
https://openjournals.uwaterloo.ca/index.php/JoCI/article/view/3053

W badanej społeczności dla starszych osób najważniejszą z sześciu wartości społecznych było `belonging to a community of peers`.

## R11 — Older adults: sense of belonging
Kim & Katagiri (2026)  
https://www.jstage.jst.go.jp/article/jssp/41/3/41_2024-022/_article/-char/en

Badani 60+. Poczucie przynależności/połączenia z innymi jest ważniejszym mechanizmem niż sama liczba relacji. Dla Kuking: liczniki mają sygnalizować życie, nie być celem.

## R12 — Older adults online social engagement
https://www.tandfonline.com/doi/full/10.1080/1369118X.2020.1804980

Badanie 60+ wskazuje związki określonych aktywności online, m.in. zadawania pytań i oglądania zdjęć innych, z bridging social capital.

## R13 — Reply a dalszy udział nowej osoby
Joyce & Kraut (2006), Predicting Continued Participation in Newsgroups  
https://onlinelibrary.wiley.com/doi/full/10.1111/j.1083-6101.2006.00033.x

2 777 newcomers: ok. 61% dostało odpowiedź; otrzymanie odpowiedzi wiązało się z ok. 12.4 pp wyższym prawdopodobieństwem ponownej publikacji; pytania miały większą szansę otrzymać reply niż wpisy informacyjne/opiniotwórcze.

Nie traktować tych liczb jako benchmarków Kuking — inny kontekst i epoka. Wniosek: response rate i time-to-first-answer są krytycznymi metrykami.

## R14 — Talk to Me, CHI 2006
https://www.cs.cmu.edu/~jaime/ArguelloCHI06.pdf

Próbka 6 172 wiadomości w 8 grupach; brak odpowiedzi i responsiveness były związane z dalszą aktywnością. Pytanie bez odpowiedzi jest potencjalną porażką relacyjną.

## R15 — Knowledge contribution w social Q&A
Jin et al. (2015)  
https://www.sciencedirect.com/science/article/abs/pii/S0378720615000737

Community identity, social learning i peer recognition były związane z knowledge-contribution behavior. Wniosek: Q&A warto osadzić w rozpoznawalnych Tematach.

---

# Źródła repozytorium użyte do decyzji technicznych

Aktualne `main` sprawdzone 11.09.2026:

- `app/Http/Controllers/TagController.php`
- `app/Models/Tag.php`
- `app/Domain/Tags/Actions/ResolveTagsForPost.php`
- `resources/views/pages/tags/index.blade.php`
- `resources/views/pages/tags/show.blade.php`
- `app/Models/Post.php`
- `app/Domain/Posts/Actions/PublishPost.php`
- `resources/views/pages/posts/create.blade.php`
- `app/Models/Comment.php`
- `app/Http/Controllers/PostController.php`
- `docs/brand/BRAND_EXTENDED.md`
- `docs/product/RETENTION_LOOPS.md`
- `docs/product/PROSTOTA_JAK_GARNEK.md`

Najważniejsze ustalenia repo:
1. `/tagi` i `/tag/{slug}` już istnieją.
2. Kod sam opisuje tag z własną stroną jako **miejsce**, a nie etykietę.
3. `Tag` jest otwartą taksonomią użytkowników.
4. `Post` może już istnieć bez zdjęcia, jeśli ma tekst.
5. `Comment` ma top-level + replies i naturalnie pasuje do Answers + nested Comments.
6. Z tego powodu `Post.kind=question` ma mniejszą powierzchnię regresji niż osobny model `Question`.
