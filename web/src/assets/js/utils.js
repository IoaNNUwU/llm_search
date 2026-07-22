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
        case 'processing':
            return ev.current_phase === 'ingest'
                ? `search ${ev.searchable_percent || 0}%`
                : `${ev.percent || 0}%`;
        case 'completed':
            return (ev.enrichment_pending_files || 0) > 0
                ? `enrich ${ev.enrichment_percent || 0}%`
                : 'done';
        case 'failed': return 'failed';
        case 'cancelled': return 'cancelled';
        default: return ev.status || '';
    }
}

export function renderEnrichmentSegments(bar, evaluation) {
    if (!bar) return;
    bar.querySelectorAll('.progress-enrichment-segment').forEach((node) => node.remove());

    const total = Number(evaluation?.total_files || 0);
    const slots = Array.isArray(evaluation?.enrichment_slots)
        ? evaluation.enrichment_slots
        : [];
    if (total < 1) return;

    for (const item of slots) {
        const slot = Math.max(1, Math.min(total, Number(item.slot || 1)));
        const segment = document.createElement('span');
        segment.className = `progress-enrichment-segment is-${item.status || 'queued'}`;
        segment.style.left = `${((slot - 1) / total) * 100}%`;
        segment.style.width = `${100 / total}%`;
        segment.title = `Qwen enrichment: ${item.status || 'queued'}`;
        bar.append(segment);
    }
}

export function isMarkdownPath(path) {
    return /\.md$/i.test(path || '');
}
