const { AppShell, Section, RecipePhoto, RecipeOrigin, IngredientList, StepList, AuthorRow, CookedCard, CommentThread, Button, ButtonRow, Badge, Icon, ConfirmDestructive, Field, EmptyState } = window.DS;

/* Ekran 07 — przepis. Panel ze składnikami jedzie do trzeciej kolumny
   i przykleja się przy przewijaniu: przy garnku składniki potrzebne są OBOK
   kroków, a nie nad nimi (D-102). */
function PanelPrzepisu({ p, zapisany, onZapisz }) {
  return (
    <>
      <section className="szyna-modul" aria-labelledby="panel-akcje">
        <h2 className="szyna-tytul" id="panel-akcje">Co możesz zrobić</h2>
        <div className="stos">
          <Button waga="primary" pelny type="submit" aria-pressed={zapisany ? "true" : "false"} onClick={onZapisz}>
            {zapisany ? "Zapisano w Zeszycie" : "Zapisz przepis w Zeszycie"}
          </Button>
          <Button waga="secondary" pelny type="submit">Ugotowałem</Button>
        </div>
        <p className="szyna-stopka">Zeszyt jest prywatny, dopóki sam nie postanowisz inaczej.</p>
      </section>
      <section className="szyna-modul" aria-labelledby="panel-skladniki">
        <h2 className="szyna-tytul" id="panel-skladniki">Składniki</h2>
        <p className="szyna-podtytul">Na 6 porcji, czyli mniej więcej 50 pierogów.</p>
        <IngredientList skladniki={p.skladniki} />
      </section>
    </>
  );
}

function EkranPrzepisu({ naglowek, nawigacja, stopka }) {
  const p = window.PRZEPIS;
  const [zapisany, setZapisany] = React.useState(false);
  return (
    <AppShell
      naglowek={naglowek}
      nawigacja={nawigacja}
      stopka={stopka}
      szyna={<PanelPrzepisu p={p} zapisany={zapisany} onZapisz={() => setZapisany(!zapisany)} />}
    >
      <RecipePhoto src={p.zdjecie} alt={p.alt} />
      <h1 className="text-title-lg">{p.tytul}</h1>
      <AuthorRow imie={p.autor.imie} href="/@marek" avatarSrc={p.autor.avatar} dopisek={p.dopisek} />
      <div className="karta-przepisu-dane" style={{ padding: 0, marginBottom: "var(--spacing-5)" }}>
        <span className="dana-przepisu"><Icon name="zegar" className="dana-przepisu-ikona" />{p.czas}</span>
        <span className="dana-przepisu"><Icon name="porcje" className="dana-przepisu-ikona" />{p.porcje}</span>
        <span className="dana-przepisu"><Icon name="czapka" className="dana-przepisu-ikona" />{p.poziom}</span>
      </div>
      <p className="lead">{p.zajawka}</p>

      <RecipeOrigin poKim={p.poKim}>
        {p.historia.map((h, i) => (
          <p key={i} style={i === p.historia.length - 1 ? { margin: 0 } : undefined}>{h}</p>
        ))}
      </RecipeOrigin>

      {/* Druga kopia składników dla ekranów bez szyny — widoczna jest zawsze
          dokładnie jedna, więc czytnik czyta ją raz. */}
      <Section tytul="Składniki" className="tylko-waskie">
        <p className="sekcja-opis">Na 6 porcji, czyli mniej więcej 50 pierogów.</p>
        <IngredientList skladniki={p.skladniki} />
      </Section>

      <Section tytul="Jak to zrobić" opis="Jeden krok to jedna czynność — łatwiej to czytać przy garnku.">
        <StepList kroki={p.kroki} />
      </Section>

      <Section tytul="Komu wyszło" opis="Zdjęcia od ludzi, którzy naprawdę to zrobili u siebie.">
        <div className="strumien">
          {p.wykonania.map((w) => (
            <CookedCard key={w.id} autor={w.autor} autorHref={"/@" + w.autor} avatarSrc={w.avatar}
              przepis={p.tytul} przepisHref="#przepis" czas={w.czas} tresc={w.tresc} />
          ))}
        </div>
        <div style={{ marginTop: "var(--spacing-5)" }}>
          <Badge waga="cooked" ikona="garnek">Ugotowano 1 raz</Badge>
        </div>
      </Section>

      <Section tytul="Rozmowa" poziom="h2" className="" >
        <div id="komentarze">
          <CommentThread komentarze={p.komentarze} />
        </div>
        <form style={{ marginTop: "var(--spacing-6)" }} onSubmit={(e) => e.preventDefault()}>
          <Field id="odpowiedz" etykieta="Napisz kilka słów" typ="textarea"
            podpowiedz="Pytanie o zamiennik albo uwaga z własnej kuchni." />
          <ButtonRow className="odstep-nad-maly">
            <Button waga="primary" type="submit">Wyślij</Button>
          </ButtonRow>
        </form>
      </Section>

      <div className="danger-zone">
        <p className="danger-zone-tytul">Ten przepis jest Twój</p>
        <ConfirmDestructive
          etykieta="Usuń przepis"
          pytanie="Na pewno usunąć ten przepis? Wykonania i komentarze innych osób też przestaną być widoczne. Tej operacji nie da się cofnąć samodzielnie."
          hrefAnuluj="#przepis"
        />
      </div>
    </AppShell>
  );
}

Object.assign(window, { EkranPrzepisu });
