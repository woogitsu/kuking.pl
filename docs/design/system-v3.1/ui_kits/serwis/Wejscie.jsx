const { Field, Button, ButtonRow, Alert, ErrorSummary, Checkbox, Wordmark, SiteFooter, Icon } = window.KukingPlSystemProjektowy_a664d7;

/* Ekrany gościa: 768 px, jedna kolumna, bez nawigacji bocznej i szyny.
   Wspólna otoczka, bo wszystkie cztery ekrany wejścia mają ten sam kształt:
   znak, jedno zdanie, formularz, jedno wyjście awaryjne. */
function EkranGoscia({ tytul, wstep, children, stopka }) {
  return (
    <div className="app-shell">
      <a className="tylko-dla-czytnika" href="#tresc">Przejdź do treści</a>
      <header className="topbar">
        <div className="topbar-wnetrze">
          <Wordmark href="#powitalna" />
        </div>
      </header>
      <div className="app-body app-body-solo">
        <main className="app-main" id="tresc">
          <h1>{tytul}</h1>
          {wstep ? <p className="lead">{wstep}</p> : null}
          {children}
        </main>
      </div>
      {stopka}
    </div>
  );
}

/* Ekran 01 — logowanie. Jedna rzecz na ekranie i nic poza nią. */
function Logowanie({ stopka, onZaloguj, onRejestracja }) {
  const [blad, setBlad] = React.useState(false);
  return (
    <EkranGoscia tytul="Zaloguj się" wstep="Wpisz nazwę i hasło. Nic więcej nie jest potrzebne." stopka={stopka}>
      {blad ? (
        <Alert odmiana="blad">
          Nie udało się zalogować. Sprawdź, czy nazwa i hasło są wpisane poprawnie.
          Jeśli nie pamiętasz hasła, kliknij „Nie pamiętam hasła”.
        </Alert>
      ) : null}
      <form onSubmit={(e) => { e.preventDefault(); onZaloguj(); }}>
        <Field id="login-nazwa" etykieta="Nazwa albo adres e-mail" wymagane szerokosc="srednie" defaultValue="basia" />
        <Field id="login-haslo" etykieta="Hasło" typ="password" wymagane szerokosc="srednie"
          help="Jeśli chcesz sprawdzić, co wpisujesz, zaznacz pole niżej." />
        <Checkbox id="pokaz-haslo">Pokaż hasło</Checkbox>
        <ButtonRow className="odstep-nad">
          <Button waga="primary" duzy type="submit">Zaloguj się</Button>
          <Button waga="quiet" href="#odzyskiwanie">Nie pamiętam hasła</Button>
        </ButtonRow>
      </form>
      <div className="odstep-nad">
        <p className="pomoc">Nie masz jeszcze konta?</p>
        <Button waga="secondary" onClick={onRejestracja}>Załóż konto — to darmowe</Button>
      </div>
      <div className="danger-zone">
        <p className="danger-zone-tytul">Podgląd stanu</p>
        <Button waga="quiet" onClick={() => setBlad(!blad)}>{blad ? "Ukryj błąd logowania" : "Pokaż błąd logowania"}</Button>
      </div>
    </EkranGoscia>
  );
}

/* Ekran 02 — rejestracja. Cztery pola. Nie pytamy o numer telefonu ani o datę
   urodzenia, i mówimy to wprost — to jest część obietnicy, nie uprzejmość. */
function Rejestracja({ stopka, onZaloguj }) {
  const [bledy, setBledy] = React.useState(false);
  return (
    <EkranGoscia tytul="Załóż konto" wstep="Cztery pola i gotowe. Nie pytamy o numer telefonu ani o datę urodzenia." stopka={stopka}>
      {bledy ? (
        <ErrorSummary bledy={[{ id: "rej-haslo", tekst: "Hasło musi mieć co najmniej 10 znaków." }]} />
      ) : null}
      <form onSubmit={(e) => { e.preventDefault(); onZaloguj(); }}>
        <Field id="rej-imie" etykieta="Jak mamy się do Ciebie zwracać" wymagane szerokosc="krotkie"
          podpowiedz="Imię wystarczy. Możesz je później zmienić." />
        <Field id="rej-nazwa" etykieta="Nazwa w serwisie" wymagane szerokosc="krotkie"
          podpowiedz="Pod tą nazwą zobaczą Cię inni. Same małe litery, bez spacji." />
        <Field id="rej-mail" etykieta="Adres e-mail" typ="email" wymagane szerokosc="srednie"
          podpowiedz="Potrzebny tylko do logowania i odzyskania hasła." />
        <Field id="rej-haslo" etykieta="Hasło" typ="password" wymagane szerokosc="srednie"
          help="Co najmniej 10 znaków. Najlepsze hasło to trzy zwykłe słowa, których nikt nie skojarzy z Tobą."
          blad={bledy ? "Hasło musi mieć co najmniej 10 znaków. Dopisz jeszcze jedno słowo." : null} />
        <Checkbox id="rej-zgoda">Przeczytałam regulamin i zgadzam się na zasady, które w nim są</Checkbox>
        <ButtonRow className="odstep-nad">
          <Button waga="primary" duzy type="submit">Załóż konto</Button>
          <Button waga="quiet" onClick={onZaloguj}>Mam już konto</Button>
        </ButtonRow>
      </form>
      <div className="danger-zone">
        <p className="danger-zone-tytul">Podgląd stanu</p>
        <Button waga="quiet" onClick={() => setBledy(!bledy)}>{bledy ? "Ukryj stan błędu" : "Pokaż stan błędu"}</Button>
      </div>
    </EkranGoscia>
  );
}

Object.assign(window, { EkranGoscia, Logowanie, Rejestracja });
