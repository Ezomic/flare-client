import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import flare from '../../resources/js/flare.js';

const URL = 'https://flare.example/api/ingest/browser';
const KEY = 'flp_' + 'a'.repeat(40);
const STACK =
    'TypeError: x\n    at render (https://app.example/build/assets/app-B7x2k1.js:14:88210)\n    at https://app.example/build/assets/app-B7x2k1.js:20:5';

let fetchMock;

const setup = (options = {}) => flare({ url: URL, key: KEY, release: 'a'.repeat(40), ...options });

const error = (message = 'boom', stack = STACK, name = 'TypeError') => Object.assign(new Error(message), { name, stack });

const sent = (call = 0) => JSON.parse(fetchMock.mock.calls[call][1].body);

const rejection = (reason) => ({ reason });

const fakeRouter = () => {
    const handlers = {};

    return {
        handlers,
        on: vi.fn((name, handler) => {
            handlers[name] = handler;
        }),
        emit: (name, detail) => handlers[name]({ detail }),
    };
};

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-15T10:00:00Z'));
    window.onerror = null;
    window.onunhandledrejection = null;
    sessionStorage.clear();
    document.head.innerHTML = '';
    fetchMock = vi.fn(() => Promise.resolve({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('delivery', () => {
    it('posts to the url with the public key in the query and keepalive set', () => {
        setup();
        window.onerror('boom', 'https://app.example/a.js', 1, 2, error());

        const [target, init] = fetchMock.mock.calls[0];

        expect(target).toBe(`${URL}?k=${KEY}`);
        expect(init.method).toBe('POST');
        expect(init.keepalive).toBe(true);
        expect(init.credentials).toBe('omit');
    });

    it('sends a plain string body with no headers, so the browser never preflights', () => {
        setup();
        window.onerror('boom', '', 0, 0, error());

        const init = fetchMock.mock.calls[0][1];

        expect(typeof init.body).toBe('string');
        expect(init.headers).toBeUndefined();
    });

    it('url-encodes the key', () => {
        setup({ key: 'a b&c' });
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock.mock.calls[0][0]).toBe(`${URL}?k=a%20b%26c`);
    });

    it('sends what the server expects', () => {
        document.head.innerHTML = '<meta name="request-id" content="01JZZZZZZZZZZZZZZZZZZZZZZZ">';
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(sent()).toMatchObject({
            type: 'onerror',
            name: 'TypeError',
            message: 'boom',
            stack: STACK,
            url: location.href,
            release: 'a'.repeat(40),
            request_id: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            viewport: [innerWidth, innerHeight],
        });
    });

    it('leaves the request id out when the page has none', () => {
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(sent().request_id).toBeUndefined();
    });

    it('truncates a long message and stack', () => {
        setup();
        window.onerror('x', '', 0, 0, error('m'.repeat(5000), 's'.repeat(20000)));

        expect(sent().message).toHaveLength(1000);
        expect(sent().stack).toHaveLength(8000);
    });

    it('keeps a body far below what keepalive allows', () => {
        setup();
        window.onerror('x', '', 0, 0, error('m'.repeat(5000), 's'.repeat(20000)));

        expect(fetchMock.mock.calls[0][1].body.length).toBeLessThan(16000);
    });
});

describe('window.onerror', () => {
    it('reports an error object', () => {
        setup();
        window.onerror('boom', 'a.js', 1, 2, error());

        expect(sent()).toMatchObject({ type: 'onerror', name: 'TypeError', message: 'boom' });
    });

    it('builds a one frame stack when there is only a message and a position', () => {
        setup();
        window.onerror('Uncaught thing', 'https://app.example/a.js', 7, 9);

        expect(sent()).toMatchObject({ message: 'Uncaught thing', stack: 'at https://app.example/a.js:7:9' });
    });

    it('keeps the position of a thrown string, which has no stack of its own', () => {
        setup();
        window.onerror('Uncaught boom', 'https://app.example/a.js', 7, 9, 'boom');

        expect(sent()).toMatchObject({ message: 'boom', stack: 'at https://app.example/a.js:7:9' });
    });

    it('keeps the position of an error object that has no stack', () => {
        setup();
        window.onerror('Uncaught x', 'https://app.example/a.js', 7, 9, { name: 'Odd', message: 'x' });

        expect(sent()).toMatchObject({ name: 'Odd', message: 'x', stack: 'at https://app.example/a.js:7:9' });
    });

    it('sends no stack when there is no source either', () => {
        setup();
        window.onerror('Uncaught thing');

        expect(sent().stack).toBe('');
    });

    it('returns false and lets the browser log when nothing came before', () => {
        setup();

        expect(window.onerror('boom', '', 0, 0, error())).toBe(false);
    });

    it('chains a handler that was already there', () => {
        const previous = vi.fn(() => true);
        window.onerror = previous;
        setup();

        const result = window.onerror('boom', 'a.js', 1, 2, error());

        expect(previous).toHaveBeenCalledWith('boom', 'a.js', 1, 2, expect.any(Error));
        expect(result).toBe(true);
        expect(fetchMock).toHaveBeenCalledOnce();
    });
});

describe('window.onunhandledrejection', () => {
    it('reports a rejected error', () => {
        setup();
        window.onunhandledrejection(rejection(error('nope')));

        expect(sent()).toMatchObject({ type: 'unhandledrejection', name: 'TypeError', message: 'nope' });
    });

    it('reports a rejected string', () => {
        setup();
        window.onunhandledrejection(rejection('plain text'));

        expect(sent()).toMatchObject({ name: 'UnhandledRejection', message: 'plain text' });
    });

    it('reports a rejected object as json', () => {
        setup();
        window.onunhandledrejection(rejection({ code: 42 }));

        expect(sent().message).toBe('{"code":42}');
    });

    it('reports a rejected value that cannot be serialised', () => {
        const circular = {};
        circular.self = circular;
        setup();
        window.onunhandledrejection(rejection(circular));

        expect(sent().message).toBe('[object Object]');
    });

    it('sends nothing for a rejection with no reason, which has nothing to group by', () => {
        setup();
        window.onunhandledrejection(rejection(undefined));
        window.onunhandledrejection(rejection(''));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does not let empty rejections use up the budget of real errors', () => {
        setup();

        for (let i = 0; i < 25; i++) window.onunhandledrejection(rejection(undefined));

        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('chains a handler that was already there', () => {
        const previous = vi.fn();
        window.onunhandledrejection = previous;
        setup();

        window.onunhandledrejection(rejection(error()));

        expect(previous).toHaveBeenCalledOnce();
        expect(fetchMock).toHaveBeenCalledOnce();
    });
});

describe('ignore list', () => {
    it.each(['ResizeObserver loop completed with undelivered notifications.', 'ResizeObserver loop limit exceeded'])(
        'drops %s',
        (message) => {
            setup();
            window.onerror(message, '', 0, 0, error(message));

            expect(fetchMock).not.toHaveBeenCalled();
        },
    );

    it.each(['Script error.', 'Script error'])('drops the opaque cross origin %s', (message) => {
        setup();
        window.onerror(message, '', 0, 0);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('keeps a script error that has a stack', () => {
        setup();
        window.onerror('Script error.', '', 0, 0, error('Script error.'));

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it.each([
        ['chrome', 'Error: x\n    at run (chrome-extension://abc/content.js:1:1)\n    at https://app.example/a.js:1:1'],
        ['firefox', 'run@moz-extension://abc/content.js:1:1'],
        ['safari', 'run@safari-web-extension://abc/content.js:1:1'],
    ])('drops an error from a %s extension', (_, stack) => {
        setup();
        window.onerror('x', '', 0, 0, error('x', stack));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('keeps an error that only passed through an extension', () => {
        setup();
        window.onerror(
            'x',
            '',
            0,
            0,
            error('x', 'Error: x\n    at run (https://app.example/a.js:1:1)\n    at wrap (chrome-extension://abc/content.js:1:1)'),
        );

        expect(fetchMock).toHaveBeenCalledOnce();
    });
});

describe('dedupe', () => {
    it('sends the same message and stack once a minute', () => {
        setup();
        window.onerror('boom', '', 0, 0, error());
        window.onerror('boom', '', 0, 0, error());

        vi.advanceTimersByTime(59000);
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledTimes(1);

        vi.advanceTimersByTime(2000);
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('treats another message or another stack as another error', () => {
        setup();
        window.onerror('a', '', 0, 0, error('a'));
        window.onerror('b', '', 0, 0, error('b'));
        window.onerror('a', '', 0, 0, error('a', STACK.replace('88210', '1')));

        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('remembers across a reload of the page within the session', () => {
        setup();
        window.onerror('boom', '', 0, 0, error());

        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('still dedupes in memory when session storage is not available', () => {
        const original = Object.getOwnPropertyDescriptor(window, 'sessionStorage');

        Object.defineProperty(window, 'sessionStorage', {
            configurable: true,
            get() {
                throw new Error('denied');
            },
        });

        try {
            setup();
            window.onerror('boom', '', 0, 0, error());
            window.onerror('boom', '', 0, 0, error());

            expect(fetchMock).toHaveBeenCalledTimes(1);
        } finally {
            Object.defineProperty(window, 'sessionStorage', original);
        }
    });

    it('survives a corrupt session entry', () => {
        sessionStorage.flare = '{not json';
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('survives a session entry that is a json primitive', () => {
        sessionStorage.flare = '"5"';
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('survives a session entry that is not an object', () => {
        sessionStorage.flare = 'null';
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('ignores a session entry that is an array', () => {
        sessionStorage.flare = '[]';
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(fetchMock).toHaveBeenCalledOnce();
        expect(Array.isArray(JSON.parse(sessionStorage.flare))).toBe(false);
    });

    it('forgets a session entry whose time is not a number, so it cannot silence an error for good', () => {
        const e = error();
        setup();
        window.onerror('boom', '', 0, 0, e);
        const [id] = Object.keys(JSON.parse(sessionStorage.flare));
        window.onerror = null;
        sessionStorage.flare = JSON.stringify({ [id]: 'y' });
        fetchMock.mockClear();
        setup();
        window.onerror('boom', '', 0, 0, e);

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('forgets an entry stamped in the future, so a clock that went backwards cannot silence an error', () => {
        const e = error();
        setup();
        window.onerror('boom', '', 0, 0, e);
        fetchMock.mockClear();
        vi.setSystemTime(new Date('2026-10-15T09:00:00Z'));
        window.onerror('boom', '', 0, 0, e);

        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('forgets entries older than a minute', () => {
        setup();
        window.onerror('boom', '', 0, 0, error());
        vi.advanceTimersByTime(61000);
        window.onerror('other', '', 0, 0, error('other'));

        expect(Object.keys(JSON.parse(sessionStorage.flare))).toHaveLength(1);
    });
});

describe('storm guard', () => {
    it('sends at most twenty distinct errors per page load', () => {
        setup();

        for (let i = 0; i < 40; i++) window.onerror('e' + i, '', 0, 0, error('e' + i));

        expect(fetchMock).toHaveBeenCalledTimes(20);
    });

    it('does not count a deduped error against the cap', () => {
        setup();

        for (let i = 0; i < 100; i++) window.onerror('same', '', 0, 0, error('same'));

        window.onerror('other', '', 0, 0, error('other'));

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});

describe('never breaks the page', () => {
    it('does nothing when fetch does not exist', () => {
        vi.stubGlobal('fetch', undefined);
        setup();

        expect(() => window.onerror('boom', '', 0, 0, error())).not.toThrow();
    });

    it('does nothing when fetch throws', () => {
        fetchMock.mockImplementation(() => {
            throw new Error('blocked');
        });
        setup();

        expect(() => window.onerror('boom', '', 0, 0, error())).not.toThrow();
    });

    it('swallows a rejected fetch so it cannot become an unhandled rejection', async () => {
        const caught = vi.fn();
        fetchMock.mockImplementation(() => ({ catch: caught }));
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(caught).toHaveBeenCalledOnce();
        expect(() => caught.mock.calls[0][0]()).not.toThrow();
    });

    it('survives odd values', () => {
        setup();

        for (const odd of [null, undefined, 0, '', NaN, Symbol('x'), () => {}, { message: 5 }, { message: null }]) {
            expect(() => window.onunhandledrejection(rejection(odd))).not.toThrow();
        }
    });

    it('survives a stack that is not a string', () => {
        setup();

        expect(() => window.onerror('x', '', 0, 0, { message: 'x', stack: 12 })).not.toThrow();
    });

    it('still calls the page rejection handler when the reason is hostile', () => {
        const previous = vi.fn();
        window.onunhandledrejection = previous;
        setup();

        const hostile = [
            {
                get message() {
                    throw new Error('getter');
                },
            },
            Object.assign(Object.create(null), { toString: undefined }),
            (() => {
                const { proxy, revoke } = Proxy.revocable({}, {});
                revoke();
                return proxy;
            })(),
        ];

        for (const reason of hostile) expect(() => window.onunhandledrejection(rejection(reason))).not.toThrow();

        expect(previous).toHaveBeenCalledTimes(3);
    });

    it('survives an error whose stack is null', () => {
        setup();
        window.onerror('x', '', 0, 0, { message: 'x', stack: null });

        expect(sent().stack).toBe('');
    });

    it('caps the page url', () => {
        history.replaceState(null, '', '/?q=' + 'a'.repeat(40000));
        setup();
        window.onerror('boom', '', 0, 0, error());

        expect(sent().url).toHaveLength(2000);

        history.replaceState(null, '', '/');
    });

    it('does not have to be given any options', () => {
        expect(() => flare()).not.toThrow();
    });
});

describe('vue', () => {
    it('reports through app.config.errorHandler and keeps the console output', () => {
        const log = vi.spyOn(console, 'error').mockImplementation(() => {});
        const app = { config: {} };
        setup().vue(app);

        app.config.errorHandler(error('render failed'), {}, 'mounted hook');

        expect(sent()).toMatchObject({ type: 'vue', message: 'render failed', info: 'mounted hook' });
        expect(log).toHaveBeenCalledOnce();
    });

    it('calls the handler the app already had instead of logging', () => {
        const log = vi.spyOn(console, 'error').mockImplementation(() => {});
        const previous = vi.fn();
        const app = { config: { errorHandler: previous } };
        const instance = {};
        const failure = error('render failed');
        setup().vue(app);

        app.config.errorHandler(failure, instance, 'setup function');

        expect(previous).toHaveBeenCalledWith(failure, instance, 'setup function');
        expect(log).not.toHaveBeenCalled();
        expect(fetchMock).toHaveBeenCalledOnce();
    });
});

describe('inertia', () => {
    it('listens for the current and the older event names', () => {
        const router = fakeRouter();
        setup({ router });

        expect(Object.keys(router.handlers).sort()).toEqual(['exception', 'httpException', 'invalid', 'navigate', 'networkError']);
    });

    it.each(['exception', 'networkError'])('reports the error of a %s', (name) => {
        const router = fakeRouter();
        setup({ router });
        router.emit(name, name === 'exception' ? { exception: error('offline') } : { error: error('offline') });

        expect(sent()).toMatchObject({ type: 'inertia', message: 'offline', info: name });
    });

    it.each(['invalid', 'httpException'])('reports the status of a %s without its body', (name) => {
        const router = fakeRouter();
        setup({ router });
        router.emit(name, { response: { status: 500, data: '<html>secret</html>' } });

        expect(sent()).toMatchObject({ type: 'inertia', name: 'InertiaResponse', message: 'Unexpected response (500)', info: name });
        expect(JSON.stringify(sent())).not.toContain('secret');
    });

    it('does not throw on an event with no detail', () => {
        const router = fakeRouter();
        setup({ router });

        expect(() => router.handlers.exception({})).not.toThrow();
        expect(sent().message).toBe('Unexpected response (undefined)');
    });

    it('sends the current page component and sanitised props', () => {
        const router = fakeRouter();
        setup({ router });
        router.emit('navigate', {
            page: {
                component: 'invoices/Index',
                props: {
                    filters: { status: 'open' },
                    auth: { token: 'x' },
                    password: 'hunter2',
                    nested: { deep: { deeper: { deepest: { gone: 1 } } } },
                    note: 'n'.repeat(500),
                },
            },
        });
        window.onerror('boom', '', 0, 0, error());

        const body = sent();

        expect(body.component).toBe('invoices/Index');
        expect(body.props.filters).toEqual({ status: 'open' });
        expect(body.props.auth).toBe('[filtered]');
        expect(body.props.password).toBe('[filtered]');
        expect(body.props.nested.deep.deeper.deepest).toBe('[truncated]');
        expect(body.props.note).toHaveLength(200);
        expect(JSON.stringify(body)).not.toContain('hunter2');
    });

    it('follows the page as it changes', () => {
        const router = fakeRouter();
        setup({ router });
        router.emit('navigate', { page: { component: 'a', props: {} } });
        router.emit('navigate', { page: { component: 'b', props: {} } });
        window.onerror('boom', '', 0, 0, error());

        expect(sent().component).toBe('b');
    });

    it('caps how many props it looks at', () => {
        const router = fakeRouter();
        setup({ router });
        router.emit('navigate', {
            page: { component: 'a', props: Object.fromEntries(Array.from({ length: 100 }, (_, i) => [`k${i}`, i])) },
        });
        window.onerror('boom', '', 0, 0, error());

        expect(Object.keys(sent().props)).toHaveLength(30);
    });

    it('keeps arrays as arrays and leaves numbers, booleans and null alone', () => {
        const router = fakeRouter();
        setup({ router });
        router.emit('navigate', { page: { component: 'a', props: { list: [1, 'two', null, true] } } });
        window.onerror('boom', '', 0, 0, error());

        expect(sent().props.list).toEqual([1, 'two', null, true]);
    });

    it('leaves props out when they are too large to send', () => {
        const router = fakeRouter();
        setup({ router });
        router.emit('navigate', {
            page: { component: 'a', props: Object.fromEntries(Array.from({ length: 30 }, (_, i) => [`k${i}`, 'x'.repeat(200)])) },
        });
        window.onerror('boom', '', 0, 0, error());

        expect(sent().props).toBeUndefined();
        expect(sent().component).toBe('a');
    });

    it('keeps a prop named __proto__ as data instead of losing it', () => {
        const router = fakeRouter();
        setup({ router });
        router.emit('navigate', { page: { component: 'a', props: JSON.parse('{"__proto__": {"x": 1}, "ok": 2}') } });
        window.onerror('boom', '', 0, 0, error());

        expect(sent().props.ok).toBe(2);
        expect(Object.keys(sent().props)).toContain('__proto__');
    });

    it('sends no page before the first navigation', () => {
        setup({ router: fakeRouter() });
        window.onerror('boom', '', 0, 0, error());

        expect(sent().component).toBeUndefined();
        expect(sent().props).toBeUndefined();
    });

    it('survives props that point back at themselves', () => {
        const props = { name: 'x' };
        props.self = props;
        const router = fakeRouter();
        setup({ router });
        router.emit('navigate', { page: { component: 'a', props } });

        expect(() => window.onerror('boom', '', 0, 0, error())).not.toThrow();
    });
});

describe('a router that is not what it should be', () => {
    it('still installs the handlers when the router has no on method', () => {
        const reporter = setup({ router: {} });
        window.onerror('boom', '', 0, 0, error());

        expect(reporter.report).toBeTypeOf('function');
        expect(fetchMock).toHaveBeenCalledOnce();
    });

    it('still installs the handlers when the router throws', () => {
        const router = {
            on: () => {
                throw new Error('no');
            },
        };

        expect(() => setup({ router })).not.toThrow();
    });
});

describe('manual reports', () => {
    it('reports an error handed to it', () => {
        setup().report(error('manual one'));

        expect(sent()).toMatchObject({ type: 'manual', message: 'manual one' });
    });
});
