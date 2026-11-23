import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { runInNewContext } from "node:vm";
import ts from "typescript";

const source = await readFile(
    new URL("../../resources/js/pages/files/Index.vue", import.meta.url),
    "utf8",
);

test("only the free-text search stays outside the filter sheet", () => {
    const search = source.indexOf('data-test="files-search"');
    const panel = source.indexOf('<Sheet v-model:open="filtersOpen">');
    const panelEnd = source.indexOf("</Sheet>", panel);
    const nextControl = source.indexOf('data-test="trash-toggle"');

    assert.ok(search !== -1 && search < panel);
    assert.ok(panelEnd > panel && panelEnd < nextControl);
    for (const control of ["chat-filter", "type-filter", "tag-filter"]) {
        const index = source.indexOf(`data-test="${control}"`);
        assert.ok(index > panel && index < panelEnd, `${control} belongs inside the filter sheet`);
    }
    for (const draft of [
        "draftMimeFilter",
        "draftCreatedAfter",
        "draftCreatedBefore",
        "draftSearchEverywhere",
    ]) {
        assert.ok(source.slice(panel, panelEnd).includes(`v-model="${draft}"`));
    }
    assert.match(source.slice(panel, panelEnd), /@submit\.prevent="applyFilters"/);
    assert.match(source.slice(panel, panelEnd), /@click="clearDraftFilters"/);
    assert.doesNotMatch(source.slice(search, panel), /<Select[\s>]/);
});

test("list columns and grid offer sorting without the header sort select", () => {
    assert.doesNotMatch(source, /:model-value="filters\.sort"/);
    assert.doesNotMatch(source, /@click="toggleDirection"/);
    for (const column of ["name", "file_type", "size", "created_at", "updated_at"]) {
        assert.match(source, new RegExp(`data-test="sort-${column}"`));
        assert.match(source, new RegExp(`@click="sortByColumn\\('${column}'\\)"`));
        assert.match(source, new RegExp(`columnSortDirection\\('${column}'\\)`));
    }
    assert.match(source, /data-test="grid-sort-columns"/);
    assert.match(source, /@click="sortByColumn\(column\.value\)"/);
});

test("clicking a column sets the default direction then reverses it", () => {
    const fn = source.match(
        /function sortByColumn\(column: SortColumn\): void \{[\s\S]*?\n\}/,
    )?.[0];
    assert.ok(fn);
    const script = ts.transpileModule(`${fn}\nthis.sortByColumn = sortByColumn;`, {
        compilerOptions: { target: ts.ScriptTarget.ES2022 },
    }).outputText;
    const visits = [];
    const ctx = {
        props: { filters: { sort: "updated_at", direction: "desc" } },
        visit: (params) => visits.push({ ...params }),
    };
    runInNewContext(script, ctx);

    ctx.sortByColumn("name");
    assert.deepEqual(visits.pop(), { sort: "name", direction: "asc", page: 1 });
    ctx.props.filters = { sort: "name", direction: "asc" };
    ctx.sortByColumn("name");
    assert.deepEqual(visits.pop(), { sort: "name", direction: "desc", page: 1 });
    ctx.props.filters = { sort: "name", direction: "desc" };
    ctx.sortByColumn("name");
    assert.deepEqual(visits.pop(), { sort: "name", direction: "asc", page: 1 });
    ctx.sortByColumn("size");
    assert.deepEqual(visits.pop(), { sort: "size", direction: "desc", page: 1 });
});


test('file manager does not expose a Transfers toolbar button or panel', () => {
    assert.doesNotMatch(source, /data-test="recent-transfers-trigger"/);
    assert.doesNotMatch(source, /data-test="recent-transfers-panel"/);
    assert.doesNotMatch(source, /Recent transfers/);
});

