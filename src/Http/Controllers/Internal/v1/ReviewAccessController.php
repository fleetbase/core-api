<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Models\Permission;
use Fleetbase\Support\AppReview;
use Fleetbase\Support\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Console's App Review access endpoints, for every type an extension registered with
 * AppReview (a Storefront store or network, an organization's Navigator app, ...):
 *
 *   GET {type}/{id}  the status, never the code
 *   PUT {type}/{id}  {enabled?: bool, regenerate_code?: bool}; the response carries the plain
 *                    `code` only when this request generated one
 *
 * `{id}` is the subject's uuid or public id, found in the signed-in company only.
 */
class ReviewAccessController extends Controller
{
    public function show(string $type, string $id, Request $request): JsonResponse
    {
        $subject = $this->subject($type, $id, $request);
        if (!$subject) {
            return response()->error('Not found.', 404);
        }

        if (!$this->allowed($type, 'view_permission', $request)) {
            return response()->authorizationError();
        }

        return response()->json(['review_access' => AppReview::status($subject)]);
    }

    public function update(string $type, string $id, Request $request): JsonResponse
    {
        $subject = $this->subject($type, $id, $request);
        if (!$subject) {
            return response()->error('Not found.', 404);
        }

        if (!$this->allowed($type, 'permission', $request)) {
            return response()->authorizationError();
        }

        $request->validate([
            'enabled'         => 'sometimes|boolean',
            'regenerate_code' => 'sometimes|boolean',
        ]);

        $enabled = $request->has('enabled') ? $request->boolean('enabled') : null;

        [, $code] = AppReview::update($subject, $enabled, $request->boolean('regenerate_code'), $request->session()->get('user'));

        $status = AppReview::status($subject);
        if ($code !== null) {
            $status['code'] = $code;
        }

        return response()->json(['review_access' => $status]);
    }

    protected function subject(string $type, string $id, Request $request): ?Model
    {
        return AppReview::resolveSubject($type, $id, $request->session()->get('company'));
    }

    /**
     * Administrators always may; anyone else needs the permission the type registered, when
     * it registered one.
     */
    protected function allowed(string $type, string $key, Request $request): bool
    {
        $permission = AppReview::definition($type)[$key] ?? null;
        if (!$permission) {
            return true;
        }

        $user = Auth::getUserFromSession($request);
        if (!$user) {
            return false;
        }

        if (!$user->isNotAdmin()) {
            return true;
        }

        // The permission or a wildcard over it, the way AuthorizationGuard reads them.
        $names = [$permission];
        $parts = explode(' ', $permission);
        if (count($parts) === 3) {
            $names[] = implode(' ', [$parts[0], '*', $parts[2]]);
            $names[] = implode(' ', [$parts[0], '*']);
        }

        $permissions = Permission::findByNames($names);

        return $permissions->isNotEmpty() && !$user->doesntHavePermissions($permissions);
    }
}
