<?php

use Illuminate\Support\Facades\Route;
use Zofe\Ai\Livewire\DevelopWithAi;

// No `auth` here: the component sends a guest to the login, unless the application opened
// the page to everyone (ai.overview_public).
Route::get('ai/develop', DevelopWithAi::class)
    ->middleware(['web'])
    ->name('ai.develop')
    ->crumbs(fn ($crumbs) => $crumbs->parent('home')->push(__('AI overview'), route_lang('ai.develop')));
