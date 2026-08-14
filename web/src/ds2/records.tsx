// DS2 v3 record/composition primitives (P0-3a). Pure, presentational, data-agnostic — each takes structured
// props (never a prose slot), composes existing atoms/surfaces, and is INVISIBLE until a surface adopts it
// (the barrel + import-guard gate adoption per-card). i18n-native: all text arrives already-translated.
//   Ds2SegBar          — §3.3 labeled 5-seg progress (done/current/todo states; per-seg label, no free text)
//   GlanceCard         — §3.2 image band → status row → segbar → label/value rows (value = Text|Tag|Button)
//   ProgrammeBandHeader— §3.7 image band with name + status pill + switcher chevron ON the photo
//   JourneyStepper     — §3.4 gold Steps + dated knots + 3 stat tiles + "what happens next" (≠ WizardRail)
import type { ReactNode } from 'react';
import { Steps } from 'antd';
import { ChevronDown } from 'lucide-react';
import { HeroBanner } from './surfaces';
import { SubPanel, StatCard } from './structure';
import { formatHktDate } from '../display/date';
import './records.css';

// ── Ds2SegBar (§3.3) — the labeled 5-segment bar (NOT antd Progress) ─────────────────────────────────
export type SegState = 'done' | 'current' | 'todo';
export interface SegItem { label: ReactNode; state: SegState }

/** Structured segments only: each is a `{label, state}` — there is no free-text/percentage slot. done=success,
 *  current=gold+glow, todo=neutral track; labels sit under each segment. Typically the 5 tracker stages. */
export function Ds2SegBar({ segments }: { segments: SegItem[] }) {
  return (
    <div className="ds2-segbar" role="list">
      {segments.map((s, i) => (
        <div key={i} role="listitem" className={`ds2-segbar__seg ds2-segbar__seg--${s.state}`}>
          <span className="ds2-segbar__bar" aria-hidden />
          <span className="ds2-segbar__lbl">{s.label}</span>
        </div>
      ))}
    </div>
  );
}

// ── GlanceCard (§3.2) — image band + status + segbar + label/value rows ──────────────────────────────
// The value is a discriminated union — Text | Tag | Button — so a page author cannot narrate a value row.
export type GlanceValue = { text: ReactNode } | { tag: ReactNode } | { action: ReactNode };
export interface GlanceRow { label: ReactNode; value: GlanceValue }

function glanceValue(v: GlanceValue): ReactNode {
  if ('text' in v) return <span className="ds2-glance__val-text">{v.text}</span>;
  if ('tag' in v) return v.tag;
  return v.action;
}

/** A card (no border, elevation, hover-lift when clickable). Identity = the image (one shared neutral scrim),
 *  never a per-card color. `status` is the single colored element (a StatusTag); values are Text|Tag|Button. */
export function GlanceCard({ image, imageFallback, title, status, segments, rows = [], onClick }: {
  image?: { src: string; alt: string };
  imageFallback?: ReactNode;
  title: ReactNode;
  status?: ReactNode;
  segments?: SegItem[];
  rows?: GlanceRow[];
  onClick?: () => void;
}) {
  const clickable = onClick !== undefined;
  return (
    <div
      className={`ds2-glance${clickable ? ' ds2-glance--clickable' : ''}`}
      role={clickable ? 'button' : undefined}
      tabIndex={clickable ? 0 : undefined}
      onClick={onClick}
      onKeyDown={clickable ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } } : undefined}
    >
      {image && (
        <div className="ds2-glance__band">
          <HeroBanner image={image} height="band" scrim="bottom" fallback={imageFallback}>{null}</HeroBanner>
        </div>
      )}
      <div className="ds2-glance__body">
        <div className="ds2-glance__head">
          <span className="ds2-glance__title">{title}</span>
          {status}
        </div>
        {segments && segments.length > 0 && <Ds2SegBar segments={segments} />}
        {rows.length > 0 && (
          <div className="ds2-glance__rows">
            {rows.map((r, i) => (
              <div key={i} className="ds2-glance__row">
                <span className="ds2-glance__lbl">{r.label}</span>
                <span className="ds2-glance__val">{glanceValue(r.value)}</span>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}

// ── ProgrammeBandHeader (§3.7) — the scoped-space band header ────────────────────────────────────────
/** The programme's image band: name + status pill + optional switcher chevron, all ON the photo. Switching
 *  is the caller's job (onSwitch) — this primitive only renders the band + affordance. */
export function ProgrammeBandHeader({ image, imageFallback, name, status, onSwitch, switchLabel }: {
  image: { src: string; alt: string };
  imageFallback?: ReactNode;
  name: ReactNode;
  status?: ReactNode;
  onSwitch?: () => void;
  switchLabel?: string;
}) {
  return (
    <HeroBanner image={image} height="band" scrim="duotone" fallback={imageFallback}>
      <div className="ds2-bandhdr">
        <div className="ds2-bandhdr__id">
          <span className="ds2-bandhdr__name">{name}</span>
          {status}
        </div>
        {onSwitch && (
          <button type="button" className="ds2-bandhdr__switch" aria-label={switchLabel} onClick={onSwitch}>
            <ChevronDown size={20} strokeWidth={1.9} aria-hidden />
          </button>
        )}
      </div>
    </HeroBanner>
  );
}

// ── JourneyStepper (§3.4) — gold Steps + dated knots + stat tiles + what-happens-next ─────────────────
// DISTINCT from WizardRail (grouped readiness rail: no dates, no antd Steps, no tiles). Both stay.
export interface JourneyStep { title: ReactNode; date?: string }
export interface JourneyTile { label: ReactNode; value: ReactNode; icon?: ReactNode }

/** antd Steps (gold ink via theme) with stage DATES under completed knots, preceded by three stat tiles and
 *  followed by one "what happens next" tile. No instructional prose lives outside `whatNext`. */
export function JourneyStepper({ steps, current, locale, tiles = [], whatNext, whatNextLabel }: {
  steps: JourneyStep[];
  current: number;
  locale: string;
  tiles?: JourneyTile[];
  whatNext?: ReactNode;
  whatNextLabel?: ReactNode;
}) {
  return (
    <div className="ds2-journey">
      {tiles.length > 0 && (
        <div className="ds2-journey__tiles">
          {tiles.map((tl, i) => <StatCard key={i} label={tl.label} value={tl.value} icon={tl.icon} />)}
        </div>
      )}
      <div className="ds2-journey__steps">
        <Steps
          current={current}
          items={steps.map((s) => ({ title: s.title, description: s.date ? formatHktDate(s.date, locale) : undefined }))}
        />
      </div>
      {whatNext != null && (
        <SubPanel tone="neutral">
          <div className="ds2-journey__next">
            {whatNextLabel != null && <span className="ds2-journey__next-lbl">{whatNextLabel}</span>}
            <div className="ds2-journey__next-body">{whatNext}</div>
          </div>
        </SubPanel>
      )}
    </div>
  );
}
