# Struktura katalogów app/

Przeniesione z `AGENTS.md` §4 bez zmian treści; zasada w skrócie i odnośnik do tego pliku zostają w `AGENTS.md`.

```text
app/
├── Domain/            # przypadki użycia i reguły domenowe
│   ├── Collections/
│   ├── Comments/
│   ├── Feed/
│   ├── Media/
│   ├── Moderation/
│   ├── Notifications/
│   ├── Posts/
│   ├── Recipes/
│   ├── Search/
│   └── Social/
├── Http/Controllers/  # cienkie: walidacja → akcja → widok
├── Jobs/              # zadania w tle
├── Models/            # Eloquent
└── Policies/          # autoryzacja
```
