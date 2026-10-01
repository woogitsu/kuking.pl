## D-003 · Własny model `media` zamiast Spatie MediaLibrary

**Data:** wrzesień 2026 · Status: **obowiązuje**

MediaLibrary nie obsługuje cyklu życia z moderacją, którego potrzebujemy:
`pending → processing → ready | rejected`, checksuma, hash percepcyjny,
re-enkodowanie zdejmujące EXIF/GPS przed pokazaniem zdjęcia komukolwiek.

**Zmiana wymaga:** wykazania, że pakiet obsługuje ten cykl bez obchodzenia go
własnym kodem.

📄 `research/PUBLIC_REPOS.md` · `app/Models/Media.php`
