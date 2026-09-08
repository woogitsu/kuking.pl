/**
 * Dolny pasek nawigacji — pięć miejsc w zasięgu kciuka, poniżej 1024 px.
 * @dsAdherence Dokładnie pięć pozycji. Nigdy same ikony bez podpisów. Nie ukrywaj paska przy przewijaniu.
 */
export interface PozycjaDolna {
  href: string;
  label: string;
  ikona: string;
  klucz: string;
  /** „Dodaj” — jedyna pozycja z kolorem marki, w kółku, ZAWSZE z podpisem. */
  glowna?: boolean;
}

export interface BottomNavProps {
  biezaca?: string;
  /** Domyślnie: Start, Szukaj, Dodaj, Zeszyt, Moje. */
  pozycje?: PozycjaDolna[];
  onPrzejdz?: (klucz: string) => void;
  className?: string;
}

export declare function BottomNav(props: BottomNavProps): JSX.Element;
export declare const POZYCJE_DOLNE: PozycjaDolna[];
