/**
 * Tekst na zdjęciu — tytuł na kadrze większym niż karta. Strona powitalna,
 * główka przepisu.
 * @dsAdherence Podkład jest ZAWSZE (reguła „nigdy” nr 7). Nie rozjaśniaj podkładu. Nie kładź na zdjęciu przycisków. Alt opisuje zdjęcie, nie powtarza napisu.
 */
export interface PhotoWithCaptionProps {
  src: string;
  /** Opis zdjęcia. Nie powtarza napisu, który na nim leży. */
  alt: string;
  /** Napis na kadrze, 24 px / 800, biały na podkładzie. */
  napis: React.ReactNode;
  /** Druga linia: „Piotr · 90 minut · 6 porcji”. */
  podpis?: React.ReactNode;
  /** Podany adres zamienia cały kadr w odnośnik; fokus rysuje obwódkę wokół całości. */
  href?: string;
  className?: string;
}

export declare function PhotoWithCaption(props: PhotoWithCaptionProps): JSX.Element;
