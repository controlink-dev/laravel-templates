<?php

use Controlink\Templates\Http\Controllers\CatalogueController;
use Controlink\Templates\Http\Controllers\StylesheetController;
use Controlink\Templates\Http\Controllers\TemplateController;
use Controlink\Templates\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

// Prefix, middleware and the `templates.` name prefix come from the service provider.
// `{template}` and `{version}` are plain ids: controllers resolve them (ResolvesTemplates),
// never route model binding, because a host's tenant middleware may run after it.
Route::get('catalogue/{type}', [CatalogueController::class, 'show'])->name('catalogue');
Route::get('stylesheet', [StylesheetController::class, 'show'])->name('stylesheet');
Route::get('fonts/{family}/{weight}', [StylesheetController::class, 'font'])->name('font')->whereNumber('weight');

Route::post('templates', [TemplateController::class, 'store'])->name('store');
Route::post('templates/{template}/duplicate', [TemplateController::class, 'duplicate'])->name('duplicate');

Route::post('templates/{template}/versions', [VersionController::class, 'store'])->name('versions.store');
Route::get('templates/{template}/versions/{version}', [VersionController::class, 'show'])->name('versions.show');
Route::put('templates/{template}/versions/{version}', [VersionController::class, 'update'])->name('versions.update');
Route::post('templates/{template}/versions/{version}/preview', [VersionController::class, 'preview'])->name('versions.preview');
Route::post('templates/{template}/versions/{version}/activate', [VersionController::class, 'activate'])->name('versions.activate');
