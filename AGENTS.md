
# Book_Store — AI Agent Instructions

## 1. Project Context

Book_Store is a Laravel 12 portfolio project for a single-brand, multi-branch bookstore selling printed books and eBooks.

The system includes:
- A customer-facing online bookstore.
- Commerce administration.
- Central warehouse and branch operations.
- A supplier portal.
- Printed-book inventory and FIFO cost accounting.
- Digital-book entitlements, reading, downloads, expiration, and renewal.
- Role-based access control with branch-level data restrictions.
- Auditable approval and change-management workflows.
- Book discovery and personalized recommendations.

This is not a multi-vendor marketplace. Suppliers provide goods to Book_Store; customers purchase from Book_Store.

## 2. Source of Truth and Conflict Resolution

The project constitution at
`.specify/memory/constitution.md` defines the project's
non-negotiable engineering and business-integrity principles.

Approved feature specifications define the expected behavior
and acceptance criteria for their respective modules.

Current user instructions define the task to perform and may
clarify or propose changes to previously approved requirements.

Follow these rules:

1. Comply with the project constitution.
2. Implement the relevant approved feature specification.
3. Follow current user instructions within the approved scope.
4. Apply this AGENTS.md and established repository conventions.
5. Use installed skills as supporting guidance.

Conflict resolution:
- Never silently override the constitution.
- A new user request that conflicts with the constitution
  must be treated as a proposed amendment, not an automatic
  override.
- Stop the conflicting work, explain the conflict, and
  request an explicit decision.
- If the owner approves an amendment, update the constitution
  and affected specifications before implementing the change.
- If current instructions conflict with an approved feature
  specification, request clarification or an approved
  specification update before implementation.
- Do not invent missing business rules.

Higher-priority platform and safety instructions remain
applicable regardless of repository documents.


## 3. Required Working Process

Work on one approved module or vertical slice at a time.

### Before implementation

1. Read the constitution and relevant approved specification.
2. Inspect existing code, migrations, dependencies, and tests.
3. Identify dependencies, integration risks, and unresolved decisions.
4. Present a bounded implementation plan, including:
   - Scope and expected deliverables.
   - Expected file and database changes.
   - Dependencies and integration points.
   - Testing and acceptance criteria.
   - Known risks and assumptions.
5. Obtain project-owner approval for the module implementation plan.

### After plan approval

The agent may execute the approved plan without requesting
separate approval for each routine file or implementation step.

This includes creating or modifying controllers, models,
migrations, policies, views, tests, and other files reasonably
required by the approved plan.

The agent must pause and request additional approval when:
- A change materially expands the approved scope.
- A new dependency or major architectural change is required.
- An unresolved business rule affects correctness.
- A destructive database operation is proposed.
- An authorization, financial, inventory, or entitlement
  invariant would change.
- A constitution amendment becomes necessary.

### During implementation

- Keep changes within the approved scope.
- Implement complete vertical slices.
- Reuse established project patterns.
- Avoid unrelated refactoring.
- Preserve backward compatibility where required.
- Record and report significant deviations from the plan.

### After implementation

- Run relevant automated tests and quality checks.
- Review authorization, security, and data integrity.
- Report changed files, test results, unresolved issues,
  and deviations.
- Do not mark the module complete until its approved
  acceptance criteria are satisfied.

## 4. Laravel Engineering Rules

- Use Laravel 12 conventions and native framework features by default.
- Prefer clear, maintainable code over speculative abstractions.
- Keep controllers focused on HTTP concerns.
- Centralize important business operations in appropriate application/domain services when complexity warrants it.
- Use Form Requests or equivalent validation for user input.
- Enforce authorization server-side with Policies, Gates, and explicit scope checks.
- Use database transactions for multi-record business operations.
- Prevent race conditions in inventory, payment, and entitlement operations.
- Use queues only where asynchronous processing is justified.
- Follow existing frontend conventions; do not introduce a new frontend framework without approval.
- Optimize queries using pagination, eager loading, indexing, and measurement.

Do not assume that an installed package is compatible with Laravel 12. Verify compatibility before proposing adoption.

## 5. Business Invariants

Never violate these rules:

### Products
- A book work may have multiple sellable editions or formats.
- Printed books and eBooks share catalog concepts but have different fulfillment rules.
- Suppliers are distinct from publishers and Book_Store branches.

