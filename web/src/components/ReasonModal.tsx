// S-UX3-1 — reasoned-decision modal: a consequence banner (so the admin knows what they are
// confirming) + a validated reason field. Reused by decline/reject/withdrawal-decide.
import { useEffect, useState } from 'react';
import { Alert, Input, Modal } from 'antd';
import { useTranslation } from 'react-i18next';

export function ReasonModal({
  open, title, consequence, okText, danger = true, minLen = 3, onOk, onCancel,
}: {
  open: boolean;
  title: string;
  consequence?: string;
  okText: string;
  danger?: boolean;
  minLen?: number;
  onOk: (reason: string) => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('');
  useEffect(() => {
    if (open) setReason('');
  }, [open]);

  return (
    <Modal
      open={open}
      title={title}
      okText={okText}
      okButtonProps={{ danger, disabled: reason.trim().length < minLen }}
      onOk={() => onOk(reason.trim())}
      onCancel={onCancel}
    >
      {consequence && <Alert type="warning" showIcon message={consequence} style={{ marginBottom: 12 }} />}
      <Input.TextArea
        rows={3}
        placeholder={t('common.reasonLabel')}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
    </Modal>
  );
}
