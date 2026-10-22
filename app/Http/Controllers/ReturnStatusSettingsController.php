<?php

namespace App\Http\Controllers;

use App\Models\ReturnStatusSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReturnStatusSettingsController extends Controller
{
    public function index()
    {
        return view('returns.statuses', ['statuses' => ReturnStatusSetting::query()->withCount('returns')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        ReturnStatusSetting::query()->create($data + ['code' => 'custom_'.Str::lower(Str::random(16))]);

        return back();
    }

    public function update(Request $request, ReturnStatusSetting $returnStatusSetting)
    {
        $returnStatusSetting->update($this->validated($request));

        return back();
    }

    public function destroy(ReturnStatusSetting $returnStatusSetting)
    {
        DB::transaction(function () use ($returnStatusSetting): void {
            ReturnStatusSetting::query()->where('code', 'new')->lockForUpdate()->firstOrFail();
            $status = ReturnStatusSetting::query()->lockForUpdate()->findOrFail($returnStatusSetting->id);
            if ($status->code === 'new' || $status->returns()->exists()) {
                throw ValidationException::withMessages(['status' => 'Nie można usunąć statusu początkowego ani statusu używanego przez zwroty.']);
            }
            $status->delete();
        });

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => ['required', 'string', 'max:100'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/D'], 'sort_order' => ['required', 'integer', 'min:0', 'max:10000']]);
    }
}
