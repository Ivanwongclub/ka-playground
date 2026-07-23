// §17.3 — bottom sheet with drag handle, snap points at 50% and 92%, velocity-aware
// swipe-to-close past 30%. Guarded sheets (dirty forms / destructive confirms) refuse
// gesture dismissal — closing is then an explicit button action only.
import { useCallback, useEffect, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

const SNAP_POINTS = [0.5, 0.92] as const;
const CLOSE_THRESHOLD = 0.3;
const CLOSE_VELOCITY = 0.5; // px/ms downward

interface BottomSheetProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  children: ReactNode;
  /** When true, swipe-to-close is disabled (dirty form / destructive confirm — §17.3). */
  guarded?: boolean;
}

export function BottomSheet({ open, onClose, title, children, guarded = false }: BottomSheetProps) {
  const { t } = useTranslation();
  const [snap, setSnap] = useState<number>(SNAP_POINTS[0]);
  const [dragOffset, setDragOffset] = useState(0);
  const drag = useRef<{ startY: number; startTime: number; lastY: number } | null>(null);

  useEffect(() => {
    if (open) setSnap(SNAP_POINTS[0]);
  }, [open]);

  // §17.6 — browser back closes the topmost sheet first
  useEffect(() => {
    if (!open) return;
    window.history.pushState({ kaSheet: true }, '');
    const onPop = () => onClose();
    window.addEventListener('popstate', onPop);
    return () => window.removeEventListener('popstate', onPop);
  }, [open, onClose]);

  const onPointerDown = useCallback((e: ReactPointerEvent) => {
    drag.current = { startY: e.clientY, startTime: performance.now(), lastY: e.clientY };
    (e.target as HTMLElement).setPointerCapture(e.pointerId);
  }, []);

  const onPointerMove = useCallback((e: ReactPointerEvent) => {
    if (!drag.current) return;
    drag.current.lastY = e.clientY;
    setDragOffset(Math.max(0, e.clientY - drag.current.startY));
  }, []);

  const onPointerUp = useCallback(() => {
    if (!drag.current) return;
    const { startY, startTime, lastY } = drag.current;
    drag.current = null;
    const dy = lastY - startY;
    const velocity = dy / Math.max(1, performance.now() - startTime);
    setDragOffset(0);

    const sheetHeight = window.innerHeight * snap;
    const draggedFraction = dy / sheetHeight;

    if (dy < -24) {
      setSnap(SNAP_POINTS[1]); // dragged up → expand
      return;
    }
    if (!guarded && (draggedFraction > CLOSE_THRESHOLD || velocity > CLOSE_VELOCITY)) {
      if (snap === SNAP_POINTS[1] && draggedFraction < CLOSE_THRESHOLD) {
        setSnap(SNAP_POINTS[0]); // fast flick from expanded settles at 50% first
      } else {
        onClose();
      }
      return;
    }
    if (dy > 24 && snap === SNAP_POINTS[1]) setSnap(SNAP_POINTS[0]);
  }, [guarded, onClose, snap]);

  if (!open) return null;

  return (
    <div className="ka-sheet-scrim" onClick={guarded ? undefined : onClose}>
      <section
        className="ka-sheet"
        role="dialog"
        aria-modal="true"
        style={{
          height: `${snap * 100}dvh`,
          transform: `translateY(${dragOffset}px)`,
          transition: drag.current ? 'none' : 'height 0.3s ease, transform 0.3s ease',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <div
          className="ka-sheet-handle-zone"
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
        >
          <div className="ka-sheet-handle" aria-hidden />
        </div>
        <header className="ka-sheet-header">
          <h3>{title}</h3>
          <button type="button" className="ka-sheet-close" onClick={onClose}>
            {t('shell.close')}
          </button>
        </header>
        <div className="ka-sheet-body">{children}</div>
      </section>
    </div>
  );
}
