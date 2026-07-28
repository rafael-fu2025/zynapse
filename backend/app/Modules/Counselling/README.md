# Module: Counselling

Sessions and encrypted notes. Notes are AES-256-GCM ciphertext; the column is never used in WHERE or ORDER BY.

## Tables

- `counselling_sessions` — header rows.
- `counselling_notes` — `notes_cipher` (VARBINARY 8192), `notes_nonce` (BINARY 12), `notes_key_version` (TINYINT).

## Endpoints (all under `api_auth`)

| Method | Path                                          | Permission                       |
| ------ | --------------------------------------------- | -------------------------------- |
| GET    | `/api/v1/counselling/sessions`                | `counselling.records.read`       |
| POST   | `/api/v1/counselling/sessions`                | `counselling.records.create`     |
| POST   | `/api/v1/counselling/sessions/{id}/notes`     | `counselling.records.write`      |
| GET    | `/api/v1/counselling/sessions/{id}/notes`     | `counselling.records.read`       |
| POST   | `/api/v1/counselling/sessions/{id}/close`     | `counselling.records.write`      |

## Crypto

`App\Services\Crypto\EncryptionService` is the only writer/reader. The stored `notes_cipher` column carries the 16-byte GCM auth tag appended to the raw ciphertext (Phase 6 — GCM decryption requires the tag). Rotating keys: bump `COUNSELLING_KEY_VERSION`, point `COUNSELLING_KEY` at the new key, insert the new version's `key_ref` into `counselling_key_versions`, and expose the retired key under its referenced env var (e.g. `COUNSELLING_KEY_V1`). The lookup table stores env var NAMES only — never key material.

## Out of scope

- Search by note content (intentionally — ciphertext cannot be indexed).
- Cross-module queries — use `referrals`.