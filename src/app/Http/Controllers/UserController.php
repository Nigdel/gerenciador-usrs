<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()->with('encarregado')->orderBy('name')->get();

        if (! $this->isJsonRequest($request)) {
            return view('users.index', compact('users'));
        }

        return response()->json(
            $users->map(fn (User $user) => $this->userData($user))
        );
    }

    public function show(Request $request, User $user)
    {
        $this->authorize('view', $user);

        $user->load('encarregado', 'subsystemAccounts.subsystem');

        if (! $this->isJsonRequest($request)) {
            return view('users.show', compact('user'));
        }

        return response()->json($this->userData($user));
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $validated = $this->validatedData($request);

        $validated['password'] = Hash::make($validated['password']);

        $user = User::create($validated);

        // `validated` ya no vale aquí: la contraseña viene hasheada y el hash
        // no sirve para nada en una bitácora. Se registra lo que sí identifica
        // al operador nuevo.
        $this->audit->log(AuditService::OPERADOR_CREADO, [
            'operador_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        if (! $this->isJsonRequest($request)) {
            return redirect()->route('users.index')->with('success', 'Usuário cadastrado com sucesso.');
        }

        return response()->json($this->userData($user), 201);
    }

    public function create()
    {
        $this->authorize('create', User::class);

        return view('usercreateform');
    }

    public function edit(User $user)
    {
        $this->authorize('update', $user);

        return view('usereditform', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $validated = $this->validatedData($request, $user);

        if (! empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $cambios = $this->diffAuditable($user, $validated);
        $user->update($validated);

        $this->audit->log(AuditService::OPERADOR_ACTUALIZADO, [
            'operador_id' => $user->id,
            'cambios' => $cambios,
        ]);

        if (! $this->isJsonRequest($request)) {
            return redirect()->route('users.index')->with('success', 'Usuário atualizado com sucesso.');
        }

        return response()->json($this->userData($user->refresh()));
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);

        // Se copian los datos antes de borrar: después el modelo ya no los tiene.
        $datos = ['operador_id' => $user->id, 'name' => $user->name, 'email' => $user->email];

        $user->delete();

        $this->audit->log(AuditService::OPERADOR_ELIMINADO, $datos);

        if (! $this->isJsonRequest($request)) {
            return redirect()->route('users.index')->with('success', 'Usuário removido com sucesso.');
        }

        return response()->json(null, 204);
    }

    private function isJsonRequest(Request $request): bool
    {
        return $request->expectsJson() || $request->header('Accept') === null;
    }

    private function validatedData(Request $request, ?User $managedUser = null): array
    {
        $userId = $managedUser?->id;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => [
                $managedUser ? 'nullable' : 'required',
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
            'role' => ['sometimes', Rule::in(User::ROLES)],
        ]);

        // Solo un admin puede conceder o retirar el rol de admin.
        $validated['role'] = $request->user()?->isAdmin()
            ? ($validated['role'] ?? $managedUser?->role ?? 'operador')
            : ($managedUser?->role ?? 'operador');

        $validated['externo'] = $request->boolean('externo');

        return $validated;
    }

    /**
     * Campos que cambian de verdad en {campo: [antes, después]}.
     *
     * El hash de la contraseña no se registra ni como valor nuevo ni como
     * anterior: es una credencial tan reutilizable como la contraseña misma, y
     * basta con anotar que se restableció.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function diffAuditable(User $user, array $data): array
    {
        $cambios = [];

        foreach ($data as $campo => $valor) {
            if ($campo === 'password') {
                $cambios[$campo] = ['cambiada' => true];

                continue;
            }

            if ($user->getOriginal($campo) === $valor) {
                continue;
            }

            $cambios[$campo] = [
                'desde' => $this->valorAuditable($user->getOriginal($campo)),
                'hasta' => $this->valorAuditable($valor),
            ];
        }

        return $cambios;
    }

    private function valorAuditable(mixed $valor): mixed
    {
        return $valor instanceof BackedEnum ? $valor->value : $valor;
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
