const { AppShell, WizardSteps, Field, FieldRow, ChoiceGroup, PhotoPicker, Checkbox, Button, ButtonRow, ErrorSummary, AutosaveBadge, Alert, RailModule } = window.DS;

/* Ekran 13 — dodaj przepis. Trzy kroki, trzy adresy (D-108). Każdy krok kończy
   się POST-em, który zapisuje szkic i przenosi dalej. */
const POMOC = {
  1: ["Nazwa i zdjęcie", "Zdjęcie nie musi być ładne — ma być prawdziwe. Nazwę wpisz tak, jak mówisz o tym daniu w domu."],
  2: ["Składniki", "Pisz tak, jak mówisz w kuchni: „szklanka mąki”, „2 duże cebule”, „mleko — ile weźmie”. Nic nie trzeba przeliczać na gramy."],
  3: ["Kroki i pochodzenie", "Jeden krok to jedna czynność. „Skąd ten przepis” to najczęściej czytana część — warto ją wypełnić."],
};

function KrokPierwszy({ bledy }) {
  return (
    <>
      <fieldset className="form-section field">
        <legend className="form-section-title">Co to za przepis</legend>
        <p className="form-section-opis">Wymagane są trzy pola — resztę wypełnij, jeśli chcesz.</p>
        <Field id="nazwa" etykieta="Nazwa przepisu" wymagane szerokosc="srednie"
          podpowiedz="Tak, jak mówisz o nim w domu." defaultValue="Pierogi ruskie po babci Halinie" />
        <Field id="zajawka" etykieta="Kilka słów o tym daniu" typ="textarea"
          podpowiedz="Co w nim jest najważniejsze i kiedy się je gotuje." />
        <FieldRow>
          <Field id="minuty" etykieta="Ile minut" typ="number" szerokosc="liczba" defaultValue="90" />
          <Field id="porcje" etykieta="Ile porcji" typ="number" szerokosc="liczba" defaultValue="6" />
        </FieldRow>
      </fieldset>
      <fieldset className="form-section field">
        <legend className="form-section-title">Zdjęcie</legend>
        <p className="form-section-opis">Jedno zdjęcie gotowego dania. Zdjęcie kartki z przepisem dołożysz w kroku trzecim.</p>
        <PhotoPicker id="zdjecie-przepisu" blad={bledy ? "Ten plik ma 14 MB, a przyjmujemy do 8 MB. Zrób zdjęcie jeszcze raz albo wybierz mniejszy plik." : null} />
      </fieldset>
      <fieldset className="form-section field">
        <ChoiceGroup legenda="Kto to widzi" />
      </fieldset>
    </>
  );
}

function KrokDrugi() {
  return (
    <fieldset className="form-section field">
      <legend className="form-section-title">Składniki</legend>
      <p className="form-section-opis">Jeden składnik w jednym wierszu. Kolejność jest taka, w jakiej ich potrzebujesz.</p>
      {["3 szklanki mąki", "szklanka gorącej wody, może trochę więcej", "1 kg ziemniaków, ugotowanych dzień wcześniej", "40 dag twarogu półtłustego", ""].map((v, i) => (
        <Field key={i} id={"skladnik-" + i} etykieta={"Składnik " + (i + 1)} szerokosc="krotkie" defaultValue={v}
          podpowiedz={i === 0 ? "Ilość i nazwa, tak jak w kuchni." : undefined} />
      ))}
      <p className="pomoc" style={{ marginTop: "var(--spacing-4)" }}>
        Po zapisaniu kroku dołożymy pięć kolejnych wierszy.
      </p>
    </fieldset>
  );
}

function KrokTrzeci() {
  return (
    <>
      <fieldset className="form-section field">
        <legend className="form-section-title">Kroki</legend>
        <p className="form-section-opis">Jeden krok to jedna czynność — łatwiej to czytać przy garnku.</p>
        {["Ziemniaki ugotuj dzień wcześniej i zostaw w chłodnym miejscu.", "Mąkę wsyp do miski i zalej gorącą wodą.", ""].map((v, i) => (
          <Field key={i} id={"krok-" + i} etykieta={"Krok " + (i + 1)} typ="textarea" defaultValue={v} />
        ))}
      </fieldset>
      <fieldset className="form-section field">
        <legend className="form-section-title">Skąd ten przepis</legend>
        <p className="form-section-opis">To najczęściej czytana część przepisu. Ludzie chcą wiedzieć, po kim on jest.</p>
        <Field id="po-kim" etykieta="Po kim ten przepis" szerokosc="krotkie" defaultValue="Po babci Halinie" />
        <Field id="historia" etykieta="Historia przepisu" typ="textarea" dlugie
          podpowiedz="Kiedy się to gotuje, co się z tym wiąże, co babcia mówiła." />
        <Checkbox id="kartka">Mam zdjęcie ręcznie zapisanej kartki i chcę je dołożyć do przepisu</Checkbox>
      </fieldset>
    </>
  );
}

function DodajPrzepis({ naglowek, nawigacja, stopka }) {
  const [krok, setKrok] = React.useState(2);
  const [bledy, setBledy] = React.useState(false);
  const [nazwaPomocy, opisPomocy] = POMOC[krok];

  return (
    <AppShell
      naglowek={naglowek}
      nawigacja={nawigacja}
      stopka={stopka}
      szyna={
        <RailModule id="szyna-pomoc" tytul={"Krok " + krok + " — " + nazwaPomocy}>
          <p className="szyna-podtytul" style={{ marginBottom: 0 }}>{opisPomocy}</p>
          <p className="szyna-stopka">Szkic zapisuje się przy każdym kroku. Możesz zamknąć stronę i wrócić.</p>
        </RailModule>
      }
    >
      <WizardSteps krok={krok} ile={3} nazwa={nazwaPomocy.toLowerCase()} />
      <h1>Dodaj przepis</h1>
      <AutosaveBadge />

      {bledy ? (
        <div style={{ marginTop: "var(--spacing-6)" }}>
          <ErrorSummary bledy={[
            { id: "nazwa", tekst: "Nazwa przepisu jest pusta." },
            { id: "zdjecie-przepisu", tekst: "Ten plik ma 14 MB, a przyjmujemy do 8 MB." },
          ]} />
        </div>
      ) : null}

      <form onSubmit={(e) => e.preventDefault()}>
        {krok === 1 ? <KrokPierwszy bledy={bledy} /> : null}
        {krok === 2 ? <KrokDrugi /> : null}
        {krok === 3 ? <KrokTrzeci /> : null}

        <div className="form-actions">
          {krok < 3 ? (
            <Button waga="primary" type="submit" onClick={() => setKrok(krok + 1)}>
              Zapisz i przejdź dalej
            </Button>
          ) : (
            <Button waga="primary" type="submit">Opublikuj przepis</Button>
          )}
          <Button waga="secondary" type="submit">Zapisz szkic</Button>
          {krok > 1 ? (
            <Button waga="quiet" onClick={() => setKrok(krok - 1)}>Wstecz</Button>
          ) : null}
        </div>
      </form>

      <div className="danger-zone">
        <p className="danger-zone-tytul">Podglądy stanów</p>
        <ButtonRow>
          <Button waga="quiet" onClick={() => setBledy(!bledy)}>
            {bledy ? "Ukryj stan błędu" : "Pokaż stan błędu"}
          </Button>
          <Button waga="quiet" onClick={() => setKrok(1)}>Krok 1</Button>
          <Button waga="quiet" onClick={() => setKrok(3)}>Krok 3</Button>
        </ButtonRow>
      </div>
    </AppShell>
  );
}

Object.assign(window, { DodajPrzepis });
