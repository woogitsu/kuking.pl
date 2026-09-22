# Kuking — checklist wdrożenia copy

## P0 — poprawność informacji

- [ ] `errors/419.blade.php`: rozdzielić pełne i częściowe odzyskanie formularza.
- [ ] `recipe-wizard.blade.php`: usunąć „To zostaje w rodzinie”.
- [ ] `recipe-wizard.blade.php`: usunąć „tak zobaczą to inni” z podglądu prywatnego.
- [ ] `recipe-wizard.blade.php`: wybrać jeden model autosave / ręczny szkic.
- [ ] `register.blade.php`: zweryfikować faktyczne zastosowania adresu e-mail.
- [ ] Usunąć nieudokumentowane „najczęściej czytana część”.
- [ ] Zweryfikować obietnicę „Jutro będzie tu ktoś inny”.
- [ ] Usunąć lub zmierzyć „zajmie minutę”.
- [ ] Zmienić przykład hasła.

## P1 — formularze i auth

- [ ] Rejestracja: skrócić intro i helpy.
- [ ] Logowanie: „Zaloguj się bez hasła”.
- [ ] Link logowania: bez „zielonego przycisku”.
- [ ] Reset hasła: bardziej konkretne CTA.
- [ ] Security: neutralne przykłady urządzeń.

## P1 — onboarding

- [ ] Jedna informacja o opcjonalności na początku.
- [ ] Usuń „Jedno i drugie jest w porządku”.
- [ ] Usuń wyjaśnianie intencji projektu.
- [ ] Skróć puste stany.

## P1 — publikacja

- [ ] Post create: usuń `To wszystko`.
- [ ] EXIF/GPS: język użytkowy.
- [ ] Recipe wizard: skrócić helpy.
- [ ] Domenowe akcje `Usuń składnik` / `Usuń krok`.
- [ ] Walidacja oparta na limitach, nie ocenach.

## P2 — marka

- [ ] Landing: CTA `Załóż darmowe konto`.
- [ ] Landing: usunąć `Gotujemy po swojemu` albo wymienić.
- [ ] Landing: zmniejszyć liczbę kontrastów `nie X, tylko Y`.
- [ ] About: konkurenci/historyczny kontekst przesunąć niżej.
- [ ] Help: usunąć ocenianie „najważniejsze/najmilsze”.
- [ ] Contact: usunąć powtórzenia `człowiek/po ludzku`.
- [ ] Discover: `chronologicznie, bez rankingu`.
- [ ] Feed: `Od obserwowanych`.

## P2 — komunikaty systemowe

- [ ] 404 skrócić.
- [ ] Powiadomienia: poprawić `ugotowane z Twojego przepisu`.
- [ ] Powiadomienie pierwszego wpisu: usunąć nieudokumentowane `zwykle bywa ostatnim`.
- [ ] E-mail logowania: skrócić i usunąć instrukcje zależne od wyglądu.

## QA końcowe

- [ ] Brak niepotrzebnych pytań retorycznych poza głównym sloganem.
- [ ] Brak obietnic czasu bez danych.
- [ ] Brak absolutów `nic/zawsze` bez technicznej gwarancji.
- [ ] Brak instrukcji opartych na kolorze/położeniu.
- [ ] Brak „po ludzku” jako pustego zapewnienia.
- [ ] Terminologia `Kuking`, `KuKing.pl`, `kuKING` zgodna ze style guide.
- [ ] `Ugotowałem` używane konsekwentnie jako nazwa mechaniki.
- [ ] Pola opcjonalne oznaczone krótko.
- [ ] Błędy mówią jak naprawić problem.
- [ ] Teksty prawne po osobnym review.
