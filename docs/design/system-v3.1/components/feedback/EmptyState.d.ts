/**
 * Pusty stan — mówi, co się tu pojawi i co zrobić, żeby się pojawiło.
 * Osiem ekranów z listami.
 * @dsAdherence Nigdy „Brak danych” bez ciągu dalszego. Zawsze dokładnie jedna konkretna akcja. Nie rysuj dużej ilustracji.
 */
export interface EmptyStateProps {
  /** Nazwa ikony nad tekstem. Znak marki albo ikona pasująca do miejsca (lupa przy braku wyników). */
  znak?: string;
  /** Zdanie o tym, czego tu nie ma: „Zeszyt jest jeszcze pusty”. */
  tytul: React.ReactNode;
  /** Co zrobić, żeby się tu coś pojawiło. */
  opis?: React.ReactNode;
  className?: string;
  /** Jedna akcja — zawsze jedna i zawsze konkretna. */
  children?: React.ReactNode;
}

export declare function EmptyState(props: EmptyStateProps): JSX.Element;
