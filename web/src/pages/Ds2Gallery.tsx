// DEV-ONLY DS2 component gallery — the visual reference + regression anchor for the atom kit (STEP 2).
// Dead-code-eliminated from production (DEV-gated route in main.tsx, like the Style Guide). It is the ONE
// deliberate adopter of @/ds2 in STEP 2 (allowlisted in scripts/ds2-import-guard.mjs). Its labels are
// developer-facing (component + prop-state names), so it is excluded from the i18n hardcoded-string scan.
import { useState } from 'react';
import { Button } from 'antd';
import { CalendarClock, FileSignature } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
  StatusAtom, StatChip, MetaChip, StateBadge, ProgressRing, DatedBadge, Seal, StatusTag,
  SubPanel, ZoneStack, StatCard, Attest, ZebraTable, WizardRail, FormLanguageSwitcher,
  PageCard, AuthCard, HeroBanner, TaskCard, EmptyState, UrgencyChip,
} from '@/ds2';
import type { Ds2Lang } from '@/ds2';
import { asset } from '../assets';

interface PayRow { id: string; student: string; amount: string; status: string }
const PAY_ROWS: PayRow[] = [
  { id: '1', student: 'Chan Sum-yu', amount: 'HK$2,500.00', status: 'pending_confirmation' },
  { id: '2', student: 'Wong Ka-ho', amount: 'HK$2,000.00', status: 'confirmed' },
  { id: '3', student: 'Lam Zi-ching', amount: 'HK$2,500.00', status: 'confirmed' },
];
const WARN: React.CSSProperties = { color: 'var(--ka-warning)', background: 'rgba(251,191,36,.12)', fontSize: 12, fontWeight: 600, padding: '2px 9px', borderRadius: 6 };