### Inventory
- Supplier-declared stock is not Book_Store-owned stock.
- Printed-book inventory belongs to a specific Book_Store location.
- Receiving, reservation, dispatch, transfer, and adjustment must preserve stock integrity.
- Branch-to-branch stock transfers pass through the central warehouse.
- FIFO procurement-lot provenance and cost must be preserved.
- Internal transfers do not create sales revenue.

### Orders and Payments
- One customer order may contain printed books and eBooks.
- An order may have multiple payment transactions.
- Payment state, physical fulfillment state, and digital entitlement state are distinct.
- Never grant an eBook entitlement without verified satisfaction of its payment condition.
- For full COD mixed orders, grant eBook access only after successful delivery and verified collection of the required payment.
- For prepaid-eBook plus printed-book COD orders, grant eBook access after its allocated prepaid amount is verified.
- Prevent duplicate charges, duplicate entitlements, and overselling.

### eBooks
- An account is required to purchase and access eBooks.
- Access is account-bound and checked on every protected request.
- Default access is long-term unless a clearly disclosed license specifies expiration.
- Renewal follows the applicable license policy.
- Private full-content files must not be publicly accessible.
- Preview content must be separated from purchased content.

### Authorization and Audit
- Roles and permissions are configurable.
- Staff actions are limited to their assigned branch or authorized scope.
- Cross-branch stock visibility does not imply cross-branch edit permission.
- Supplier users may access only their own supplier data.
- Sensitive operations require appropriate approval.
- Changes must be auditable.
- Content undo is limited to 10 minutes and must reject conflicting or unsafe restoration.
- Inventory, finance, and entitlement corrections use controlled compensating operations.

## 6. Testing Requirements

For each completed vertical slice, test:
- Successful business workflows.
- Validation and authorization failures.
- Branch and supplier data isolation.
- Relevant concurrency and duplicate-request scenarios.
- Transactional integrity and rollback on failure.
- Critical edge cases identified in the specification.

Do not claim a test passed unless it was actually executed.

## 7. Tool and Skill Coordination

### GitHub Spec Kit
Use Spec Kit for specification-driven development:
- Constitution and requirements.
- Clarification of business rules.
- Implementation planning.
- Task breakdown.
- Acceptance criteria and traceability.

Do not start implementation from an incomplete or unapproved specification.

### Ponytail
Use relevant Ponytail guidance for Laravel-oriented design and review:
- Framework-native approaches.
- Simplicity and maintainability.
- Security and data integrity.
- Avoidance of unnecessary abstractions.

Ponytail is advisory; it does not override approved business rules.

### Impeccable
Use relevant Impeccable guidance for UI/UX work:
- Visual hierarchy and consistency.
- Accessibility and responsive layouts.
- Form usability, feedback, and error states.
- Design review and refinement.

Do not prioritize visual polish over functional correctness or accessibility.

Use installed skills only when relevant. Do not invoke every skill for every task.

## 8. Agent Communication

- Communicate with the project owner in Vietnamese.
- Use English for code identifiers and technical conventions unless established project patterns require otherwise.
- Clearly distinguish confirmed requirements, recommendations, and assumptions.
- Ask for clarification before irreversible or high-impact changes.
- Never invent successful test results, completed work, or verified behavior.
- Report blockers and trade-offs directly.

## 9. Change Boundaries

Do not perform the following unless they are explicitly included in an approved plan or separately authorized by the project owner:
- Create or modify unrelated modules.
- Reset or destructively alter databases.
- Delete existing user data.
- Change established financial or inventory rules.
- Change authorization boundaries.
- Install substantial dependencies or introduce infrastructure.
- Deploy to production.
- Commit, push, or publish work unless requested.

## 10. Code Flow Documentation and Navigation

All important application workflows MUST be easy to trace, understand, and explain.

### Flow Documentation Comments

Add concise flow comments at important entry points and integration boundaries, including:
- Routes that initiate significant workflows.
- Controller methods handling important user actions.
- Services or Actions implementing complex business operations.
- Blade views containing important forms or navigation actions.
- Alpine.js components implementing non-trivial interactions.

When relevant, comments SHOULD identify:
- Entry point: which route, controller, event, or component initiates the flow.
- Next step: which class, method, or route handles the action.
- Output: which Blade view, response, or event is produced.
- Business rules: important authorization, validation, or transaction requirements.

### Comment Quality

