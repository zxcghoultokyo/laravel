<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('chat'); // resources/views/chat.blade.php
});
