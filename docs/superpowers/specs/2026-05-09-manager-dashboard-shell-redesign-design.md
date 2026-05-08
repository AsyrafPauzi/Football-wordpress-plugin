# Manager Dashboard Shell Redesign (Spec)

## Goal
Deliver a visibly different Team Manager Dashboard with:
- a true left navigation shell on desktop,
- mobile-first table behavior that preserves real `<table>` markup with horizontal swipe,
- clearer priority for match fees and urgent manager actions,
- maximum desktop content width of `1200px`,
- no backend behavior regressions (same POST keys, nonces, field names, IDs/hooks used by JS).

## Scope
### In scope
- `includes/class-flms-manager-dashboard.php` markup restructuring
- `assets/css/flms-style.css` dashboard-scoped shell/layout styling
- Keep existing actions/handlers unchanged
- Keep existing transfer and edit modal JS compatibility
- Sidebar links use real URLs where available; otherwise safe in-page anchors

### Out of scope
- New backend routes/controllers
- Data model changes
- Changes to payment/transfer business logic
- Replacing tables with card rows on mobile

## Constraints (Must Keep)
- Preserve all form `name` attributes, hidden inputs, action values, nonces.
- Preserve JS-sensitive selectors/IDs/classes:
  - `#flms-edit-modal`
  - `.edit-player-btn`
  - `.close-modal`
  - `#btn-check-transfer`
  - `#trans_ic`
  - `#flms-transfer-form`
  - `#flms-edit-modal form` submit behavior
  - classes used by `flms-checkout.js` loading states (`.flms-add-player-box`, `.flms-transfer-box`)
- Do not modify the plan file.

## Information Architecture
## Navigation Shell
- Desktop (`>=1024px`):
  - Left sticky sidebar with sections:
    - Dashboard
    - Players
    - Create Friendly Match
    - Inbox
    - Team Settings
    - Matches
      - Friendly Matches
      - League Matches
  - Right content column for all dashboard modules.
- Mobile (`<1024px`):
  - Sidebar collapses into top horizontal nav rail (scrollable chips/links).

## Content Priority (Main Column)
1. Team identity + status (hero)
2. Summary metrics/alerts row:
   - unpaid match fees
   - incoming transfer requests
   - transfer payments pending
3. Match Fees panel (promoted, near top)
4. Incoming Transfers / Pending Transfer Payments
5. Next Match Lineup
6. Full Squad table
7. Register New Player + Sign Player forms
8. Match history tabs/panels (Friendly + League)
9. Account/Team settings

## URL Strategy
- Real links where available:
  - Inbox URL (existing `$inbox_url`)
  - Create Friendly Match URL (existing `$create_url`)
- Internal anchor links for sections that remain in same view (`#flms-mgr-dashboard`, `#flms-mgr-players`, `#flms-mgr-settings`, etc.).
- Preserve current history tab behavior for friendly/league query arg switching.

## UX and Visual System
## Container and Grid
- Dashboard shell max width: `1200px` on laptop/desktop.
- Inner shell:
  - desktop: `grid-template-columns: 260px minmax(0,1fr)`
  - tablet/mobile: single column.

## Table Responsiveness (Required by user)
- Keep real table markup at all breakpoints.
- Every dense table wrapped in `.flms-table-responsive` and/or `.flms-mgr-scroll`.
- Ensure:
  - `overflow-x: auto`
  - table `min-width` set sufficiently (e.g. `680px+`) to avoid clipping
  - no forced card conversion on mobile
  - action column remains readable and not over-compressed

## Buttons / Actions
- Action buttons remain visible and tappable on small screens.
- Prevent text clipping in action cells.
- Keep hierarchy:
  - Primary (gold): key submit/pay actions
  - Secondary/Ghost: edit/update
  - Danger: remove/reject

## Accessibility
- Maintain label associations (`for` + `id`) on form fields.
- Sidebar/nav sections use clear landmarks and heading hierarchy.
- Keep modal close accessible with button and label.
- Ensure focus/contrast remain visible in dark theme.

## File-by-File Design
## 1) `includes/class-flms-manager-dashboard.php`
- Add outer shell wrappers:
  - `.flms-mgr-shell`
  - `.flms-mgr-shell__sidebar`
  - `.flms-mgr-shell__main`
- Sidebar markup:
  - grouped nav links and match submenu
  - active states based on current context/query.
- Reorder existing section blocks into new priority order.
- Add section IDs to support anchor nav.
- Keep all existing form internals and hidden fields intact.
- Keep `render_completed_matches_panel()` output integrated with new shell sections.
- Keep `render_create_team_form()` visually aligned with new design language.

## 2) `assets/css/flms-style.css`
- Extend current manager scoped styles with shell-specific classes.
- Set max width to `1200px`.
- Add sidebar styles (sticky desktop, horizontal mobile rail).
- Remove/override mobile table-card behavior so tables remain tables.
- Strengthen horizontal scroll affordance.
- Improve squad/action-column sizing to avoid truncation.
- Keep all changes scoped under manager dashboard selectors to avoid global regressions.

## Risks and Mitigations
- Risk: hidden regressions from renaming hooks/classes.
  - Mitigation: additive classes, preserve existing IDs/classes used by JS.
- Risk: mobile clipping in squad actions.
  - Mitigation: explicit action column width + nowrap strategy + scroll container width safeguards.
- Risk: navigation links to non-existent routes.
  - Mitigation: use real URLs only where available; fallback anchors for same-page sections.

## Verification Plan
- PHP syntax:
  - `php -l includes/class-flms-manager-dashboard.php`
- UI checks:
  - desktop/laptop (`>=1024px`): sidebar visible, content width capped at `1200px`
  - tablet/mobile: top nav rail works, tables scroll horizontally
- Functional checks:
  - save lineup
  - pay match fee
  - pay transfer fee
  - add player
  - edit player (modal + submit)
  - remove player
  - sign player transfer flow
  - logo upload
  - password change
- JS compatibility:
  - confirm `flms-checkout.js` submit loading still triggers for add/transfer forms
  - confirm edit modal open/close unchanged.

## Rollout Notes
- Implement in one stream but verify after major blocks:
  1) shell + sidebar
  2) panel reorder and anchors
  3) responsive table behavior
  4) polish and final regression pass

