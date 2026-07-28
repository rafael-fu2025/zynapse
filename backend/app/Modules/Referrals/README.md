# Module: Referrals

Bridge contract between clinic and counselling. The only table that references both modules — and it does so by `patient_school_id`, not by joinable person IDs.

## Lifecycle

```
Submitted → Acknowledged → UnderReview → Closed
```

## QR tokens

- 128-bit CSPRNG, base64url-encoded.
- `REFERRAL_HMAC_KEY` env supplies the HMAC-SHA256 key.
- Only the keyed hash is persisted (`qr_token_hash`).
- TTL clamps 60s–86400s.

## Endpoints

| Method | Path                                       | Auth                | Permission                  |
| ------ | ------------------------------------------ | ------------------- | --------------------------- |
| GET    | `/api/v1/referrals`                        | api_auth            | `referrals.read`            |
| POST   | `/api/v1/referrals`                        | api_auth            | `referrals.create`          |
| POST   | `/api/v1/referrals/{id}/acknowledge`       | api_auth            | `referrals.acknowledge`     |
| POST   | `/api/v1/referrals/{id}/review`            | api_auth            | `referrals.review`          |
| POST   | `/api/v1/referrals/{id}/close`             | api_auth            | `referrals.close`           |
| POST   | `/api/v1/referrals/{id}/issue-qr`          | api_auth            | `referrals.issue_qr`        |
| POST   | `/api/v1/referrals/verify`                 | **PUBLIC**          | n/a                         |

## Verify endpoint

Returns ONLY the minimum-disclosure envelope:

```json
{ "status": "Valid|Expired|Revoked", "artifact_type": "…", "issuer": "…" }
```

NEVER includes patient identifiers, notes, or PII.