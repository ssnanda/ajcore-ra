# Insurance and HOA mailbox parsing: research and design

Date: 2026-11-15. Data: every 2026 email in the Insurance (447) and HOA (616) tabs, bodies fetched read-only from Zoho, parsers run
offline against them with a stand-in for Rentec. Nothing was written to any live system.

## 1. What is in the mailboxes

### Insurance (insurance@intlord.com), 447 emails

| What | Emails | Notes |
|---|---|---|
| Auto-Owners "New or Updated Policy is ready for review" | 150 | policy number only, no property, no amount |
| Auto-Owners "Bill Added" | 79 | account #, policy #, **Minimum Due**, due date |
| Auto-Owners "Cancellation Invoice" | 71 | really a **past-due notice**: "will be canceled if the Minimum Due is not received by <date>"; 71 policies |
| Tenant renters-insurance proof (tenant, agent, forwards, COIs) | 36 | proof is an **attachment** (not readable); text gives tenant / address |
| eSignature applications, agent threads, underwriting surveys | 28 | underwriting surveys name the property and the repairs due |
| Interested-party notices (Lemonade, MSI) | 4 | name the tenant, address, policy # (MSI also the cancel date) |
| UPOS reception scans | 14 | PDF only |
| Account / login / marketing | 17 | labelled Notices |
| Steadily, CNA, AmTrust, Travelers, Progressive, GEICO | ~20 | own policies (landlord, commercial, workers comp, auto) |

Reference formats: Auto-Owners `55-827237-NN` (dwelling fire, **87 distinct policies**), `56-...` (personal), Lemonade `LP9C9A28752`,
Steadily `SP3-NC-24379944-01`, MSI `84418303`, AmTrust `KWC1397152`. Amount labels: Minimum Due, Account Balance, Payment Amount.

### HOA (hoa@intlord.com), 616 emails

Two roles share the mailbox:

- **Owner** (the owner's own properties): PayHOA / Associa (Grenelefe, Rose Croft II), Cedar / CMG (Devonshire Court, East Gate Village,
  Mallard Forest), Western Alliance ePay payment confirmations, Red Rock / CINC (Ashton Manor, Olmsted, Nevin Creek), TownSq (Michael's Landing).
  Kinds: charge, past due, statement, payment, upcoming / recurring payment, violation, request. **347 emails.**
- **Board** (the owner sits on Citiside and Mallard Woods II): ARC requests and comments (96), board tasks (28), management and board
  threads (142). Not tied to one of the owner's properties. **269 emails.**
- Community mail (newsletters, events, "app is live", "realtors of ...") is **193 emails** and is labelled Notices.

Violations: the notice ("Action Required at your property", association, date, sometimes a partial street) arrives by email, but the
violation type, cure-by date and photos are in the **attached letter / portal**, which the API cannot read.

## 2. Linking to properties and tenants

Rentec model (verified): homes and buildings have a street address; units have **no address** and a parent (`sub_of`); named groups with
no address are communities ("Mallard Forest", "Michaels Landing", "Mallard Woods", "Nevin Creek"). Tenants carry a `properties` list (current
unit) and an e-mail address.

| Link | How | Coverage on the data |
|---|---|---|
| Renters e-mail -> tenant | sender address = Rentec tenant e-mail | 14 of 105 distinct senders (all the tenant senders) |
| Tenant -> unit / property | tenant's current `properties` entry (lease as fallback) | 24 distinct tenants; 35 of 43 renters emails get a property |
| Address in text -> property | street address match; units collapse to the building unless the unit is named ("Apt 1-E") | all owner-side HOA charges, MSI, Lemonade, Steadily, agent threads |
| HOA association -> community | community name inside the association name | Mallard Forest, Michaels Landing, Mallard Woods, Nevin Creek |
| Policy / account # -> property | **learned** from any email that names both (agent / underwriting threads, PayHOA charges), then applied to the others | 4 of 87 Auto-Owners policies so far; rest need a one-time assignment |
| Association -> property | learned from emails with both; becomes ambiguous (blank) if a second property appears | Grenelefe -> 2802 Hosta Dr |

Result of the offline run: Insurance property missing on 318 of 447 (almost all Auto-Owners bills / renewals / past-due: **the carrier
never names the property**). HOA property missing on 52 of 616, mostly statements and ePay confirmations for Devonshire Court and East Gate
Village (account numbers only).

## 3. Rentec

Rentec's v3 API is create-only for the resources our integration knows (properties, tenants, leases, expenses, messages). A tenant's renters
policy **cannot be updated** through the API. Options: keep policy data in AJCore (this work), and optionally post a dated Rentec *message* on
the tenant as a log entry (not a field; cannot be edited afterwards).

## 4. What was built (not deployed)

- AJCore engine (mail pipelines): parsed columns (kind, ref_no, amount, due_date, property_tag, property_name, tenant, facts), a time-budgeted
  parse step, a learned-links table, Notices label, filters (type, needs attention), Sync (read + parse), hourly job also parses.
- AJCore-RA: `class-ra-property-match.php` (Rentec properties / units / communities / tenants), `class-ra-mail-insurance.php`,
  `class-ra-mail-hoa.php`, `class-ra-mail-util.php`; registered on the Insurance and HOA tabs.
- AJOps: same columns, filters, Sync, Notices, property editor. iOS not yet.

## 5. Parser results on the real data

Insurance kinds: renewal 161, bill 83, past due 71, renters proof 36, application 15, scan 14, other 12, promo 11, agency 8, underwriting 5, ...
HOA kinds: info 193, board thread 142, ARC 96, account 44, payment 29, board task 28, request 23, recurring payment 20, statement 15, charge 8,
past due 5, upcoming payment 5, violation 5, work order 3. Amounts, due dates and account numbers are filled for every bill, past-due notice,
payment confirmation and recurring-payment notice that carries them.

## 6. Open decisions

1. **Policy -> property for the 87 Auto-Owners policies**: a "Policies" view (policy #, latest bill, due date, past-due flag, property picker
   from Rentec) so each is assigned once. After that every bill, renewal and past-due notice is connected automatically.
2. **Past-due notices**: 71 policies got a "cancellation invoice" with a Sep 10, 2026 deadline. Confirm they were paid; the payment link is
   per billing account, not per policy.
3. **Renters coverage tracker**: expiry dates are in attachments (not readable). Options: enter the expiry by hand once per tenant, or
   mark "proof received <date>" and remind at 12 months.
4. **HOA violations**: track as items (association, property, notice date, status New / Responded / Closed) created from the email; details
   stay in the portal link.
5. **Workflow stages**: Insurance New > Reviewed > Done; HOA New > Reviewed > Done; Invoices New > Verified > Paid (Zoho PAID label).
6. Post-to-Rentec message button for renters insurance (optional).
