## D-192 · Próba odtworzenia kopii kończy się liczbami i nie dowodzi, że kopia istnieje

**Data:** 12 września 2026 · PR #455 · issue #193 · Status: **obowiązuje**

### Czego brakowało

Liczba kopii produkcyjnej bazy wynosi **zero** i z repozytorium zmienić się nie może.
Brakowało czego innego: **dowodu, że z kopii da się mieć bazę z powrotem**. Sygnał
„dawno nie było kopii" istniał i miał testy; ćwiczenie odtworzenia było rozpisane na
siedem komend do przepisania z dokumentu — czyli było ćwiczeniem, którego nikt nie zrobi.

### Decyzja

`scripts/proba-odtworzenia.sh --petla-lokalna` robi całość **jedną komendą**: kopia tym
samym skryptem, którym robi się kopię naprawdę (nie zrzutem zrobionym obok, innymi
flagami) → odtworzenie do świeżej bazy → porównanie liczby wierszy w **każdej** tabeli →
`migrate:status` na odtworzonej bazie.

Dwa kroki są nowe, bo dwa pytania zostawały bez odpowiedzi:

- **wszystkie tabele, nie cztery wybrane z nazwy.** Zrzut, który zgubił piątą,
  przechodził bez ostrzeżenia — bo o piątą nikt nie pytał.
- **`migrate:status`.** Komplet wierszy w schemacie sprzed trzech migracji to nie jest
  działająca baza.

Przebieg kończy się **liczbami, nie ptaszkiem**. Zmierzone 12.09.2026: 50 tabel po obu
stronach, 50 porównanych co do jednego wiersza, 159 wierszy, 75 migracji wykonanych,
0 czekających, odtworzenie 1 s.

### Czego to nie dowodzi

Że kuking.pl ma kopię. **Nie ma.** Produkcji ten skrypt nie dotyka w żadnym trybie
i pilnują tego dwa bezpieczniki. Zielony przebieg znaczy „mechanizm kopii i odtworzenia
działa", nie „dane są bezpieczne". Pierwsza prawdziwa kopia produkcyjna i ćwiczenie
odtworzenia zostają po stronie właściciela i są bramką alfy.

### Przy okazji złapana pułapka 1

Pierwsza wersja kroku `migrate:status` meldowała migrację czekającą na bazie, w której
wszystkie były wykonane: `grep -i 'Pending'` trafiał w **nazwę pliku**
`create_pending_email_changes_table`. Ta pomyłka wypadła w stronę fałszywej **czerwieni**
— gdyby wypadła w drugą, nikt by jej nie zauważył.

📄 `scripts/proba-odtworzenia.sh` · `tests/skrypty/proba-odtworzenia.sh` ·
`PetlaOdtworzeniaJestJednaKomendaTest` · `docs/PULAPKI_TESTOW.md`
