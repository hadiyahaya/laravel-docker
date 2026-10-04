<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    /**
     * List departments with how many users are in each.
     */
    public function index(): View
    {
        $departments = Department::query()
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return view('departments.index', [
            'departments' => $departments,
        ]);
    }
}
