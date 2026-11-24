import type { Db } from './db.ts';

export const SESSION_STATES = ['unpaired', 'connecting', 'connected', 'logged_out', 'degraded'] as const;
export type SessionState = (typeof SESSION_STATES)[number];

export type SessionRow = {
  state: SessionState;
  sender_jid: string | null;
  sender_display: string | null;
  last_connected_at: number | null;
  last_disconnected_at: number | null;
  last_disconnect_code: number | null;
  updated_at: number;
};

/**
 * Persisted session status so `npm run status`, the HTTP status endpoint and
 * the admin badge all agree after a restart — an in-memory flag would report
 * "unpaired" for a paired account until the socket reconnects.
 */
export class SessionStore {
  private readonly db: Db;

  constructor(db: Db) {
    this.db = db;
  }

  get(): SessionRow {
    return this.db
      .prepare(
        `SELECT state, sender_jid, sender_display, last_connected_at,
                last_disconnected_at, last_disconnect_code, updated_at
           FROM session_state WHERE id = 1`,
      )
      .get() as SessionRow;
  }

  setState(state: SessionState, now: number = Date.now()): void {
    this.db
      .prepare(`UPDATE session_state SET state = ?, updated_at = ? WHERE id = 1`)
      .run(state, now);
  }

  markConnected(senderJid: string | null, senderDisplay: string | null, now: number = Date.now()): void {
    this.db
      .prepare(
        `UPDATE session_state
            SET state = 'connected',
                sender_jid = COALESCE(?, sender_jid),
                sender_display = COALESCE(?, sender_display),
                last_connected_at = ?,
                updated_at = ?
          WHERE id = 1`,
      )
      .run(senderJid, senderDisplay, now, now);
  }

  markDisconnected(
    state: SessionState,
    disconnectCode: number | null,
    now: number = Date.now(),
  ): void {
    this.db
      .prepare(
        `UPDATE session_state
            SET state = ?, last_disconnected_at = ?, last_disconnect_code = ?, updated_at = ?
          WHERE id = 1`,
      )
      .run(state, now, disconnectCode, now);
  }

  /** Clears the sender identity when the pairing is invalidated. */
  clearSender(state: SessionState, disconnectCode: number | null, now: number = Date.now()): void {
    this.db
      .prepare(
        `UPDATE session_state
            SET state = ?, sender_jid = NULL, sender_display = NULL,
                last_disconnected_at = ?, last_disconnect_code = ?, updated_at = ?
          WHERE id = 1`,
      )
      .run(state, now, disconnectCode, now);
  }
}
