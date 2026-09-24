#!/usr/bin/env bash
# Mutacje wyłącznie w izolowanym runtime, nigdy w drzewie roboczym.
set -euo pipefail
cd /home/mateusz/flota/gpt-kontakt-panel-run
export PATH="/opt/kuking-php-8.4-avif/bin:$HOME/.nvm/versions/node/v24.19.0/bin:$PATH"
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439
export DB_DATABASE=kuking_flota_gpt-kontakt-panel DB_USERNAME=kuking DB_PASSWORD=kuking
control() {
  local name="$1" file="$2" before="$3" after="$4" test="$5"
  bash scripts/kontrola-ujemna.sh --nazwa "$name" --plik "$file" \
    --zamien "$before" --na "$after" --oczekuj 'Failed asserting|Session has unexpected errors|The following errors occurred' \
    --json "storage/kontakt-$name.json" -- php artisan test --filter "$test"
}
control 843 app/Models/ContactMessage.php \
  'if ($status !== $this->status) {' 'if (true) {' \
  test_edycja_notatki_zostawia_pierwotnego_autora_i_date_zalatwienia
control 846 app/Domain/Contact/Actions/UpdateContactMessage.php \
  'if ($current->version !== $version) {' 'if (false) {' \
  test_stara_karta_nie_nadpisuje_nowszego_stanu_i_zachowuje_tekst
control 839 app/Domain/Contact/Actions/WyslijOdpowiedz.php \
  'if ($claimed === 0) {' 'if (false) {' \
  test_ponowiony_post_to_jedna_odpowiedz_i_jeden_list
control audit app/Domain/Contact/Actions/WyslijOdpowiedz.php \
  'DB::transaction(function () use ($reply, $message, $ip): void {' \
  'call_user_func(function () use ($reply, $message, $ip): void {' \
  test_awaria_audytu_cofa_znacznik_i_wejscie_dokancza_bez_nowego_listu
control 840 app/Domain/Contact/Actions/WyslijOdpowiedz.php \
  'if ($e instanceof OdmowaEmailLabs && $e->isConfirmedRejection()) {' \
  'if ($e instanceof OdmowaEmailLabs) {' \
  test_zerwane_polaczenie_nie_udaje_pewnej_odmowy
control 845 app/Http/Controllers/Admin/WiadomosciController.php \
  '->withInput($request->only(['"'"'odpowiedz'"'"', '"'"'reply_key'"'"']))' \
  '->withInput([])' test_zapis_stanu_zachowuje_odpowiedz_ale_nie_wysyla_listu
# Ten sam błąd formularza mierzony także przez prawdziwą przeglądarkę.
npm run build
bash scripts/kontrola-ujemna.sh --nazwa '845-browser' \
  --plik app/Http/Controllers/Admin/WiadomosciController.php \
  --zamien '->withInput($request->only(['"'"'odpowiedz'"'"', '"'"'reply_key'"'"']))' \
  --na '->withInput([])' --oczekuj 'FAIL: odpowiedz-przy-zapisie; AssertionError' \
  --json storage/kontakt-845-browser.json -- bash scripts/kontakt-browser-local.sh
