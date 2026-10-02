@extends('layouts.app')

@section('title', 'Nuevo usuario gestionado')

@section('content')
    <section class="form-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <a class="eyebrow" href="{{ route('home') }}">Home</a>
            <h1 id="page-title">Novo Usuario</h1>
            <p>Cadastra o usuário e cria suas contas nos subsistemas selecionados.</p>
        </div>

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">Revisa los campos destacados.</div>
        @endif

        @php $chatwootSubsystem = $subsystems->firstWhere('slug', 'chatwoot'); @endphp

        <form action="{{ route('gestor-users.store') }}" method="POST">
            @csrf
            <div class="field-grid">
                <div class="field">
                    <label for="cpf">CPF <span aria-hidden="true">*</span></label>
                    <input id="cpf" name="cpf" type="text" pattern="\d{11}" minlength="11" maxlength="11" value="{{ old('cpf') }}" autocomplete="off" placeholder="Solo 11 dígitos" required>
                    <small id="cpf-status" class="field-hint" aria-live="polite">Insira o CPF para consultar no Adagio.</small>
                    @error('cpf')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field field-wide">
                    <label for="nombre_completo">Nome Completo <span aria-hidden="true">*</span></label>
                    <input id="nombre_completo" name="nombre_completo" type="text" maxlength="255" value="{{ old('nombre_completo') }}" required>
                    @error('nombre_completo')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="usuario">Usuário</label>
                    <input id="usuario" name="usuario" type="text" maxlength="255" value="{{ old('usuario') }}">
                    <small class="field-hint">Se ficar vazio, o sistema proporá um.</small>
                    @error('usuario')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="email_personal">E-mail</label>
                    <input id="email_personal" name="email_personal" type="email" maxlength="255" value="{{ old('email_personal') }}">
                    @error('email_personal')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="empresa">Empresa <span aria-hidden="true">*</span></label>
                    <select id="empresa" name="empresa" required >
                        <option value="">Seleccione uma empresa</option>
                        <option value="klios" @selected(old('empresa') === 'klios')>Klios</option>
                        <option value="federal" @selected(old('empresa') === 'federal')>Federal</option>
                    </select>
                    @error('empresa')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="telefono_personal">Teléfono pessoal</label>
                    <input id="telefono_personal" name="telefono_personal" type="tel" maxlength="30" value="{{ old('telefono_personal') }}">
                </div>
                <div class="field">
                    <label for="telefono_trabajo">Teléfono corporativo</label>
                    <input id="telefono_trabajo" name="telefono_trabajo" type="tel" maxlength="30" value="{{ old('telefono_trabajo') }}">
                </div>
                <div class="field field-wide">
                    <label for="direccion_particular">Endereço particular</label>
                    <input id="direccion_particular" name="direccion_particular" type="text" maxlength="255" value="{{ old('direccion_particular') }}">
                </div>
                <div class="field field-wide">
                    <label for="password_general">Senha Padrao <span aria-hidden="true">*</span></label>
                    <input id="password_general" name="password_general" type="password" minlength="8" autocomplete="new-password" required>
                    <small class="field-hint">Será armazenada com hash e utilizada para o aprovisionamento.</small>
                    @error('password_general')<small class="error-message">{{ $message }}</small>@enderror
                </div>
            </div>

            <fieldset class="mt-8 border-t border-[#d9e2dc] pt-6">
                <legend class="mb-2 text-lg font-bold text-[#17211b]">Contas nos Subsistemas</legend>
                <p class="mb-4 text-sm text-[#68756d]">Selecione os subsistemas nos quais o usuário terá uma conta.</p>
                @if ($subsystems->isEmpty())
                    <p class="feedback feedback-error">No hay subsistemas activos disponibles.</p>
                @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($subsystems as $subsystem)
                            <label class="checkbox-field rounded-md border border-[#d9e2dc] p-3 {{ $subsystem->slug === 'adagio' ? 'opacity-75' : '' }}" for="subsystem-{{ $subsystem->id }}" data-subsystem-slug="{{ $subsystem->slug }}">
                                @if ($subsystem->slug === 'adagio')
                                    <input type="hidden" name="subsistemas[]" value="adagio">
                                @endif
                                <input id="subsystem-{{ $subsystem->id }}" name="subsistemas[]" type="checkbox" value="{{ $subsystem->slug }}" @checked($subsystem->slug === 'adagio' || in_array($subsystem->slug, old('subsistemas', []), true)) @disabled($subsystem->slug === 'adagio') @if ($subsystem->slug === 'chatwoot') data-chatwoot-route="{{ route('subsystems.chatwoot.teams', $subsystem) }}" @endif>
                                <span>
                                    <strong>{{ $subsystem->nombre }}</strong>
                                    <small class="block text-[#68756d]" data-original-text="{{ $subsystem->descripcion ?: $subsystem->slug }}">
                                        @if ($subsystem->slug === 'adagio')
                                            Consultando disponibilidad...
                                        @else
                                            {{ $subsystem->descripcion ?: $subsystem->slug }}
                                        @endif
                                    </small>
                                </span>

                                @if ($subsystem->slug === 'chatwoot')
                                    <div class="chatwoot-team-panel mt-3 hidden w-full rounded-md border border-[#d9e2dc] bg-slate-50 p-3">
                                        <div class="mb-2 flex items-center justify-between gap-2">
                                            <strong class="text-sm text-[#17211b]">Equipos disponibles</strong>
                                            <small class="chatwoot-team-status text-xs text-[#68756d]">Selecciona una empresa y marca Chatwoot.</small>
                                        </div>
                                        <div class="chatwoot-team-options grid w-full gap-2"></div>
                                    </div>
                                @endif
                            </label>
                        @endforeach
                    </div>
                @endif
                @error('subsistemas')<small class="error-message">{{ $message }}</small>@enderror
                @error('subsistemas.*')<small class="error-message">{{ $message }}</small>@enderror
            </fieldset>

            <div class="form-actions">
                <a class="button button-secondary" href="{{ route('gestor-users.index') }}">Cancelar</a>
                <button class="button button-primary" type="submit" @disabled($subsystems->isEmpty())>Crear usuario y cuentas</button>
            </div>
        </form>
    </section>
