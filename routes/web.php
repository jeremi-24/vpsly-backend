<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/auth/google', [App\Http\Controllers\Auth\GoogleController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/callback', [App\Http\Controllers\Auth\GoogleController::class, 'handleGoogleCallback']);

Route::get('/auth/github', [App\Http\Controllers\Auth\GitHubController::class, 'redirectToGithub'])->name('auth.github');
Route::get('/auth/github/callback', [App\Http\Controllers\Auth\GitHubController::class, 'handleGithubCallback']);
