const { AppShell, Section, Avatar, Badge, Button, ButtonRow, Chip, ChipRow, Field, RecipeCard, PostCard, EmptyState, ShowMore, RailModule, RailPerson, Icon, PhotoPicker, ChoiceGroup, Alert, ConfirmDestructive, Checkbox } = window.KukingPlSystemProjektowy_a664d7;

/* Ekran 09 — profil. Główka profilu, potem to samo, co w strumieniu:
   karty zwarte w dwóch kolumnach. Nigdzie nie ma liczby obserwujących —
   to jest liczba, która dzieli ludzi na dwie klasy. */
function Profil({ naglowek, nawigacja, stopka, onPrzepis }) {
  const [zakladka, setZakladka] = React.useState("dania");
  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka}
      szyna={
        <RailModule id="szyna-profil" tytul="Podobnie gotują">
          <RailPerson imie="Halina z Mazowsza" opis="Ciasta i przetwory" />
          <RailPerson imie="Piotr Zalewski" opis="Pizza i chleb, Pomorze" avatarSrc={window.ZDJ + "avatar_piotr.png"} />
          <p className="szyna-stopka">Nie jest to ranking. Po prostu ktoś, kogo warto zobaczyć.</p>
        </RailModule>
      }>
      <header className="profil-glowka" style={{ display: "flex", gap: "var(--spacing-5)", alignItems: "flex-start", flexWrap: "wrap", marginBottom: "var(--spacing-6)" }}>
        <Avatar src={window.ZDJ + "avatar_kasia.png"} imie="Kasia Wrzosek" rozmiar="lg" />
        <div style={{ minWidth: 0, flex: "1 1 18rem" }}>
          <h1 style={{ marginBottom: "var(--spacing-2)" }}>Kasia Wrzosek</h1>
          <p className="pomoc" style={{ marginBottom: "var(--spacing-3)" }}>Zupy i pierogi, Podkarpacie. Gotuję po babci i po swojemu.</p>
          <ButtonRow>
            <Button waga="primary" aria-pressed="false">Obserwuj</Button>
            <Button waga="quiet" href="#wiadomosc">Napisz</Button>
          </ButtonRow>
        </div>
      </header>

      <ChipRow etykieta="Co pokazujemy na profilu">
        <Chip biezacy={zakladka === "dania"} onClick={() => setZakladka("dania")}>Dania</Chip>
        <Chip biezacy={zakladka === "przepisy"} onClick={() => setZakladka("przepisy")}>Przepisy</Chip>
        <Chip biezacy={zakladka === "ugotowane"} onClick={() => setZakladka("ugotowane")}>Ugotowane</Chip>
      </ChipRow>

      {zakladka === "ugotowane" ? (
        <div style={{ marginTop: "var(--spacing-6)" }}>
          <EmptyState znak="garnek" tytul="Kasia nie pokazała jeszcze żadnego wykonania"
            opis="Kiedy ugotuje z czyjegoś przepisu i doda zdjęcie, pojawi się to tutaj." />
        </div>
      ) : (
        <>
          <div className="siatka-kart" style={{ marginTop: "var(--spacing-6)" }}>
            {window.ZESZYT.slice(0, zakladka === "przepisy" ? 2 : 4).map((z) => (
              <RecipeCard key={z.id} zwarta poziomTytulu="h2" tytul={z.tytul} tytulHref="#przepis"
                zdjecie={z.zdjecie} alt={z.alt}
                czasPrzygotowania={zakladka === "przepisy" ? z.czasPrzygotowania : undefined}
                porcje={zakladka === "przepisy" ? z.porcje : undefined} />
            ))}
          </div>
          <div style={{ marginTop: "var(--spacing-8)" }}>
            <ShowMore pokazano={zakladka === "przepisy" ? 2 : 4} wszystkich={zakladka === "przepisy" ? 7 : 23}
              rzeczownik={zakladka === "przepisy" ? "przepisów" : "dań"} />
          </div>
        </>
      )}
    </AppShell>
  );
}