const S: Record<string, React.CSSProperties> = {
  page: { background: 'var(--ka-bg)', color: 'var(--ka-fg)', minHeight: '100vh', padding: 32, fontFamily: 'var(--ka-font-body)' },
  h1: { fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 28, marginBottom: 4 },
  lead: { color: 'var(--ka-fg-muted)', fontSize: 13, marginBottom: 28 },
  eyebrow: { fontFamily: 'var(--ka-font-mono)', fontSize: 11, letterSpacing: '.15em', textTransform: 'uppercase', color: 'var(--ka-gold)', marginBottom: 10 },
  card: { background: 'var(--ka-card)', border: '1px solid var(--ka-border)', borderRadius: 'var(--ka-r-lg)', padding: 20, marginBottom: 16 },
  row: { display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' },
  avatar: { width: 48, height: 48, borderRadius: '50%', background: 'linear-gradient(135deg,#2c2338,#3a2f4a)', display: 'grid', placeItems: 'center', fontFamily: 'var(--ka-font-display)', fontWeight: 700, color: 'var(--ka-gold)', border: '1px solid var(--ka-border)', position: 'relative' },
  avOk: { boxShadow: '0 0 0 2px var(--ka-gold)' },
  avWarn: { boxShadow: '0 0 0 2px var(--ka-warning)' },
  badgePos: { position: 'absolute', right: -3, bottom: -3 },
};

function Section({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={S.card}>
      <div style={S.eyebrow}>{label}</div>
      <div style={S.row}>{children}</div>
    </div>
  );
}

export function Ds2Gallery() {
  const { i18n } = useTranslation();
  const locale = i18n.language;
  const [lang, setLang] = useState<Ds2Lang>('en');
  return (
    <div style={S.page}>
      <div style={S.h1}>DS2 Atom Kit</div>
      <div style={S.lead}>Every atom in every state — on the --ka-* tokens, darkAlgorithm-native. DEV-only reference.</div>

      <Section label="StatusAtom · D5 hierarchy (loud status + demoted detail)">
        <StatusAtom icon={<Seal size={20} />} status="Consent complete" detail="Signed 28 Jul 2026" tone="attested" />
        <StatusAtom status="Signature needed" detail="Confirms the seat before it starts" tone="action" />
        <StatusAtom status="Draft" />
      </Section>

      <Section label="StatChip · MetaChip · DatedBadge (data-as-atoms, D7)">
        <StatChip value={2} label="programmes" />
        <StatChip value={11} label="age" />
        <MetaChip>Mon 16:00</MetaChip>
        <MetaChip>Kowloon studio</MetaChip>
        <DatedBadge label="Signed" date="2026-07-28T00:00:00Z" locale={locale} />
      </Section>

      <Section label="StateBadge (on an avatar) · Seal">
        <div style={{ ...S.avatar, ...S.avOk }}>SY<span style={S.badgePos}><StateBadge state="sealed" title="Consent sealed" /></span></div>
        <div style={{ ...S.avatar, ...S.avWarn }}>MK<span style={S.badgePos}><StateBadge state="warn" title="Action needed" /></span></div>
        <StateBadge state="ok" title="OK" />
        <StateBadge state="action" title="Action" />
        <Seal size={24} />
      </Section>

      <Section label="ProgressRing (readiness as a visual, D7)">
        <ProgressRing value={7} total={9} />
        <ProgressRing value={2} total={2} />
        <ProgressRing value={0} total={5} />
      </Section>

      <Section label="StatusTag (re-exported existing status pills)">
        <StatusTag domain="orderStatus" value="paid" />
        <StatusTag domain="paymentStatus" value="pending_confirmation" />
        <StatusTag domain="enrolmentStatus" value="active" />
        <StatusTag domain="sessionStatus" value="in_progress" />
      </Section>

      <div style={{ ...S.h1, fontSize: 20, marginTop: 12 }}>Structure primitives</div>

      <Section label="SubPanel · ZoneStack — D6 zones (shade + gap, no borders)">
        <div style={{ width: '100%', maxWidth: 560 }}>
          <ZoneStack>
            <SubPanel tone="attested"><span className="ds2-atom">Attested zone</span></SubPanel>
            <SubPanel tone="neutral">Neutral zone — a shade-banded sub-panel</SubPanel>
            <SubPanel tone="action"><span className="ds2-atom">Action zone</span></SubPanel>
          </ZoneStack>
        </div>
      </Section>

      <Section label="StatCard — KPI tile (default · gold accent · alert · money-tier: seal + sub + warn + formatMoney value)">
        <div style={{ width: 200 }}><StatCard label="Enrolments" value={6} /></div>
        <div style={{ width: 200 }}><StatCard label="Confirmed" value={12} accent="gold" /></div>
        <div style={{ width: 200 }}><StatCard label="Issuance gaps" value={3} alert /></div>
        <div style={{ width: 230 }}><StatCard label="Awaiting confirmation" value="HK$2,500.00" accent="warn" seal sub={<StatChip value={1} label="to confirm" />} /></div>
      </Section>

      <Section label="Attest — the honesty component (fact as atom + record on demand; attested REQUIRES onViewRecord)">
        <div style={{ width: '100%', maxWidth: 560, display: 'flex', flexDirection: 'column', gap: 8 }}>
          <Attest
            tone="attested"
            icon={<Seal size={20} />}
            status="Consent complete"
            dated={{ label: 'Signed', date: '2026-07-28T00:00:00Z', locale }}
            onViewRecord={() => alert('open the audited record')}
            viewRecordLabel="View record"
          />
          <Attest
            tone="action"
            icon={<StateBadge state="action" title="Action needed" />}
            status="Signature needed"
            action={<Button type="primary" size="small">Review &amp; sign</Button>}
          />
        </div>
      </Section>

      <Section label="ZebraTable — D6 horizontal (zebra + money-right, no column rules)">
        <div style={{ width: '100%' }}>
          <ZebraTable<PayRow>
            rowKey={(r) => r.id}
            data={PAY_ROWS}
            columns={[
              { key: 'student', title: 'Student', type: 'text', render: (r) => r.student },
              { key: 'amount', title: 'Amount', type: 'money', render: (r) => r.amount },
              { key: 'status', title: 'Status', type: 'status', render: (r) => <StatusTag domain="paymentStatus" value={r.status} /> },
              { key: 'action', title: 'Action', type: 'action', render: () => <a href="#">View</a> },
            ]}
          />
        </div>
      </Section>

      <Section label="WizardRail — grouped stepper (state icons + per-phase counts, no captions)">
        <div style={{ width: 300 }}>
          <WizardRail
            phases={[
              { title: 'Setup', steps: [{ label: 'Basics', state: 'done' }, { label: 'Eligibility', state: 'done' }] },
              { title: 'Money & consent', steps: [{ label: 'Fees', state: 'done', locked: true }, { label: 'Consent', state: 'current', num: 4, locked: true }] },
              { title: 'Teams & roles', steps: [{ label: 'Team rules', state: 'done' }, { label: 'Role library', state: 'wip', num: 6 }, { label: 'Tracker', state: 'blocked' }] },
            ]}
          />
        </div>
      </Section>

      <Section label="FormLanguageSwitcher — the ONLY trilingual pattern (form-level + completeness dots)">
        <FormLanguageSwitcher
          value={lang}
          onChange={setLang}
          complete={{ en: true, tc: true, sc: false }}
          labels={{ en: 'English', tc: '繁體', sc: '简体' }}
          editingLabel="Editing"
          warning={lang === 'sc' ? <span style={WARN}>简 incomplete</span> : undefined}
        />
      </Section>

      <div style={{ ...S.h1, fontSize: 20, marginTop: 12 }}>DS2 v2 primitives (R0-B1)</div>

      <Section label="StatCard v2 — count/unit sub-line + built-in drill-down (to / onClick)">
        <div style={{ width: 220 }}><StatCard label="Outstanding" value="HK$4,500.00" accent="warn" count={2} unit="orders" /></div>
        <div style={{ width: 220 }}><StatCard label="Enrolments" value={6} to="/ds2-gallery" /></div>
        <div style={{ width: 220 }}><StatCard label="Issuance gaps" value={3} alert onClick={() => alert('drill into the gaps')} /></div>
      </Section>

      <Section label="PageCard (§2.1) — standalone SOLID card (not a SubPanel zone); center=false for this preview">
        <PageCard width={340} center={false}>
          <div style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 18, marginBottom: 6 }}>A page-level card</div>
          <div style={{ color: 'var(--ka-muted-fg)', fontSize: 13 }}>Solid var(--ka-card) surface, width-constrained, elevated — the container SubPanel is not.</div>
        </PageCard>
      </Section>

      <Section label="AuthCard (§2.1 preset) — logo + LocaleSwitcher header (full-height centred; clipped preview)">
        <div style={{ position: 'relative', height: 360, width: '100%', overflow: 'hidden', border: '1px dashed var(--ka-border)', borderRadius: 8 }}>
          <AuthCard logoAlt="Armour Academy" width="form">
            <div style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 20, textAlign: 'center' }}>Sign in</div>
            <div style={{ color: 'var(--ka-muted-fg)', fontSize: 13, textAlign: 'center', marginTop: 4 }}>Email + password fields go here (interactive AntD, unconverted).</div>
          </AuthCard>
        </div>
      </Section>

      <Section label="HeroBanner (§2.2) — imagery + duotone scrim · and the image-error fallback">
        <div style={{ width: '100%', display: 'flex', flexDirection: 'column', gap: 12 }}>
          <HeroBanner image={{ src: asset('auth/featured-sc5.jpg'), alt: 'Students at work' }} height="band">
            <div style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 22 }}>Welcome back</div>
            <div style={{ fontSize: 13 }}>Foreground contrast comes from the scrim, not the image.</div>
          </HeroBanner>
          <HeroBanner image={{ src: '/deliberately-missing.jpg', alt: '' }} height="band" fallback={<div />}>
            <div style={{ fontFamily: 'var(--ka-font-display)', fontWeight: 700, fontSize: 22 }}>Image failed → flat gradient</div>
            <div style={{ fontSize: 13 }}>Text stays legible on the fallback.</div>
          </HeroBanner>
        </div>
      </Section>

      <Section label="TaskCard (§2.3) — action-first home unit (icon · title · context · urgency · ONE cta)">
        <div style={{ width: 300 }}>
          <TaskCard icon={<FileSignature size={18} />} title="Sign STEM consent" context="Summer STEM · Chan Sum-yu" urgency="due" urgencyLabel="Due in 2 days" cta={{ label: 'Review & sign', to: '/ds2-gallery' }} />
        </div>
        <div style={{ width: 300 }}>
          <TaskCard icon={<CalendarClock size={18} />} title="3 sessions this week" context="You are the mentor" urgency="soon" urgencyLabel="Starts Mon" cta={{ label: 'Take attendance', onClick: () => alert('open attendance') }} />
        </div>
        <div style={{ width: 300 }}>
          <TaskCard icon={<FileSignature size={18} />} title="Consent complete" context="Nothing to do" seal cta={{ label: 'View record', to: '/ds2-gallery' }} />
        </div>
      </Section>

      <Section label="UrgencyChip (§3) — one treatment; level from urgencyLevel(deadline, thresholds)">
        <UrgencyChip level="soon" label="In 5 days" />
        <UrgencyChip level="due" label="Due tomorrow" />
        <UrgencyChip level="overdue" label="Overdue by 3 days" />
        <span style={{ color: 'var(--ka-muted-fg)', fontSize: 12 }}>none → renders nothing</span>
      </Section>

      <Section label="EmptyState (§2.4) — the designed zero surface (inline · page · with cta)">
        <div style={{ width: 260 }}><SubPanel tone="neutral"><EmptyState message="No pending links" size="inline" /></SubPanel></div>
        <div style={{ width: 320 }}><SubPanel tone="neutral"><EmptyState message="Your dashboard is empty" detail="It fills in as you take part." cta={{ label: 'Browse programmes', to: '/ds2-gallery' }} size="page" /></SubPanel></div>
      </Section>
    </div>
  );
}
