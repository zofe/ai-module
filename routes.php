<?php

use Illuminate\Support\Facades\Route;
use Zofe\Ai\Livewire\DevelopWithAi;

Route::get('ai/develop', DevelopWithAi::class)
    ->middleware(['web', 'auth'])
    ->name('ai.develop')
    ->crumbs(fn ($crumbs) => $crumbs->parent('home')->push('Develop with AI', route('ai.develop')));
