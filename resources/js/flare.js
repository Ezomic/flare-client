const SECRET = /pass|secret|token|key|auth|credit|card|cvv|iban|bsn|ssn|otp|pin|signature|session/i;
const EXTENSION = /(?:chrome|moz|safari(?:-web)?)-extension:\/\//;
const EVENTS = ['exception', 'invalid', 'networkError', 'httpException'];

const hash = (text) => [...text].reduce((h, c) => (Math.imul(31, h) + c.charCodeAt(0)) | 0, 0);

const clean = (value, depth = 0) => {
    if (value && typeof value === 'object') {
        if (depth > 3) return '[truncated]';

        const out = Array.isArray(value) ? [] : Object.create(null);

        Object.keys(value)
            .slice(0, 30)
            .forEach((key) => (out[key] = SECRET.test(key) ? '[filtered]' : clean(value[key], depth + 1)));

        return out;
    }

    return typeof value === 'string' ? value.slice(0, 200) : value;
};

const asError = (value, name) => {
    if (value && typeof value.message === 'string') return value;

    let message;

    try {
        message = typeof value === 'string' ? value : JSON.stringify(value);
    } catch {
        message = String(value);
    }

    return { name, message };
};

export default function flare({ url, key, release, router } = {}) {
    let page;
    let sent = 0;
    let seen = {};

    try {
        const stored = JSON.parse(sessionStorage.flare);

        if (stored && typeof stored === 'object' && !Array.isArray(stored)) seen = stored;
    } catch {}

    const fresh = (id) => {
        const now = Date.now();

        for (const k in seen) if (!(now - seen[k] >= 0 && now - seen[k] <= 60000)) delete seen[k];

        if (seen[id]) return false;

        seen[id] = now;

        try {
            sessionStorage.flare = JSON.stringify(seen);
        } catch {}

        return true;
    };

    const report = (type, error, extra) => {
        try {
            const {
                name = 'Error',
                message = '',
                stack: raw,
            } = asError(error, type === 'unhandledrejection' ? 'UnhandledRejection' : 'Error');
            const stack = String(raw ?? '');
            const text = String(message).slice(0, 1000);
            const top = stack.split('\n').find((line) => /:\d+:\d+/.test(line)) || '';

            if (!text && !stack) return;

            if (/^ResizeObserver loop/.test(text) || (!stack && /^Script error\.?$/.test(text)) || EXTENSION.test(top)) return;

            if (!fresh(hash(text + stack)) || sent >= 20) return;

            sent++;

            const props = clean(page?.props);
            const body = {
                type,
                name,
                message: text,
                stack: stack.slice(0, 8000),
                url: location.href.slice(0, 2000),
                release,
                request_id: document.querySelector('meta[name=request-id]')?.content,
                component: page?.component,
                props: JSON.stringify(props)?.length < 4000 ? props : undefined,
                viewport: [innerWidth, innerHeight],
                ...extra,
            };

            fetch(`${url}?k=${encodeURIComponent(key)}`, {
                method: 'POST',
                body: JSON.stringify(body),
                keepalive: true,
                credentials: 'omit',
            }).catch(() => {});
        } catch {}
    };

    const onerror = window.onerror;
    window.onerror = function (message, source, line, col, error) {
        report(
            'onerror',
            error?.stack ? error : { ...asError(error || message, 'Error'), stack: source ? `at ${source}:${line}:${col}` : '' },
        );

        return onerror ? onerror.apply(this, arguments) : false;
    };

    const onrejection = window.onunhandledrejection;
    window.onunhandledrejection = function (event) {
        report('unhandledrejection', event.reason);

        return onrejection ? onrejection.apply(this, arguments) : undefined;
    };

    try {
        router?.on('navigate', (event) => (page = event.detail.page));

        EVENTS.forEach((name) =>
            router.on(name, (event) => {
                const { exception, error, response } = event.detail || {};

                report('inertia', exception || error || { name: 'InertiaResponse', message: `Unexpected response (${response?.status})` }, {
                    info: name,
                });
            }),
        );
    } catch {}

    return {
        report: (error) => report('manual', error),
        vue: (app) => {
            const previous = app.config.errorHandler;

            app.config.errorHandler = (error, instance, info) => {
                report('vue', error, { info });

                if (previous) previous(error, instance, info);
                else console.error(error);
            };
        },
    };
}
