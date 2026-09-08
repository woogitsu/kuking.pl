/**
 * Moduł szyny — trzecia kolumna od 1280 px. „kuKINGi na dziś”: kilka osób
 * i kilka dań wartych zobaczenia. Tablica, szukanie.
 * @dsAdherence Szyna nigdy nie powtarza kolumny głównej. Nigdy ranking. Nic, bez czego strona przestaje działać. Nigdy obie kopie treści widoczne naraz.
 */
export interface RailModuleProps {
  /** Tytuł modułu, 20 px / 800. */
  tytul: React.ReactNode;
  /** Zdanie pod tytułem. */
  podtytul?: React.ReactNode;
  /** Zdanie na końcu: „Jutro będzie tu ktoś inny.” — treść, nie ozdoba. */
  stopka?: React.ReactNode;
  /** Identyfikator tytułu dla aria-labelledby. Unikalny, gdy modułów jest kilka. */
  id?: string;
  className?: string;
  children?: React.ReactNode;
}

/** Wiersz z osobą i przyciskiem „Obserwuj / Obserwujesz”. */
export interface RailPersonProps {
  imie: string;
  /** Krótki opis: „Zupy i pierogi, Podkarpacie”. */
  opis?: React.ReactNode;
  avatarSrc?: string;
  href?: string;
  /** Zmienia NAPIS przycisku, nie tylko aria-pressed i kolor. */
  obserwowana?: boolean;
  onObserwuj?: (e: React.MouseEvent) => void;
}

/** Wiersz z daniem. Zdjęcie 64 px — szyna wskazuje, gdzie zajrzeć. */
export interface RailDishProps {
  tytul: React.ReactNode;
  opis?: React.ReactNode;
  zdjecie: string;
  alt?: string;
  href?: string;
}

export declare function RailModule(props: RailModuleProps): JSX.Element;
export declare function RailPerson(props: RailPersonProps): JSX.Element;
export declare function RailDish(props: RailDishProps): JSX.Element;
