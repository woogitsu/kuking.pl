#!/usr/bin/env bash
# =============================================================================
#  Kuking.pl — decyzja „czy ten stan wdrożenia to alarm” (job `alarm_bez_sukcesu`
#  w .github/workflows/deploy.yml, #611 etap 6)
# =============================================================================
#  Problem: job `verify` reaguje tylko na `deployment_status: success`. Wdrożenie,
#  które kończy się `failure`, `error` albo `inactive` bez ani jednego sukcesu
#  (Railway zbudował, ale nie wstał; albo wdrożenie porzucono w połowie), nie
#  zapala niczego: brak zielonego testu dymnego wygląda tak samo jak brak
#  wdrożenia. Ten skrypt jest sygnałem, który to odróżnia.
#
#  NIE ŁĄCZY SIĘ Z RAILWAY ANI Z GITHUBEM — dostaje dane i tylko decyduje.
#  Historię statusów wdrożenia pobiera krok workflow (`gh api`, GitHub) i
#  zapisuje do pliku; dzięki temu skrypt da się przetestować tabelą przypadków
#  (tests/skrypty/stan-wdrozenia.sh) bez sieci.
#
#  WEJŚCIE (zmienne środowiska):
#    STAN          — `deployment_status.state` bieżącego zdarzenia;
#    HISTORIA_PLIK — plik z historią statusów TEGO wdrożenia, jeden wiersz na
#                    status: `<created_at ISO 8601 UTC> <state>`, w dowolnej
#                    kolejności (bieżący status też może być na liście);
#    STAN_CZAS     — opcjonalnie `deployment_status.created_at` bieżącego
#                    zdarzenia; gdy podany, liczą się tylko statusy NIE
#                    późniejsze (późny sukces nie wybiela wcześniejszej porażki);
#    SRODOWISKO, SHA — opcjonalnie, tylko do komunikatu.
#
#  REGUŁY:
#    - stan inny niż inactive/failure/error        -> 0, nie dotyczy;
#    - failure albo error                          -> 1, ZAWSZE alarm: GitHub
#      dostał od Railway jawną wiadomość o porażce (także po wcześniejszym
#      sukcesie — wtedy wersja, która stała, przestała działać);
#    - inactive po wcześniejszym `success`         -> 0, NIE alarm: to zwykłe
#      zastąpienie wdrożenia nowszym;
#    - inactive bez `success`, ale po `in_progress` -> 1, alarm: wdrożenie
#      ruszyło i nigdy nie stało się zdrowe;
#    - inactive bez `success` i bez `in_progress`  -> 0, tylko ostrzeżenie:
#      wdrożenie zastąpiono, zanim ruszyło (kolejka), nic nie padło;
#    - brak/nieczytelna historia albo śmieciowy STAN -> 2, czerwono: nie
#      zgadujemy, bo cichy sukces jest gorszy od fałszywego alarmu.
#  Wyjście: 0 = bez alarmu, 1 = alarm (czerwony job), 2 = błąd wejścia.
# =============================================================================
set -euo pipefail

stan="${STAN:-}"
historia="${HISTORIA_PLIK:-}"
czas="${STAN_CZAS:-}"
srodowisko="${SRODOWISKO:-nieznane}"
sha="${SHA:-nieznany}"

# Wartości ze zdarzenia to dane: przed wypisaniem tylko bezpieczny kształt.
[[ "$srodowisko" =~ ^[A-Za-z0-9][A-Za-z0-9\ ._/-]{0,99}$ ]] || srodowisko="(nazwa o niedozwolonym kształcie)"
[[ "$sha" =~ ^[0-9a-f]{7,40}$ ]] || sha="(nieprawidłowy SHA)"
sha="${sha:0:12}"

case "$stan" in
  success|failure|error|inactive|in_progress|queued|pending) ;;
  *)
    echo "::error title=Niedozwolony stan wdrożenia::deployment_status.state spoza listy stanów GitHuba (długość: ${#stan}). Wartości nie wypisuję."
    exit 2
    ;;
esac

case "$stan" in
  failure|error|inactive) ;;
  *)
    echo "Stan '${stan}' nie jest terminalnym stanem bez sukcesu — ten job go nie ocenia."
    exit 0
    ;;
esac

if [ "$stan" = failure ] || [ "$stan" = error ]; then
  echo "::error title=Wdrożenie zakończone porażką::Środowisko ${srodowisko}, wersja ${sha}: Railway zgłosił stan '${stan}'. Nowa wersja nie działa. Sprawdź logi wdrożenia w panelu Railway i, jeśli trzeba, wznów poprzednie wdrożenie (instrukcja: deploy.yml -> operate -> instrukcja-cofniecia)."
  exit 1
fi

# Dalej: inactive. Potrzebna historia — bez niej nie rozstrzygniemy.
if [ -z "$historia" ] || [ ! -r "$historia" ]; then
  echo "::error title=Brak historii wdrożenia::Stan 'inactive' wymaga historii statusów tego wdrożenia, a jej nie ma (środowisko ${srodowisko}, wersja ${sha}). Nie zgaduję, czy to zwykłe zastąpienie, czy porażka — sprawdź wdrożenie ręcznie w panelu Railway."
  exit 2
fi

byl_sukces=0
byl_start=0
policzone=0
while read -r ts st _; do
  [ -n "${ts:-}" ] || continue
  if ! [[ "$ts" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9:.]+Z$ ]] || [ -z "${st:-}" ]; then
    echo "::error title=Nieczytelna historia wdrożenia::Wiersz historii ma zły kształt (oczekiwano '<czas ISO UTC> <stan>'). Sprawdź krok pobierania historii statusów."
    exit 2
  fi
  # ISO 8601 UTC porównuje się jak tekst.
  if [ -n "$czas" ] && [[ "$ts" > "$czas" ]]; then
    continue
  fi
  policzone=$((policzone + 1))
  case "$st" in
    success) byl_sukces=1 ;;
    in_progress) byl_start=1 ;;
  esac
done < "$historia"

if [ "$policzone" -eq 0 ]; then
  echo "::error title=Pusta historia wdrożenia::Lista statusów jest pusta, choć przyszło zdarzenie 'inactive' (środowisko ${srodowisko}, wersja ${sha}). Nie rozstrzygam, czy to porażka — sprawdź wdrożenie w panelu Railway."
  exit 2
fi

if [ "$byl_sukces" -eq 1 ]; then
  echo "Wdrożenie ${sha} (${srodowisko}) było wcześniej udane, teraz 'inactive' — zastąpione nowszym. Bez alarmu."
  exit 0
fi

if [ "$byl_start" -eq 1 ]; then
  echo "::error title=Wdrożenie zniknęło bez sukcesu::Środowisko ${srodowisko}, wersja ${sha}: wdrożenie ruszyło (in_progress), ale zakończyło się stanem 'inactive' i ani razu nie było udane. Ta wersja nie działa na serwerze. Sprawdź w panelu Railway, jaka wersja stoi teraz, i zajrzyj do logów budowania."
  exit 1
fi

echo "::warning title=Wdrożenie zastąpione przed startem::Środowisko ${srodowisko}, wersja ${sha}: 'inactive' bez wcześniejszego uruchomienia (zastąpione w kolejce). Nic nie padło; to nie jest alarm."
exit 0
