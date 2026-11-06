import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        coverage: {
            include: ['resources/js/flare.js'],
            thresholds: { lines: 100, functions: 100, branches: 100, statements: 100 },
        },
    },
});
