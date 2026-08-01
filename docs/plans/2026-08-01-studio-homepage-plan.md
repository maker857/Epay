# Studio Homepage Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a selectable personal/team studio homepage while preserving all existing payment and admin behavior.

**Architecture:** The existing template loader automatically resolves `template/<name>/index.php` and the admin picker enumerates template directories. Add one new template directory with local CSS and a preview image, leaving database settings and routing unchanged.

**Tech Stack:** PHP templates, semantic HTML, CSS, existing Docker Compose PHP 8.3 development stack.

---

### Task 1: Add the studio template shell

**Files:**
- Create: `template/studio/index.php`
- Create: `template/studio/assets/css/studio.css`

Implement the studio layout using the existing `IN_CRONLITE`, `INDEX_ROOT`, and `STATIC_ROOT` conventions. Include responsive navigation, hero, project cards, capabilities, process, and contact sections. Use escaped configured site text where displayed and local image paths only.

### Task 2: Add an admin preview asset

**Files:**
- Create: `template/studio/preview.png`

Provide a representative preview so the existing admin template picker can display the new option. If a generated bitmap is unavailable, use an existing local image copied into the template directory as a fallback preview.

### Task 3: Verify template discovery and rendering

Run:

```powershell
php -l template/studio/index.php
php -l template/studio/assets/css/studio.css
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build
curl.exe -sS -o NUL -w "%{http_code}\n" http://127.0.0.1:8090/
```

Confirm the admin page lists `studio`, then switch the template through the existing `ajax.php?act=set` endpoint and confirm `/` returns the studio markup.

### Task 4: Commit the implementation

```powershell
git add docs/plans template/studio
git commit -m "feat: add selectable studio homepage template"
```
