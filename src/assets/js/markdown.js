import { marked } from 'https://cdn.jsdelivr.net/npm/marked@15.0.12/lib/marked.esm.js';
import DOMPurify from 'https://cdn.jsdelivr.net/npm/dompurify@3.2.6/+esm';

marked.setOptions({
    gfm: true,
    breaks: true,
});

const PURIFY_OPTS = {
    USE_PROFILES: { html: true },
    ADD_ATTR: ['target', 'rel', 'title'],
};

/**
 * @param {unknown} value
 * @returns {string}
 */
function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Turn [n] markers into links using citation metadata (skip code blocks).
 * @param {string} html
 * @param {Array<{n: number, link: string, title?: string}>} citations
 * @returns {string}
 */
function linkifyCitations(html, citations) {
    if (!citations.length) {
        return html;
    }

    /** @type {Record<string, {n: number, link: string, title?: string}>} */
    const byN = {};
    for (const citation of citations) {
        const n = Number(citation?.n);
        const link = typeof citation?.link === 'string' ? citation.link.trim() : '';
        if (n > 0 && link) {
            byN[String(n)] = citation;
        }
    }
    if (Object.keys(byN).length === 0) {
        return html;
    }

    return html.replace(/(<pre[\s\S]*?<\/pre>|<code[\s\S]*?<\/code>)|\[(\d+)\]/gi, (match, code, num) => {
        if (code) {
            return code;
        }
        const citation = byN[num];
        if (!citation) {
            return match;
        }
        const href = escapeHtml(citation.link);
        const title = escapeHtml(citation.title || `Reference ${num}`);
        return `<a class="cite" href="${href}" target="_blank" rel="noopener noreferrer" title="${title}">🔗</a>`;
    });
}

/**
 * Convert markdown to sanitized HTML (GFM: bold, lists, tables, code, links, …).
 * @param {string} text
 * @param {Array<{n: number, link: string, title?: string}>} [citations]
 * @returns {string}
 */
export function renderMarkdown(text, citations = []) {
    const raw = typeof text === 'string' ? text : '';
    if (raw.trim() === '') {
        return '';
    }
    const html = marked.parse(raw, { async: false });
    const linked = linkifyCitations(html, Array.isArray(citations) ? citations : []);
    return DOMPurify.sanitize(linked, PURIFY_OPTS);
}

/**
 * Replace plain-text markdown in chat bubbles with rendered HTML.
 * @param {ParentNode} [root]
 */
export function hydrateChatMarkdown(root = document) {
    root.querySelectorAll('.bubble .content:not([data-md])').forEach((el) => {
        const source = el.textContent ?? '';
        let citations = [];
        const raw = el.getAttribute('data-citations');
        if (raw) {
            try {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    citations = parsed;
                }
            } catch {
                // ignore bad payload
            }
        }
        el.innerHTML = renderMarkdown(source, citations);
        el.setAttribute('data-md', '1');
    });
}
