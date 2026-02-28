<?php

use Illuminate\Support\Facades\Route;
use Omnify\Workflow\Http\Controllers\WorkflowDefinitionController;
use Omnify\Workflow\Http\Controllers\WorkflowInstanceController;

/*
|--------------------------------------------------------------------------
| Workflow Routes
|--------------------------------------------------------------------------
|
| Các routes này được mount bởi WorkflowServiceProvider nếu:
|   config('workflow.routes.enabled') = true
|
| Mặc định prefix: 'api/workflow' (thay đổi qua WORKFLOW_ROUTE_PREFIX)
| Mặc định middleware: ['web', 'core.auth'] (người dùng phải đăng nhập)
|
| URL Examples (với prefix mặc định):
|   GET  /api/workflow/pending              → Việc cần duyệt của tôi
|   GET  /api/workflow/instances/{id}       → Chi tiết instance
|   POST /api/workflow/instances/{id}/approve
|   POST /api/workflow/instances/{id}/reject
|   POST /api/workflow/instances/{id}/cancel
|
|   POST   /api/workflow/definitions              → Tạo definition
|   PUT    /api/workflow/definitions/{definition} → Update definition
|   DELETE /api/workflow/definitions/{definition} → Xóa definition
|
*/

Route::get('/pending', [WorkflowInstanceController::class, 'pending'])->name('workflow.pending');

Route::prefix('instances')->group(function () {
    Route::get('/{id}', [WorkflowInstanceController::class, 'show'])->name('workflow.instances.show');
    Route::post('/{id}/approve', [WorkflowInstanceController::class, 'approve'])->name('workflow.instances.approve');
    Route::post('/{id}/reject', [WorkflowInstanceController::class, 'reject'])->name('workflow.instances.reject');
    Route::post('/{id}/cancel', [WorkflowInstanceController::class, 'cancel'])->name('workflow.instances.cancel');
});

Route::prefix('definitions')->group(function () {
    Route::post('/', [WorkflowDefinitionController::class, 'store'])->name('workflow.definitions.store');
    Route::put('/{definition}', [WorkflowDefinitionController::class, 'update'])->name('workflow.definitions.update');
    Route::delete('/{definition}', [WorkflowDefinitionController::class, 'destroy'])->name('workflow.definitions.destroy');
});
