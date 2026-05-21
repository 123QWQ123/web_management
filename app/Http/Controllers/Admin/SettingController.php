<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\StoreSettingRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all();

        return view('admin.settings.index', compact('settings'));
    }

    public function store(StoreSettingRequest $request): RedirectResponse
    {
        $value = array_map('trim', explode(',', $request->input('value')));

        Setting::updateOrCreate([
            'key' => $request->input('key'),
        ], [
            'value' => $value,
        ]);

        return redirect()->back()->with('status', 'Setting saved.');
    }
}
