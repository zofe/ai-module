<?php

use Illuminate\Support\Facades\Route;
use Zofe\Ai\Livewire\DevelopWithAi;

Route::get('ai/develop', DevelopWithAi::class)
    ->middleware(['web', 'auth'])
    ->name('ai.develop')
    ->crumbs(fn ($crumbs) => $crumbs->parent('home')->push(__('AI overview'), route_lang('ai.develop')));
