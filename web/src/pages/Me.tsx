// B5-ME — the two "Me" surfaces, composed to the prototype's stu-me (L661-674) and gua-me (L905-918).
// CLIENT ONLY: no server change, no new read.
//
// Both blocks are the same shape — h2 "Me", an identity card (avatar · name · role line · hr · label/value
// rows), then ONE second card — so the identity card is a single definition here and each persona supplies
// its own rows and second card.
//
// OMITTED, never placeholdered (verdicts, B5 STEP 1):
//   · stu-me's "My guardians" card — NOT-SERVED (read-narrow). guardian_links_read DOES admit the student
//     (`student_id::text = current_setting('app.actor_id')`), but NO route serves it: the only readers are
//     AccessIdentityReportController (audit-gated) and SessionReadController:67 (an internal guard). So it is
//     a missing endpoint, not a forbidden disclosure — D-7 classes it PROTOTYPE-WRONG/ungranted, which is
//     incorrect and predates the read cards. FLAG: reclass to RW; `GET /my/guardians` (name + status only,
//     the roster-elevation shape) joins the RW backlog.
//   · gua-me's "· {n} linked children" — same gap from the guardian's side: no route serves their links. The
//     count that IS derivable (/api/enrolments, distinct student_id) is a DIFFERENT FACT — children WITH
//     ENROLMENTS — and would read as quietly false for a newly-linked child with no enrolment. "Guardian"
//     alone renders instead.
//   · gua-me's "Notifications — In-app · email coming" row — DOMAIN-UNBUILT (D6): no notifications table,
//     route or client anywhere, and "email coming" is a promise about an unbuilt domain.
//   · the ceremony copy above the pairing button — instructional (Kit).
//   · a sign-out control — the shell carries it three ways (UserMenu:49, NavFooter:35, NavDrawer:46) and
//     neither block shows one here; nothing is stranded.
import { useState } from 'react';
import { App, Button, Input, Modal, Skeleton, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { KaLocale } from '../i18n';
import { useIdentity } from '../auth/identity';
import { initials, personName, schoolName } from '../display/names';
import { useResource } from '@/ds2';
import { mutate } from '../api/mutate';
import { LocaleSwitcher } from '../components/LocaleSwitcher';

interface EnrolRow { student_id: number; school_name_en: string | null; school_name_tc: string | null; school_name_sc: string | null }

/** One label/value row — the block's `.req-row` grammar: muted label left, value right. */
function MeRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 16, padding: '10px 0', borderTop: '1px solid var(--ka-border)' }}>
      <Typography.Text type="secondary">{label}</Typography.Text>
      <span style={{ textAlign: 'right', minWidth: 0 }}>{children}</span>
    </div>
  );
}

/** The identity card both blocks open with: avatar · name · role line · then the caller's rows. */
function IdentityCard({ name, roleLine, children }: { name: string; roleLine: string; children: React.ReactNode }) {
  return (
    <div style={{ background: 'var(--ka-card)', borderRadius: 'var(--ka-r-md)', padding: '18px 20px' }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 12, marginBottom: 14 }}>
        <span style={{ width: 44, height: 44, borderRadius: '50%', background: 'var(--ka-muted)', display: 'grid', placeItems: 'center', fontSize: 16, fontWeight: 600, color: 'var(--ka-muted-fg)', flex: 'none' }}>
          {initials(name)}
        </span>
        <div>
          <Typography.Title level={3} style={{ fontSize: 17, margin: 0 }}>{name}</Typography.Title>
          <Typography.Text type="secondary">{roleLine}</Typography.Text>
        </div>
      </div>
      {children}
    </div>
  );
}

function MeTitle() {
  const { t } = useTranslation();
  return <Typography.Title level={2} style={{ fontSize: 23, marginBottom: 16 }}>{t('me.title')}</Typography.Title>;
}

