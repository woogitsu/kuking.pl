"""Zmienne Railwaya per rola (#1013, #1014, #193).

Strażnik czyta `.railway/railway.ts` statycznie, więc tylko mutacja dowodzi,
że parser widzi bloki usług, a nie pusty zbiór: sekret OAuth dopisany
workerowi, klucz modelu zabrany workerowi i adres alarmów zabrany
schedulerowi — każda z mutacji ma zapalić test.

- Scheduler budujący mailer w digeście musi mieć klucze EmailLabs (przegląd #1013).
- Klucz modelu i adres alarmu tylko na produkcji — środowisko PR jest kopią
  bazowego, więc bez warunku preview dostałby wartości produkcji (#1014).
- Serwis `kopia-bazy` ma zamkniętą listę zmiennych (#193): spread zestawu
  aplikacji dałby procesowi ze zrzutem bazy APP_KEY i klucze zdjęć/poczty.
"""

from kontrole_negatywne._narzedzia import Kontrola, replace_once


RAILWAY_IAC = ".railway/railway.ts"
ZMIENNE_ROL_TEST = "ZmienneRailwayaPerRolaTest"
HARMONOGRAM_POCZTA_TEST = "harmonogram_budujacy_mailer_ma_klucze_poczty"
TYLKO_PRODUKCJA_TEST = "klucz_modelu_i_adres_alarmu_tylko_na_produkcji"
KOPIA_BEZ_SPREADU_TEST = "serwis_kopii_nie_rozwija_zadnego_zestawu_aplikacji"
KOPIA_DB_URL = "      DB_URL: db.env.DATABASE_URL,\n"

KONTROLE_DODATNIE = [ZMIENNE_ROL_TEST]

KONTROLE = [
    Kontrola("Sekret OAuth w workerze", RAILWAY_IAC, ZMIENNE_ROL_TEST,
             lambda s: replace_once(s, 'env: { ...workerEnv, APP_ROLE: "worker" },', 'env: { ...workerEnv, GOOGLE_CLIENT_SECRET: ctx.shared.GOOGLE_CLIENT_SECRET, APP_ROLE: "worker" },')),
    Kontrola("Worker bez klucza moderacji modelem", RAILWAY_IAC, ZMIENNE_ROL_TEST,
             lambda s: replace_once(s, "    ...modelEnv,\n", "")),
    Kontrola("Scheduler bez adresu alarmów moderacji", RAILWAY_IAC, ZMIENNE_ROL_TEST,
             lambda s: replace_once(s, "const schedulerEnv = { ...appEnv, ...pocztaEnv, ...alarmModeratoraEnv, ...kopieOdczytEnv, ", "const schedulerEnv = { ...appEnv, ...pocztaEnv, ...kopieOdczytEnv, ")),
    # Filtr na samą metodę strażnika, nie całą klasę: ta sama mutacja zapala
    # też macierz, a kontrola ma dowieść, że parser `routes/console.php`
    # i komend WIDZI digest wołający `Mail::` z procesu schedulera.
    # Web czyta adres synchronicznie w `AlarmujOPilnymZgloszeniu` (D-236).
    Kontrola("Web bez adresu alarmów moderacji", RAILWAY_IAC, ZMIENNE_ROL_TEST,
             lambda s: replace_once(s, "...czyszczenieCdnEnv, ...alarmModeratoraEnv };", "...czyszczenieCdnEnv };")),
    Kontrola("Klucz modelu bez warunku produkcji", RAILWAY_IAC, TYLKO_PRODUKCJA_TEST,
             lambda s: replace_once(s, 'OPENAI_MODERATION_KEY: isProduction ? ctx.shared.OPENAI_MODERATION_KEY : "",', "OPENAI_MODERATION_KEY: ctx.shared.OPENAI_MODERATION_KEY,")),
    Kontrola("Adres alarmu bez warunku produkcji", RAILWAY_IAC, TYLKO_PRODUKCJA_TEST,
             lambda s: replace_once(s, 'KUKING_MODEL_ALARM_EMAIL: isProduction ? ctx.shared.KUKING_MODEL_ALARM_EMAIL : "",', "KUKING_MODEL_ALARM_EMAIL: ctx.shared.KUKING_MODEL_ALARM_EMAIL,")),
    Kontrola("Scheduler bez kluczy poczty przy digeście", RAILWAY_IAC, HARMONOGRAM_POCZTA_TEST,
             lambda s: replace_once(s, "const schedulerEnv = { ...appEnv, ...pocztaEnv, ", "const schedulerEnv = { ...appEnv, ")),
    Kontrola("Kopia bazy ze spreadem zestawu aplikacji", RAILWAY_IAC, KOPIA_BEZ_SPREADU_TEST,
             lambda s: replace_once(s, KOPIA_DB_URL, "      ...schedulerEnv,\n" + KOPIA_DB_URL)),
]
