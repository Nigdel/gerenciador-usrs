<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
   public function index(Request $request)
   {
       $users = User::query()->with('encarregado')->orderBy('name')->get();

       if (!$this->isJsonRequest($request)) {
           return view('users.index', compact('users'));
       }

       return response()->json(
           $users->map(fn (User $user) => $this->userData($user))
       );
   }

   public function show(Request $request, User $user)
   {
       $user->load('encarregado', 'subsystemAccounts.subsystem');

       if (!$this->isJsonRequest($request)) {
           return view('users.show', compact('user'));
       }

       return response()->json($this->userData($user));
   }

   public function store(Request $request)
   {
       $validated = $this->validatedData($request);

       $validated['password'] = Hash::make($validated['password']);

       $user = User::create($validated);

       if (!$this->isJsonRequest($request)) {
           return redirect()->route('users.index')->with('success', 'Usuário cadastrado com sucesso.');
       }

       return response()->json($this->userData($user), 201);
   }

   public function create()
   {
       return view('usercreateform');
   }

   public function edit(User $user)
   {
       return view('usereditform', compact('user'));
   }

   public function update(Request $request, User $user)
   {
       $validated = $this->validatedData($request, $user);

       if (!empty($validated['password'])) {
           $validated['password'] = Hash::make($validated['password']);
       } else {
           unset($validated['password']);
       }

       $user->update($validated);

       if (!$this->isJsonRequest($request)) {
           return redirect()->route('users.index')->with('success', 'Usuário atualizado com sucesso.');
       }

       return response()->json($this->userData($user->refresh()));
   }

   public function destroy(Request $request, User $user)
   {
       $user->delete();

       if (!$this->isJsonRequest($request)) {
           return redirect()->route('users.index')->with('success', 'Usuário removido com sucesso.');
       }

       return response()->json(null, 204);
   }

   private function isJsonRequest(Request $request): bool
   {
       return $request->expectsJson() || $request->header('Accept') === null;
   }

   private function validatedData(Request $request, ?User $user = null): array
   {
       $userId = $user?->id;

       $validated = $request->validate([
           'name' => ['required', 'string', 'max:255'],
           'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
           'password' => [
               $user ? 'nullable' : 'required',
               'string',
               'min:8',
               'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).+$/',
           ],
           'cpf' => ['nullable', 'string', 'max:11', Rule::unique('users', 'cpf')->ignore($userId)],
           'telefone_pessoal' => ['nullable', 'string', 'max:20'],
           'telefone_servico' => ['nullable', 'string', 'max:20'],
           'empresa' => ['nullable', 'string', 'max:255'],
           'cargo' => ['nullable', 'string', 'max:255'],
           'externo' => ['nullable', 'boolean'],
           'encarregado_id' => ['nullable', 'exists:users,id'],
       ]);

       $validated['externo'] = $request->boolean('externo');

       return $validated;
   }

   private function userData(User $user): array
   {
       return $user->only([
           'id',
           'name',
           'email',
           'cpf',
           'telefone_pessoal',
           'telefone_servico',
           'empresa',
           'cargo',
           'externo',
           'encarregado_id',
           'created_at',
           'updated_at',
       ]);
   }
}