// ── STUDENT: identity + School + Language. The "My guardians" card is omitted (see header). ──────────
export function StudentMe() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language as KaLocale;
  const { identity } = useIdentity();
  // ONE read, and only for the school chip — the identity itself rides the shell's /api/me.
  const enr = useResource<{ data: EnrolRow[] }>('/api/enrolments');
  if (!identity) return <Skeleton active paragraph={{ rows: 3 }} />;

  const mine = (enr.data?.data ?? []).find((e) => e.student_id === identity.id) ?? null;
  const school = mine ? schoolName(mine, locale) : null;

  return (
    <div data-density="product" style={{ maxWidth: 900 }}>
      <MeTitle />
      <IdentityCard name={personName(identity.name)} roleLine={t('me.roleStudent')}>
        {/* School — a FACT, not a destination: neutral pill, no dot, rendered with the pill classes because
            StatusTag's contract is a closed enum → i18n label and a school name is free text (B3). */}
        {school && <MeRow label={t('me.school')}><span className="ant-tag ka-pill ka-pill--neutral">{school}</span></MeRow>}
        {/* The one row that is a CONTROL rather than a value: the block puts language here, and C3 put the
            switcher in the user menu. Same component in both places — LocaleSwitcher is the one definition
            (extracted at S-UX1 so public pages could switch too). */}
        <MeRow label={t('me.language')}><LocaleSwitcher /></MeRow>
      </IdentityCard>
    </div>
  );
}

// ── GUARDIAN: identity + Language, then the pairing card. ────────────────────────────────────────────
export function GuardianMe() {
  const { t } = useTranslation();
  const { identity } = useIdentity();
  const { message } = App.useApp();
  const [open, setOpen] = useState(false);
  const [code, setCode] = useState('');
  const [busy, setBusy] = useState(false);
  if (!identity) return <Skeleton active paragraph={{ rows: 3 }} />;

  // REDEEM only. The block's "Start a pairing code" names the wrong actor: POST /my/pairing-codes is
  // role:student — the STUDENT generates, the GUARDIAN redeems (POST /pairing-codes/redeem, role:guardian).
  // Model beats prototype. requestByEmail is deliberately NOT here: it is a different ceremony that creates a
  // pending link against a student located by exact email, and it wants its own verdict. FLAG.
  const redeem = async () => {
    setBusy(true);
    const r = await mutate('/api/pairing-codes/redeem', { code });
    setBusy(false);
    if (r.ok) {
      // NOT "linked": the server returns status `pending_confirmation`. Redeeming a code opens the two-stage
      // ceremony (OD-23/27) — the STUDENT confirms, then an admin approves — so claiming a completed link
      // here would tell the guardian something untrue about a child-safety relationship.
      void message.success(t('me.codeAccepted'));
      setOpen(false);
      setCode('');
      return;
    }
    // The SERVER's message is the message — PairingService says "Invalid pairing code", "This code has
    // expired", "This student already has a guardian …". Inventing client copy here would put a second,
    // drifting vocabulary in front of a child-safety ceremony.
    void message.error(r.message ?? t('mutate.failed'));
  };

  return (
    <div data-density="product" style={{ maxWidth: 900 }}>
      <MeTitle />
      <IdentityCard name={personName(identity.name)} roleLine={t('me.roleGuardian')}>
        <MeRow label={t('me.language')}><LocaleSwitcher /></MeRow>
      </IdentityCard>

      <div style={{ background: 'var(--ka-card)', borderRadius: 'var(--ka-r-md)', padding: '18px 20px', marginTop: 'var(--ka-zone-gap)' }}>
        <Typography.Title level={3} style={{ fontSize: 15.5, marginTop: 0, marginBottom: 12 }}>{t('me.linkChild')}</Typography.Title>
        <Button onClick={() => setOpen(true)}>{t('me.enterCode')}</Button>
      </div>

      <Modal
        open={open}
        title={t('me.enterCode')}
        okText={t('me.codeSubmit')}
        onOk={() => void redeem()}
        confirmLoading={busy}
        okButtonProps={{ disabled: code.trim().length !== 6 }}
        onCancel={() => setOpen(false)}
        destroyOnClose
      >
        {/* 6 characters is the server's own rule (`size:6`), enforced here only to stop a doomed round-trip —
            the server remains the authority. */}
        <Input
          value={code}
          onChange={(e) => setCode(e.target.value.trim())}
          maxLength={6}
          aria-label={t('me.codeLabel')}
          placeholder={t('me.codeLabel')}
        />
      </Modal>
    </div>
  );
}