/* Ekran 12 — szukanie. Chipy zawężają zakres, a pusty wynik nie jest ślepym
   zaułkiem: mówi, co zrobić dalej. */
function Szukaj({ naglowek, nawigacja, stopka, onPrzepis }) {
  const [zakres, setZakres] = React.useState("wszystko");
  const pusto = zakres === "osoby";
  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka} szyna={null}>
      <h1>Szukaj</h1>
      <form className="rzad-pol" onSubmit={(e) => e.preventDefault()} style={{ alignItems: "flex-end" }}>
        <Field id="szukaj-q" etykieta="Czego szukasz" typ="search" szerokosc="srednie" defaultValue="pierogi"
          podpowiedz="Nazwa dania, składnik albo imię osoby." />
        <Button waga="primary" type="submit" ikona="lupa">Szukaj</Button>
      </form>

      <ChipRow etykieta="Zakres wyników">
        <Chip biezacy={zakres === "wszystko"} onClick={() => setZakres("wszystko")}>Wszystko</Chip>
        <Chip biezacy={zakres === "przepisy"} onClick={() => setZakres("przepisy")}>Przepisy</Chip>
        <Chip biezacy={zakres === "dania"} onClick={() => setZakres("dania")}>Dania</Chip>
        <Chip biezacy={zakres === "osoby"} onClick={() => setZakres("osoby")}>Osoby</Chip>
      </ChipRow>

      <p className="pomoc" style={{ marginTop: "var(--spacing-5)" }} aria-live="polite">
        {pusto ? "Nie znaleźliśmy nikogo o takiej nazwie." : "Znaleźliśmy 3 rzeczy dla słowa „pierogi”."}
      </p>

      {pusto ? (
        <EmptyState znak="lupa" tytul="Nic takiego tu nie ma"
          opis="Sprawdź, czy słowo jest wpisane bez literówki, albo poszukaj czegoś ogólniejszego — na przykład „pierogi” zamiast „pierogi ruskie po babci”.">
          <Button waga="secondary" onClick={() => setZakres("wszystko")}>Szukaj we wszystkim</Button>
        </EmptyState>
      ) : (
        <div className="siatka-kart" style={{ marginTop: "var(--spacing-4)" }}>
          <RecipeCard zwarta poziomTytulu="h2" tytul="Pierogi ruskie po babci Halinie" tytulHref="#przepis"
            zdjecie={window.ZDJ + "pierogi.png"} alt="Talerz pierogów polanych zesmażoną cebulką"
            czasPrzygotowania="90 minut" porcje="6 porcji" />
          <PostCard zwarta poziomTytulu="h2" autor="Kasia Wrzosek" avatarSrc={window.ZDJ + "avatar_kasia.png"}
            czas="7 września, 18:20" tytul="Pierogi ruskie na niedzielę" tytulHref="#danie"
            zdjecie={window.ZDJ + "pierogi.png"} alt="Talerz pierogów na kuchennym stole" />
        </div>
      )}
    </AppShell>
  );
}

/* Ekran 04 — dodaj zdjęcie. Najkrótsza droga w całym serwisie: jedna akcja
   główna, jedno pole tekstowe, jeden wybór widoczności. */
