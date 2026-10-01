## D-133 · `$this->fail()` nie stoi wewnątrz `try` w teście łapiącym odmowę

**Data:** 11 września 2026 · Status: **obowiązuje**

`PHPUnit\Framework\AssertionFailedError` dziedziczy po `RuntimeException`. Test
napisany tak:

```php
try {
    $this->migracja()->down();
    $this->fail('Cofnięcie przeszło.');
} catch (RuntimeException) {
    // ...
}
```

**łapie własne `fail()` we własnym `catch`.** Przy teście wąskości, gdzie `catch`
jest z natury pusty, taki test byłby zielony także wtedy, gdyby strażnika w ogóle
nie było.

Wzorzec: odłóż wyjątek do zmiennej, oceń **poza** blokiem.

Znalezione przez agenta w cudzym pliku wzorcowym (`CofniecieDziennikaZgodOdmawiaTest`),
gdzie ratowały to asercje w `catch` — czyli było bezpieczne **przez przypadek, nie
z konstrukcji**.
