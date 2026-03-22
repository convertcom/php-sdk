<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\EventsController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\StatisticsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);
Route::get('/events', [EventsController::class, 'index']);
Route::get('/pricing', [PricingController::class, 'index']);
Route::get('/statistics', [StatisticsController::class, 'index']);
Route::post('/api/buy', [ApiController::class, 'buy']);
