# Studio Motion Intensity 8 Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Upgrade the Northstar Studio homepage to a cinematic, accessible `MOTION_INTENSITY: 8` motion system while preserving its content structure and backend template selection.

**Architecture:** Keep the page server-rendered in PHP. Add semantic motion hooks to the existing markup, move interaction logic into one dependency-free JavaScript file, and implement choreography through CSS transforms, opacity, and custom properties. Enhancement-only hidden states are gated by a JavaScript class so the page remains readable without JavaScript.

**Tech Stack:** PHP 8.3, native CSS, native JavaScript, IntersectionObserver, requestAnimationFrame, Docker Compose

---

### Task 1: Add the motion contract regression test

**Files:**
- Create: `tests/StudioMotionDesignTest.php`

**Step 1: Write the failing test**

Create a static regression test that reads the studio PHP, CSS, and planned JavaScript files. Assert that the template loads `studio.js`, exposes reveal and tilt hooks, and has no malformed replacement glyphs. Assert that CSS declares `--motion-intensity: 8`, contains reduced-motion handling, and avoids `transition: all`. Assert that JavaScript uses `IntersectionObserver`, `requestAnimationFrame`, `matchMedia('(prefers-reduced-motion: reduce)')`, and does not register a window scroll listener.

**Step 2: Run test to verify it fails**

Run:

```powershell
docker compose --env-file G:\epay\.env -f G:\epay\docker-compose.yml run --rm -v "G:\epay\.worktrees\studio-motion-8:/var/www/html" app php tests/StudioMotionDesignTest.php
```

Expected: FAIL because `studio.js` and the intensity-8 hooks do not exist.

**Step 3: Commit the failing test**

```powershell
git add tests/StudioMotionDesignTest.php
git commit -m "test: define studio motion intensity 8 contract"
```

### Task 2: Add semantic motion hooks and native interaction controller

**Files:**
- Modify: `template/studio/index.php`
- Create: `template/studio/assets/js/studio.js`

**Step 1: Add minimal markup hooks**

Add `data-reveal`, `data-reveal-group`, `data-reveal-item`, `data-tilt`, `data-parallax`, and magnetic-link hooks to existing elements without changing IDs, navigation labels, or section order. Wrap hero headline lines in clipping spans and add decorative layers inside the existing hero artwork. Load `studio.js` with `defer`.

**Step 2: Implement progressive enhancement**

In `studio.js`:

- Add `motion-ready` to the root element.
- Stop immediately when reduced motion is requested.
- Reveal elements with `IntersectionObserver` and unobserve after entry.
- Batch pointer transforms with `requestAnimationFrame`.
- Limit card tilt to `2.4deg`, translation to `6px`, and hero parallax to `28px`.
- Reset transforms on pointer leave.
- Use abortable event listeners for cleanup.
- Do not use window scroll listeners.

**Step 3: Run syntax checks**

Run:

```powershell
docker compose --env-file G:\epay\.env -f G:\epay\docker-compose.yml run --rm -v "G:\epay\.worktrees\studio-motion-8:/var/www/html" app php -l template/studio/index.php
```

Expected: `No syntax errors detected`.

**Step 4: Commit**

```powershell
git add template/studio/index.php template/studio/assets/js/studio.js
git commit -m "feat: add studio motion interaction controller"
```

### Task 3: Implement cinematic motion styling

**Files:**
- Modify: `template/studio/assets/css/studio.css`

**Step 1: Implement load and reveal choreography**

Add explicit motion tokens for the intensity level, easing, durations, and stagger. Animate navigation, hero copy, headline lines, hero art, and supporting labels in a readable sequence. Gate hidden states behind `.motion-ready`.

**Step 2: Implement spatial artwork and project feedback**

Add layered hero depth, slow artwork drift, rotating orbit accents, reveal transforms, card spotlight variables, pointer tilt variables, image depth, link arrow travel, and tactile active states. Animate only transforms and opacity.

**Step 3: Add responsive and accessibility fallbacks**

Disable pointer physics on coarse pointers and below `800px`. Under reduced motion, remove transforms, transitions, ambient loops, reveal hiding, and smooth scrolling.

**Step 4: Run the regression test**

Run:

```powershell
docker compose --env-file G:\epay\.env -f G:\epay\docker-compose.yml run --rm -v "G:\epay\.worktrees\studio-motion-8:/var/www/html" app php tests/StudioMotionDesignTest.php
```

Expected: PASS.

**Step 5: Commit**

```powershell
git add template/studio/assets/css/studio.css
git commit -m "feat: redesign studio motion at intensity 8"
```

### Task 4: Verify rendering and the complete regression suite

**Files:**
- Modify if required: `template/studio/index.php`
- Modify if required: `template/studio/assets/css/studio.css`
- Modify if required: `template/studio/assets/js/studio.js`

**Step 1: Run static and syntax verification**

Run the motion test, `php -l` for the template and test, and `git diff --check`.

**Step 2: Run the complete test suite**

Run:

```powershell
docker compose --env-file G:\epay\.env -f G:\epay\docker-compose.yml run --rm -v "G:\epay\.worktrees\studio-motion-8:/var/www/html" app sh -lc 'set -e; for test in tests/*Test.php; do php "$test"; done'
```

Expected: all test commands exit 0.

**Step 3: Render locally and inspect**

Serve the worktree through Docker or a temporary PHP rendering harness. Inspect desktop and mobile widths, confirm the initial viewport contains the hero CTA, confirm motion fires once, and confirm reduced-motion content remains visible.

**Step 4: Run the design pre-flight audit**

Check motion motivation, reduced-motion behavior, no scroll listener, one accent color, one radius system, mobile collapse, readable button contrast, and no visible encoding artifacts.

**Step 5: Commit verification adjustments**

```powershell
git add template/studio tests/StudioMotionDesignTest.php
git commit -m "fix: polish studio motion accessibility"
```

