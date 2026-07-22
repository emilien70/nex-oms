<?php

namespace Modules\Invoices\Http\Controllers;

use Illuminate\View\View;

class InvoiceController
{
    public function index(): View
    {
        return view('invoices.index');
    }
}
