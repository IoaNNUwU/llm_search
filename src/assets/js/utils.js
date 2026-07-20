export async function parseJsonResponse(res) {
    const text = await res.text();
    try {
        return JSON.parse(text);
    } catch {
        const snippet = text.replace(/\s+/g, ' ').trim().slice(0, 180);
        throw new Error(snippet || `Upload failed (HTTP ${res.status})`);
    }
}

export async function postProjectAction(url, projectId) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: projectId }),
    });
    const data = await parseJsonResponse(res);
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}

export function statusLabel(ev) {
    if (!ev) return '';
    switch (ev.status) {
        case 'pending': return 'queued';
        case 'processing': return `${ev.percent}%`;
        case 'completed': return 'done';
        case 'failed': return 'failed';
        case 'cancelled': return 'cancelled';
        default: return ev.status || '';
    }
}

export function isMarkdownPath(path) {
    return /\.md$/i.test(path || '');
}
