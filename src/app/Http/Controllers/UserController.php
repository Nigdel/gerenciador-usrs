<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
   public function index()
   {
       return response()->json(
           User::all(['id', 'name', 'email'])
       );
   }

   public function store(Request $request)
   {
       $validated = $request->validate([
           'name' => ['required', 'string', 'max:255'],
           'email' => ['required', 'email', 'max:255', 'unique:users,email'],
           'password' => [
               'required',
               'string',
               'min:8',
               'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).+$/',
           ],
           'cpf' => ['nullable', 'string', 'max:11', 'unique:users,cpf'],
           'telefone_pessoal' => ['nullable', 'string', 'max:20'],
           'telefone_servico' => ['nullable', 'string', 'max:20'],
           'empresa' => ['nullable', 'string', 'max:255'],
           'cargo' => ['nullable', 'string', 'max:255'],
           'externo' => ['nullable', 'boolean'],
           'encarregado_id' => ['nullable', 'exists:users,id'],
       ]);

       $validated['password'] = Hash::make($validated['password']);

       $user = User::create($validated);

       return response()->json(
           $user->only([
               'id',
               'name',
               'email',
               'cpf',
               'telefone_pessoal',
               'telefone_servico',
               'empresa',
               'cargo',
               'externo',
           ]),
           201
       );
   }

   public function create()
   {
       return view('usercreateform');
   }
}
