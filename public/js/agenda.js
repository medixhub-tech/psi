document.addEventListener('submit', (event) => {
    const form = event.target;
    const message = form.dataset.confirm;
    if (message && !window.confirm(message)) { event.preventDefault(); return; }
    if (form.method.toLowerCase() === 'post') {
        document.documentElement.dataset.submitting = 'true';
        form.querySelectorAll('button').forEach(button => { button.disabled = true; });
    }
});
const queue = document.getElementById('live-queue');
if (queue) {
    const status = document.getElementById('queue-status');
    let busy = false;
    setInterval(async () => {
        if (busy || document.hidden || document.documentElement.dataset.submitting || queue.contains(document.activeElement)) return;
        busy = true;
        try {
            const response = await fetch(queue.dataset.url, {credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'},cache:'no-store'});
            if (response.redirected || response.status === 401 || response.status === 403) {
                queue.replaceChildren();
                status.textContent = 'A sessão ou suas permissões mudaram. Atualize a página para continuar.';
                return;
            }
            if (!response.ok) throw new Error('queue');
            queue.innerHTML = await response.text();
            status.textContent = 'Atualizado às ' + new Date().toLocaleTimeString('pt-BR') + '. Atualização a cada 15 segundos.';
        } catch (_) { status.textContent = 'Não foi possível atualizar a fila. Tentaremos novamente em instantes.'; }
        finally { busy = false; }
    },15000);
}
