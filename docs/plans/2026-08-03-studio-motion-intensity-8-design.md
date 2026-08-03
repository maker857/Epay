# Studio Homepage Motion Intensity 8 Design

## Design read

This is a redesign-preserve pass for an independent creative studio homepage aimed at prospective clients. The existing editorial composition, content hierarchy, neutral green palette, and backend template-switching behavior remain intact. Motion becomes more cinematic and spatial without turning the site into a demo reel.

## Design dials

- `DESIGN_VARIANCE: 8`
- `MOTION_INTENSITY: 8`
- `VISUAL_DENSITY: 4`

## Motion concept

The page uses cinematic editorial choreography. Motion communicates hierarchy, progression, and interaction feedback:

- Navigation and hero copy enter in a deliberate sequence so the value proposition is read before the supporting visual.
- Hero typography uses clipped line reveals while the artwork arrives with depth, subtle tilt, and layered drift.
- Content sections reveal as they enter the viewport using `IntersectionObserver`, avoiding continuous scroll handlers.
- Project cards use restrained pointer tilt, spotlight movement, image depth, and a press response to make the work feel tangible.
- The approach list reveals in sequence to reinforce the studio process.
- Contact content closes with a focused scale and translation reveal rather than another repeated card treatment.

## Timing and physics

- Primary entry duration: `900ms`
- Hero stagger: `90ms`
- Scroll reveal duration: `760ms`
- Scroll reveal stagger: `80ms`
- Primary easing: `cubic-bezier(.16, 1, .3, 1)`
- Hero art entrance: `1100ms`
- Hero ambient drift: `10s` with approximately `16px` travel
- Project hover transition: `450ms`
- Pointer tilt limit: `2.4deg`
- Pointer translation limit: `6px`
- Artwork parallax limit: `28px`
- Orbit duration: `22s` to `28s`

## Implementation architecture

The existing PHP template remains server-rendered. CSS owns initial load choreography, hover states, ambient animation, and motion-reduction fallbacks. A small native JavaScript file owns viewport reveals and pointer physics. It uses `IntersectionObserver`, `requestAnimationFrame` for pointer transforms, and cleanup through abortable listeners. No animation framework or new runtime dependency is introduced.

JavaScript adds an enhancement class to the document root. Content remains visible when JavaScript is unavailable. Initial hidden states only activate after the enhancement class is present.

## Responsive and accessibility behavior

- Below `800px`, pointer tilt and parallax are disabled.
- Touch devices retain tap feedback but not cursor-dependent effects.
- `prefers-reduced-motion: reduce` disables entrance animation, ambient loops, tilt, parallax, and smooth scrolling.
- Keyboard focus remains visually clear and never depends on animation.
- Animation changes only `transform`, `opacity`, and CSS custom properties used by transforms.

## Scope boundaries

- Preserve section order, anchor IDs, navigation labels, and backend homepage selection.
- Preserve the current light theme and single lime accent.
- Do not add scroll hijacking, custom cursors, multiple marquees, audio, WebGL, or heavy third-party libraries.
- Correct malformed arrow glyphs and other visible encoding artifacts encountered in the template.

## Verification

- A static regression test verifies required motion hooks, the external script, IntersectionObserver usage, motion intensity marker, and reduced-motion coverage.
- PHP syntax is checked for the template and the regression test.
- The complete project test suite is run in Docker.
- The page is rendered locally and inspected at desktop and mobile widths.

