<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class IntegrationController extends Controller
{
    public function index(): View
    {
        return view('integrations.index');
    }
}
