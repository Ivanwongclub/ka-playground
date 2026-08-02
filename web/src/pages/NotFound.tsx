// S-UX2a — catch-all 404 within the shell (found via S-UX1 S13's blank-page evidence).
import { Button, Result } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';

export function NotFound() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  return (
    <Result
      status="404"
      title={t('notFound.title')}
      subTitle={t('notFound.caption')}
      extra={
        <Button type="primary" onClick={() => void navigate('/')}>
          {t('notFound.backHome')}
        </Button>
      }
    />
  );
}