@endsection

@push('scripts')
    <script>
        (() => {
            const cpfInput = document.getElementById('cpf');
            const status = document.getElementById('cpf-status');
            const fields = {
                cpf: cpfInput,
                nombre_completo: document.getElementById('nombre_completo'),
                email_personal: document.getElementById('email_personal'),
                usuario: document.getElementById('usuario'),
                empresa: document.getElementById('empresa'),
            };
            const chatwootLabel = document.querySelector('label[data-subsystem-slug="chatwoot"]');
            const chatwootCheckbox = chatwootLabel ? chatwootLabel.querySelector('input[name="subsistemas[]"][value="chatwoot"]') : null;
            const chatwootRoute = chatwootCheckbox ? chatwootCheckbox.dataset.chatwootRoute || '' : '';
            const chatwootPanel = chatwootLabel ? chatwootLabel.querySelector('.chatwoot-team-panel') : null;
            const chatwootTeamOptions = chatwootLabel ? chatwootLabel.querySelector('.chatwoot-team-options') : null;
            const chatwootTeamStatus = chatwootLabel ? chatwootLabel.querySelector('.chatwoot-team-status') : null;
            let requestNumber = 0;
            let teamRequestNumber = 0;
            let usuarioEncontradoEnAdagio = false; // Flag para saber si el usuario existe en Adagio
            let ultimoCpfConsultado = ''; // Para evitar consultas duplicadas

            const clearChatwootTeams = () => {
                if (!chatwootTeamOptions) {
                    return;
                }

                chatwootTeamOptions.querySelectorAll('input[type="checkbox"][name="subsystem_config[chatwoot][teams][]"]').forEach((checkbox) => {
                    checkbox.checked = false;
                });
            };

            const hideChatwootTeams = () => {
                if (!chatwootPanel) {
                    return;
                }

                chatwootPanel.classList.add('hidden');
                clearChatwootTeams();
                if (chatwootTeamStatus) {
                    chatwootTeamStatus.textContent = 'Selecciona una empresa y marca Chatwoot.';
                }
            };

            const renderChatwootTeams = (teams) => {
                if (!chatwootTeamOptions) {
                    return;
                }

                chatwootTeamOptions.innerHTML = '';

                if (!Array.isArray(teams) || teams.length === 0) {
                    if (chatwootTeamStatus) {
                        chatwootTeamStatus.textContent = 'No se encontraron equipos disponibles para la empresa seleccionada.';
                    }
                    return;
                }

                if (chatwootTeamStatus) {
                    chatwootTeamStatus.textContent = `${teams.length} equipos disponibles.`;
                }

                teams.forEach((team) => {
                    const label = document.createElement('label');
                    label.className = 'flex items-center gap-2 rounded-md border border-[#d9e2dc] bg-white p-2 text-sm text-[#17211b]';

                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.name = 'subsystem_config[chatwoot][teams][]';
                    input.value = team.id;
                    input.checked = false;

                    const span = document.createElement('span');
                    span.textContent = team.name;

                    label.appendChild(input);
                    label.appendChild(span);
                    chatwootTeamOptions.appendChild(label);
                });
            };

            const loadChatwootTeams = async () => {
                if (!chatwootCheckbox || !chatwootCheckbox.checked || !fields.empresa || !fields.empresa.value || !chatwootRoute) {
                    hideChatwootTeams();
                    return;
                }

                if (!chatwootPanel || !chatwootTeamStatus) {
                    return;
                }

                chatwootPanel.classList.remove('hidden');
                chatwootTeamStatus.textContent = 'Consultando equipos de Chatwoot...';
                clearChatwootTeams();

                const requestId = ++teamRequestNumber;

                try {
                    const response = await fetch(`${chatwootRoute}?empresa=${encodeURIComponent(fields.empresa.value)}`, {
                        headers: { Accept: 'application/json' },
                    });

                    if (requestId !== teamRequestNumber) {
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('chatwoot-teams-error');
                    }

                    const result = await response.json();
                    renderChatwootTeams(Array.isArray(result.teams) ? result.teams : []);
                } catch (error) {
                    if (requestId !== teamRequestNumber) {
                        return;
                    }

                    chatwootTeamStatus.textContent = 'No se pudieron cargar los equipos para la empresa seleccionada.';
                    clearChatwootTeams();
                }
            };

            if (chatwootCheckbox) {
                chatwootCheckbox.addEventListener('change', () => {
                    if (!chatwootCheckbox.checked) {
                        hideChatwootTeams();
                        return;
                    }

                    loadChatwootTeams();
                });
            }

            if (fields.empresa) {
                fields.empresa.addEventListener('change', () => {
                    clearChatwootTeams();
                    loadChatwootTeams();
                });
            }

            // Validación para aceptar solo números en el campo CPF
            cpfInput.addEventListener('input', (e) => {
                // Remover todo lo que no sea dígito
                e.target.value = e.target.value.replace(/\D/g, '');

                // Limitar a 11 dígitos
                if (e.target.value.length > 11) {
                    e.target.value = e.target.value.slice(0, 11);
                }

                // Limpiar todos los campos cuando cambia el CPF
                fields.nombre_completo.value = '';
                fields.email_personal.value = '';
                fields.usuario.value = '';
                fields.empresa.value = '';
                usuarioEncontradoEnAdagio = false;

                // Resetear mensaje de Adagio
                if (adagioDescription) {
                    adagioDescription.textContent = 'Ingresa un CPF para verificar disponibilidad';
                    adagioDescription.classList.remove('text-amber-700', 'text-[#1f5d49]');
                    adagioDescription.classList.add('text-[#68756d]');
                }

                // Resetear mensaje de status
                if (e.target.value.length === 0) {
                    status.textContent = 'Ingresa el CPF para consultar Adagio.';
                    ultimoCpfConsultado = '';
                } else if (e.target.value.length < 11) {
                    status.textContent = `Ingresa ${11 - e.target.value.length} dígito(s) más.`;
                } else if (e.target.value !== ultimoCpfConsultado) {
                    // CPF completo y diferente al último consultado: consultar automáticamente
                    status.textContent = 'Consultando Adagio...';
                    lookup().then(() => {
                        // Mover foco al siguiente campo después de la consulta
                        fields.nombre_completo.focus();
                    });
                }
            });

            // Auto-generar usuario cuando NO existe en Adagio
            fields.nombre_completo.addEventListener('blur', () => {
                if (!usuarioEncontradoEnAdagio && fields.nombre_completo.value.trim() && !fields.usuario.value) {
                    generarUsuario();
                }
            });

            const generarUsuario = () => {
                const nombreCompleto = fields.nombre_completo.value.trim();
                if (!nombreCompleto) return;

                // Tomar primer nombre y primer apellido
                const partes = nombreCompleto.toLowerCase()
                    .normalize('NFD')
                    .replace(/[̀-ͯ]/g, '') // Remover acentos
                    .split(/\s+/)
                    .filter(p => p.length > 0);

                if (partes.length === 0) return;

                let usuario = '';
                if (partes.length === 1) {
                    // Solo un nombre
                    usuario = partes[0];
                } else {
                    // Primer nombre + primer apellido
                    usuario = partes[0] + '.' + partes[partes.length - 1];
                }

                // Limpiar caracteres especiales
                usuario = usuario.replace(/[^a-z0-9.]/g, '');

                fields.usuario.value = usuario;
            };

            // Buscar el checkbox y label de Adagio usando el atributo data-subsystem-slug
            const adagioLabel = document.querySelector('label[data-subsystem-slug="adagio"]');
            const adagioCheckbox = adagioLabel ? adagioLabel.querySelector('input[type="checkbox"]') : null;
            const adagioDescription = adagioLabel ? adagioLabel.querySelector('small') : null;

            const determinarEmpresaPorEmail = (email) => {
                if (!email) return null;
                const emailLower = email.toLowerCase();
                if (emailLower.endsWith('@klios.com.br') || emailLower.includes('klios')) {
                    return 'klios';
                }
                if (emailLower.endsWith('@federalst.com.br') || emailLower.includes('federal')) {
                    return 'federal';
                }
                return null;
            };

            const lookup = async () => {
                const cpf = cpfInput.value.trim();

                if (!cpf) {
                    status.textContent = 'Ingresa el CPF para consultar Adagio.';
                    // Cuando no hay CPF, mostrar mensaje por defecto
                    if (adagioDescription) {
                        adagioDescription.textContent = 'Ingresa un CPF para verificar disponibilidad';
                        adagioDescription.classList.remove('text-amber-700', 'text-[#1f5d49]');
                        adagioDescription.classList.add('text-[#68756d]');
                    }
                    return;
                }

                // Validar que tenga exactamente 11 dígitos
                if (cpf.length !== 11) {
                    status.textContent = 'El CPF debe contener exactamente 11 dígitos.';
                    return;
                }

                // No consultar si es el mismo CPF que ya se consultó
                if (cpf === ultimoCpfConsultado) {
                    return;
                }

                ultimoCpfConsultado = cpf;

                const currentRequest = ++requestNumber;
                status.textContent = 'Consultando Adagio...';
                if (adagioDescription) {
                    adagioDescription.textContent = 'Consultando disponibilidad...';
                    adagioDescription.classList.remove('text-amber-700', 'text-[#1f5d49]');
                    adagioDescription.classList.add('text-[#68756d]');
                }

                try {
                    const response = await fetch("{{ route('gestor-users.lookup-cpf') }}?cpf=" + encodeURIComponent(cpf), {
                        headers: { Accept: 'application/json' },
                    });

                    if (currentRequest !== requestNumber) {
                        return;
                    }

                    if (!response.ok) {
                        throw new Error('lookup-failed');
                    }

                    const result = await response.json();

                    if (!result.found) {
                        usuarioEncontradoEnAdagio = false;
                        status.textContent = 'CPF no encontrado en Adagio. Completa los datos manualmente.';
                        // Usuario NO existe: se creará en Adagio
                        if (adagioDescription) {
                            adagioDescription.textContent = 'Usuario no existe en Adagio, se creará automáticamente';
                            adagioDescription.classList.remove('text-[#1f5d49]', 'text-[#68756d]');
                            adagioDescription.classList.add('text-amber-700');
                        }
                        return;
                    }

                    usuarioEncontradoEnAdagio = true;

                    // Usuario SÍ existe: se vinculará cuenta existente
                    if (adagioDescription) {
                        adagioDescription.textContent = 'Usuario ya existe en Adagio, se vinculará la cuenta existente';
                        adagioDescription.classList.remove('text-amber-700', 'text-[#68756d]');
                        adagioDescription.classList.add('text-[#1f5d49]');
                    }

                    // Llenar los campos con los datos encontrados
                    Object.entries(result.user).forEach(([name, value]) => {
                        if (name === 'empresa') {
                            // La empresa se determina por el email, no por el valor que viene
                            return;
                        }
                        if (fields[name] && value !== null && value !== '') {
                            fields[name].value = value;
                        }
                    });

                    // Determinar la empresa según el email
                    const email = result.user.email_personal || result.user.usuario;
                    const empresa = determinarEmpresaPorEmail(email);
                    if (empresa && fields.empresa) {
                        fields.empresa.value = empresa;
                    }

                    status.textContent = 'Datos encontrados en Adagio. Revisa y completa los campos restantes.';
                } catch (error) {
                    if (currentRequest === requestNumber) {
                        usuarioEncontradoEnAdagio = false;
                        status.textContent = 'No se pudo consultar Adagio. Completa los datos manualmente.';
                        // En caso de error
                        if (adagioDescription) {
                            adagioDescription.textContent = 'Error al consultar Adagio, se intentará crear el usuario';
                            adagioDescription.classList.remove('text-[#1f5d49]', 'text-[#68756d]');
                            adagioDescription.classList.add('text-amber-700');
                        }
                    }
                }
            };
        })();
    </script>
@endpush