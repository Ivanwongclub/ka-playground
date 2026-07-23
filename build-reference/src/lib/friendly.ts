/**
 * Friendly user-facing labels for technical integration fields.
 * Rule: never expose SSO/OIDC/SAML/Token/Webhook/Polling jargon in the UI.
 * Always look up via these maps before rendering.
 */

export const FRIENDLY = {
  sign_in: {
    standard: "Standard login link",
    school: "School account login",
    enterprise: "Enterprise login",
  },
  progress: {
    realtime: "Real-time progress updates",
    daily: "Daily progress updates",
  },
} as const;

export type SignInMethod = keyof typeof FRIENDLY.sign_in;
export type ProgressMethod = keyof typeof FRIENDLY.progress;

export function friendlySignIn(method: string): string {
  return FRIENDLY.sign_in[method as SignInMethod] ?? "Standard login link";
}

export function friendlyProgress(method: string): string {
  return FRIENDLY.progress[method as ProgressMethod] ?? "Real-time progress updates";
}
