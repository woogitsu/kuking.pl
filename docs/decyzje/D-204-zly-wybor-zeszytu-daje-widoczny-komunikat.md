## D-204 · Zły wybór zeszytu daje widoczny komunikat bez ujawniania własności

**Data:** 12 września 2026 · PR #483 · Status: **obowiązuje**

### Decyzja

Oba endpointy zapisu sprawdzają UUID przed zapytaniem do PostgreSQL oraz istnienie
zeszytu w obrębie właściciela. Cudzy i nieistniejący zeszyt mają ten sam komunikat.
Autoryzacja treści pozostaje pierwsza, a pobranie modelu nadal ogranicza właściciel.
Błąd jest dostępny po powrocie na strumień, nie tylko w sesji walidacji.
Puste pole lub brak wyboru zachowuje zapis do zeszytu domyślnego.

### Dowód

Czternaście przypadków HTTP, rzeczywiste ciasteczko sesji przy powrocie oraz
oddzielne kontrole usunięcia UUID, własności i komunikatu.
Usunięcie zeszytu pomiędzy walidacją a pobraniem może nadal dać 404; ta zmiana
nie przebudowuje transakcji.

📄 `app/Http/Controllers/CollectionController.php` ·
`tests/Feature/WyborZeszytuMaWalidacjeTest.php` ·
`docs/product/WALIDACJA_WYBORU_ZESZYTU.md`
