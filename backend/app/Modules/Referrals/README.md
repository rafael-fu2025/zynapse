# Module: Referrals

Bridge contract between clinic and counselling. The only table that references both modules — and it does so by `patient_school_id`, not by joinable person IDs.

## Lifecycle

```
Submitted → Acknowledged → UnderReview → Closed
```

After acknowledge/review the patient can be handed off into the target module's queue (`POST /referrals/{id}/queue-handoff`), and counselling-bound referrals can be booked directly from the Referrals page.

## Endpoints

| Method | Path                                       | Auth                | Permission                  |
| ------ | ------------------------------------------ | ------------------- | --------------------------- |
| GET    | `/api/v1/referrals`                        | api_auth            | `referrals.read`            |
| POST   | `/api/v1/referrals`                        | api_auth            | `referrals.create`          |
| POST   | `/api/v1/referrals/{id}/acknowledge`       | api_auth            | `referrals.acknowledge`     |
| POST   | `/api/v1/referrals/{id}/review`            | api_auth            | `referrals.review`          |
| POST   | `/api/v1/referrals/{id}/close`             | api_auth            | `referrals.close`           |
| POST   | `/api/v1/referrals/{id}/queue-handoff`     | api_auth            | `referrals.acknowledge` + receiving side |

The former per-referral QR token feature (issue/revoke/verify) was removed — see the `DropReferralQrColumns` migration. The clinic appointment QR (`/api/v1/appointments/verify`, public) is a separate feature and is unaffected.