- Use concise, meaningful comments rather than documenting every line.
- Prefer route names, class names, and method names over hard-coded file paths.
- Include file paths only when they materially improve navigation.
- Explain why complex business rules exist, not merely what the code does.
- Do not duplicate information already obvious from the code.
- Never include secrets or sensitive information in comments.
- Keep comments synchronized when routes, methods, or views change.

### Workflow Traceability

For complex modules, maintain a short workflow description showing:

User Action → Route → Controller → Service/Action
→ Model/Database → Response/Blade View

For Alpine.js interactions, also document:

User Interaction → Alpine.js Component
→ Client-side State Change or HTTP Request
→ Laravel Route/Controller → UI Update

Documentation MUST reflect the actual implementation,
not an assumed or planned execution path.

Keep changes reviewable and reversible wherever practical.

## 11. Frontend Architecture and Fluid Responsiveness Standards

All Book_Store user interfaces MUST adhere to strict separation of concerns and continuous fluid responsiveness across every module:

### Asset Separation
- **Blade Templates (`resources/views/`)**: Own semantic HTML structure, Laravel directives, data rendering, and accessible ARIA markup. Avoid large inline `<style>` or `<script>` blocks.
- **Stylesheets (`resources/css/`)**: Global design tokens and Tailwind CSS v4 configuration belong in `resources/css/app.css`; module-specific styles belong in dedicated CSS files under `resources/css/`.
- **Scripts (`resources/js/`)**: Shared initialization belongs in `resources/js/app.js`; module-specific interactions and Alpine.js data components belong in dedicated JS files under `resources/js/` and MUST be registered before Alpine initialization without creating duplicate Alpine instances.

### Mandatory Fluid Responsiveness ("Book_Store — Liquid White")
- Every screen MUST support **continuous browser resizing** from `320px` up to `1920px+` (including all intermediate widths: `320px`, `360px`, `390px`, `430px`, `480px`, `600px`, `768px`, `900px`, `1024px`, `1280px`, `1440px`, `1920px`) and **200% browser zoom** without horizontal overflow, overlapping elements, clipped controls, or abrupt breakpoint jumps.
- Use mobile-first CSS with fluid sizing (`clamp()`, `min()`, `max()`, `minmax()`, Flexbox, CSS Grid, and `min-width: 0` on flex/grid children).
- Ensure all interactive touch targets meet a minimum `44×44px` hit area, inputs use at least `16px` (`1rem`) font size to prevent forced mobile browser zoom, and long Vietnamese labels or validation messages wrap cleanly (`overflow-wrap: break-word`).

## 12. Centralized Business Values & Canonical Enums

Follow the permanent architectural principle: **ONE BUSINESS CONCEPT = ONE CANONICAL DEFINITION.**

- Fixed-value business concepts MUST live in PHP 8.3 string-backed Enums under `app/Enums/` with lowercase `snake_case` database values, `PascalCase` case names, explicit Vietnamese labels via `label(): string` backed by `lang/vi/enums.php`, and valid transition helpers where applicable.
- Do not duplicate raw business status strings across models, actions, middleware, factories, or views. Do not introduce Enums for unimplemented modules.
- **Canonical Authentication & Access Enums**:
  - `App\Enums\AccountStatus` (`users.account_status`, default `active`): `active`, `suspended`.
  - `App\Enums\StaffAccessStatus` (`users.staff_access_status`, default `not_applicable`):
    - `not_applicable`: Customer account with no staff relationship (mandatory default for registration, factories, and database schema).
    - `pending`: Staff account awaiting activation.
    - `active`: Activated staff account (prerequisite status only; NEVER independently grants administrative or branch permissions without explicit RBAC & branch-scope checks).
    - `inactive`: Staff account whose staff access has been disabled (never substitute `inactive` for `not_applicable`).
  - `App\Enums\LoginEventType` (`login_events.event_type`): `register_success`, `login_success`, `login_failed`, `account_locked`, `logout`, `session_revoked`.
