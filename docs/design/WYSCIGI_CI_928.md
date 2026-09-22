# Wyścigi — wyjaśnienie czerwonego zadania CI #928

Sprawdzone 13 września 2026 przez niezależnego subagenta, na aktualnym API
GitHub. Historyczny [job 103702460527](https://github.com/woogitsu/kuking.pl/actions/runs/34749126034/job/103702460527)
dotyczył main `dbd1cb920f872233f8cc8f240f94273f26f634e8`.

Adnotacja check-runa `103702460527/annotations`:

> The job was not started because it repeatedly failed to be acquired (5 attempts).

API pokazuje `runner_id=0`, pustą nazwę runnera i `steps=[]`. Zadanie nie
wystartowało: nie jest to negatywny wynik testu aplikacji. Log nadal zwraca
Azure 404 BlobNotFound. Szczegółowa przyczyna nieudanego przejęcia zadania
(GitHub, runner, sieć) pozostaje nieustalona; adnotacja tego nie rozstrzyga.

## Dalsze dowody

- Powtórzenie #928, job `103704702033`: log zawiera 5 testów / 44 asercje,
  sukces, 1,77 s.
- Przebiegi #929–#948: 14 zadań wyścigów zakończonych sukcesem i 6 pominiętych,
  bez czerwonego. Pominięcia: #933, #934, #937, #938, #943, #944.
- #948, job `103759588610`: 5 testów / 44 asercje, sukces, 1,62 s.
- Źródła `tests/Dwa/` i `scripts/testy-dwa-polaczenia.sh` nie zmieniły się
  między `dbd1cb9` a `6ea99a5`, na którym wykonano porównanie.

D-105 pozostaje w mocy. Nie zmieniono testów, progów ani limitów czasu.
Nie uruchamiano ponowień w ramach diagnozy. 20 przebiegów zawierających
6 pominięć nie stanowi 20 wykonanych pomiarów stabilności.
