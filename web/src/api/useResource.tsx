// S-UX2a — the ONE data-fetch convention. Every authenticated GET goes through this:
// res.ok guarded, errors caught (no unhandled promise → no crash), 401 handled by authFetch.
// <DataBoundary> renders the loading / error / empty states consistently, so no page silently
// blanks on failure again. Fixes the Consents-list crash and the four silent-blank pages.
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Alert, Empty, Spin } from 'antd';
import { useTranslation } from 'react-i18next';
import { authFetch } from '../auth/session';

interface ResourceState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  reload: () => void;
}

export function useResource<T>(url: string): ResourceState<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [nonce, setNonce] = useState(0);
  const reload = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    let alive = true;
    if (!url) {
      // No URL yet (e.g. a dependent fetch awaiting a selection) — idle, not loading.
      setData(null);
      setLoading(false);
      setError(null);
      return;
    }
    setLoading(true);
    setError(null);
    void (async () => {
      try {
        const res = await authFetch(url);
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const body = (await res.json()) as T;
        if (alive) setData(body);
      } catch (e) {
        if (alive) setError(e instanceof Error ? e.message : 'error');
      } finally {
        if (alive) setLoading(false);
      }
    })();
    return () => {
      alive = false;
    };
  }, [url, nonce]);

  return { data, loading, error, reload };
}

interface DataBoundaryProps {
  loading: boolean;
  error: string | null;
  empty?: boolean;
  onRetry?: () => void;
  children: ReactNode;
}

/** Consistent loading / error / empty chrome around a resource-backed view. */
export function DataBoundary({ loading, error, empty, children }: DataBoundaryProps) {
  const { t } = useTranslation();
  if (loading) {
    return (
      <div style={{ padding: 48, textAlign: 'center' }}>
        <Spin />
      </div>
    );
  }
  if (error) {
    return <Alert type="error" showIcon message={t('data.error')} description={error} style={{ margin: '16px 0' }} />;
  }
  if (empty) {
    return <Empty description={t('data.empty')} style={{ padding: 32 }} />;
  }
  return <>{children}</>;
}
