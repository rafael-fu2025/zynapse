/**
 * Pure routing decision for the admin "edit roles" save action.
 *
 * The admin user list mixes two kinds of rows:
 *   - real local accounts (positive `users.id`);
 *   - MIS directory entries (`is_directory_record`), which have NO local
 *     row yet and are shown with a synthetic NEGATIVE id.
 *
 * A directory entry must never be saved through the numeric role-replace
 * route: its synthetic id does not exist server-side. Directory saves go
 * through POST /admin/users/provision with the university identifier and
 * kind, which resolves/provisions the person and ADDITIVELY grants roles.
 * Real accounts keep the deliberate full-replacement route.
 *
 * Kept dependency-free (no React, no hooks) so it is unit-testable and so
 * the component cannot accidentally dispatch a numeric update for a
 * synthetic id.
 */

export type RoleSaveRoute =
  | { mode: 'provision'; identifier: string; kind: 'student' | 'employee' }
  | { mode: 'replace'; id: number }
  | { mode: 'invalid'; reason: string };

export interface RoleRouteUser {
  id: number;
  is_directory_record?: boolean | undefined;
  directory_identifier?: string | undefined;
  person_kind?: string | null | undefined;
}

export function resolveRoleSaveRoute(user: RoleRouteUser): RoleSaveRoute {
  if (user.is_directory_record === true) {
    const identifier = typeof user.directory_identifier === 'string'
      ? user.directory_identifier.trim()
      : '';
    if (identifier === '') {
      return {
        mode: 'invalid',
        reason: 'This directory entry is missing its university identifier. Refresh and try again.',
      };
    }

    const kind = user.person_kind;
    if (kind !== 'student' && kind !== 'employee') {
      return {
        mode: 'invalid',
        reason: 'This directory entry has an unsupported person type.',
      };
    }

    return { mode: 'provision', identifier, kind };
  }

  // A real account always has a positive id. Anything else is a synthetic
  // or otherwise unusable row — refuse to send a numeric role update.
  if (! Number.isInteger(user.id) || user.id <= 0) {
    return {
      mode: 'invalid',
      reason: 'This account has no local record yet. Provision it from the directory first.',
    };
  }

  return { mode: 'replace', id: user.id };
}
