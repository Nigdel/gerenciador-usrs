{{--
    Comportamiento del panel de equipos de Chatwoot.

    Se comparte entre el alta de usuario y el modal de nuevas cuentas para que
    ambos carguen los equipos de la misma forma.

    Espera:
      $chatwootCheckboxSelector    Selector del checkbox del subsistema Chatwoot.
      $chatwootPanelSelector       Selector del contenedor del panel.
      $chatwootOptionsSelector     Selector del contenedor de opciones.
      $chatwootStatusSelector      Selector del texto de estado.
      $chatwootTeamsRoute          URL del endpoint de equipos.
      $chatwootEmpresa             Empresa fija (opcional).
      $chatwootEmpresaSelector     Selector del campo empresa (opcional).
      $chatwootInitialStatus       Texto inicial del estado.
      $chatwootEmptyEmpresaMessage Mensaje cuando no hay empresa.
      $chatwootSelectedTeams       Equipos ya marcados (para repintar tras un error).
--}}
@push('scripts')
@php
    $chatwootConfig = [
        'checkbox' => $chatwootCheckboxSelector,
        'panel' => $chatwootPanelSelector,
        'options' => $chatwootOptionsSelector,
        'status' => $chatwootStatusSelector,
        'route' => $chatwootTeamsRoute,
        'empresa' => $chatwootEmpresa ?? null,
        'empresaSelector' => $chatwootEmpresaSelector ?? null,
        'initialStatus' => $chatwootInitialStatus ?? 'Selecciona una empresa y marca Chatwoot.',
        'emptyEmpresaMessage' => $chatwootEmptyEmpresaMessage ?? 'Selecciona una empresa y marca Chatwoot.',
        'selectedTeams' => array_map('strval', is_array($chatwootSelectedTeams ?? null) ? $chatwootSelectedTeams : []),
    ];
@endphp
<script>
    (() => {
        const config = @json($chatwootConfig);

        const checkbox = document.querySelector(config.checkbox);
        const panel = document.querySelector(config.panel);
        const options = document.querySelector(config.options);
        const status = document.querySelector(config.status);
        const empresaField = config.empresaSelector
            ? document.querySelector(config.empresaSelector)
            : null;

        if (!checkbox || !panel || !options || !status || !config.route) {
            return;
        }

        let requestNumber = 0;

        const empresa = () => (empresaField ? empresaField.value : (config.empresa || '')).trim();

        const clearTeams = () => {
            options.querySelectorAll('input[type="checkbox"][name="subsystem_config[chatwoot][teams][]"]').forEach((teamCheckbox) => {
                teamCheckbox.checked = false;
            });
        };

        const hidePanel = () => {
            panel.classList.add('hidden');
            clearTeams();
            status.textContent = config.initialStatus;
        };

        const renderTeams = (teams) => {
            options.innerHTML = '';

            if (!Array.isArray(teams) || teams.length === 0) {
                status.textContent = 'No se encontraron equipos disponibles para la empresa seleccionada.';
                return;
            }

            status.textContent = `${teams.length} equipos disponibles.`;

            teams.forEach((team) => {
                const label = document.createElement('label');
                label.className = 'flex items-center gap-2 rounded-md border border-[#d9e2dc] bg-white p-2 text-sm text-[#17211b]';

                const input = document.createElement('input');
                input.type = 'checkbox';
                input.name = 'subsystem_config[chatwoot][teams][]';
                input.value = team.id;
                input.checked = config.selectedTeams.includes(String(team.id));

                const span = document.createElement('span');
                span.textContent = team.name;

                label.appendChild(input);
                label.appendChild(span);
                options.appendChild(label);
            });
        };

        const loadTeams = async () => {
            if (!checkbox.checked) {
                hidePanel();
                return;
            }

            panel.classList.remove('hidden');

            const empresaActual = empresa();

            if (!empresaActual) {
                clearTeams();
                status.textContent = config.emptyEmpresaMessage;
                return;
            }

            status.textContent = 'Consultando equipos de Chatwoot...';
            clearTeams();

            const requestId = ++requestNumber;

            try {
                const url = new URL(config.route, window.location.origin);
                url.searchParams.set('empresa', empresaActual);

                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json' },
                });

                if (requestId !== requestNumber) {
                    return;
                }

                if (!response.ok) {
                    throw new Error('chatwoot-teams-error');
                }

                const result = await response.json();
                renderTeams(Array.isArray(result.teams) ? result.teams : []);
            } catch (error) {
                if (requestId !== requestNumber) {
                    return;
                }

                status.textContent = 'No se pudieron cargar los equipos para la empresa seleccionada.';
                clearTeams();
            }
        };

        checkbox.addEventListener('change', () => {
            if (!checkbox.checked) {
                hidePanel();
                return;
            }

            loadTeams();
        });

        if (empresaField) {
            empresaField.addEventListener('change', () => {
                clearTeams();
                loadTeams();
            });
        }

        if (checkbox.checked) {
            loadTeams();
        } else {
            hidePanel();
        }
    })();
</script>
@endpush
