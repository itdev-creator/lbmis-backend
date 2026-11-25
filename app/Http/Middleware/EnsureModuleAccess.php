<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: module:<key>
 *
 * Lets a franchise owner through only when their active plan includes the module.
 * Internal users always pass (their permissions decide what they can do).
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ($user->canUseModule($module)) {
            return $next($request);
        }

        if ($user->can('subscription.view-own')) {
            $label = config("lbmis.modules.{$module}.label", $module);

            return redirect()
                ->route('subscription.show')
                ->with('module_locked', is_string($label) ? $label : $module);
        }

        abort(403);
    }
}
