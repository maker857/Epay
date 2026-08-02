# PostgreSQL Merchant UID Compatibility Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Preserve the upstream merchant UID start of 1000 on PostgreSQL and prevent test orders for nonexistent merchants.

**Architecture:** Add a small PostgreSQL bootstrap helper that only restarts an identity when its table is empty. Invoke it on every initialization run, then validate the configured test merchant before creating an admin test order.

**Tech Stack:** PHP 8.3, PDO PostgreSQL, Docker Compose

---

### Task 1: Add identity-start regression coverage

**Files:**
- Create: `tests/PostgresUserIdentityTest.php`
- Create: `docker/PostgresBootstrap.php`

**Steps:**
1. Write an integration test that creates an isolated identity table.
2. Assert that the first inserted row receives ID 1000 after reconciliation.
3. Assert that a non-empty table is not reset.
4. Run the test and confirm it fails before the helper exists.
5. Implement the minimal validated helper and rerun the test.

### Task 2: Apply reconciliation during bootstrap

**Files:**
- Modify: `docker/init-postgres.php`

**Steps:**
1. Load `PostgresBootstrap.php`.
2. Reconcile `<prefix>_user.uid` to start at 1000 after initialization or upgrade.
3. Run PHP syntax checks and the PostgreSQL integration test.

### Task 3: Reject invalid test merchants

**Files:**
- Modify: `admin/ajax_pay.php`
- Create: `tests/TestPaymentMerchantValidationTest.php`

**Steps:**
1. Add a failing source-level regression test for merchant existence validation.
2. Query `pre_user` using the configured test UID before creating an order.
3. Return a clear admin error when the user does not exist.
4. Run syntax and regression tests.

### Task 4: Verify and publish

**Files:**
- Verify all files above.

**Steps:**
1. Run `php -l` on modified PHP files in Docker.
2. Run the focused tests.
3. Run `git diff --check`.
4. Commit and push to `origin/main`.

