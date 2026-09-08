const { AppShell, Section, PostCard, RecipeCard, Button, ButtonRow, Badge, Chip, ChipRow, ShowMore, RailModule, RailPerson, RailDish, Icon } = window.DS;

/* Ekran 08 — tablica. Strumień kart w kolumnie treści, szyna po prawej.
   Szyna NIE powtarza kolumny głównej. */
function Kompozytor({ onDodaj }) {
  return (
    <div className="card" style={{ marginBottom: "var(--spacing-6)" }}>
      <Button waga="primary" duzy ikona="aparat" onClick={onDodaj}>
        Dodaj zdjęcie tego, co ugotowałeś
      </Button>
      <p className="pomoc" style={{ margin: "var(--spacing-3) 0 0" }}>
        Nie musi być ładne — ma być prawdziwe.
      </p>
    </div>
  );
}

function KartaWpisu({ w, onPrzepis }) {
  const wspolne = {
    autor: w.autor.imie,
    autorHref: "/@" + w.autor.imie,
    avatarSrc: w.autor.avatar,
    czas: w.czas,
    plakietka: <Badge waga="cichy">{w.widocznosc || "konto przykładowe"}</Badge>,
    tytul: w.tytul,
    tresc: w.tresc,
    zdjecie: w.zdjecie,
    alt: w.alt,
    bezZdjecia: w.bezZdjecia,
  };
  const akcje = (
    <>
      <Button waga="primary" type="submit">Ugotowałem</Button>
      <Button waga="quiet" type="submit">Zapisz</Button>
      {w.komentarze ? (
        <a className="btn btn-quiet karta-stopka-odstep" href="#komentarze">
          {w.komentarze} komentarze
        </a>
      ) : null}
    </>
  );
  if (w.przepis) {
    return (
      <RecipeCard
        {...wspolne}
        tytulHref="#przepis"
        czasPrzygotowania={w.przepis.czas}
        porcje={w.przepis.porcje}
        poziom={w.przepis.poziom}
        akcje={akcje}
        onClick={onPrzepis}
      />
    );
  }
  return <PostCard {...wspolne} akcje={akcje} />;
}

function Szyna({ onPrzepis }) {
  const [obs, setObs] = React.useState({});
  return (
    <>
      <RailModule id="szyna-szkic" tytul="Twój szkic czeka">
        <p className="szyna-podtytul">
          Przepis „Pierogi ruskie po babci Halinie”, zapisany wczoraj o 21:10.
        </p>
        <Button waga="secondary" pelny onClick={onPrzepis}>Dokończ przepis</Button>
        <p className="szyna-stopka">Nic nie zginie i wrócisz do tego, kiedy zechcesz.</p>
      </RailModule>
      <RailModule
        id="szyna-kukingi"
        tytul="kuKINGi na dziś"
        podtytul="Kilka osób i kilka dań, które dziś warto zobaczyć."
        stopka="Jutro będzie tu ktoś inny."
      >
        <RailPerson imie="Kasia Wrzosek" opis="Zupy i pierogi, Podkarpacie" avatarSrc={window.ZDJ + "avatar_kasia.png"}
          obserwowana={!!obs.kasia} onObserwuj={() => setObs({ ...obs, kasia: !obs.kasia })} />
        <RailPerson imie="Piotr Zalewski" opis="Pizza i chleb, Pomorze" avatarSrc={window.ZDJ + "avatar_piotr.png"}
          obserwowana={!!obs.piotr} onObserwuj={() => setObs({ ...obs, piotr: !obs.piotr })} />
        <RailPerson imie="Halina z Mazowsza" opis="Ciasta i przetwory"
          obserwowana={!!obs.halina} onObserwuj={() => setObs({ ...obs, halina: !obs.halina })} />
        <p className="szyna-podtytul" style={{ marginTop: "var(--spacing-4)" }}>Dania</p>
        <RailDish tytul="Pomidorowa z własnych pomidorów" opis="Halina z Mazowsza · 40 minut"
          zdjecie={window.ZDJ + "soup.png"} href="#przepis" />
        <RailDish tytul="Sernik bez spodu" opis="Kasia Wrzosek · 90 minut"
          zdjecie={window.ZDJ + "cake.png"} href="#przepis" />
      </RailModule>
    </>
  );
}

function Tablica({ naglowek, nawigacja, stopka, onPrzepis, onDodaj }) {
  const [zakres, setZakres] = React.useState("obserwowani");
  const wpisy = zakres === "obserwowani" ? window.WPISY : window.WPISY.slice().reverse();
  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka} szyna={<Szyna onPrzepis={onPrzepis} />}>
      <h1>Dobry wieczór, Basia. Pokaż, co dziś wyszło.</h1>
      <Kompozytor onDodaj={onDodaj} />
      <ChipRow etykieta="Zakres strumienia">
        <Chip biezacy={zakres === "obserwowani"} onClick={() => setZakres("obserwowani")}>Obserwowani</Chip>
        <Chip biezacy={zakres === "swiezo"} onClick={() => setZakres("swiezo")}>Świeżo z Kuking</Chip>
      </ChipRow>
      <div className="strumien" style={{ marginTop: "var(--spacing-6)" }}>
        {wpisy.map((w) => (
          <KartaWpisu key={w.id} w={w} onPrzepis={onPrzepis} />
        ))}
      </div>
      <div style={{ marginTop: "var(--spacing-8)" }}>
        <ShowMore pokazano={3} wszystkich={48} rzeczownik="wpisów" />
      </div>
    </AppShell>
  );
}

Object.assign(window, { Tablica });
