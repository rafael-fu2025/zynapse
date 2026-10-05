/**
 * bmgActions — which BMG drum actions are available, given a drum's
 * current state.
 *
 * This logic used to live inline in the Facilities table's overflow menu,
 * as a dozen `disabled={...}` expressions. That is exactly the shape that
 * rots silently: the `curing` state stayed wired into three of them for
 * months after the transition that produced it was removed from the UI,
 * so the menu implied a state an operator could never reach. Extracting
 * it makes the rules reviewable in one place and directly testable
 * (see `bmgActions.test.ts`).
 *
 * The vocabulary:
 *   active  — the drum holds a batch that is still in flight, so the
 *             operator can act ON that batch (log, finish, cancel).
 *   idle    — available for a new batch.
 *   archived— soft-archived; history is kept, but it can't be worked.
 */

export type BmgAction =
  | 'start'
  | 'addUpdate'
  | 'viewUpdates'
  | 'finish'
  | 'cancel'
  | 'certificate'
  | 'analytics'
  | 'drumStatus'
  | 'archive'
  | 'restore';

export interface BmgUnitState {
  status: string;
  // Declared as `| undefined` (not just `?`) because the project runs
  // `exactOptionalPropertyTypes`, and `BmgUnit` genuinely carries these
  // as optional-and-nullable.
  active_batch_id?: number | null | undefined;
  archived_at?: string | null | undefined;
}

export type BmgActionAvailability = Record<BmgAction, boolean>;

/**
 * Statuses where the drum is mid-run. Mirrors the backend's
 * `active_unit_id` generated column, which is the real invariant: at most
 * one unfinished batch per drum, where "unfinished" means exactly these.
 * `maintenance` and `cancelled` are unit-only and never carry a batch.
 */
export const ACTIVE_STATUSES = ['processing', 'awaiting_output'] as const;

/**
 * Resolve every drum action at once.
 *
 * `pending` covers in-flight mutations. Gating a menu item on a
 * pending flag is a UX nicety, not a state rule, so callers pass their
 * own pending set and the state logic stays pure.
 */
export function bmgActionAvailability(
  unit: BmgUnitState,
  pending: Partial<Record<BmgAction, boolean | undefined>> = {},
): BmgActionAvailability {
  const hasBatch =
    unit.active_batch_id !== null && unit.active_batch_id !== undefined;
  const isActive = (ACTIVE_STATUSES as readonly string[]).includes(unit.status);
  const isArchived =
    unit.archived_at !== null && unit.archived_at !== undefined;

  // A live batch is the precondition for every batch-scoped action.
  const onActiveBatch = hasBatch && isActive;

  return {
    // Only an idle drum can take a new batch. The backend enforces the
    // same rule via the unique index on `active_unit_id`.
    start: !pending.start && !isArchived && unit.status === 'idle',

    addUpdate: !pending.addUpdate && onActiveBatch,
    viewUpdates: onActiveBatch,

    // Graded release — available from either active state.
    finish: !pending.finish && onActiveBatch,
    cancel: !pending.cancel && onActiveBatch,

    // Read-only views of the live batch.
    certificate: onActiveBatch,
    analytics: onActiveBatch,

    // Parking a drum is only legal when nothing is running on it.
    drumStatus: !pending.drumStatus && !isArchived && !hasBatch,

    archive: !pending.archive && !isArchived && !hasBatch,
    restore: !pending.restore && isArchived,
  };
}

/**
 * ESP32 boards a drum may integrate right now: registered (unarchived)
 * and held by no drum. Every drum carries exactly one device, so a
 * fleet whose boards are all bound blocks drum creation until another
 * board is registered — the create dialog gates on `length === 0`.
 *
 * `exceptDeviceId` keeps a drum's CURRENT board in the list when
 * building reassignment options (assigning it back is a no-op, not a
 * conflict).
 */
export function availableBmgDevices<
  T extends {
    id: number;
    // `| undefined` spelled out because the project runs
    // exactOptionalPropertyTypes and BmgUnit/BmgDevice genuinely carry
    // these as optional-and-nullable.
    unit_id?: number | null | undefined;
    archived_at?: string | null | undefined;
  },
>(devices: T[], exceptDeviceId?: number): T[] {
  return devices.filter((d) => {
    if (d.archived_at !== null && d.archived_at !== undefined) return false;
    if (d.unit_id === null || d.unit_id === undefined) return true;
    return exceptDeviceId !== undefined && d.id === exceptDeviceId;
  });
}
