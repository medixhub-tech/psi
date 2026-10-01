(() => {
    const panel = document.getElementById('cpf-lookup');
    if (!panel) return;
    const cpf = document.getElementById('lookup-cpf');
    const button = document.getElementById('lookup-cpf-button');
    const apply = document.getElementById('lookup-cpf-apply');
    const status = document.getElementById('lookup-cpf-status');
    const name = document.getElementById('full_name');
    let result = null;
    let revision = 0;
    cpf.addEventListener('input', () => {
        revision++;
        result = null;
        apply.classList.add('d-none');
        status.textContent = '';
    });
    button.addEventListener('click', async () => {
        const current = ++revision;
        result = null;
        apply.classList.add('d-none');
        button.disabled = true;
        const originalName = name.value;
        status.textContent = 'Consultando...';
        try {
            const response = await fetch(panel.dataset.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': panel.closest('form').querySelector('[name="_token"]').value},
                body: JSON.stringify({cpf: cpf.value.trim()}),
                signal: AbortSignal.timeout(25000)
            });
            if (current !== revision) return;
            if (!response.ok) {
                status.textContent = response.status === 422 ? 'Informe um CPF válido.' : response.status === 429 ? 'Limite de consultas atingido. Aguarde um minuto.' : 'Consulta indisponível. Você pode preencher o nome manualmente.';
                return;
            }
            const data = await response.json();
            if (current !== revision) return;
            if (data.status === 'found') {
                result = data.name;
                status.textContent = 'Nome encontrado: ' + result + '. Confira antes de usar.';
                if (!originalName.trim() && name.value === originalName) {
                    name.value = result;
                    status.textContent = 'Nome preenchido pela consulta. Confira antes de salvar.';
                } else {
                    apply.classList.remove('d-none');
                }
            } else {
                const messages = {
                    not_recognized: 'O serviço consultado não reconheceu este CPF. Confira os dígitos. Esse retorno não comprova inexistência ou irregularidade na Receita.',
                    not_found: 'O serviço consultado não retornou o nome deste CPF. Nenhum dado foi alterado.',
                    unavailable: 'O serviço de consulta não respondeu corretamente. Tente novamente mais tarde.'
                };
                status.textContent = messages[data.status] || messages.unavailable;
            }
        } catch {
            if (current === revision) status.textContent = 'Consulta indisponível. Preencha o cadastro manualmente.';
        } finally {
            button.disabled = false;
        }
    });
    apply.addEventListener('click', () => {
        if (!result) return;
        document.getElementById('full_name').value = result;
        document.getElementById('full_name').focus();
        status.textContent = 'Nome preenchido. Confira os dados e salve o cadastro.';
        apply.classList.add('d-none');
    });
})();
