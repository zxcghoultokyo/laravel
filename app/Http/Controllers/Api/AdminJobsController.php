<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RebuildCategoryIndexJob;
use App\Jobs\SyncHoroshopProductsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class AdminJobsController extends Controller
{
    // GET /api/admin/jobs/sync-horoshop?token=...
    public function syncHoroshop(Request $request)
    {
        $this->guard($request);

        $mode = $request->query('mode', 'queue'); // queue|sync

        if ($mode === 'sync') {
            Artisan::call('horoshop:sync', ['--limit' => (int) $request->query('limit', 200)]);
            return response()->json(['ok' => true, 'mode' => 'sync', 'output' => Artisan::output()]);
        }

        dispatch(new SyncHoroshopProductsJob((int) $request->query('limit', 200)));

        return response()->json(['ok' => true, 'mode' => 'queue', 'job' => 'SyncHoroshopProductsJob']);
    }

    // GET /api/admin/jobs/rebuild-category-index?token=...
    public function rebuildCategoryIndex(Request $request)
    {
        $this->guard($request);

        $mode = $request->query('mode', 'queue'); // queue|sync

        if ($mode === 'sync') {
            Artisan::call('catalog:rebuild-category-index');
            return response()->json(['ok' => true, 'mode' => 'sync', 'output' => Artisan::output()]);
        }

        dispatch(new RebuildCategoryIndexJob());

        return response()->json(['ok' => true, 'mode' => 'queue', 'job' => 'RebuildCategoryIndexJob']);
    }

    protected function guard(Request $request): void
    {
        $token = (string) $request->query('token', '');
        $expected = (string) config('app.admin_jobs_token');

        if ($expected === '' || ! hash_equals($expected, $token)) {
            Log::warning('AdminJobsController unauthorized');
            abort(403, 'Forbidden');
        }
    }
}
