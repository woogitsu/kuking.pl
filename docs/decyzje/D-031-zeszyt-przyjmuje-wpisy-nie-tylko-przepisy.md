## D-031 · Zeszyt przyjmuje wpisy, nie tylko przepisy

**Data:** 8 września 2026 · **Decyzja właściciela** (potwierdzenie stanu
wdrożonego 6 września) · Status: **obowiązuje**

Zeszyt powstał na przepisy. Od 6 września przyjmuje też cudze wpisy: migracja
`2026_09_06_150000_collection_items_accept_posts`, trasa
`collections.save-post`, `CollectionController::savePost()`, akcja
`App\Domain\Collections\Actions\SavePostToCollection`, przycisk na karcie
wpisu, test `ZeszytPrzyjmujeWpisyTest`.

Pytanie postawione właścicielowi brzmiało: **zostaje czy cofamy?** — bo
funkcja weszła szybciej, niż powstał wpis, który ją uzasadnia, a dokumenty
projektowe dalej opisywały ją jako „nową funkcję produktową wymagającą
decyzji". Odpowiedź: **zostaje**.

**Dlaczego to nie jest to samo, co zapisanie przepisu.** Zapisany przepis
znaczy „chcę to kiedyś ugotować". Zapisany wpis znaczy „chcę kiedyś zrobić coś
TAKIEGO" — przy wpisie zwykle nie ma żadnego przepisu, jest zdjęcie i kilka
słów. To są dwie różne potrzeby i dlatego zawartość zeszytu pokazuje się
w dwóch grupach, nie wymieszana.

**Co za tym poszło w tej samej zmianie:** teksty, które dalej obiecywały same
przepisy — nagłówek Zeszytu i jego pusty stan
(`resources/views/pages/collections/index.blade.php`) oraz tabela porównawcza
w `docs/design/STAN_WDROZENIA_KITU.md`. Obietnica węższa niż produkt jest
akurat tym rodzajem nieprawdy, którego nikt nie zgłosi — człowiek po prostu
nie spróbuje.

**Zmiana wymaga:** zmierzonego dowodu, że dwie grupy w jednym zeszycie mylą
ludzi bardziej, niż pomaga im samo zapisywanie wpisów.

📄 `app/Domain/Collections/Actions/SavePostToCollection.php` ·
`tests/Feature/ZeszytPrzyjmujeWpisyTest.php` · `docs/design/STAN_WDROZENIA_KITU.md`
