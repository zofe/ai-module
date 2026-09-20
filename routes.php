<?php

use Illuminate\Support\Facades\Route;
use Zofe\Ai\Livewire\AiStatus;

Route::get('ai/status', AiStatus::class)
    ->middleware(['web', 'auth'])
    ->name('ai.status')
    ->crumbs(fn ($crumbs) => $crumbs->parent('home')->push('AI readiness', route('ai.status')));
