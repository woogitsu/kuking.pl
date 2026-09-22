# Tymczasowe uploady Livewire — #608

Wspólne `appEnv` w `.railway/railway.ts` jawnie ustawia
`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2`. Kreator nie zależy już od wartości
`FILESYSTEM_DISK`. Dysk `r2` wskazuje bucket oryginałów; pliki przed
przetworzeniem mogą zawierać EXIF/GPS i nie mogą trafić do publicznego magazynu.

To utrwalenie dotychczasowego wyboru wynikającego z fallbacku, bez zmiany
interfejsu, migracji ani przenoszenia plików. Własny sterownik `r2` nie jest
dla Livewire sterownikiem `s3`; zmiana nie włącza bezpośredniego uploadu S3.

## Sprawdzenie lokalne

`DyskTymczasowyLivewireTest` odczytuje wartość z rzeczywistego `appEnv`,
ładuje konfigurację Livewire i wywołuje resolver pakietu z domyślnym dyskiem
`local`. Wyłącza testowy wybór `tmp-for-tests` przez środowisko aplikacji
`production` w osobnym procesie. Nie łączy się z bazą ani R2.

Wynik: 1 test / 10 asercji; razem z istniejącymi testami konfiguracji dysków, rozdziału magazynów i odnośników dokumentacji: 18 testów / 91 asercji. Pint przeszedł. Dwie fizyczne kontrole ujemne rzeczywistego
`railway.ts`, także przy odziedziczonym `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2`, oblały test: wybór `local` oraz usunięcie przypisania.
Po każdej przywrócono kopię spoza repo i zweryfikowano MD5 oraz mtime;
końcowy przebieg ponownie przeszedł. Dowody robocze są w
`docs/infra/evidence/upload608/`: wyniki, logi i dane przywrócenia.

## Granice i wdrożenie

Test potwierdza wybór dysku, nie prywatność rzeczywistego bucketu ani
działanie uploadu między dwiema replikami. Te wymagają odbioru infrastruktury.
Zmiana pliku IaC nie dowodzi zmiany zmiennych Railway. Wdrożenie wymaga
kontrolowanego planu oraz odbioru zgodnie z #595; nie wykonano apply.
Niezależny końcowy review bez uwag blokujących. Wskazaną podczas pierwszego przeglądu możliwość przesłonięcia wartości przez odziedziczoną zmienną środowiskową usunięto i ponowiono obie kontrole ujemne. Pełny hook i CI pozostają do wykonania.

Wycofanie przypisania przy `FILESYSTEM_DISK=r2` przywraca dotychczasowy
fallback. Nie usuwać ani nie przenosić istniejących plików tymczasowych.

