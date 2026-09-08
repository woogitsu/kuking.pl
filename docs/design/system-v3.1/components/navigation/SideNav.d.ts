/**
 * Nawigacja boczna — sześć miejsc serwisu, od 1024 px. Siedem ekranów
 * zalogowanego.
 * @dsAdherence Nie zwijaj do samych ikon. Nie zmieniaj kolejności między podstronami. Nie dokładaj siódmej pozycji bez usunięcia innej.
 */
export interface PozycjaNawigacji {
  href: string;
  label: string;
  ikona: string;
  /** Klucz do rozpoznania bieżącej pozycji. */
  klucz: string;
}

export interface SideNavProps {
  /** Klucz bieżącej pozycji — dostaje aria-current="page" i wagę 800. */
  biezaca?: string;
  /** Domyślnie: Start, Szukaj, Dodaj, Zeszyt, Powiadomienia, Moje. */
  pozycje?: PozycjaNawigacji[];
  /** Przejęcie kliknięcia w makiecie klikalnej. */
  onPrzejdz?: (klucz: string) => void;
  className?: string;
}

export declare function SideNav(props: SideNavProps): JSX.Element;
export declare const POZYCJE_NAWIGACJI: PozycjaNawigacji[];
