// Shared image with the §12 rule: a missing slot falls back to the
// category-colour gradient — never a broken image (ASSET-MANIFEST §3.5).
import { useState } from 'react';
import { asset } from '../../assets';
import { kaCategoryAccents, kaColors } from '../../theme/theme';

type Category = keyof typeof kaCategoryAccents;

interface AssetImageProps {
  /** Path relative to ASSET_BASE_URL, e.g. "programmes/cards/card-sc5.jpg" */
  path: string;
  alt: string;
  category?: Category;
  className?: string;
  style?: React.CSSProperties;
}

export function AssetImage({ path, alt, category, className, style }: AssetImageProps) {
  const [failed, setFailed] = useState(false);

  if (failed) {
    const accent = category ? kaCategoryAccents[category] : kaColors.gold;
    return (
      <div
        role="img"
        aria-label={alt}
        className={className}
        style={{
          ...style,
          background: `linear-gradient(135deg, ${kaColors.card} 0%, ${accent}55 100%)`,
        }}
      />
    );
  }

  return (
    <img
      src={asset(path)}
      alt={alt}
      className={className}
      style={{ maxWidth: '100%', ...style }}
      onError={() => setFailed(true)}
    />
  );
}
