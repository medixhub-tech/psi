document.querySelectorAll('[data-address-url]').forEach(panel => {
    const input = panel.querySelector('[name="postal_code"]');
    const button = panel.querySelector('[data-cep-button]');
    const status = panel.querySelector('[data-cep-status]');
    let revision = 0;
    panel.addEventListener('input', () => { revision++; });
    button.addEventListener('click', async () => {
        const current = ++revision;
        button.disabled = true;
        status.textContent = 'Consultando CEP...';
        try {
            const response = await fetch(panel.dataset.addressUrl, {
                method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': panel.closest('form').querySelector('[name="_token"]').value},
                body: JSON.stringify({cep: input.value.trim()}), signal: AbortSignal.timeout(10000)
            });
            const data = await response.json();
            if (revision !== current) { status.textContent = 'O endereço foi editado durante a consulta. Consulte novamente se necessário.'; return; }
            if (response.ok && data.status === 'found') {
                Object.entries(data.address).forEach(([field,value]) => { const target = panel.querySelector('[name="'+field+'"]'); if (target) target.value = value; });
                status.textContent = 'Endereço preenchido. Confira e informe número e complemento.';
            } else {
                status.textContent = response.status === 422 ? 'Informe um CEP válido.' : data.status === 'not_found' ? 'CEP não encontrado. Preencha manualmente.' : 'Consulta indisponível. Preencha manualmente.';
            }
        } catch { status.textContent = 'Consulta indisponível. Preencha manualmente.'; }
        finally { button.disabled = false; }
    });
});