- **Canonical RBAC, Branch & Audit Enums (Stage 01B.2)**:
  - `App\Enums\BranchType` (`branches.type`): `central_warehouse`, `retail_branch` (at most one `central_warehouse`; never converted to `retail_branch`).
  - `App\Enums\BranchStatus` (`branches.status`): `active`, `inactive` (`central_warehouse` must never transition to `inactive`).
  - `App\Enums\RoleScope` (`roles.scope`, `permissions.scope`): `global`, `branch`.
  - `App\Enums\BranchAssignmentStatus` (`branch_assignments.status`): `active`, `inactive`.
  - `App\Enums\StaffApprovalStatus` (`staff_activation_proposals.status`): `pending`, `approved`, `rejected`, `cancelled` (`pending` transitions only to terminal states `approved`, `rejected`, `cancelled`).
  - `App\Enums\AuditAction` (`audit_logs.action`): `staff_created`, `staff_linked`, `temp_password_issued`, `forced_password_changed`, `staff_activation_proposed`, `staff_activation_approved`, `staff_activation_rejected`, `staff_suspended`, `staff_reactivated`, `role_assigned`, `role_revoked`, `branch_assigned`, `branch_removed`, `account_suspended`, `account_reactivated`, `super_admin_bootstrapped`, `branch_created`, `branch_updated`.

## 13. Stage 01B.2 → Stage 01B.3 Architecture & Invariant Notes

All Stage 01B.3+ services, middleware, policies, commands, and UI workflows MUST enforce these invariants:

### Four Mandatory Corrections
- **Correction A — Domain Invariants Above `Gate::before`**: Even if Super Admin receives broad capability checks via `Gate::before` or authorization resolvers, Super Admin MUST NOT bypass domain invariants (self-action prohibition, Last Effective Super Admin protection, `central_warehouse` immutability/active guarantee, or approval segregation).
- **Correction B — Independent Onboarding Route Group**: The forced temporary-password change onboarding route group MUST remain independent of `staff.active` middleware so both `pending` and `active` staff holding a temporary password can complete their mandatory password change first; afterward, `pending` staff MUST stay blocked from operational staff routes until their activation proposal is approved.
- **Correction C — SQLite-Compatible Concurrency for Last Effective Super Admin**: Do not rely on `lockForUpdate()` row locks (no-op in SQLite) or assume PHP 8.3 `BEGIN IMMEDIATE` works uniformly without explicit handling. Wrap Super Admin revocation, deactivation, or suspension checks inside an atomic transaction with driver-aware serialization and post-check re-verification before commit so at least one effective Super Admin (`account_status = active`, `staff_access_status = active`, and holding an active Super Admin global role) always remains.
- **Correction D — Deferred Bootstrap CLI**: `bookstore:bootstrap-super-admin` is intentionally deferred to Stage 01B.3+ because it depends on credential issuance, RBAC assignment, and audit-logging services implemented in later stages.

### Staff Governance & Audit Contracts
- **Strict Self-Action Guard (Decision 2.6)**: An actor MUST NOT approve/reject their own `staff_activation_proposals`, suspend/reactivate/deactivate their own staff access or account, or revoke their own Super Admin / administrative roles.
- **Last Branch Removal Contract (Decision 2.7)**: Removing or deactivating a staff member's last `active` branch assignment MUST atomically transition `users.staff_access_status` to `inactive` within the same transaction — sparing users who hold an active global `SuperAdmin` role (`role_scope = global`).
- **AuditLog Append-Only & Zero-Secret Rule**: `audit_logs` records are strictly append-only (never updated or deleted in normal application flows) and MUST NEVER contain plaintext passwords, temporary passwords, password hashes, session IDs, tokens, or secrets in `old_values`, `new_values`, or `reason`.
- **Composite `(user_id, branch_id)` Query Rule (Stage 01B.2.1)**: `BranchAssignment` MUST NOT expose a single-key `HasMany` Eloquent relation for `branch_role_assignments` (to prevent cross-user leakage during eager loading, `whereHas()`, or `withCount()`). Use `BranchAssignment::matchingRoleAssignments()` for single-assignment lookups (returns empty query if either key is null) or `User::branchRoleAssignments()` / direct `(user_id, branch_id)` queries.
- **Approved Stage 01B.3 Permission Additions (Pending Catalog Reconciliation)**:
  - `staff.reset_temp_password` (`global`, Super Admin)
  - `staff.suspend` (`global`, Super Admin)
  - `roles.view` (`branch`, Branch Admin, restricted to authorized branch-role visibility)
  - These three permissions are approved for Stage 01B.3 catalog reconciliation in `RoleAndPermissionSeeder` BEFORE implementing `AuthorizationService`; they are NOT yet seeded or implicitly granted in Stage 01B.2.



