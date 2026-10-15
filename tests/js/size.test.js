// @vitest-environment node
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
import { transformSync } from 'esbuild';
import { expect, it } from 'vitest';

const source = readFileSync(new URL('../../resources/js/flare.js', import.meta.url), 'utf8');
const minified = transformSync(source, { minify: true, format: 'esm', target: 'es2020' }).code;

it('stays around two kilobytes minified', () => {
    console.info(`flare.js minified: ${minified.length} bytes, gzipped: ${gzipSync(minified).length} bytes`);

    expect(minified.length).toBeLessThan(2560);
});

it('has no secret or url baked in', () => {
    expect(minified).not.toMatch(/flr_|flp_|https?:\/\//);
});
