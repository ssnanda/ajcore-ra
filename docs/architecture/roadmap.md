# AJCore ecosystem roadmap

Last updated: 2026-11-05. Numbers are stable; do not renumber.

## Architecture (decided)

- **AJCore**: generic base plugin on every site. Base REST API (`/ajcore/v1`) and extension hooks.
- **AJCore-RA**: private extension for ncllcagents.com + universityofficesuites.com. Registers its routes into AJCore's REST namespace. Needs AJCore extension API >= `AJCORE_RA_MIN_EXTENSION_API` (23). RA cannot change AJCore; it only checks the number.
- **AJCore-HOA** (planned): private extension for wozig.com (AJCore + AJCore-HOA, no AJOps today).
- **AJOps** (Next.js) and the iOS app: one app each, able to switch between sites. Generic core screens plus one module per extension, shown only when the site's capabilities list includes it (`ajops-ra`, `ajops-hoa` as modules in the same codebase, not separate repos, unless someone else runs AJOps).
- Deploy order: AJCore, then RA (then HOA), then AJOps, then iOS.

## Phase 1: finish the AJCore-RA migration

1. [x] University Place brand (filters in AJCore, defaults in RA)
2. [x] Flyers: one shared file each, no mail import, manual update UI, auto-share to RA / ncllcagents.com customers
3. [x] File categories and tags moved to RA (AJCore ships generic defaults; RA-only names hidden on non-RA sites)
4. [x] Articles of Organization folded into Formation Documents
5. [x] Expense invoices moved to RA (low use; may fold into Requests, see 12)
6. [ ] AJPhone to RA
7. [ ] Zoho calendar / Reservations (left alone until tested)
8. [ ] Luma: remove LUMA_* from the live OpenShift ConfigMap/Secret
9. [ ] Live ddev.site cleanup (see memory to-do)

Not moving: **Service Requests** (see 12) and the **accounting catalog** (core Products & Services uses it).

## Phase 2: ajcore-hoa (basic)

10. [ ] Create the plugin: registers with `AJCore_Extensions`, requires the same minimum API check as RA.
11. [ ] HOA fields and screens on top of base AJCore (leads exist in base).
12. [ ] **Requests as workflows** (generic, in AJCore):
    - A request has a type, ordered steps, a current step, assignees, due dates, notes, attachments, vendor/payee, costs and payments, and a history log.
    - Invoices and payments are steps/fields of a request, not a separate screen.
    - Extensions supply workflow templates. HOA example: repair request, vendor assigned, work done, pending vendor receipt, payment, closed. RA's add-service / change-of-RA flows become templates later.
    - Today's Service Requests are Stripe-tied (customer + price/product); build the generic engine beside them, migrate RA over afterwards.
13. [ ] HOA inspections as a request type with its own steps.

Open questions for 12/13: exact HOA steps, who sees what (do owners see requests?), whether vendors get a portal.

## Phase 3: AJOps and iOS

14. [ ] AJCore capabilities endpoint (active extensions and features).
15. [ ] AJOps site switcher (check how the AJCore URL and login token are stored today).
16. [ ] AJOps module structure: `ra` and `hoa` module folders, registry of nav items and routes, hidden when not in capabilities.
17. [ ] iOS app: same capabilities list and site switching (one app, or a second app if needed).
18. [ ] Build the HOA AJOps module.

## Notes

- Rentec: create-only API (no update/delete except sticky notes). AJOps Rentec screens, forms and file upload exist; test with live keys. Property/tenant note screens not built.
- Message "Log Message" in Rentec records a communication; it does not send email.
