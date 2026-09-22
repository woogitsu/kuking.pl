# Stary czytnik DTO

`TrescDigestu-ac5ff9d.php.fixture` to dokładne bajty wyniku:

```text
git show ac5ff9d:app/Domain/Digest/TrescDigestu.php
```

SHA-256: `d6b8412d72f92d8219d37001391099ae3932b914e763d0eb1edfefa01a858745`.

Test ładuje tę klasę w osobnym procesie PHP przed autoloadem obecnego DTO. Nie aktualizować jej razem z implementacją: ma wykrywać niezgodny zapis podczas rolling deploy. Plik zachowuje historyczny kod, nie zawiera danych kont ani payloadów.
