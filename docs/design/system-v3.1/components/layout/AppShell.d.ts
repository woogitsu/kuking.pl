/**
 * Szkielet strony zalogowanego: belka, trzy kolumny, stopka — jedna szerokość
 * dla wszystkich trzech warstw.
 * @dsAdherence Trzecia kolumna istnieje zawsze od 80rem, także pusta (D-102). Nie licz belce ani stopce szerokości osobno od siatki treści. Trzy progi, nie pięć.
 */
export interface AppShellProps {
  /** Ekran gościa: jedna kolumna 768 px, bez nawigacji bocznej i szyny. */
  solo?: boolean;
  /** Belka górna. */
  naglowek?: React.ReactNode;
  /** Nawigacja boczna (od 1024 px) i dolny pasek (poniżej). */
  nawigacja?: React.ReactNode;
  /** Zawartość trzeciej kolumny. Może być pusta — kolumna zostaje. */
  szyna?: React.ReactNode;
  stopka?: React.ReactNode;
  className?: string;
  children: React.ReactNode;
}

/** Sekcja treści z nagłówkiem, zdaniem opisu i opcjonalną akcją. */
export interface SectionProps {
  tytul?: React.ReactNode;
  opis?: React.ReactNode;
  akcja?: React.ReactNode;
  poziom?: "h2" | "h3";
  className?: string;
  children?: React.ReactNode;
}

export declare function AppShell(props: AppShellProps): JSX.Element;
export declare function Section(props: SectionProps): JSX.Element;
