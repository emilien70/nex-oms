<?php

namespace App\Http\Controllers;

use App\Services\OrderVariableService;
use Illuminate\View\View;

class SettingsVariablesController extends Controller
{
    public function index(OrderVariableService $variables): View
    {
        return view('settings.variables.index', [
            'variableGroups' => collect($variables->definitions())->groupBy('group'),
        ]);
    }
}
