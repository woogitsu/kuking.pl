# Propozycja: typowy rzeczywisty czas przygotowania z wykonań (#2067)

Status: **odblokowane i zbudowane 1 października 2026** (wiersz #2067 w D-333;
reguły wg rekomendacji poniżej, bez flagi z punktu 8). Dalej tekst propozycji
z chwili decyzji.

## Stan faktyczny

- Dane **już istnieją**: `cooked_events.actual_minutes integer NULL`
  (CHECK `>= 0`, w formularzu „Ugotowałem” max 10080). Pole jest opcjonalne
  i widoczne na formularzu (`pages/cooked/create`). Nowe pole w formularzu
  **nie jest potrzebne**.
- Widoczność wykonań: `CookedEvent::scopeWidoczneDla` (blokady w obie strony,
  status konta kucharza). Agregat musi iść tym samym zakresem.
- **Blokada decyzji:** #2067 stoi na liście „V2, ale nie teraz”
  (`docs/FEATURES.md`). D-331 odblokowała pięć innych pozycji, a #2067 wprost
  zostawiła zakazaną „bez nowej decyzji”. D-333 jej nie zmienia. Stąd brak
  implementacji.

## Proponowane rozstrzygnięcia (do akceptacji lub korekty)

1. **Miejsce:** jedna linia na stronie przepisu obok czasu autora, nie
   w galerii. Tekst: „Autor podaje: 45 min. Gotujący zwykle potrzebują:
   około 60 min (na podstawie 7 wykonań).” Dwa zdania, dwa źródła, nigdy
   w jednym zdaniu ani w jednej liczbie.
2. **Miara:** mediana (`percentile_cont(0.5)`), zaokrąglona do 5 minut; do
   tego zakres „zwykle od X do Y” z 25. i 75. percentyla tylko przy
   >= 10 wykonaniach. Bez średniej.
3. **Próg:** wynik dopiero od **5 różnych osób** z podanym czasem. Wykonania
   jednej osoby liczą się jako jedno (jej mediana), więc nikt sam nie
   zbuduje statystyki. Poniżej progu: nic nie pokazujemy i nie piszemy
   „za mało danych” (żeby nie wyglądało na usterkę).
4. **Zakres danych:** tylko wykonania z `actual_minutes > 0`, widoczne dla
   bieżącego odbiorcy (`widoczneDla`); gość widzi to, co gość widzi w galerii.
   Wartości > 1440 min (doba) odrzucone z agregatu jako prawdopodobne
   pomyłki (np. czas z odleżakowaniem); decyzja do potwierdzenia.
5. **Obliczenie:** jedno zapytanie agregujące w bazie (CTE: mediana na osobę,
   potem mediana median), klasa w `app/Domain/Recipes` (np.
   `TypowyCzasPrzepisu`), kontroler tylko przekazuje wynik; test kosztu
   zapytania (`*KosztTest`: stała liczba zapytań niezależna od liczby
   wykonań). Bez migracji; ewentualny indeks częściowy
   `(recipe_id) WHERE actual_minutes > 0` dopiero po pomiarze.
6. **UX 50+:** zwykły tekst >= 18 px, bez wykresu, bez ikon, bez hovera;
   słowo „zwykle/około”, bez obietnicy. Bez wskaźnika „szybciej niż autor”.
7. **Prywatność:** agregat nie ujawnia, kto podał czas; nie zmienia
   eksportu danych ani API (opcjonalnie później pole w API przepisu).
8. **Wyłącznik:** flaga konfiguracyjna `kuking.typowy_czas_wlaczony`,
   domyślnie wyłączona do pierwszego testu z osobami 50+ (jak #1902).

## Pytania do właściciela

1. Czy odblokować #2067 (nowy wpis w DECISIONS.md, przeniesienie z listy
   „V2, ale nie teraz”)?
2. Próg 5 różnych osób — ok? Czy 3?
3. Pokazywać sam „około N min”, czy też zakres od 10 wykonań?
4. Odcinać wartości > 24 h z agregatu?
5. Za flagą (do testu 50+), czy od razu włączone?
