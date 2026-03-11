# Job: Fix yearly boundary bug in `clear.php` monthly safe-hours aggregation

## Status
Open

## Problem statement
The yearly aggregation query in `clear.php` currently uses:

```sql
WHERE dateTime BETWEEN :start AND :end
```

with `:end` set to `YYYY+1-01-01 00:00:00`.

Because `BETWEEN` is inclusive, a row exactly at midnight on January 1st of the following year is incorrectly included in the selected year totals. This can misattribute time to January in the selected year.

## Impact
- Monthly totals on the **Clear / Monthly View** page can be slightly inflated for January.
- The displayed data can be inconsistent with year-based reporting expectations.

## Proposed fix
1. Change the SQL range predicate to a half-open interval:
   - `dateTime >= :start AND dateTime < :end`
2. Apply the same half-open interval rule to the `lastStmt` query so record selection for carry-forward logic matches the aggregation window.
3. Add a code comment documenting why half-open ranges are used for period boundaries.

## Acceptance criteria
- Selecting year `Y` never includes rows where `dateTime >= (Y+1)-01-01 00:00:00`.
- A record exactly at `(Y+1)-01-01 00:00:00` is excluded from year `Y` and included only in year `Y+1`.
- `php -l clear.php` passes.

## Notes
This job avoids any database-dependent testing in local CI; validation can be done with a targeted SQL fixture or in staging where DB access is available.
