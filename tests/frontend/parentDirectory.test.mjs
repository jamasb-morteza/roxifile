import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';
import { compile } from '@vue/compiler-dom';
import * as Vue from 'vue';

const source = await readFile(
    new URL('../../resources/js/pages/files/Index.vue', import.meta.url),
    'utf8',
);
const button = source.match(
    /<button\b[^>]*data-test="parent-directory-row"[\s\S]*?<\/button>/,
)?.[0];
assert.ok(button, 'Parent navigation is rendered before the file listing');
const render = runInNewContext(`(function () { ${compile(button).code} })()`, {
    Vue,
});

for (const [name, directory, breadcrumbs, expected] of [
    ['root', null, [], null],
    ['top-level folder', { id: 1 }, [{ id: 1 }], null],
    ['nested folder', { id: 3 }, [{ id: 1 }, { id: 2 }, { id: 3 }], 2],
]) {
    assert.equal(typeof name, 'string');
    if (typeof name !== 'string') throw new TypeError('Expected a test name');
    test(`parent navigation in ${name}`, () => {
        const calls = [];
        const row = render(
            {
                directory,
                breadcrumbs,
                openDirectory: (id) => calls.push(id),
            },
            [],
        );
        assert.equal(row.props.disabled, directory === null);
        if (directory) {
            row.props.onClick({ stopPropagation() {} });
            assert.deepEqual(calls, [expected]);
        }
    });
}

test('breadcrumbs and parent entries belong to the listing panel below search', () => {
    const panel = source.indexOf('data-test="file-listing-panel"');
    const breadcrumbs = source.indexOf('aria-label="Directory breadcrumb"');
    const list = source.indexOf('<TableBody>');
    const parentRow = source.indexOf('data-test="parent-directory-row"');
    const directoryRow = source.indexOf('data-test="directory-row"');
    const grid = source.indexOf('data-test="grid-view"');
    const directoryCard = source.indexOf('data-test="directory-card"');
    assert.ok(source.indexOf('placeholder="Search files…"') < panel);
    assert.ok(panel < breadcrumbs && breadcrumbs < list);
    assert.ok(list < parentRow && parentRow < directoryRow);
    assert.ok(grid < directoryCard);
    assert.equal(source.includes('data-test="parent-directory-card"'), false);
    assert.ok(source.indexOf('<!-- Empty state -->') > directoryCard);
});

test('parent entry uses standard table cells without button decoration', () => {
    assert.equal(button.match(/class="([^"]*)"/)[1], 'flex items-center gap-2');
    assert.equal(source.includes(':colspan="5" class="p-0"'), false);
});
