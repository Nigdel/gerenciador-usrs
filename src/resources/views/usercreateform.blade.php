<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastrar usuário</title>
    <link rel="stylesheet" href="{{ asset('user-form.css') }}">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body>
    <main class="page-shell">
        <section class="form-panel" aria-labelledby="page-title">
            <div class="form-heading">
                <p class="eyebrow">Gestão de acessos</p>
                <h1 id="page-title">Cadastrar usuário</h1>
                <p>Preencha os dados abaixo para criar um novo acesso no sistema.</p>
            </div>

            <div id="feedback" class="feedback" role="status" aria-live="polite" hidden></div>

            <form id="user-form" action="{{ url('/users') }}" method="POST">
                @csrf

                <div class="field-grid">
                    <div class="field field-wide">
                        <label for="name">Nome completo <span aria-hidden="true">*</span></label>
                        <input id="name" name="name" type="text" autocomplete="name" maxlength="255" required>
                        <small class="error-message" data-error-for="name"></small>
                    </div>

                    <div class="field">
                        <label for="email">E-mail <span aria-hidden="true">*</span></label>
                        <input id="email" name="email" type="email" autocomplete="email" maxlength="255" required>
                        <small class="error-message" data-error-for="email"></small>
                    </div>

                    <div class="field">
                        <label for="cpf">CPF</label>
                        <input id="cpf" name="cpf" type="text" inputmode="numeric" maxlength="11" placeholder="Somente números">
                        <small class="error-message" data-error-for="cpf"></small>
                    </div>

                    <div class="field">
                        <label for="telefone_pessoal">Telefone pessoal</label>
                        <input id="telefone_pessoal" name="telefone_pessoal" type="tel" autocomplete="tel" maxlength="20">
                        <small class="error-message" data-error-for="telefone_pessoal"></small>
                    </div>

                    <div class="field">
                        <label for="telefone_servico">Telefone de serviço</label>
                        <input id="telefone_servico" name="telefone_servico" type="tel" maxlength="20">
                        <small class="error-message" data-error-for="telefone_servico"></small>
                    </div>

                    <div class="field">
                        <label for="empresa">Empresa</label>
                        <input id="empresa" name="empresa" type="text" maxlength="255">
                        <small class="error-message" data-error-for="empresa"></small>
                    </div>

                    <div class="field">
                        <label for="cargo">Cargo</label>
                        <input id="cargo" name="cargo" type="text" maxlength="255">
                        <small class="error-message" data-error-for="cargo"></small>
                    </div>

                    <div class="field">
                        <label for="encarregado_id">ID do encarregado</label>
                        <input id="encarregado_id" name="encarregado_id" type="number" min="1" inputmode="numeric">
                        <small class="field-hint">Opcional. Informe o ID de um usuário já cadastrado.</small>
                        <small class="error-message" data-error-for="encarregado_id"></small>
                    </div>

                    <div class="field field-wide">
                        <label for="password">Senha <span aria-hidden="true">*</span></label>
                        <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                        <small class="field-hint">Mínimo de 8 caracteres, com maiúscula, minúscula, número e símbolo.</small>
                        <small class="error-message" data-error-for="password"></small>
                    </div>
                </div>

                <label class="checkbox-field" for="externo">
                    <input id="externo" name="externo" type="checkbox" value="1">
                    <span>Este é um usuário externo</span>
                </label>

                <div class="form-actions">
                    <a class="button button-secondary" href="{{ url('/') }}">Cancelar</a>
                    <button class="button button-primary" type="submit" id="submit-button">Cadastrar usuário</button>
                </div>
            </form>
        </section>
    </main>

    <script>
        const form = document.getElementById('user-form');
        const feedback = document.getElementById('feedback');
        const submitButton = document.getElementById('submit-button');

        function showFeedback(message, type) {
            feedback.textContent = message;
            feedback.className = `feedback feedback-${type}`;
            feedback.hidden = false;
        }

        function clearErrors() {
            document.querySelectorAll('.error-message').forEach((element) => {
                element.textContent = '';
            });
            document.querySelectorAll('.input-error').forEach((element) => {
                element.classList.remove('input-error');
            });
        }

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            clearErrors();
            feedback.hidden = true;
            submitButton.disabled = true;
            submitButton.textContent = 'Cadastrando...';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: new FormData(form),
                });
                const data = await response.json();

                if (!response.ok) {
                    Object.entries(data.errors || {}).forEach(([field, messages]) => {
                        const input = document.getElementById(field);
                        const error = document.querySelector(`[data-error-for="${field}"]`);
                        if (input) input.classList.add('input-error');
                        if (error) error.textContent = messages[0];
                    });
                    showFeedback('Revise os campos destacados.', 'error');
                    return;
                }

                form.reset();
                showFeedback('Usuário cadastrado com sucesso.', 'success');
            } catch (error) {
                showFeedback('Não foi possível concluir o cadastro. Tente novamente.', 'error');
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = 'Cadastrar usuário';
            }
        });
    </script>
</body>
</html>