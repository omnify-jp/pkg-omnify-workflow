<?php

use Illuminate\Support\Facades\Route;
use Omnify\Workflow\Http\Controllers\Admin\WorkflowDefinitionAdminController;
use Omnify\Workflow\Http\Controllers\Admin\WorkflowInstanceAdminController;

/*
|--------------------------------------------------------------------------
| Workflow Admin Routes
|--------------------------------------------------------------------------
|
| Admin page routes + JSON API for workflow management.
| Mounted by WorkflowServiceProvider if config('workflow.admin.enabled').
|
| Default prefix: 'admin/workflow'
| Default middleware: ['web', 'auth']
|
| Page routes (GET → Inertia):
|   GET /admin/workflow/definitions           → Definitions list
|   GET /admin/workflow/instances             → Instances list
|   GET /admin/workflow/instances/{id}        → Instance detail
|
| API routes (POST/PUT/DELETE → JSON):
|   POST   /admin/workflow/definitions           → Create definition
|   PUT    /admin/workflow/definitions/{def}     → Update definition
|   DELETE /admin/workflow/definitions/{def}     → Delete definition
|
*/

// Overview
Route::get('/', [WorkflowDefinitionAdminController::class, 'overview'])
    ->name('workflow.admin.overview');

// Definitions — CRUD
Route::get('/definitions', [WorkflowDefinitionAdminController::class, 'index'])
    ->name('workflow.admin.definitions.index');
Route::post('/definitions', [WorkflowDefinitionAdminController::class, 'store'])
    ->name('workflow.admin.definitions.store');
Route::put('/definitions/{definition}', [WorkflowDefinitionAdminController::class, 'update'])
    ->name('workflow.admin.definitions.update');
Route::delete('/definitions/{definition}', [WorkflowDefinitionAdminController::class, 'destroy'])
    ->name('workflow.admin.definitions.destroy');

// Instances — Read-only admin views
Route::get('/instances', [WorkflowInstanceAdminController::class, 'index'])
    ->name('workflow.admin.instances.index');
Route::get('/instances/{id}', [WorkflowInstanceAdminController::class, 'show'])
    ->name('workflow.admin.instances.show');