test('select-all checkbox sits before Name and replaces the page selection button', () => {
    assert.doesNotMatch(source, /Select this page/);
    const header = source.slice(
        source.indexOf('<!-- Listing (list view) -->'),
        source.indexOf('</TableHeader>', source.indexOf('<!-- Listing (list view) -->')),
    );
    assert.match(header, /data-test="select-all-files"/);
    assert.ok(header.indexOf('data-test="select-all-files"') < header.indexOf('data-test="sort-name"'));
    assert.match(header, /:checked="allPageFilesSelected"/);
    assert.match(header, /:indeterminate="somePageFilesSelected && !allPageFilesSelected"/);
    assert.match(header, /@change="togglePageSelection/);
    assert.match(header, /v-if="!filters.trash"/);
});

test('header checkbox selects only page files, preserves other page selection, and reflects partial selection', () => {
    const match = source.match(
        /const selectedIds = ref<number\[\]>\(\[\]\);[\s\S]*?(?=const bulkTagDraft)/,
    );
    assert.ok(match, 'select-all implementation should be present');
    const script = ts.transpileModule(
        match[0] + '\nthis.selection = { selectedIds, selectablePageIds, allPageFilesSelected, somePageFilesSelected, togglePageSelection };',
        { compilerOptions: { target: ts.ScriptTarget.ES2022 } },
    ).outputText;

    const fileListing = { value: { data: [{ id: 11 }, { id: 12 }] } };
    const ctx = {
        fileListing,
        ref: (value) => ({ value }),
        computed: (fn) => ({ get value() { return fn(); } }),
        Set,
    };
    runInNewContext(script, ctx);
    const { selectedIds, allPageFilesSelected, somePageFilesSelected, togglePageSelection } = ctx.selection;

    assert.equal(allPageFilesSelected.value, false);
    assert.equal(somePageFilesSelected.value, false);
    selectedIds.value = [11, 99];
    assert.equal(allPageFilesSelected.value, false);
    assert.equal(somePageFilesSelected.value, true);

    togglePageSelection(true);
    assert.deepEqual([...selectedIds.value].sort((a, b) => a - b), [11, 12, 99]);
    assert.equal(allPageFilesSelected.value, true);

    togglePageSelection(false);
    assert.deepEqual([...selectedIds.value], [99]);
    assert.equal(somePageFilesSelected.value, false);

    fileListing.value = { data: [] };
    assert.equal(allPageFilesSelected.value, false);
    togglePageSelection(true);
    assert.deepEqual([...selectedIds.value], [99]);
});

test('bulk selection actions render as icon buttons opposite the directory breadcrumb', () => {
    const listing = source.slice(source.indexOf('data-test="file-listing-panel"'));
    const navStart = listing.indexOf('<nav');
    const navEnd = listing.indexOf('</nav>');
    const nav = listing.slice(navStart, navEnd);
    const breadcrumb = nav.indexOf("t('My Files')");
    const actions = nav.indexOf('data-test="breadcrumb-bulk-actions"');

    assert.ok(breadcrumb >= 0 && actions > breadcrumb, 'bulk actions follow breadcrumbs in the same nav');
    assert.match(nav, /class="ms-auto flex shrink-0 items-center gap-1"/);
    for (const [dataTest, icon] of [
        ['bulk-add-tags', 'Tags'],
        ['bulk-move-to-trash', 'Trash2'],
        ['bulk-clear-selection', 'X'],
    ]) {
        assert.match(nav, new RegExp(`data-test="${dataTest}"[\\s\\S]*?<${icon} class="size-4" />`));
    }
    assert.doesNotMatch(source.slice(0, source.indexOf('<!-- Upload cards -->')), /Tags, separated by commas/);
    assert.doesNotMatch(source, />Move to trash<\/Button>/);
    assert.match(nav, /v-if="!filters\.trash && selectedIds\.length"/);
});

test('tag icon opens a dialog with a comma-delimited input and an apply action', () => {
    const listing = source.slice(source.indexOf('data-test="file-listing-panel"'));
    const dialog = listing.slice(listing.indexOf('<Dialog v-model:open="bulkTagOpen">'));
    const content = dialog.slice(0, dialog.indexOf('</Dialog>'));
    assert.match(listing, /@click="openBulkTagDialog"/);
    assert.match(content, /data-test="bulk-tag-dialog"/);
    assert.match(content, /@submit\.prevent="tagSelectedFiles"/);
    assert.match(content, /v-model="bulkTagDraft"/);
    assert.match(content, /data-test="bulk-tag-input"/);
    assert.match(content, /data-test="bulk-tag-apply"/);
    assert.match(content, /type="submit"/);
    assert.match(content, /bulkTagForm\.processing \|\| privateBulkTagProcessing/);
    assert.match(source, /bulkTagForm\.post\(filesRoutes\.bulkTags\.url\(\)/);
    assert.match(source, /bulkTagOpen\.value = false/);
});

test('File Manager hides worker and Roxi connection pills while keeping flagged-file alerts', () => {
    assert.doesNotMatch(source, /Worker online|Worker offline|Roxi connected|Roxi disconnected/);
    assert.doesNotMatch(source, /data-test="worker-status"/);
    assert.match(source, /v-if="\(workerStatus\?\.flagged_files \?\? 0\) > 0"/);
    assert.match(source, /file\(s\) need review/);
});
