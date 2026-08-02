# PostgreSQL Merchant UID Compatibility Design

## Problem

The upstream MySQL schema does not create a merchant account, but declares `pre_user` with `AUTO_INCREMENT=1000`. The PostgreSQL schema converter removes MySQL table options and currently loses that starting value, so the first PostgreSQL merchant receives UID 1. Admin test payments may still be configured for merchant UID 1000, producing orders that cannot be credited because that user does not exist.

## Decision

Preserve upstream behavior without creating a default merchant. When the PostgreSQL user table is empty, its identity column will be restarted at 1000. Existing non-empty user tables will never be renumbered or have their sequence moved backward.

Admin test payment creation will also verify that the configured `test_pay_uid` exists before inserting an order. This prevents a payment from reaching the provider when the local merchant account cannot receive the successful transaction.

## Components

- `docker/PostgresBootstrap.php`: validated helper for restarting an empty PostgreSQL identity table.
- `docker/init-postgres.php`: invoke the helper after fresh initialization and schema upgrades.
- `admin/ajax_pay.php`: reject test payment creation when `test_pay_uid` is missing.
- `tests/PostgresUserIdentityTest.php`: integration coverage proving an empty identity starts at 1000 and a non-empty table is not reset.

## Existing Deployments

On container startup, an empty `pay_user` table is repaired automatically. If users already exist with IDs starting at 1, their IDs remain unchanged; the administrator must configure `test_pay_uid` to an existing merchant.

