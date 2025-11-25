<?php

use App\Http\Controllers\Payment\EPSPaymentController;

// Add EPS payment callback routes
Route::controller(EPSPaymentController::class)->group(function () {
    // EPS may call back via GET or POST depending on config -> accept any
    Route::any('/eps/success', 'success')->name('eps.success');
    Route::any('/eps/fail', 'fail')->name('eps.fail');
    Route::any('/eps/cancel', 'cancel')->name('eps.cancel');
});
