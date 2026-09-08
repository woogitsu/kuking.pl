/**
 * Wiersz autora pod tytułem przepisu — poza kartą, więc z własnymi wcięciami.
 * Ekran przepisu.
 * @dsAdherence Nie używaj tu klasy karta-glowka: jej wcięcia są liczone dla wnętrza karty i rozjeżdżają się z tytułem.
 */
export interface AuthorRowProps {
  imie: string;
  href?: string;
  avatarSrc?: string;
  /** Dopisek w atramencie stonowanym: „przepis po dziadku”. */
  dopisek?: React.ReactNode;
  className?: string;
}

export declare function AuthorRow(props: AuthorRowProps): JSX.Element;
