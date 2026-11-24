import { createHash, timingSafeEqual } from 'node:crypto';

/**
 * Constant-time comparison of the X-Internal-Key header.
 *
 * Both sides are hashed to a fixed length first so `timingSafeEqual` never
 * throws on a length mismatch (which would itself leak the expected length) and
 * so comparison time is independent of the candidate's length.
 */
export function verifyInternalKey(candidate: string | undefined, expected: string): boolean {
  if (typeof candidate !== 'string' || candidate.length === 0) return false;
  const a = createHash('sha256').update(candidate, 'utf8').digest();
  const b = createHash('sha256').update(expected, 'utf8').digest();
  return timingSafeEqual(a, b);
}