function DodajZdjecie({ naglowek, nawigacja, stopka }) {
  const [wyslane, setWyslane] = React.useState(false);
  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka}
      szyna={
        <RailModule id="szyna-dodaj" tytul="Nie musi być ładne">
          <p className="szyna-podtytul" style={{ marginBottom: 0 }}>
            Zdjęcie z telefonu, zrobione przy stole, wystarczy. Nikt tu nie ocenia kadru — liczy się to, że ugotowałeś.
          </p>
          <p className="szyna-stopka">Możesz też nie dodawać zdjęcia i napisać samo zdanie.</p>
        </RailModule>
      }>
      <h1>Pokaż, co ugotowałeś</h1>
      {wyslane ? <Alert odmiana="sukces">Gotowe. Twój wpis jest już na tablicy.</Alert> : null}
      <form onSubmit={(e) => { e.preventDefault(); setWyslane(true); }}>
        <fieldset className="form-section field">
          <legend className="form-section-title">Zdjęcie</legend>
          <PhotoPicker id="zdjecie-wpisu" />
        </fieldset>
        <fieldset className="form-section field">
          <legend className="form-section-title">Kilka słów</legend>
          <p className="form-section-opis">Nie musisz wypełniać żadnego pola — wystarczy, że klikniesz „Wyślij”.</p>
          <Field id="wpis-tytul" etykieta="Co to było" szerokosc="srednie" podpowiedz="Nazwa dania, tak jak mówisz o nim w domu." />
          <Field id="wpis-tresc" etykieta="Napisz kilka słów" typ="textarea" />
        </fieldset>
        <fieldset className="form-section field">
          <ChoiceGroup name="widocznosc-wpisu" legenda="Kto to widzi" />
        </fieldset>
        <div className="form-actions">
          <Button waga="primary" duzy type="submit">Wyślij</Button>
          <Button waga="quiet" href="#tablica">Anuluj</Button>
        </div>
      </form>
    </AppShell>
  );
}

/* Ekran 14 — czytelność. Jedyne miejsce, w którym zmienia się wielkość tekstu
   na stałe. Podgląd jest ŻYWY: człowiek widzi skutek, zanim zapisze. */
const KROKI_SKALI = [
  ["90", "Mniejszy"], ["100", "Zwykły"], ["112", "Trochę większy"],
  ["125", "Większy"], ["140", "Duży"], ["150", "Bardzo duży"], ["200", "Największy"],
];

function Czytelnosc({ naglowek, nawigacja, stopka, motyw, onMotyw }) {
  const [skala, setSkala] = React.useState("100");
  React.useEffect(() => {
    document.documentElement.setAttribute("data-text-scale", skala);
    return () => document.documentElement.removeAttribute("data-text-scale");
  }, [skala]);

  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka} szyna={null}>
      <h1>Czytelność</h1>
      <p className="lead">
        Tekst powiększa się tutaj na stałe. Będzie większy na każdej stronie Kuking i na każdym urządzeniu,
        na którym się zalogujesz — nie trzeba tego ustawiać drugi raz.
      </p>

      <form onSubmit={(e) => e.preventDefault()}>
        <fieldset className="form-section field">
          <legend className="form-section-title">Wielkość tekstu</legend>
          <p className="form-section-opis">Wybierz i od razu zobacz, jak to wygląda. Nic się nie zapisze, dopóki nie klikniesz „Zapisz”.</p>
          <div className="choice-grid">
            {KROKI_SKALI.map(([w, nazwa]) => (
              <label className="choice" htmlFor={"skala-" + w} key={w}>
                <span className="choice-naglowek">
                  <input type="radio" name="skala" id={"skala-" + w} value={w}
                    checked={skala === w} onChange={() => setSkala(w)} />
                  <span className="choice-label">{nazwa}</span>
                </span>
                <span className="choice-help">{w}% wielkości podstawowej</span>
              </label>
            ))}
          </div>
        </fieldset>

        <fieldset className="form-section field">
          <legend className="form-section-title">Wygląd strony</legend>
          <p className="form-section-opis">Jasny jest domyślny. Ciemny bywa łatwiejszy dla oczu wieczorem.</p>
          <div className="rzad-przyciskow">
            <Button waga={motyw === "jasny" ? "secondary" : "quiet"} aria-pressed={motyw === "jasny" ? "true" : "false"} onClick={() => onMotyw("jasny")}>Jasny</Button>
            <Button waga={motyw === "ciemny" ? "secondary" : "quiet"} aria-pressed={motyw === "ciemny" ? "true" : "false"} onClick={() => onMotyw("ciemny")}>Ciemny</Button>
          </div>
        </fieldset>

        <section className="card odstep-nad">
          <p className="pomoc" style={{ marginBottom: "var(--spacing-3)" }}>Tak będzie wyglądał wpis na tablicy:</p>
          <PostCard autor="Kasia Wrzosek" avatarSrc={window.ZDJ + "avatar_kasia.png"} czas="wczoraj, 18:40"
            tytul="Pierogi ruskie na niedzielę"
            tresc="Ciasto na gorącej wodzie, farsz jak zawsze — ziemniaki, twaróg i dużo cebuli."
            bezZdjecia="Miejsce na zdjęcie potrawy." />
        </section>

        <div className="form-actions">
          <Button waga="primary" duzy type="submit">Zapisz</Button>
          <Button waga="quiet" onClick={() => setSkala("100")}>Przywróć zwykły rozmiar</Button>
        </div>
      </form>
    </AppShell>
  );
}

