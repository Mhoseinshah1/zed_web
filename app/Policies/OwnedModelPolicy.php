<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Ownership for the dashboard's user-scoped records.
 *
 * ── Two different questions, two different ability names ──────────────────
 *
 * "May this customer open their own record?" and "may this administrator manage
 * records in the admin panel?" are not the same question, and answering them
 * with one ability is what produced the defect this class was rewritten to fix.
 *
 * The first version added `before()` returning true for administrators, to
 * repair Filament access. Reproduced consequence: an administrator hitting
 * `GET /dashboard/orders/{order}` for ANOTHER user's order received **HTTP
 * 200** — a cross-user read through the customer surface, which no product
 * requirement asked for and no audit trail covers.
 *
 * So the abilities are split:
 *
 *   • `viewOwned` / `updateOwned` — the CUSTOMER surface. Ownership and nothing
 *     else. There is deliberately no administrator bypass; an administrator
 *     browsing the customer dashboard is just a user.
 *   • `viewAny` / `view` / `create` / `update` / `delete` — the ADMIN surface,
 *     which is what Filament resolves. Administrators only.
 *
 * Least privilege falls out of the naming: a controller that wants the customer
 * rule cannot accidentally get the administrative one.
 *
 * ── Canonical owner identity ──────────────────────────────────────────────
 *
 * Comparison never goes through a lossy `(int)` cast. Measured: `(int)` maps
 * `'3abc'`, `' 3'`, `'03'`, `'3.5'`, `'+3'`, `'3e0'` and `'3 '` all to `3`, so
 * any of them would have authorized as owner 3. Only a real positive integer,
 * or a canonical decimal integer string, is accepted — the latter because some
 * drivers return integer columns as strings.
 */
abstract class OwnedModelPolicy
{
    // ── Customer surface: ownership only, no administrative bypass ─────────

    public function viewOwned(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    public function updateOwned(User $user, Model $model): bool
    {
        return $this->owns($user, $model);
    }

    // ── Administrative surface: what Filament resolves ─────────────────────

    public function viewAny(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function view(User $user, Model $model): bool
    {
        return $this->isAdministrator($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdministrator($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $this->isAdministrator($user);
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->isAdministrator($user);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->isAdministrator($user);
    }

    public function forceDelete(User $user, Model $model): bool
    {
        return $this->isAdministrator($user);
    }

    // ── Internals ──────────────────────────────────────────────────────────

    protected function isAdministrator(User $user): bool
    {
        return $user->is_admin === true;
    }

    protected function owns(User $user, Model $model): bool
    {
        $ownerId = $this->canonicalId($model->getAttribute('user_id'));
        $userId = $this->canonicalId($user->getAttribute('id'));

        // A record with no owner (a system notification addressed to staff)
        // belongs to no customer, so it is denied to every customer.
        if ($ownerId === null || $userId === null) {
            return false;
        }

        return $ownerId === $userId;
    }

    /**
     * A positive integer identifier, or null when the input is not one.
     *
     * Accepts an `int` and a CANONICAL decimal integer string (`"3"`), because a
     * database driver may hand back an integer column as a string. Everything
     * else is refused — including forms a `(int)` cast would silently accept:
     * `'3abc'`, `' 3'`, `'3 '`, `'03'`, `'3.5'`, `'+3'`, `'3e0'`, plus floats,
     * booleans, arrays, objects, empty strings, zero and negatives.
     */
    protected function canonicalId(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        // Reject anything that would not survive the round trip (overflow).
        $asInt = (int) $value;

        return (string) $asInt === $value ? $asInt : null;
    }
}
