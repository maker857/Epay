# Studio Homepage Design

## Goal

Add a personal/team studio homepage that can be selected from the existing admin homepage template picker without changing payment, user, or admin workflows.

## Design

- Add a new `template/studio` template discovered automatically by `lib\\Template::getList()`.
- Use a self-contained responsive layout with a dark editorial palette, warm paper surfaces, lime accent, project cards, capability list, process timeline, and contact CTA.
- Use existing local image assets from `template/index9/assets/picture` so the page has real visual content without adding an external dependency.
- Keep the existing `homepage` mode behavior and payment URLs unchanged. Selecting the `studio` template changes only the public `/` template.

## Verification

- Run PHP syntax checks for the new template.
- Build the Docker development stack and request `/` and `/admin/`.
- Confirm the admin template picker lists `studio` and the template switch endpoint accepts `studio`.