/* Ekran 15 — konto. Rzeczy nieodwracalne mają własną, wyraźnie oddzieloną
   sekcję: „Usuń” nigdy nie stoi obok „Zapisz”. */
function Konto({ naglowek, nawigacja, stopka }) {
  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka} szyna={null}>
      <h1>Twoje konto</h1>
      <form onSubmit={(e) => e.preventDefault()}>
        <fieldset className="form-section field">
          <legend className="form-section-title">Kto to jest</legend>
          <Field id="konto-imie" etykieta="Jak mamy się do Ciebie zwracać" szerokosc="krotkie" defaultValue="Basia" />
          <Field id="konto-nazwa" etykieta="Nazwa w serwisie" szerokosc="krotkie" defaultValue="basia" disabled
            powodWylaczenia="Nazwy nie da się zmienić po założeniu konta. Napisz do nas, jeśli to potrzebne." />
          <Field id="konto-mail" etykieta="Adres e-mail" typ="email" szerokosc="srednie" defaultValue="basia@example.com" />
        </fieldset>

        <fieldset className="form-section field">
          <legend className="form-section-title">Powiadomienia</legend>
          <p className="form-section-opis">Wysyłamy tylko to, co dotyczy Twoich rzeczy. Nigdy niczego reklamowego.</p>
          <Checkbox id="pow-ugotowal" defaultChecked>Ktoś ugotował z mojego przepisu</Checkbox>
          <Checkbox id="pow-komentarz" defaultChecked>Ktoś napisał komentarz pod moim wpisem</Checkbox>
          <Checkbox id="pow-obserwuje">Ktoś zaczął mnie obserwować</Checkbox>
        </fieldset>

        <fieldset className="form-section field">
          <legend className="form-section-title">Twoje rzeczy</legend>
          <p className="form-section-opis">
            Zabierzesz stąd wszystko, co dodasz. Paczka zawiera zdjęcia w oryginalnym rozmiarze, wpisy i przepisy
            w postaci, którą otworzysz na swoim komputerze.
          </p>
          <Button waga="secondary">Pobierz paczkę ze swoimi danymi</Button>
        </fieldset>

        <div className="form-actions">
          <Button waga="primary" type="submit">Zapisz</Button>
          <Button waga="quiet" href="#moje">Anuluj</Button>
        </div>
      </form>

      <div className="danger-zone">
        <p className="danger-zone-tytul">Usunięcie konta</p>
        <p className="pomoc">
          Konto znika po 30 dniach. Przez ten czas możesz się rozmyślić — wystarczy się zalogować.
          Po 30 dniach nie da się już tego cofnąć.
        </p>
        <ConfirmDestructive
          etykieta="Usuń konto"
          pytanie="Na pewno usunąć konto? Po 30 dniach znikną Twoje wpisy, przepisy i zdjęcia. Cudze wykonania Twoich przepisów też przestaną być widoczne."
          potwierdzenie="Tak, usuń moje konto"
          hrefAnuluj="#moje"
        />
      </div>
    </AppShell>
  );
}

Object.assign(window, { Profil, Szukaj, DodajZdjecie, Czytelnosc, Konto });
