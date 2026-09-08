const { AppShell, Section, RowList, NotificationRow, EmptyState, Button, ShowMore } = window.DS;

/* Ekran 11 — powiadomienia. Wiersze, nie karty: to krótkie, jednorodne
   pozycje, a karta w tym miejscu udaje treść, której nie ma. */
function Powiadomienia({ naglowek, nawigacja, stopka }) {
  const [puste, setPuste] = React.useState(false);
  const lista = window.POWIADOMIENIA;
  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka} szyna={null}>
      <h1>Powiadomienia</h1>
      <p className="pomoc" style={{ marginBottom: "var(--spacing-6)" }}>
        Dwa nowe. Reszta jest już przeczytana.
      </p>

      {puste ? (
        <EmptyState znak="dzwonek" tytul="Nic tu jeszcze nie ma"
          opis="Kiedy ktoś ugotuje z Twojego przepisu, napisze komentarz albo zacznie Cię obserwować, dowiesz się o tym tutaj.">
          <Button waga="primary" href="#tablica">Zobacz przepisy</Button>
        </EmptyState>
      ) : (
        <>
          <RowList>
            {lista.map((p) => (
              <NotificationRow key={p.id} imie={p.imie} avatarSrc={p.avatar} tresc={p.tresc} czas={p.czas} nieprzeczytane={p.nowe} />
            ))}
          </RowList>
          <div style={{ marginTop: "var(--spacing-8)" }}>
            <ShowMore pokazano={4} wszystkich={4} rzeczownik="powiadomień" koniec />
          </div>
        </>
      )}

      <div className="danger-zone">
        <p className="danger-zone-tytul">Podgląd stanu</p>
        <Button waga="quiet" onClick={() => setPuste(!puste)}>
          {puste ? "Pokaż listę" : "Pokaż pusty stan"}
        </Button>
      </div>
    </AppShell>
  );
}

/* Ekran 10 — zeszyt. Karty zwarte, maksimum dwie kolumny: przy czterech
   zdjęcie robi się miniaturą, a to jest zakazane. */
function Zeszyt({ naglowek, nawigacja, stopka, onPrzepis }) {
  const { RecipeCard, Badge } = window.DS;
  const [puste, setPuste] = React.useState(false);
  return (
    <AppShell naglowek={naglowek} nawigacja={nawigacja} stopka={stopka} szyna={null}>
      <h1>Zeszyt</h1>
      <p className="pomoc" style={{ marginBottom: "var(--spacing-6)" }}>
        Przepisy, które chcesz zachować. Zeszyt jest prywatny, dopóki sam nie postanowisz inaczej.
      </p>

      {puste ? (
        <EmptyState tytul="Zeszyt jest jeszcze pusty"
          opis="Kiedy znajdziesz przepis, który chcesz zachować, kliknij przy nim „Zapisuję”. Trafi tutaj i zawsze go znajdziesz.">
          <Button waga="primary" onClick={onPrzepis}>Zobacz przepisy</Button>
        </EmptyState>
      ) : (
        <>
          <div className="siatka-kart">
            {window.ZESZYT.map((z) => (
              <RecipeCard
                key={z.id}
                zwarta
                poziomTytulu="h2"
                autor={z.autor.imie}
                autorHref={"/@" + z.autor.imie}
                avatarSrc={z.autor.avatar}
                czas={z.czas}
                plakietka={<Badge waga="cichy">konto przykładowe</Badge>}
                tytul={z.tytul}
                tytulHref="#przepis"
                zdjecie={z.zdjecie}
                alt={z.alt}
                czasPrzygotowania={z.czasPrzygotowania}
                porcje={z.porcje}
              />
            ))}
          </div>
          <div style={{ marginTop: "var(--spacing-8)" }}>
            <ShowMore pokazano={4} wszystkich={12} rzeczownik="przepisów" />
          </div>
        </>
      )}

      <div className="danger-zone">
        <p className="danger-zone-tytul">Podgląd stanu</p>
        <Button waga="quiet" onClick={() => setPuste(!puste)}>
          {puste ? "Pokaż zeszyt" : "Pokaż pusty stan"}
        </Button>
      </div>
    </AppShell>
  );
}

Object.assign(window, { Powiadomienia, Zeszyt });
