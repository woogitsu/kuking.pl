/**
 * Zdjęcie przepisu — jedyne zdjęcie w serwisie poza kartą, 3:2.
 * Ekran przepisu.
 * @dsAdherence Proporcja 3:2, bo pod zdjęciem stoi panel, a nie tekst wpisu. Alt opisuje, co jest na talerzu.
 */
export interface RecipePhotoProps {
  src: string;
  /** Opis tego, co jest na talerzu, nie „zdjęcie dania”. */
  alt: string;
  className?: string;
}

export declare function RecipePhoto(props: RecipePhotoProps): JSX.Element;
