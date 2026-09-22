# v1.6.63 — Driver, customer and contact accounts stay out of the console

## Improvements

- Treat `driver`, `customer` and `contact` users as managed accounts: the FleetOps profile owns them, not IAM. `User` gains `MANAGED_TYPES`, `isManagedAccount()`, `isStaffAccount()`, `canAccessConsole()`, `canHoldConsoleSession()` and a `managed()` scope.
- Promote instead of duplicating. When IAM creates or invites a team member whose email or phone belongs to a managed account in the organization, that account becomes a `user`. It gets the chosen role, permissions and policies and a join invite, and keeps its driver and customer profiles. The response carries `promoted_from`. Accepting any IAM invite also promotes a managed account and asks it to set a console password.

## Fixes

- Keep managed accounts out of the console:
  - `auth/login` refuses drivers, contacts and customers. Customers keep the `customer_login_not_allowed` code; drivers and contacts get `console_access_not_allowed`.
  - Session restore, bootstrap, 2FA verification, verify-email tokens and impersonation refuse drivers and contacts.
  - Customers are still allowed on those endpoints because the customer portal runs inside the console and restores its session through them.
- Free a deleted user's email and phone so a new account can use them. On soft delete they move to `meta.deleted_identity`; restoring the user puts them back only if no other account has taken them.

## Security

- Never grant the Administrator role by default. Before this fix:
  - Creating or inviting a user without a role gave them the Administrator role, and so full organization access. Reported for IAM › Customers › Add customer with a blank Role.
  - Accepting an invite with no role also granted it, and `joinOrganization` ignored the invite's role altogether.

  Now:
  - A role is required when creating or inviting a user; without one the request returns 422.
  - `Company::addUser`, `Company::assignUser` and `User::assignCompany` assign no role unless one is given.
  - An invite without a role joins with no role.
- Only admins or holders of the Administrator role may grant the Administrator role (403 otherwise), on create, invite and role update.

## Reliability

- Cover the console guards for each account type, identity release and restore, and promotion through create, invite and invite acceptance.

A database migration is not required. No configuration change is needed. The FleetOps side ships in fleetbase/fleetops#338.

Changes: [#264](https://github.com/fleetbase/core-api/pull/264), [#266](https://github.com/fleetbase/core-api/pull/266).
