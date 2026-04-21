<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index()
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@vpsly.local'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );

        return response()->json(Application::with('server')->where('user_id', $user->id)->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'name' => 'required|string|unique:applications,name',
            'repo_url' => 'required|url',
            'branch' => 'nullable|string',
        ]);

        $user = User::firstOrCreate(
            ['email' => 'admin@vpsly.local'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );

        $app = Application::create([
            'user_id' => $user->id,
            'server_id' => $request->server_id,
            'name' => $request->name,
            'repo_url' => $request->repo_url,
            'branch' => $request->branch ?? 'main',
        ]);

        return response()->json([
            'message' => 'Application created successfully',
            'application' => $app
        ], 201);
    }
}
