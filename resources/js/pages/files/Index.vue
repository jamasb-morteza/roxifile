<script setup lang="ts">
import { Head, Link, router, useHttp, usePage, usePoll } from '@inertiajs/vue3';
import { useLocalization } from '@/composables/useLocalization';
import {
    ArrowDown,
    ArrowUp,
    ChevronLeft,
    ChevronRight,
    CloudDownload,
    Download,
    File as FileIcon,
    FileAudio,
    FileImage,
    FileText,
    FileVideo,
    Folder,
    FolderPlus,
    LayoutGrid,
    List,
    Search,
    SlidersHorizontal,
    ArrowDownUp,
    Tags,
    Trash2,
    TriangleAlert,
    Upload,
    X,
} from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowReactive,
    shallowRef,
    watch,
} from 'vue';
import ConnectionVault from '@/components/ConnectionVault.vue';
import BrowserDownloads from '@/components/BrowserDownloads.vue';
import LanguageSelector from '@/components/LanguageSelector.vue';
import {
    availableBrowserCopyIds,
    browserAccount,
    browserLogoutEpoch,
    onBrowserCopiesChanged,
    readBrowserCopy,
} from '@/lib/browserDownloads';
import MediaViewer from '@/components/MediaViewer.vue';
import { downloadToBrowser } from '@/lib/browserDownloadTransfer';
import {
    decryptFile,
    decryptFileStream,
    encryptFile,
    decryptMetadata,
    encryptMetadata,
    encryptedSize,
    stageDownload,
} from '@/lib/contentsEncryption';
import type {
    MetadataEnvelope,
    ProtectedMetadata,
    StagedFile,
    VaultEnvelope,
} from '@/lib/contentsEncryption';
import {
    filterProtectedFiles,
    unlockFileMetadata,
} from '@/lib/contentsEncryption';
import type { ProtectedFileEntry } from '@/lib/contentsEncryption';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { createUploadTracker, uploadRetryMode } from '@/lib/uploadTracker';
import { useTelegramEvents } from '@/composables/useTelegramEvents';
import * as directoriesRoutes from '@/routes/directories';
import * as filesRoutes from '@/routes/files';
import * as operationRoutes from '@/routes/file-operations';
import * as filesTags from '@/routes/files/tags';
import { index as tagsIndexRoute } from '@/routes/tags';
import { connections as telegramConnections } from '@/routes/telegram';
import EntryMenu from './EntryMenu.vue';

type Tag = { id: number; name: string };

type FileEntry = ProtectedFileEntry & {
    encryption_header: string | null;
    connection_id: number | null;
    id: number;
    uuid: string;
    name: string;
    mime_type: string;
    file_type: string;
    size: number;
    status: string;
    description: string | null;
    flagged: boolean;
    created_at: string | null;
    updated_at: string | null;
    deleted_at: string | null;
    directory_id: number | null;
    tags: Tag[];
    telegram: {
        account: string | null;
        connection: string | null;
        method: string | null;
        chat_id: number | null;
        message_id: number | null;
    };
};

type DirectoryEntry = {
    id: number;
    parent_id: number | null;
    name: string;
    path: string;
    updated_at: string | null;
    purging: boolean;
};

type Breadcrumb = { id: number | null; name: string; path: string };

type Connection = {
    id: number;
    name: string;
    method: string;
    encryption_mode: string;
    vault_envelope: VaultEnvelope | null;
};

type Props = {
    max_upload_bytes: number;
    directory: { id: number; name: string; path: string } | null;
    breadcrumbs: Breadcrumb[];
    directories: DirectoryEntry[];
    connections: Connection[];
    vault_connections: Connection[];
    filter_connections: {
        id: number;
        name: string;
        chat_title: string | null;
        avatar_url: string | null;
    }[];
    operations: {
        operation_id: number;
        file_id: number | null;
        file_name: string;
        operation: string;
        status: string;
        progress: number;
        can_retry_operation: boolean;
        error: string | null;
        attempts: number;
        cancel_requested: boolean;
        created_at: string | null;
        completed_at: string | null;
    }[];
    selected_file?: FileEntry | null;
    files: {
        data: FileEntry[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        q: string | null;
        file_type: string | null;
        tag_ids: number[];
        sort: string;
        direction: string;
        trash: boolean;
        mime_type: string | null;
        telegram_connection_id: number | null;
        created_after: string | null;
        created_before: string | null;
        scope: 'folder' | 'all';
    };
    workerStatus: {
        running: boolean;
        roxi_connected: boolean;
        flagged_files?: number;
    } | null;
};

const props = defineProps<Props>();
const browserSaves = new Map<number, AbortController>();

// ── Listing navigation & filters ────────────────────────────────────

const search = ref(props.filters.q ?? '');
const mimeFilter = ref(props.filters.mime_type ?? '');
const connectionFilter = ref(
    props.filters.telegram_connection_id
        ? String(props.filters.telegram_connection_id)
        : '',
);
const createdAfter = ref(props.filters.created_after ?? '');
const createdBefore = ref(props.filters.created_before ?? '');
const searchEverywhere = ref(props.filters.scope === 'all');
const filtersOpen = ref(false);
const draftChatFilter = ref('');
const draftTypeFilter = ref('all');
const draftTagFilter = ref('all');
const draftMimeFilter = ref('');
const draftCreatedAfter = ref('');
const draftCreatedBefore = ref('');
const draftSearchEverywhere = ref(false);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

// ── Filters (state carried in URL query params, spec §19) ──────────

const tagOptions = ref<{ id: number; name: string }[]>([]);
const tagFilter = ref<string>(
    props.filters.tag_ids.length > 0 ? String(props.filters.tag_ids[0]) : 'all',
);
const typeFilter = ref<string>(props.filters.file_type ?? 'all');
const gridSortColumns = [
    { value: 'name', label: 'Name' },
    { value: 'file_type', label: 'Type' },
    { value: 'size', label: 'Size' },
    { value: 'created_at', label: 'Created' },
    { value: 'updated_at', label: 'Updated' },
] as const;
type SortColumn = (typeof gridSortColumns)[number]['value'];
const activeFilterCount = computed(() => [
    connectionFilter.value !== '',
    typeFilter.value !== 'all',
    tagFilter.value !== 'all',
    mimeFilter.value !== '',
    createdAfter.value !== '',
    createdBefore.value !== '',
    searchEverywhere.value,
].filter(Boolean).length);

function syncDraftFilters(): void {
    draftChatFilter.value = connectionFilter.value;
    draftTypeFilter.value = typeFilter.value;
    draftTagFilter.value = tagFilter.value;
    draftMimeFilter.value = mimeFilter.value;
    draftCreatedAfter.value = createdAfter.value;
    draftCreatedBefore.value = createdBefore.value;
    draftSearchEverywhere.value = searchEverywhere.value;
}
watch(filtersOpen, (open) => {
    if (open) syncDraftFilters();
});
function updateDraftChat(value: AcceptableValue): void {
    draftChatFilter.value = value === 'all' ? '' : String(value ?? '');
    if (draftChatFilter.value !== connectionFilter.value) {
        draftTagFilter.value = 'all';
        draftTypeFilter.value = 'all';
        draftMimeFilter.value = '';
    }
}
function applyFilters(): void {
    if (draftChatFilter.value !== connectionFilter.value) {
        resetFileSelectionForChatChange();
        connectionFilter.value = draftChatFilter.value;
        const connection = availableConnections.value.find(
            (item) => item.id === Number(connectionFilter.value),
        );
        if (connection?.encryption_mode === 'contents_and_metadata') {
            connectionId.value = connection.id;
        }
    }
    typeFilter.value = draftTypeFilter.value;
    tagFilter.value = draftTagFilter.value;
    mimeFilter.value = draftMimeFilter.value;
    createdAfter.value = draftCreatedAfter.value;
    createdBefore.value = draftCreatedBefore.value;
    searchEverywhere.value = draftSearchEverywhere.value;
    filtersOpen.value = false;
    visit({ page: 1 });
}
function clearDraftFilters(): void {
    draftChatFilter.value = '';
    draftTypeFilter.value = 'all';
    draftTagFilter.value = 'all';
    draftMimeFilter.value = '';
    draftCreatedAfter.value = '';
    draftCreatedBefore.value = '';
    draftSearchEverywhere.value = false;
    applyFilters();
}
const view = ref<'list' | 'grid'>(
    (localStorage.getItem('roxifile.files.view') as 'list' | 'grid' | null) ??
        'grid',
);

function setView(mode: 'list' | 'grid'): void {
    view.value = mode;
    localStorage.setItem('roxifile.files.view', mode);
}

function visit(overrides: Record<string, unknown> = {}): void {
    router.get(
        filesRoutes.index.url(),
        {
            directory_id: props.directory?.id,
            q: privateConnection.value ? undefined : search.value || undefined,
            mime_type: privateConnection.value
                ? undefined
                : mimeFilter.value || undefined,
            telegram_connection_id: connectionFilter.value || undefined,
            created_after: createdAfter.value || undefined,
            created_before: createdBefore.value || undefined,
            scope: searchEverywhere.value ? 'all' : 'folder',
            sort: props.filters.sort,
            direction: props.filters.direction,
            trash: props.filters.trash || undefined,
            tag_ids:
                !privateConnection.value && tagFilter.value !== 'all'
                    ? [Number(tagFilter.value)]
                    : undefined,
            file_type:
                !privateConnection.value && typeFilter.value !== 'all'
                    ? typeFilter.value
                    : undefined,
            ...overrides,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function resetFileSelectionForChatChange(): void {
    clearTimeout(searchTimer);
    search.value = '';
    previewOpen.value = false;
    detailsFile.value = null;
    descriptionDraft.value = '';
    tagDraft.value = '';
    bulkTagDraft.value = '';
    selectedIds.value = [];
    renameOpen.value = false;
    renameTarget.value = null;
    renameForm.name = '';
}

function setChatFilter(value: AcceptableValue): void {
    resetFileSelectionForChatChange();
    tagFilter.value = 'all';
    typeFilter.value = 'all';
    mimeFilter.value = '';
    connectionFilter.value = value === 'all' ? '' : String(value ?? '');
    const connection = availableConnections.value.find(
        (item) => item.id === Number(connectionFilter.value),
    );
    if (connection?.encryption_mode === 'contents_and_metadata')
        connectionId.value = connection.id;
    visit({ page: 1 });
}

async function refreshTagOptions(): Promise<void> {
    try {
        const response = await fetch(tagsIndexRoute.url(), {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            const body = (await response.json()) as {
                tags: { id: number; name: string }[];
            };
            tagOptions.value = body.tags;
        }
    } catch {
        tagOptions.value = [];
    }
}
onMounted(refreshTagOptions);

function onSearchInput(): void {
    clearTimeout(searchTimer);
    if (!privateConnection.value) searchTimer = setTimeout(() => visit(), 350);
}

function openDirectory(id: number | null): void {
    searchEverywhere.value = false;
    visit({ directory_id: id ?? undefined, page: 1 });
}

function sortByColumn(column: SortColumn): void {
    const direction = props.filters.sort === column
        ? (props.filters.direction === 'asc' ? 'desc' : 'asc')
        : (column === 'name' || column === 'file_type' ? 'asc' : 'desc');
    visit({ sort: column, direction, page: 1 });
}

function columnSortDirection(column: SortColumn): 'asc' | 'desc' | null {
    return props.filters.sort === column
        ? (props.filters.direction === 'asc' ? 'asc' : 'desc')
        : null;
}

function toggleTrash(): void {
    visit({
        trash: props.filters.trash ? undefined : 1,
        directory_id: undefined,
        page: 1,
    });
}

function changePage(page: number): void {
    visit({ page });
}

usePoll(30_000, {
    only: ['files', 'directories', 'operations', 'workerStatus'],
});

// ── Uploads (spec §20-23, §35) ──────────────────────────────────────

type UploadCard = {
    connectionId: number;
    directoryId: number | null;
    abort: AbortController;
    operationId?: number;
    canRetryOperation?: boolean;
    needsReview?: boolean;
    key: number;
    name: string;
    progress: number;
    status: 'uploading' | 'processing' | 'done' | 'failed';
    error?: string;
    file: globalThis.File;
};

const uploadCards = ref<UploadCard[]>([]);
const uploadTracker = createUploadTracker();
let uploadKey = 0;
let uploading = false;

const availableConnections = computed(() => [
    ...props.connections,
    ...props.vault_connections.filter(
        (vault) =>
            !props.connections.some((connection) => connection.id === vault.id),
    ),
]);
const connectionId = ref<number>(
    props.filters.telegram_connection_id ?? (props.connections.length === 1 ? props.connections[0].id : 0),
);
watch(
    () => props.filters.telegram_connection_id,
    (chatId) => {
        connectionId.value = chatId ?? (props.connections.length === 1 ? props.connections[0].id : 0);
    },
);
const selectedConnection = computed(() =>
    availableConnections.value.find(
        (connection) => connection.id === connectionId.value,
    ),
);
const vaultUserId = Number(usePage().props.auth.user.id);
let logoutEpoch = browserLogoutEpoch(vaultUserId);
const sessionUserId = computed(() => Number(usePage().props.auth.user?.id));
const vaultKeys = shallowReactive(new Map<number, CryptoKey>());
const vaultGenerations = new Map<number, number>();
const downloadAborts = new Set<AbortController>();
const exportedDownloads = new Set<() => void>();
const privateConnection = computed(() =>
    availableConnections.value.find(
        (item) =>
            item.id === Number(connectionFilter.value) &&
            item.encryption_mode === 'contents_and_metadata',
    ),
);
const privateCatalog = ref<FileEntry[]>([]);
const metadataLoading = ref(false);
const metadataError = ref('');
let catalogGeneration = 0;
let catalogAbort: AbortController | undefined;
let catalogKey: CryptoKey | undefined;
let catalogConnectionId: number | undefined;
const displayedTagOptions = computed(() =>
    privateConnection.value
        ? [
              ...new Set(
                  privateCatalog.value.flatMap((file) =>
                      file.tags.map((tag) => tag.name),
                  ),
              ),
          ]
              .sort()
              .map((name, id) => ({ id, name }))
        : tagOptions.value,
);
const draftTagOptions = computed(() => {
    if (draftChatFilter.value === connectionFilter.value) {
        return displayedTagOptions.value;
    }
    const connection = availableConnections.value.find(
        (item) => item.id === Number(draftChatFilter.value),
    );
    return connection?.encryption_mode === 'contents_and_metadata'
        ? []
        : tagOptions.value;
});
const fileListing = computed(() => {
    if (!privateConnection.value || !vaultKeys.has(privateConnection.value.id))
        return props.files;
    const data = filterProtectedFiles(
        privateCatalog.value,
        search.value,
        tagFilter.value,
        typeFilter.value,
        mimeFilter.value,
    );
    const direction = props.filters.direction === 'asc' ? 1 : -1;
    const sort = props.filters.sort as keyof FileEntry;
    data.sort(
        (a, b) =>
            direction *
            (typeof a[sort] === 'number' && typeof b[sort] === 'number'
                ? Number(a[sort]) - Number(b[sort])
                : String(a[sort] ?? '').localeCompare(String(b[sort] ?? ''))),
    );
    return { data, total: data.length, current_page: 1, last_page: 1 };
});
async function loadPrivateCatalog(): Promise<void> {
    const generation = ++catalogGeneration;
    catalogAbort?.abort();
    metadataError.value = '';
    metadataLoading.value = false;
    const connection = privateConnection.value;
    const key = connection ? vaultKeys.get(connection.id) : undefined;
    if (key !== catalogKey || connection?.id !== catalogConnectionId)
        privateCatalog.value = [];
    catalogKey = key;
    catalogConnectionId = connection?.id;
    if (!connection || !key || sessionUserId.value !== vaultUserId) {
        privateCatalog.value = [];
        return;
    }
    const abort = new AbortController();
    catalogAbort = abort;
    metadataLoading.value = true;
    try {
        const files: FileEntry[] = [];
        let lastPage = 1;
        for (let page = 1; page <= lastPage; page++) {
            const response = await fetch(
                filesRoutes.index.url({
                    query: {
                        telegram_connection_id: connection.id,
                        directory_id: props.directory?.id,
                        scope: searchEverywhere.value ? 'all' : 'folder',
                        trash: props.filters.trash ? 1 : undefined,
                        created_after: createdAfter.value || undefined,
                        created_before: createdBefore.value || undefined,
                        per_page: 100,
                        page,
                    },
                }),
                {
                    headers: { Accept: 'application/json' },
                    signal: abort.signal,
                },
            );
            if (!response.ok)
                throw new Error(
                    'Could not load protected files. Retry or reload the page.',
                );
            const body = (await response.json()) as { files: Props['files'] };
            lastPage = body.files.last_page;
            for (const file of body.files.data) {
                abort.signal.throwIfAborted();
                files.push(
                    await unlockFileMetadata(file, key, {
                        userId: vaultUserId,
                        connectionId: connection.id,
                    }),
                );
            }
        }
        if (
            generation === catalogGeneration &&
            vaultKeys.get(connection.id) === key
        )
            privateCatalog.value = files;
    } catch {
        if (!abort.signal.aborted && generation === catalogGeneration) {
            privateCatalog.value = [];
            metadataError.value =
                'Protected metadata could not be authenticated or loaded. No readable fallback was used. Retry after unlocking again.';
        }
    } finally {
        if (generation === catalogGeneration) metadataLoading.value = false;
    }
}
watch(
    [
        () => props.files.data,
        () => privateConnection.value?.id,
        () =>
            privateConnection.value
                ? vaultKeys.get(privateConnection.value.id)
                : undefined,
        searchEverywhere,
    ],
    () => {
        void loadPrivateCatalog();
    },
    { immediate: true },
);
function unlockConnection(key: CryptoKey): void {
    const connection = selectedConnection.value;
    if (!connection || sessionUserId.value !== vaultUserId) {
        toast.error(
            'The account changed. Reload before unlocking this connection.',
        );
        return;
    }
    vaultKeys.set(connection.id, key);
    if (
        connection.encryption_mode === 'contents_and_metadata' &&
        connectionFilter.value !== String(connection.id)
    ) {
        setChatFilter(String(connection.id));
    }
}
async function saveProtectedMetadata(
    file: FileEntry,
    changes: Partial<ProtectedMetadata>,
): Promise<boolean> {
    const key =
        file.connection_id === null
            ? undefined
            : vaultKeys.get(file.connection_id);
    if (
        sessionUserId.value !== vaultUserId ||
        !file.metadata_unlocked ||
        !key ||
        file.connection_id === null ||
        !file.protected_metadata
    ) {
        toast.error(
            'Unlock this connection and authenticate its metadata before editing.',
        );
        return false;
    }
    const id = file.connection_id;
    const generation = vaultGenerations.get(id) ?? 0;
    const abort = new AbortController();
    downloadAborts.add(abort);
    try {
        const previousMetadata = file.protected_metadata;
        const original = await decryptMetadata(
            previousMetadata,
            key,
            { userId: vaultUserId, connectionId: id },
            file.storage_name,
        );
        const value: ProtectedMetadata = {
            ...original,
            name: file.name,
            mime: file.mime_type,
            description: file.description ?? '',
            tags: file.tags.map((tag) => tag.name),
            ...changes,
        };
        const envelope = await encryptMetadata(
            value,
            key,
            { userId: vaultUserId, connectionId: id },
            file.storage_name,
        );
        if (
            (vaultGenerations.get(id) ?? 0) !== generation ||
            sessionUserId.value !== vaultUserId
        )
            return false;
        const response = await fetch(
            filesRoutes.protectedMetadata({ file: file.id }).url,
            {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    protected_metadata: envelope,
                    previous_metadata: previousMetadata,
                }),
                signal: abort.signal,
            },
        );
        if (!response.ok)
            throw new Error(
                response.status === 409
                    ? 'Metadata changed elsewhere. Reload and unlock before editing again.'
                    : 'Could not save encrypted metadata. Try again.',
            );
        if (
            (vaultGenerations.get(id) ?? 0) !== generation ||
            sessionUserId.value !== vaultUserId
        )
            return false;
        if (privateConnection.value?.id === id) {
            catalogGeneration++;
            catalogAbort?.abort();
            metadataLoading.value = false;
        }
        file.protected_metadata = envelope;
        file.name = value.name;
        file.description = value.description;
        file.tags = value.tags.map((name, index) => ({ id: index, name }));
        privateCatalog.value = privateCatalog.value.map((item) =>
            item.id === file.id ? file : item,
        );
        toast.success('Encrypted metadata saved');
        return true;
    } catch (error) {
        if (!abort.signal.aborted)
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'Encrypted metadata could not be saved.',
            );
        return false;
    } finally {
        downloadAborts.delete(abort);
    }
}

function lockVault(id: number): void {
    vaultKeys.delete(id);
    if (previewFile.value?.connection_id === id) previewOpen.value = false;
    if (
        availableConnections.value.find((item) => item.id === id)
            ?.encryption_mode === 'contents_and_metadata'
    ) {
        catalogGeneration++;
        catalogKey = undefined;
        catalogConnectionId = undefined;
        catalogAbort?.abort();
        privateCatalog.value = [];
        detailsFile.value = null;
        descriptionDraft.value = '';
        tagDraft.value = '';
        renameOpen.value = false;
        renameTarget.value = null;
        renameForm.name = '';
        previewOpen.value = false;
        previewFile.value = null;
        moveOpen.value = false;
        moveTarget.value = null;
        copyForm.name = '';
        bulkTagDraft.value = '';
        search.value = '';
        tagFilter.value = 'all';
        selectedIds.value = [];
        for (const card of uploadCards.value.filter(
            (item) => item.connectionId === id,
        )) {
            card.abort.abort();
            if (activeUpload === card.key) uploadForm.cancel();
        }
        uploadCards.value = uploadCards.value.filter(
            (card) => card.connectionId !== id,
        );
    }
    vaultGenerations.set(id, (vaultGenerations.get(id) ?? 0) + 1);
    exportDestinations.clear();
    for (const [operationId, pending] of pendingDownloads.value) {
        if (pending.file.connection_id === id)
            pendingDownloads.value.delete(operationId);
    }
    for (const card of uploadCards.value) {
        if (card.connectionId === id && card.status === 'uploading') {
            card.abort.abort();
            if (activeUpload === card.key) uploadForm.cancel();
            card.status = 'failed';
            card.error = 'Vault locked. Unlock and retry.';
        }
    }
    for (const abort of downloadAborts) abort.abort();
    for (const release of exportedDownloads) release();
}

onBeforeUnmount(() => {
    for (const id of vaultKeys.keys()) lockVault(id);
    for (const card of uploadCards.value) card.abort.abort();
    uploadForm.cancel();
    catalogAbort?.abort();
    catalogGeneration++;
});
watch(
    () => usePage().props.auth.user?.id,
    (id) => {
        if (Number(id) !== vaultUserId) {
            previewOpen.value = false;
            for (const key of vaultKeys.keys()) lockVault(key);
        }
    },
);

const canUpload = computed(
    () =>
        sessionUserId.value === vaultUserId &&
        props.connections.some(
            (connection) => connection.id === connectionId.value,
        ),
);
const maxUploadBytes = computed(() =>
    Math.min(
        props.max_upload_bytes,
        props.connections.find(
            (connection) => connection.id === connectionId.value,
        )?.method === 'bot'
            ? 20 * 1024 * 1024
            : props.max_upload_bytes,
    ),
);

const fileInput = ref<HTMLInputElement | null>(null);
const uploadDestinationOpen = ref(false);
const uploadDestinationId = ref<number | null>(null);
const pendingUploadFiles = ref<globalThis.File[]>([]);
const isCombinedView = computed(() => !props.filters.telegram_connection_id);
const selectedChatName = computed(() => {
    const chat = props.filter_connections.find(
        (item) => item.id === props.filters.telegram_connection_id,
    );
    return chat?.chat_title || chat?.name || '';
});
function fileChatName(file: FileEntry): string {
    const chat = props.filter_connections.find(
        (item) => item.id === file.connection_id,
    );
    return chat?.chat_title || chat?.name || file.telegram.connection || t('Unknown chat');
}
function openUploadPicker(): void {
    if (isCombinedView.value && props.connections.length > 1) {
        pendingUploadFiles.value = [];
        uploadDestinationId.value = null;
        uploadDestinationOpen.value = true;
        return;
    }
    fileInput.value?.click();
}
function confirmUploadDestination(): void {
    if (!uploadDestinationId.value) return;
    connectionId.value = uploadDestinationId.value;
    uploadDestinationOpen.value = false;
    const files = pendingUploadFiles.value;
    pendingUploadFiles.value = [];
    if (files.length) {
        enqueueUploads(files, true);
    } else {
        fileInput.value?.click();
    }
}
const dragging = ref(false);

function enqueueUploads(list: FileList | globalThis.File[] | null, destinationChosen = false): void {
    if (list && list.length && isCombinedView.value && props.connections.length > 1 && !destinationChosen) {
        pendingUploadFiles.value = Array.from(list);
        uploadDestinationId.value = null;
        uploadDestinationOpen.value = true;
        return;
    }
    if (!list || !canUpload.value) {
        if (!canUpload.value) {
            toast.error(
                'Open Storage Chats and connect a Telegram group or channel before uploading.',
            );
        }

        return;
    }

    for (const file of Array.from(list)) {
        if (
            (selectedConnection.value?.encryption_mode !== 'none'
                ? encryptedSize(file.size)
                : file.size) > maxUploadBytes.value
        ) {
            toast.error(
                `${file.name}: maximum upload size is ${formatSize(maxUploadBytes.value)}.`,
            );
            continue;
        }
        uploadCards.value.push({
            connectionId: connectionId.value,
            directoryId: props.directory?.id ?? null,
            abort: new AbortController(),
            key: ++uploadKey,
            name: file.name,
            progress: 0,
            status: 'uploading',
            file,
        });
    }

    processUploadQueue();
}

// JSON endpoint — standalone HTTP, not an Inertia visit.
const uploadForm = useHttp({
    file: null as globalThis.File | null,
    connection_id: 0,
    directory_id: null as number | null,
    encrypted: false,
    plaintext_mime: null as string | null,
    protected_metadata: null as MetadataEnvelope | null,
});

let activeUpload: number | null = null;
async function processUploadQueue(): Promise<void> {
    if (sessionUserId.value !== vaultUserId) return;
    const next = uploadCards.value.find((c) => c.status === 'uploading');

    if (!next || uploading) {
        return;
    }

    uploading = true;
    activeUpload = next.key;
    let staged: StagedFile | undefined;
    const connection = props.connections.find(
        (item) => item.id === next.connectionId,
    );
    const encrypted = connection?.encryption_mode !== 'none';
    const protectedMetadata =
        connection?.encryption_mode === 'contents_and_metadata';
    const storageName = protectedMetadata
        ? crypto.randomUUID()
        : next.file.name;
    let metadata: MetadataEnvelope | null = null;
    try {
        if (!connection)
            throw new Error('The selected connection is no longer available.');
        if (encrypted) {
            const key = vaultKeys.get(next.connectionId);
            if (!key)
                throw new Error(
                    'Unlock the selected connection before uploading.',
                );
            if (protectedMetadata)
                metadata = await encryptMetadata(
                    {
                        name: next.file.name,
                        mime: next.file.type || 'application/octet-stream',
                        tags: [],
                        description: '',
                    },
                    key,
                    { userId: vaultUserId, connectionId: next.connectionId },
                    storageName,
                );
            staged = await encryptFile(
                next.file,
                key,
                { userId: vaultUserId, connectionId: next.connectionId },
                next.abort.signal,
            );
        }
        next.abort.signal.throwIfAborted();
    } catch (error) {
        await staged?.dispose();
        next.status = 'failed';
        next.error =
            error instanceof Error ? error.message : 'Local encryption failed.';
        uploading = false;
        activeUpload = null;
        void processUploadQueue();
        return;
    }
    uploadForm.file = next.file;
    if (staged)
        uploadForm.file = new globalThis.File([staged.file], storageName, {
            type: 'application/octet-stream',
        });
    uploadForm.encrypted = encrypted;
    uploadForm.protected_metadata = metadata;
    uploadForm.plaintext_mime =
        encrypted && !protectedMetadata
            ? next.file.type || 'application/octet-stream'
            : null;
    uploadForm.connection_id = next.connectionId;
    uploadForm.directory_id = next.directoryId;

    uploadForm.post(filesRoutes.upload.url(), {
        onSuccess: (response) => {
            const result = response as { operation_id: number; status: string };
            uploadTracker.attach(next, result.operation_id);
            uploadTracker.record(
                {
                    ...result,
                    progress: result.status === 'completed' ? 100 : 0,
                },
                uploadCards.value,
            );
            refetch();
        },
        onError: (errors) => {
            next.status = 'failed';
            next.error =
                errors.file ?? errors.connection_id ?? 'Upload failed.';
        },
        onProgress: (progress) => {
            if (progress?.percentage !== undefined) {
                next.progress = Math.round(progress.percentage);
            }
        },
        onFinish: () => {
            void staged?.dispose();
            uploadForm.file = null;
            uploadForm.protected_metadata = null;
            activeUpload = null;
            uploading = false;
            processUploadQueue();
        },
    });
}

function retryUpload(card: UploadCard): void {
    const retryMode = uploadRetryMode(card);
    if (retryMode === 'blocked') return;
    if (retryMode === 'operation' && card.operationId !== undefined) {
        controlOperation(card.operationId, 'retry');
        return;
    }

    uploadAgainFromBrowser(card);
}

function uploadAgainFromBrowser(card: UploadCard): void {
    card.operationId = undefined;
    card.canRetryOperation = undefined;
    card.needsReview = false;
    card.status = 'uploading';
    card.abort = new AbortController();
    card.progress = 0;
    card.error = undefined;
    processUploadQueue();
}

function dismissUpload(card: UploadCard): void {
    card.abort.abort();
    if (activeUpload === card.key) uploadForm.cancel();
    if (card.operationId !== undefined && card.status === 'processing')
        controlOperation(card.operationId, 'cancel');
    uploadCards.value = uploadCards.value.filter((c) => c.key !== card.key);
}

// ── Realtime updates (spec §34-35) ──────────────────────────────────

function refetch(): void {
    router.reload({
        only: ['files', 'directories', 'operations'],
        onSuccess: () => {
            void refreshTagOptions();
        },
    });
}

useTelegramEvents({
    onUploadProgress: (event) => uploadTracker.record(event, uploadCards.value),
    onUploadCompleted: (event) => {
        uploadTracker.record(event, uploadCards.value);
        refetch();
    },
    onUploadFailed: (event) => {
        uploadTracker.record(event, uploadCards.value);
        refetch();
    },
    onDeleteCompleted: () => refetch(),
});

// ── New folder / rename / move ──────────────────────────────────────

const newFolderOpen = ref(false);
const newFolderForm = useHttp({ name: '', parent_id: null as number | null });

function openNewFolder(): void {
    newFolderForm.reset();
    newFolderForm.parent_id = props.directory?.id ?? null;
    newFolderOpen.value = true;
}

function createFolder(): void {
    newFolderForm.post(directoriesRoutes.store.url(), {
        onSuccess: () => {
            newFolderOpen.value = false;
            toast.success('Folder created');
            refetch();
        },
    });
}

type EntryKind = 'file' | 'directory';

const renameOpen = ref(false);
const renameTarget = ref<{
    id: number;
    name: string;
    metadata_protected?: boolean;
} | null>(null);
const renameKind = ref<EntryKind>('file');
const renameForm = useHttp({ name: '' });

function openRename(
    entry: { id: number; name: string },
    kind: EntryKind,
): void {
    renameTarget.value = entry;
    renameKind.value = kind;
    renameForm.name = entry.name;
    renameOpen.value = true;
}

async function submitRename(): Promise<void> {
    const target = renameTarget.value;

    if (!target) {
        return;
    }

    const protectedFile =
        renameKind.value === 'file'
            ? privateCatalog.value.find(
                  (file) => file.id === target.id && file.metadata_protected,
              )
            : undefined;
    if (target.metadata_protected && !protectedFile) {
        toast.error('Unlock and authenticate this file before renaming it.');
        return;
    }
    if (protectedFile) {
        if (
            await saveProtectedMetadata(protectedFile, {
                name: renameForm.name,
            })
        )
            renameOpen.value = false;
        return;
    }
    const url =
        renameKind.value === 'file'
            ? filesRoutes.rename({ file: target.id }).url
            : directoriesRoutes.rename({ directory: target.id }).url;

    renameForm.patch(url, {
        onSuccess: () => {
            renameOpen.value = false;
            refetch();
        },
    });
}

const moveOpen = ref(false);
const copyMode = ref(false);
const copyForm = useHttp({
    directory_id: null as number | null,
    name: '',
    request_id: '',
});
const moveTarget = ref<{ id: number; name: string } | null>(null);
const moveKind = ref<EntryKind>('file');
const moveDirectoryId = ref<number | null>(null);
const moveForm = useHttp({
    directory_id: null as number | null,
    parent_id: null as number | null,
});
const allDirectories = ref<{ id: number; name: string; path: string }[]>([]);

async function openMove(
    entry: { id: number; name: string },
    kind: EntryKind,
    copying = false,
): Promise<void> {
    const file =
        kind === 'file'
            ? fileListing.value.data.find((item) => item.id === entry.id)
            : undefined;
    if (copying && (file?.encryption_header || file?.metadata_protected)) {
        toast.error(
            'Encrypted copies are not supported. Download and encrypt a new file on your device.',
        );
        return;
    }
    copyMode.value = copying;
    if (copying) {
        copyForm.name = entry.name;
        copyForm.request_id = crypto.randomUUID();
    }
    moveTarget.value = entry;
    moveKind.value = kind;
    moveDirectoryId.value = props.directory?.id ?? null;

    try {
        const response = await fetch(directoriesRoutes.index.url(), {
            headers: { Accept: 'application/json' },
        });

        if (response.ok) {
            const body = (await response.json()) as {
                directories: { id: number; name: string; path: string }[];
            };
            allDirectories.value = body.directories;
        }
    } catch {
        allDirectories.value = [];
    }

    moveOpen.value = true;
}

function submitMove(): void {
    const target = moveTarget.value;

    if (!target) {
        return;
    }

    if (copyMode.value) {
        copyForm.directory_id = moveDirectoryId.value;
        copyForm.post(filesRoutes.copy({ file: target.id }).url, {
            onSuccess: () => {
                moveOpen.value = false;
                toast.info('Copy queued');
                refetch();
            },
            onHttpException: (response) => {
                toast.error(
                    (response.data as { message?: string })?.message ??
                        'Copy could not be started.',
                );
                return false;
            },
        });
        return;
    }
    moveForm.directory_id = moveDirectoryId.value;
    moveForm.parent_id = moveDirectoryId.value;

    const url =
        moveKind.value === 'file'
            ? filesRoutes.move({ file: target.id }).url
            : directoriesRoutes.move({ directory: target.id }).url;

    moveForm.patch(url, {
        onSuccess: () => {
            moveOpen.value = false;
            toast.success('Moved');
            refetch();
        },
    });
}

const selectedIds = ref<number[]>([]);
const selectablePageIds = computed(() => fileListing.value.data.map((file) => file.id));
const allPageFilesSelected = computed(
    () =>
        selectablePageIds.value.length > 0 &&
        selectablePageIds.value.every((id) => selectedIds.value.includes(id)),
);
const somePageFilesSelected = computed(() =>
    selectablePageIds.value.some((id) => selectedIds.value.includes(id)),
);

function togglePageSelection(checked: boolean): void {
    const pageIds = new Set(selectablePageIds.value);
    selectedIds.value = checked
        ? [...new Set([...selectedIds.value, ...pageIds])]
        : selectedIds.value.filter((id) => !pageIds.has(id));
}

const bulkTagOpen = ref(false);
const bulkTagDraft = ref('');
const privateBulkTagProcessing = ref(false);
const bulkTagForm = useHttp({ file_ids: [] as number[], tags: [] as string[] });

function openBulkTagDialog(): void {
    bulkTagDraft.value = '';
    bulkTagOpen.value = true;
}
const bulkDeleteForm = useHttp({ file_ids: [] as number[] });

async function tagSelectedFiles(): Promise<void> {
    const tags = [...new Set(
        bulkTagDraft.value
            .split(',')
            .map((tag) => tag.trim())
            .filter(Boolean),
    )];
    if (!selectedIds.value.length || !tags.length || bulkTagForm.processing || privateBulkTagProcessing.value) {
        return;
    }

    if (privateConnection.value) {
        privateBulkTagProcessing.value = true;
        try {
            for (const file of fileListing.value.data.filter((item) =>
                selectedIds.value.includes(item.id),
            )) {
                if (
                    !(await saveProtectedMetadata(file, {
                        tags: [
                            ...new Set([
                                ...file.tags.map((tag) => tag.name),
                                ...tags,
                            ]),
                        ],
                    }))
                ) {
                    return;
                }
            }
            bulkTagOpen.value = false;
            bulkTagDraft.value = '';
            selectedIds.value = [];
            toast.success('Tags added');
        } finally {
            privateBulkTagProcessing.value = false;
        }
        return;
    }

    bulkTagForm.file_ids = [...selectedIds.value];
    bulkTagForm.tags = tags;
    bulkTagForm.post(filesRoutes.bulkTags.url(), {
        onSuccess: () => {
            selectedIds.value = [];
            bulkTagDraft.value = '';
            bulkTagOpen.value = false;
            toast.success('Tags added');
            refetch();
        },
    });
}

function deleteSelectedFiles(): void {
    bulkDeleteForm.file_ids = [...selectedIds.value];
    bulkDeleteForm.post(filesRoutes.bulkDelete.url(), {
        onSuccess: () => {
            selectedIds.value = [];
            refetch();
        },
    });
}

// ── Details sheet ───────────────────────────────────────────────────

const detailsFile = ref<FileEntry | null>(null);
watch(
    () => fileListing.value.data,
    (files) => {
        if (detailsFile.value)
            detailsFile.value =
                files.find((file) => file.id === detailsFile.value?.id) ??
                (privateConnection.value ? null : detailsFile.value);
    },
);
const descriptionDraft = ref('');
const descriptionForm = useHttp({ description: '' });
const tagDraft = ref('');

function openDetails(file: FileEntry): void {
    if (file.metadata_protected && !file.metadata_unlocked) {
        toast.error('Unlock the connection before viewing protected metadata.');
        return;
    }
    void preview(file);
}

async function saveBrowserDownload(file: FileEntry): Promise<void> {
    if (browserSaves.has(file.id)) return;
    const request = new AbortController();
    browserSaves.set(file.id, request);
    const notification = toast.loading('Saving for offline use…');
    try {
        await downloadToBrowser(vaultUserId, file.id, request.signal, () => {});
        toast.success('Saved for offline use', { id: notification });
    } catch (cause) {
        if (request.signal.aborted) toast.dismiss(notification);
        else
            toast.error(
                cause instanceof Error
                    ? cause.message
                    : 'Could not save the file.',
                { id: notification },
            );
    } finally {
        browserSaves.delete(file.id);
    }
}

async function saveDescription(): Promise<void> {
    const file = detailsFile.value;

    if (!file) {
        return;
    }

    // Autosave on blur (spec §19): skip no-op saves.
    if (descriptionDraft.value === (file.description ?? '')) {
        return;
    }

    if (file.metadata_protected) {
        await saveProtectedMetadata(file, {
            description: descriptionDraft.value,
        });
        return;
    }
    descriptionForm.description = descriptionDraft.value;
    descriptionForm.patch(filesRoutes.description({ file: file.id }).url, {
        onSuccess: () => {
            toast.success('Description saved');
            refetch();
        },
    });
}

async function attachTag(): Promise<void> {
    const file = detailsFile.value;
    const name = tagDraft.value.trim();

    if (!file || !name) {
        return;
    }

    if (file.metadata_protected) {
        if (
            await saveProtectedMetadata(file, {
                tags: [...new Set([...file.tags.map((tag) => tag.name), name])],
            })
        )
            tagDraft.value = '';
        return;
    }
    await fetch(filesTags.attach({ file: file.id }).url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ tags: [name] }),
    });

    tagDraft.value = '';
    refetch();
}

async function detachTag(tag: Tag): Promise<void> {
    const file = detailsFile.value;

    if (!file) {
        return;
    }

    if (file.metadata_protected) {
        await saveProtectedMetadata(file, {
            tags: file.tags
                .filter((item) => item.name !== tag.name)
                .map((item) => item.name),
        });
        return;
    }
    await fetch(filesTags.detach({ file: file.id, tag: tag.id }).url, {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });

    file.tags = file.tags.filter((t) => t.id !== tag.id);
    refetch();
}

function csrfToken(): string {
    return decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );
}

// ── Destructive actions ─────────────────────────────────────────────

// JSON endpoints — standalone HTTP, not Inertia visits.
const http = useHttp();

function deleteEntry(entry: { id: number }, kind: EntryKind): void {
    const url =
        kind === 'file'
            ? filesRoutes.destroy({ file: entry.id }).url
            : directoriesRoutes.destroy({ directory: entry.id }).url;

    http.delete(url, {
        onSuccess: () => refetch(),
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ??
                    'The request could not be completed.',
            ),
        onHttpException: (response) => {
            toast.error(
                (response.data as { message?: string })?.message ??
                    'The request could not be completed.',
            );
            return false;
        },
    });
}

function restoreEntry(entry: { id: number }, kind: EntryKind): void {
    const url =
        kind === 'file'
            ? filesRoutes.restore({ file: entry.id }).url
            : directoriesRoutes.restore({ directory: entry.id }).url;

    http.post(url, {
        onSuccess: () => refetch(),
        onError: (errors) =>
            toast.error(Object.values(errors)[0] ?? 'Restore failed.'),
        onHttpException: (response) => {
            toast.error(
                (response.data as { message?: string })?.message ??
                    'Restore failed.',
            );
            return false;
        },
    });
}

function purgeEntry(entry: { id: number }, kind: EntryKind): void {
    const url =
        kind === 'file'
            ? filesRoutes.purge({ file: entry.id }).url
            : directoriesRoutes.purge({ directory: entry.id }).url;

    http.delete(url, {
        onSuccess: (response) => {
            toast.info(
                (response as { purged: boolean }).purged
                    ? 'Permanently deleted.'
                    : 'Deletion queued. The file will be removed when processing completes.',
            );
            refetch();
        },
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? 'Permanent deletion failed.',
            ),
        onHttpException: (response) => {
            toast.error(
                (response.data as { message?: string })?.message ??
                    'Permanent deletion failed.',
            );
            return false;
        },
    });
}

const pendingDownloads = ref(
    new Map<number, { file: FileEntry; preview: boolean }>(),
);
const previewOpen = ref(false);
const previewFile = ref<FileEntry | null>(null);
const previewUrl = ref('');
const previewStream = shallowRef<ReadableStream<Uint8Array> | null>(null);
const previewError = ref('');
let previewGeneration = 0;
let previewAbort: AbortController | undefined;
let previewCiphertext: StagedFile | undefined;
let previewPlaintext: StagedFile | undefined;
let previewObjectUrl = '';
function clearPreviewResources(): void {
    previewAbort?.abort();
    previewAbort = undefined;
    previewStream.value = null;
    void previewCiphertext?.dispose();
    previewCiphertext = undefined;
    void previewPlaintext?.dispose();
    previewPlaintext = undefined;
    if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
    previewObjectUrl = '';
}
let unsubscribeBrowserAccount: (() => void) | undefined;
const browserCopyIds = ref<ReadonlySet<number>>(new Set());
async function refreshBrowserCopyIds(): Promise<void> {
    browserCopyIds.value = await availableBrowserCopyIds(vaultUserId);
}
onMounted(() => {
    unsubscribeBrowserAccount = onBrowserCopiesChanged(() => {
        const currentLogoutEpoch = browserLogoutEpoch(vaultUserId);
        if (
            browserAccount() !== vaultUserId ||
            currentLogoutEpoch !== logoutEpoch
        ) {
            logoutEpoch = currentLogoutEpoch;
            previewOpen.value = false;
            for (const request of browserSaves.values()) request.abort();
            for (const id of vaultKeys.keys()) lockVault(id);
        }
        void refreshBrowserCopyIds();
    });
    void refreshBrowserCopyIds();
});
onBeforeUnmount(() => {
    unsubscribeBrowserAccount?.();
    for (const request of browserSaves.values()) request.abort();
});
watch(
    previewOpen,
    (open) => {
        if (!open) {
            detailsFile.value = null;
            previewGeneration++;
            clearPreviewResources();
            previewUrl.value = '';
            previewFile.value = null;
            for (const [id, pending] of pendingDownloads.value) {
                if (pending.preview) pendingDownloads.value.delete(id);
            }
        }
    },
    { flush: 'sync' },
);
async function preview(file: FileEntry): Promise<void> {
    if (detailsFile.value?.id !== file.id) {
        detailsFile.value =
            file.metadata_protected && !file.metadata_unlocked ? null : file;
        tagDraft.value = '';
        descriptionDraft.value = detailsFile.value?.description ?? '';
    }
    previewGeneration++;
    clearPreviewResources();
    for (const [id, pending] of pendingDownloads.value) {
        if (pending.preview) pendingDownloads.value.delete(id);
    }
    previewFile.value = file;
    previewUrl.value = '';
    previewError.value = '';
    previewOpen.value = true;
    const types = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'application/pdf',
        'text/plain',
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
        'audio/webm;codecs=opus',
        'video/mp4',
        'video/webm',
        'video/webm;codecs=vp8',
    ];
    const encryptedMediaTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'audio/mpeg',
        'audio/ogg',
        'audio/wav',
        'audio/webm',
        'audio/webm;codecs=opus',
        'video/mp4',
        'video/webm',
        'video/webm;codecs=vp8',
    ];
    if (file.encryption_header || file.metadata_protected) {
        if (
            !file.encryption_header ||
            file.connection_id === null ||
            !vaultKeys.has(file.connection_id) ||
            (file.metadata_protected && !file.metadata_unlocked)
        ) {
            previewError.value =
                'Unlock this file’s vault and authenticate its details before previewing.';
            return;
        }
        if (!encryptedMediaTypes.includes(file.mime_type)) {
            previewError.value =
                'Protected preview is unavailable for this format. Use Download.';
            return;
        }
        if (
            file.mime_type.startsWith('image/') &&
            file.size > 16 * 1024 * 1024
        ) {
            previewError.value =
                'This protected image is too large to preview safely. Use Download.';
            return;
        }
    } else if (
        !types.includes(file.mime_type) ||
        (file.mime_type === 'text/plain' && file.size > 1024 * 1024)
    ) {
        previewError.value =
            'Preview is unavailable for this format or size. Use Download.';
        return;
    }
    const generation = previewGeneration;
    const local = await readBrowserCopy(vaultUserId, file.id).catch(() => null);
    if (generation !== previewGeneration || !previewOpen.value) return;

    if (local && local.size === file.size) {
        if (file.encryption_header) {
            await receiveEncryptedDownload(file, null, true, local);
        } else {
            previewObjectUrl = URL.createObjectURL(
                local.slice(0, local.size, file.mime_type),
            );
            previewUrl.value = previewObjectUrl;
        }
        return;
    }

    void download(file, true);
}
const prepareDownloadForm = useHttp({});
const operationPoll = usePoll(
    2000,
    { only: ['operations'] },
    { autoStart: false },
);

type SaveHandle = {
    createWritable: () => Promise<FileSystemWritableFileStream>;
};
const exportDestinations = new Map<number, SaveHandle>();
async function download(file: FileEntry, showPreview = false): Promise<void> {
    const requestPreviewGeneration = previewGeneration;
    if (
        file.metadata_protected &&
        (!file.metadata_unlocked || !file.encryption_header)
    ) {
        toast.error(
            'Unlock and authenticate protected metadata before downloading.',
        );
        return;
    }
    const generation =
        file.connection_id === null
            ? 0
            : (vaultGenerations.get(file.connection_id) ?? 0);
    if (
        file.encryption_header &&
        (file.connection_id === null || !vaultKeys.has(file.connection_id))
    ) {
        toast.error(
            'Select this file’s storage chat and unlock its vault before downloading.',
        );
        return;
    }
    if (
        file.encryption_header &&
        file.size > 32 * 1024 * 1024 &&
        !showPreview
    ) {
        const picker = (
            window as Window & {
                showSaveFilePicker?: (options: {
                    suggestedName: string;
                }) => Promise<SaveHandle>;
            }
        ).showSaveFilePicker;
        if (!picker) {
            toast.error(
                'This browser cannot safely export encrypted files larger than 32 MiB. Use a browser with Save File support.',
            );
            return;
        }
        try {
            exportDestinations.set(
                file.id,
                await picker.call(window, { suggestedName: file.name }),
            );
        } catch {
            return;
        }
    }
    toast.info('Preparing download…');
    prepareDownloadForm.post(
        filesRoutes.prepareDownload({ file: file.id }).url,
        {
            onSuccess: (response) => {
                if (
                    showPreview &&
                    requestPreviewGeneration !== previewGeneration
                )
                    return;
                if (
                    file.encryption_header &&
                    file.connection_id !== null &&
                    (vaultGenerations.get(file.connection_id) ?? 0) !==
                        generation
                ) {
                    exportDestinations.delete(file.id);
                    return;
                }
                const operation = response as {
                    operation_id: number;
                    status: string;
                };
                if (operation.status === 'completed') {
                    receiveDownload(file, operation.operation_id, showPreview);
                } else {
                    pendingDownloads.value.set(operation.operation_id, {
                        file,
                        preview: showPreview,
                    });
                    operationPoll.start();
                    refetch();
                }
            },
            onError: () => {
                if (
                    showPreview &&
                    requestPreviewGeneration === previewGeneration
                )
                    previewError.value = 'Preparation failed. Try again.';
                toast.error('The download could not be prepared.');
            },
            onHttpException: () => {
                if (
                    showPreview &&
                    requestPreviewGeneration === previewGeneration
                )
                    previewError.value = 'Preparation failed. Try again.';
                toast.error('The download could not be prepared.');
                return false;
            },
        },
    );
}

function receiveDownload(
    file: FileEntry,
    operationId: number,
    showPreview = false,
): void {
    const url = filesRoutes.download(
        { file: file.id },
        { query: { operation_id: operationId, inline: showPreview ? 1 : 0 } },
    ).url;
    if (file.encryption_header) {
        void receiveEncryptedDownload(file, url, showPreview);
        return;
    }
    if (showPreview) {
        if (previewOpen.value && previewFile.value?.id === file.id)
            previewUrl.value = url;
    } else {
        window.location.href = url;
    }
}

async function receiveEncryptedDownload(
    file: FileEntry,
    url: string | null,
    showPreview = false,
    local?: File,
): Promise<void> {
    const key =
        file.connection_id === null
            ? undefined
            : vaultKeys.get(file.connection_id);
    if (!key || file.connection_id === null) {
        toast.error(
            'The vault is locked. Unlock it and prepare the download again.',
        );
        return;
    }
    const generation = vaultGenerations.get(file.connection_id) ?? 0;
    const abort = new AbortController();
    if (showPreview) previewAbort = abort;
    downloadAborts.add(abort);
    let ciphertext: StagedFile | undefined;
    let plaintext: StagedFile | undefined;
    let destination: FileSystemWritableFileStream | undefined;
    try {
        destination = await exportDestinations.get(file.id)?.createWritable();
        exportDestinations.delete(file.id);
        if (local) {
            ciphertext = { file: local, dispose: async () => {} };
        } else if (url) {
            const response = await fetch(url, {
                headers: { Accept: 'application/octet-stream' },
                signal: abort.signal,
            });
            ciphertext = await stageDownload(response, file.size, abort.signal);
        } else {
            throw new Error('No download source is available.');
        }
        if (showPreview) {
            const stream = await decryptFileStream(
                ciphertext.file,
                key,
                { userId: vaultUserId, connectionId: file.connection_id },
                abort.signal,
                file.encryption_header ?? undefined,
            );
            if (
                abort.signal.aborted ||
                !previewOpen.value ||
                previewFile.value?.id !== file.id ||
                (vaultGenerations.get(file.connection_id) ?? 0) !== generation
            ) {
                await ciphertext.dispose();
                return;
            }
            if (file.mime_type.startsWith('image/')) {
                const reader = stream.getReader();
                const chunks: Uint8Array<ArrayBuffer>[] = [];
                try {
                    while (true) {
                        abort.signal.throwIfAborted();
                        const { done, value } = await reader.read();
                        if (done) break;
                        const chunk = new Uint8Array(value.byteLength);
                        chunk.set(value);
                        chunks.push(chunk);
                    }
                    abort.signal.throwIfAborted();
                } catch (error) {
                    for (const chunk of chunks) chunk.fill(0);
                    throw error;
                } finally {
                    reader.releaseLock();
                }
                if (
                    !previewOpen.value ||
                    previewFile.value?.id !== file.id ||
                    (vaultGenerations.get(file.connection_id) ?? 0) !==
                        generation
                ) {
                    for (const chunk of chunks) chunk.fill(0);
                    await ciphertext.dispose();
                    return;
                }
                const image = new Blob(chunks, { type: file.mime_type });
                for (const chunk of chunks) chunk.fill(0);
                previewObjectUrl = URL.createObjectURL(image);
                previewUrl.value = previewObjectUrl;
                previewCiphertext = ciphertext;
                ciphertext = undefined;
                return;
            }
            const reader = stream.getReader();
            const stagedCiphertext = ciphertext;
            let stagedCiphertextDisposed = false;
            const disposeStagedCiphertext = async (): Promise<void> => {
                if (stagedCiphertextDisposed) return;
                stagedCiphertextDisposed = true;
                await stagedCiphertext.dispose();
                if (previewCiphertext === stagedCiphertext)
                    previewCiphertext = undefined;
            };
            previewStream.value = new ReadableStream<Uint8Array>(
                {
                    async pull(controller) {
                        try {
                            const { done, value } = await reader.read();
                            if (done) {
                                controller.close();
                                await disposeStagedCiphertext();
                            } else {
                                controller.enqueue(value);
                            }
                        } catch (error) {
                            controller.error(error);
                            await disposeStagedCiphertext();
                        }
                    },
                    async cancel(reason) {
                        await reader.cancel(reason).catch(() => {});
                        await disposeStagedCiphertext();
                    },
                },
                { highWaterMark: 1 },
            );
            previewCiphertext = stagedCiphertext;
            ciphertext = undefined;
            return;
        }
        plaintext = await decryptFile(
            ciphertext.file,
            key,
            { userId: vaultUserId, connectionId: file.connection_id },
            abort.signal,
            file.encryption_header ?? undefined,
            destination,
        );
        abort.signal.throwIfAborted();
        if (destination) {
            plaintext = undefined;
            toast.success('File decrypted and saved.');
            return;
        }
        const exported = plaintext;
        const objectUrl = URL.createObjectURL(exported.file);
        const release = (): void => {
            URL.revokeObjectURL(objectUrl);
            void exported.dispose();
            exportedDownloads.delete(release);
        };
        exportedDownloads.add(release);
        const anchor = document.createElement('a');
        anchor.href = objectUrl;
        anchor.download = file.name;
        anchor.click();
        plaintext = undefined;
        setTimeout(release, 60_000);
    } catch (error) {
        await destination?.abort().catch(() => {});
        if (
            showPreview &&
            previewOpen.value &&
            previewFile.value?.id === file.id &&
            !abort.signal.aborted
        ) {
            const authenticationFailure =
                error instanceof Error && error.name === 'OperationError';
            previewError.value = authenticationFailure
                ? 'Protected media could not be authenticated. The vault key may be wrong or the encrypted file may be damaged.'
                : error instanceof Error
                  ? error.message
                  : 'Protected media could not be authenticated. Use Download.';
        }
        toast.error(
            error instanceof Error
                ? error.message
                : 'Decryption failed. No file was exported.',
        );
    } finally {
        await ciphertext?.dispose();
        await plaintext?.dispose();
        if (previewAbort === abort) previewAbort = undefined;
        downloadAborts.delete(abort);
    }
}

watch(
    () => props.operations,
    async (operations) => {
        const snapshots = [...operations];
        const watchedIds = [
            ...pendingDownloads.value.keys(),
            ...uploadCards.value
                .filter((card) => card.status === 'processing')
                .map((card) => card.operationId),
        ]
            .filter((id): id is number => id !== undefined)
            .filter(
                (id) =>
                    !snapshots.some(
                        (operation) => operation.operation_id === id,
                    ),
            );
        if (watchedIds.length) {
            try {
                const response = await fetch(
                    operationRoutes.index({
                        query: { ids: [...new Set(watchedIds)].slice(0, 20) },
                    }).url,
                    { headers: { Accept: 'application/json' } },
                );
                if (response.ok) {
                    const body = (await response.json()) as {
                        operations: Props['operations'];
                    };
                    snapshots.push(...body.operations);
                }
            } catch {
                // The next snapshot refresh retries an interrupted status lookup.
            }
        }
        for (const operation of snapshots) {
            if (operation.operation === 'upload')
                uploadTracker.record(operation, uploadCards.value);
            const pending = pendingDownloads.value.get(operation.operation_id);
            if (!pending) continue;
            if (operation.status === 'completed') {
                pendingDownloads.value.delete(operation.operation_id);
                receiveDownload(
                    pending.file,
                    operation.operation_id,
                    pending.preview,
                );
            } else if (
                ['failed', 'cancelled', 'needs_review'].includes(
                    operation.status,
                )
            ) {
                pendingDownloads.value.delete(operation.operation_id);
                previewError.value = 'Preparation failed. Try again.';
                toast.error('Download preparation failed. Please try again.');
            }
        }
        if (
            operations.some((operation) =>
                [
                    'pending',
                    'processing',
                    'uploading',
                    'downloading',
                    'retrying',
                ].includes(operation.status),
            )
        ) {
            operationPoll.start();
        } else if (pendingDownloads.value.size === 0) {
            operationPoll.stop();
        }
    },
    { immediate: true },
);

const operationForm = useHttp({});
function controlOperation(id: number, command: 'retry' | 'cancel'): void {
    const url =
        command === 'retry'
            ? operationRoutes.retry({ operation: id }).url
            : operationRoutes.cancel({ operation: id }).url;
    operationForm.post(url, {
        onSuccess: () => {
            if (command === 'retry') {
                const card = uploadCards.value.find(
                    (item) => item.operationId === id,
                );
                if (card) {
                    card.status = 'processing';
                    card.progress = 0;
                    card.error = undefined;
                }
            }
            refetch();
        },
        onHttpException: (response) => {
            const body = response.data as { message?: string };
            if (
                command === 'retry' &&
                body.message ===
                    'The original upload expired. Upload the file again.'
            ) {
                const card = uploadCards.value.find(
                    (item) => item.operationId === id,
                );
                if (card) {
                    uploadAgainFromBrowser(card);
                    return false;
                }
                toast.error(
                    'The original upload is unavailable. Select the file and upload it again.',
                );
                return false;
            }
            toast.error(body.message ?? 'The operation could not be updated.');
            return false;
        },
    });
}

// ── Helpers ─────────────────────────────────────────────────────────

function formatSize(bytes?: number | null): string {
    if (!bytes) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
}

function formatDate(value?: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

function fileTypeIcon(file?: { file_type?: string } | null) {
    switch (file?.file_type) {
        case 'image':
            return FileImage;
        case 'video':
            return FileVideo;
        case 'audio':
            return FileAudio;
        case 'document':
            return FileText;
        default:
            return FileIcon;
    }
}

function onDrop(event: DragEvent): void {
    dragging.value = false;
    enqueueUploads(event.dataTransfer?.files ?? null);
}
watch(
    () => props.selected_file?.id,
    () => {
        if (props.selected_file) void openDetails(props.selected_file);
    },
    { immediate: true },
);

const { t } = useLocalization();
</script>

<template>
    <div
        class="flex min-h-[calc(100svh-3rem)] flex-col gap-4"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
    >
        <Head title="File Manager" />
        <header
            class="bg-card -mx-4 -mt-4 flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3 md:-mx-6 md:px-6"
            data-test="file-manager-header"
        >
            <div class="flex min-w-0 items-center gap-3">
                <SidebarTrigger class="-ml-1 shrink-0" />
                <span class="font-medium">{{ t('File Manager') }}</span>
            </div>
            <div
                class="flex shrink-0 items-center gap-2"
                aria-label="Header actions"
            >
                <LanguageSelector />
                <Button
                    variant="outline"
                    size="sm"
                    data-test="new-folder-button"
                    @click="openNewFolder"
                >
                    <FolderPlus class="size-4" />
                    {{ t('New folder') }}
                </Button>

                <Button
                    size="sm"
                    data-test="upload-button"
                    @click="openUploadPicker"
                >
                    <Upload class="size-4" />
                    Upload
                </Button>
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="hidden"
                    @change="
                        enqueueUploads(
                            ($event.target as HTMLInputElement).files,
                            true,
                        );
                        ($event.target as HTMLInputElement).value = ''
                    "
                />
            </div>
        </header>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <Heading
                variant="small"
                :title="filters.trash ? 'Trash' : (directory?.name ?? (selectedChatName || t('File Manager')))"
                :description="selectedChatName ? t('Files in this storage chat') : t('Files from all storage chats')"
            />

            <Badge v-if="selectedChatName" variant="secondary" data-test="active-storage-chat">
                {{ selectedChatName }}
            </Badge>
            <Button v-if="!availableConnections.length" size="sm" as-child>
                <Link :href="telegramConnections()">Connect Chat</Link>
            </Button>
        </div>
        <Dialog v-model:open="uploadDestinationOpen">
            <DialogContent data-test="upload-destination-dialog">
                <DialogHeader>
                    <DialogTitle>{{ t('Choose a storage chat') }}</DialogTitle>
                    <DialogDescription>
                        {{ t('Select which Telegram chat should store these files.') }}
                    </DialogDescription>
                </DialogHeader>
                <Select v-model="uploadDestinationId">
                    <SelectTrigger aria-label="Upload destination chat">
                        <SelectValue :placeholder="t('Select a chat')" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="connection in props.connections"
                            :key="connection.id"
                            :value="connection.id"
                        >{{ connection.name }}</SelectItem>
                    </SelectContent>
                </Select>
                <DialogFooter>
                    <Button variant="outline" @click="uploadDestinationOpen = false">{{ t('Cancel') }}</Button>
                    <Button :disabled="!uploadDestinationId" @click="confirmUploadDestination">
                        {{ pendingUploadFiles.length ? t('Upload files') : t('Choose files') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
        <p
            v-if="!availableConnections.length && !filters.trash"
            class="text-muted-foreground text-sm"
            role="status"
        >
            To upload files, first connect a Telegram group or channel in
            Storage Chats.
        </p>

        <ConnectionVault
            v-if="
                selectedConnection &&
                selectedConnection.encryption_mode !== 'none'
            "
            :key="selectedConnection.id"
            :context="{
                userId: sessionUserId,
                connectionId: selectedConnection.id,
            }"
            :mode="selectedConnection.encryption_mode"
            :envelope="selectedConnection.vault_envelope"
            @unlocked="unlockConnection"
            @locked="lockVault"
            @configured="
                router.reload({ only: ['connections', 'vault_connections'] })
            "
        />

        <p
            v-if="privateConnection"
            class="text-muted-foreground text-sm"
            role="status"
        >
            Protected search and tags stay on this device. Folder paths remain
            readable.
            {{ metadataLoading ? 'Authenticating metadata…' : '' }}
        </p>
        <p v-if="metadataError" class="text-destructive text-sm" role="alert">
            {{ metadataError }}
        </p>
        <!-- Only surface actionable worker alerts in File Manager. -->
        <div
            v-if="(workerStatus?.flagged_files ?? 0) > 0"
            class="text-muted-foreground flex flex-wrap items-center gap-3 text-sm"
        >
            <Badge variant="outline" class="text-amber-600">
                <TriangleAlert class="size-3" />
                {{ workerStatus?.flagged_files }} file(s) need review
            </Badge>
        </div>

        <!-- Toolbar -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative w-full max-w-xs">
                <Search
                    class="text-muted-foreground absolute top-2.5 left-2.5 size-4"
                />
                <Input
                    v-model="search"
                    class="pl-8"
                    type="search"
                    placeholder="Search files…"
                    data-test="files-search"
                    @input="onSearchInput"
                />
            </div>

            <div
                class="flex items-center rounded-md border"
                data-test="view-toggle"
            >
                <Button
                    :variant="view === 'list' ? 'secondary' : 'ghost'"
                    size="icon"
                    class="size-8 rounded-r-none border-0"
                    aria-label="List view"
                    @click="setView('list')"
                >
                    <List class="size-4" />
                </Button>
                <Button
                    :variant="view === 'grid' ? 'secondary' : 'ghost'"
                    size="icon"
                    class="size-8 rounded-l-none border-0"
                    aria-label="Grid view"
                    @click="setView('grid')"
                >
                    <LayoutGrid class="size-4" />
                </Button>
            </div>

            <Sheet v-model:open="filtersOpen">
                <SheetTrigger as-child>
                    <Button
                        variant="outline"
                        size="sm"
                        data-test="advanced-filters-trigger"
                    >
                        <SlidersHorizontal class="size-4" />
                        Filters
                        <Badge v-if="activeFilterCount" variant="secondary">{{ activeFilterCount }}</Badge>
                    </Button>
                </SheetTrigger>
                <SheetContent
                    side="right"
                    class="w-full overflow-y-auto sm:max-w-md"
                    data-test="advanced-filters-panel"
                >
                    <SheetHeader>
                        <SheetTitle>Search filters</SheetTitle>
                        <SheetDescription
                            >Filter by chat, type, tag, date, and
                            location.</SheetDescription
                        >
                    </SheetHeader>
                    <form
                        class="flex flex-col gap-4 p-4"
                        @submit.prevent="applyFilters"
                    >
                        <div class="space-y-2">
                            <Label>Storage chat</Label>
                            <Select
                                :model-value="draftChatFilter || 'all'"
                                @update:model-value="updateDraftChat"
                            >
                                <SelectTrigger class="w-full" data-test="chat-filter" aria-label="Filter by chat">
                                    <SelectValue placeholder="All chats" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All chats</SelectItem>
                                    <SelectItem
                                        v-for="connection in filter_connections"
                                        :key="connection.id"
                                        :value="String(connection.id)"
                                    >
                                        {{ connection.chat_title
                                            ? `${connection.chat_title} (${connection.name})`
                                            : connection.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="space-y-2">
                            <Label>File type</Label>
                            <Select v-model="draftTypeFilter">
                                <SelectTrigger class="w-full" data-test="type-filter">
                                    <SelectValue placeholder="All types" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All types</SelectItem>
                                    <SelectItem value="image">Images</SelectItem>
                                    <SelectItem value="video">Videos</SelectItem>
                                    <SelectItem value="audio">Audio</SelectItem>
                                    <SelectItem value="pdf">PDF</SelectItem>
                                    <SelectItem value="document">Documents</SelectItem>
                                    <SelectItem value="spreadsheet">Spreadsheets</SelectItem>
                                    <SelectItem value="presentation">Presentations</SelectItem>
                                    <SelectItem value="archive">Archives</SelectItem>
                                    <SelectItem value="text">Text</SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="space-y-2">
                            <Label>Tag</Label>
                            <Select v-model="draftTagFilter" :disabled="draftChatFilter !== connectionFilter && draftTagOptions.length === 0">
                                <SelectTrigger class="w-full" data-test="tag-filter">
                                    <SelectValue placeholder="All tags" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All tags</SelectItem>
                                    <SelectItem
                                        v-for="tag in draftTagOptions"
                                        :key="tag.id"
                                        :value="draftChatFilter === connectionFilter && privateConnection ? tag.name : String(tag.id)"
                                    >
                                        {{ tag.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="draftChatFilter !== connectionFilter && draftTagOptions.length === 0" class="text-muted-foreground text-xs">
                                Apply the selected chat first to load protected tags.
                            </p>
                        </div>
                        <label class="flex flex-col gap-2 text-sm"
                            >MIME type<Input
                                v-model="draftMimeFilter"
                                placeholder="application/pdf"
                        /></label>
                        <label class="text-sm"
                            >Created after<Input
                                v-model="draftCreatedAfter"
                                type="date"
                        /></label>
                        <label class="text-sm"
                            >Created before<Input
                                v-model="draftCreatedBefore"
                                type="date"
                        /></label>
                        <label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="draftSearchEverywhere"
                                type="checkbox"
                            />Search all folders</label
                        >
                        <div class="flex items-center justify-between gap-2 pt-2">
                            <Button type="button" variant="ghost" size="sm" @click="clearDraftFilters">
                                Clear filters
                            </Button>
                            <Button type="submit" size="sm">Apply filters</Button>
                        </div>
                    </form>
                </SheetContent>
            </Sheet>
            <div class="grow" />

            <Button
                :variant="filters.trash ? 'secondary' : 'ghost'"
                size="sm"
                data-test="trash-toggle"
                @click="toggleTrash"
            >
                <Trash2 class="size-4" />
                {{ t('Trash') }}
            </Button>
        </div>

        <!-- Upload cards -->
        <Teleport to="body">
            <div
                v-if="uploadCards.length"
                class="fixed right-4 bottom-4 left-4 z-40 flex max-h-[50dvh] flex-col gap-2 overflow-y-auto sm:left-auto sm:w-96"
                role="region"
                aria-label="File uploads"
            >
                <div
                    v-for="card in uploadCards"
                    :key="card.key"
                    :data-test="`upload-card-${card.status}`"
                    class="bg-card flex shrink-0 items-center gap-3 rounded-lg border p-3 shadow-lg"
                >
                    <Upload class="text-muted-foreground size-4 shrink-0" />
                    <div class="min-w-0 grow">
                        <div class="truncate text-sm font-medium">
                            {{ card.name }}
                        </div>
                        <div
                            class="bg-muted mt-1 h-1.5 overflow-hidden rounded-full"
                        >
                            <div
                                class="bg-primary h-full transition-all"
                                :style="{ width: `${card.progress}%` }"
                            />
                        </div>
                        <div
                            v-if="card.error"
                            class="text-destructive mt-1 text-xs"
                        >
                            {{ card.error }}
                        </div>
                    </div>
                    <Badge
                        v-if="card.status === 'uploading'"
                        variant="secondary"
                        >{{ card.progress }}%</Badge
                    >
                    <Badge
                        v-else-if="card.status === 'processing'"
                        variant="secondary"
                        >Telegram {{ card.progress }}%</Badge
                    >
                    <Badge v-else-if="card.status === 'done'" variant="default"
                        >Done</Badge
                    >
                    <span
                        v-else-if="card.needsReview"
                        class="text-muted-foreground text-xs"
                        >Review before retrying</span
                    >
                    <Button
                        v-else
                        variant="outline"
                        size="sm"
                        @click="retryUpload(card)"
                    >
                        {{
                            card.canRetryOperation === false
                                ? 'Upload again'
                                : 'Retry'
                        }}
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="size-7"
                        @click="dismissUpload(card)"
                    >
                        <X class="size-3.5" />
                    </Button>
                </div>
            </div>
        </Teleport>

        <section
            class="overflow-hidden rounded-xl border"
            data-test="file-listing-panel"
        >
            <nav
                aria-label="Directory breadcrumb"
                class="text-muted-foreground flex min-w-0 flex-wrap items-center justify-between gap-2 border-b px-4 py-3 text-sm"
            >
                <div class="flex min-w-0 flex-wrap items-center gap-1">
                    <button
                        class="hover:text-foreground"
                        :class="{ 'text-foreground font-medium': !directory }"
                        @click="openDirectory(null)"
                    >
                        {{ t('My Files') }}
                    </button>
                    <template v-for="crumb in breadcrumbs" :key="crumb.path">
                        <span>/</span>
                        <button
                            v-if="crumb.id"
                            class="hover:text-foreground"
                            :class="{
                                'text-foreground font-medium':
                                    crumb.path === directory?.path,
                            }"
                            @click="openDirectory(crumb.id)"
                        >
                            {{ crumb.name }}
                        </button>
                        <span v-else class="text-foreground font-medium">{{
                            crumb.name
                        }}</span>
                    </template>
                </div>
                <div
                    v-if="!filters.trash && selectedIds.length"
                    class="ms-auto flex shrink-0 items-center gap-1"
                    data-test="breadcrumb-bulk-actions"
                    role="group"
                    :aria-label="t('Selected file actions')"
                >
                    <Badge variant="secondary" class="me-1" data-test="selected-files-count">
                        {{ selectedIds.length }} {{ t('selected') }}
                    </Badge>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        :title="t('Add tags')"
                        :aria-label="t('Add tags')"
                        data-test="bulk-add-tags"
                        @click="openBulkTagDialog"
                    >
                        <Tags class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="text-destructive hover:text-destructive size-8"
                        :title="t('Move to trash')"
                        :aria-label="t('Move to trash')"
                        :disabled="bulkDeleteForm.processing"
                        data-test="bulk-move-to-trash"
                        @click="deleteSelectedFiles"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="size-8"
                        :title="t('Clear selection')"
                        :aria-label="t('Clear selection')"
                        data-test="bulk-clear-selection"
                        @click="selectedIds = []"
                    >
                        <X class="size-4" />
                    </Button>
                </div>
            </nav>
            <Dialog v-model:open="bulkTagOpen">
                <DialogContent class="sm:max-w-sm" data-test="bulk-tag-dialog">
                    <form class="space-y-4" @submit.prevent="tagSelectedFiles">
                        <DialogHeader>
                            <DialogTitle>{{ t('Add tags') }}</DialogTitle>
                            <DialogDescription>
                                {{ t('Add tags to the selected files. Separate multiple tags with commas.') }}
                            </DialogDescription>
                        </DialogHeader>
                        <div class="space-y-2">
                            <Label for="bulk-tags-input">{{ t('Tags') }}</Label>
                            <Input
                                id="bulk-tags-input"
                                v-model="bulkTagDraft"
                                autofocus
                                :disabled="bulkTagForm.processing || privateBulkTagProcessing"
                                :placeholder="t('Tags, separated by commas')"
                                data-test="bulk-tag-input"
                            />
                        </div>
                        <p
                            v-if="bulkTagForm.hasErrors"
                            class="text-destructive text-sm"
                            role="alert"
                        >
                            {{ Object.values(bulkTagForm.errors).join(' ') }}
                        </p>
                        <DialogFooter>
                            <Button type="button" variant="outline" @click="bulkTagOpen = false">
                                {{ t('Cancel') }}
                            </Button>
                            <Button
                                type="submit"
                                :disabled="!bulkTagDraft.trim() || bulkTagForm.processing || privateBulkTagProcessing"
                                data-test="bulk-tag-apply"
                            >
                                {{ t('Apply tags') }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <div class="p-3">
                <!-- Sortable columns remain accessible in grid view without a sort select. -->
                <div v-if="view === 'grid'" class="mb-3 flex flex-wrap items-center gap-2" data-test="grid-sort-columns">
                    <span class="text-muted-foreground text-xs">Sort by:</span>
                    <div role="group" aria-label="Sort files" class="flex flex-wrap items-center gap-1">
                        <Button
                            v-for="column in gridSortColumns"
                            :key="column.value"
                            type="button"
                            size="sm"
                            :variant="columnSortDirection(column.value) ? 'secondary' : 'ghost'"
                            class="h-8 gap-1.5"
                            :aria-pressed="columnSortDirection(column.value) !== null"
                            @click="sortByColumn(column.value)"
                        >
                            {{ column.label }}
                            <ArrowUp v-if="columnSortDirection(column.value) === 'asc'" class="size-3.5" />
                            <ArrowDown v-else-if="columnSortDirection(column.value) === 'desc'" class="size-3.5" />
                            <ArrowDownUp v-else class="size-3.5 opacity-50" />
                        </Button>
                    </div>
                </div>
                <!-- Listing (list view) -->
                <Table v-if="view === 'list'">
                    <TableHeader>
                        <TableRow>
                            <TableHead class="w-8" />
                            <TableHead class="" :aria-sort="columnSortDirection('name') === 'asc' ? 'ascending' : columnSortDirection('name') === 'desc' ? 'descending' : 'none'">
                                <div class="flex items-center gap-2">
                                    <input
                                        v-if="!filters.trash"
                                        type="checkbox"
                                        data-test="select-all-files"
                                        class="size-4 shrink-0"
                                        :checked="allPageFilesSelected"
                                        :indeterminate="somePageFilesSelected && !allPageFilesSelected"
                                        :disabled="!selectablePageIds.length"
                                        :aria-label="t('Select all files on this page')"
                                        @change="togglePageSelection(($event.target as HTMLInputElement).checked)"
                                    />
                                    <button
                                        type="button"
                                        class="hover:text-foreground inline-flex items-center gap-1.5 rounded-sm transition-colors focus-visible:outline-2 focus-visible:outline-ring"
                                        data-test="sort-name"
                                        @click="sortByColumn('name')"
                                    >
                                        {{ t('Name') }}
                                        <ArrowUp v-if="columnSortDirection('name') === 'asc'" class="size-3.5" />
                                        <ArrowDown v-else-if="columnSortDirection('name') === 'desc'" class="size-3.5" />
                                        <ArrowDownUp v-else class="size-3.5 opacity-50" />
                                    </button>
                                </div>
                            </TableHead>
                            <TableHead data-test="file-chat-column">{{ t('Chat') }}</TableHead>
                            <TableHead class="hidden lg:table-cell" :aria-sort="columnSortDirection('file_type') === 'asc' ? 'ascending' : columnSortDirection('file_type') === 'desc' ? 'descending' : 'none'">
                                <button
                                    type="button"
                                    class="hover:text-foreground inline-flex items-center gap-1.5 rounded-sm transition-colors focus-visible:outline-2 focus-visible:outline-ring"
                                    data-test="sort-file_type"
                                    @click="sortByColumn('file_type')"
                                >
                                    Type
                                    <ArrowUp v-if="columnSortDirection('file_type') === 'asc'" class="size-3.5" />
                                    <ArrowDown v-else-if="columnSortDirection('file_type') === 'desc'" class="size-3.5" />
                                    <ArrowDownUp v-else class="size-3.5 opacity-50" />
                                </button>
                            </TableHead>
                            <TableHead class="hidden sm:table-cell" :aria-sort="columnSortDirection('size') === 'asc' ? 'ascending' : columnSortDirection('size') === 'desc' ? 'descending' : 'none'">
                                <button
                                    type="button"
                                    class="hover:text-foreground inline-flex items-center gap-1.5 rounded-sm transition-colors focus-visible:outline-2 focus-visible:outline-ring"
                                    data-test="sort-size"
                                    @click="sortByColumn('size')"
                                >
                                    Size
                                    <ArrowUp v-if="columnSortDirection('size') === 'asc'" class="size-3.5" />
                                    <ArrowDown v-else-if="columnSortDirection('size') === 'desc'" class="size-3.5" />
                                    <ArrowDownUp v-else class="size-3.5 opacity-50" />
                                </button>
                            </TableHead>
                            <TableHead class="hidden xl:table-cell" :aria-sort="columnSortDirection('created_at') === 'asc' ? 'ascending' : columnSortDirection('created_at') === 'desc' ? 'descending' : 'none'">
                                <button
                                    type="button"
                                    class="hover:text-foreground inline-flex items-center gap-1.5 rounded-sm transition-colors focus-visible:outline-2 focus-visible:outline-ring"
                                    data-test="sort-created_at"
                                    @click="sortByColumn('created_at')"
                                >
                                    Created
                                    <ArrowUp v-if="columnSortDirection('created_at') === 'asc'" class="size-3.5" />
                                    <ArrowDown v-else-if="columnSortDirection('created_at') === 'desc'" class="size-3.5" />
                                    <ArrowDownUp v-else class="size-3.5 opacity-50" />
                                </button>
                            </TableHead>
                            <TableHead class="hidden md:table-cell" :aria-sort="columnSortDirection('updated_at') === 'asc' ? 'ascending' : columnSortDirection('updated_at') === 'desc' ? 'descending' : 'none'">
                                <button
                                    type="button"
                                    class="hover:text-foreground inline-flex items-center gap-1.5 rounded-sm transition-colors focus-visible:outline-2 focus-visible:outline-ring"
                                    data-test="sort-updated_at"
                                    @click="sortByColumn('updated_at')"
                                >
                                    Updated
                                    <ArrowUp v-if="columnSortDirection('updated_at') === 'asc'" class="size-3.5" />
                                    <ArrowDown v-else-if="columnSortDirection('updated_at') === 'desc'" class="size-3.5" />
                                    <ArrowDownUp v-else class="size-3.5 opacity-50" />
                                </button>
                            </TableHead>
                            <TableHead class="w-10">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            :class="{ 'cursor-pointer': directory }"
                            @click="
                                directory &&
                                openDirectory(breadcrumbs.at(-2)?.id ?? null)
                            "
                        >
                            <TableCell />
                            <TableCell>
                                <button
                                    type="button"
                                    class="flex items-center gap-2"
                                    data-test="parent-directory-row"
                                    aria-label="Go up to parent directory"
                                    :disabled="!directory"
                                    @click.stop="
                                        openDirectory(
                                            breadcrumbs.at(-2)?.id ?? null,
                                        )
                                    "
                                >
                                    <ArrowUp
                                        class="text-muted-foreground size-4"
                                    />
                                    <span class="font-medium">..</span>
                                </button>
                            </TableCell>
                            <TableCell>—</TableCell>
                            <TableCell class="hidden lg:table-cell">—</TableCell>
                            <TableCell class="hidden sm:table-cell">—</TableCell>
                            <TableCell class="hidden xl:table-cell">—</TableCell>
                            <TableCell class="hidden md:table-cell">—</TableCell>
                            <TableCell />
                        </TableRow>
                        <TableRow
                            v-for="dir in directories"
                            :key="'d' + dir.id"
                            class="cursor-pointer"
                            data-test="directory-row"
                            @click="openDirectory(dir.id)"
                        >
                            <TableCell />
                            <TableCell>
                                <div class="flex items-center gap-2">
                                    <Folder
                                        class="size-4 fill-amber-400 text-amber-500"
                                    />
                                    <span class="font-medium"
                                        >{{ dir.name
                                        }}{{
                                            dir.purging
                                                ? ' · deletion pending'
                                                : ''
                                        }}</span
                                    >
                                </div>
                            </TableCell>
                            <TableCell>—</TableCell>
                            <TableCell class="hidden lg:table-cell">—</TableCell>
                            <TableCell class="hidden sm:table-cell">—</TableCell>
                            <TableCell class="hidden xl:table-cell">—</TableCell>
                            <TableCell class="hidden md:table-cell">{{
                                formatDate(dir.updated_at)
                            }}</TableCell>
                            <TableCell @click.stop>
                                <EntryMenu
                                    kind="directory"
                                    :in-trash="filters.trash"
                                    @open="openDirectory(dir.id)"
                                    @rename="openRename(dir, 'directory')"
                                    @move="openMove(dir, 'directory')"
                                    @delete="deleteEntry(dir, 'directory')"
                                    @restore="restoreEntry(dir, 'directory')"
                                    @purge="purgeEntry(dir, 'directory')"
                                />
                            </TableCell>
                        </TableRow>

                        <TableRow
                            v-for="file in fileListing.data"
                            :key="'f' + file.id"
                            data-test="file-row"
                        >
                            <TableCell data-test="offline-copy-cell">
                                <CloudDownload
                                    v-if="browserCopyIds.has(file.id)"
                                    role="img"
                                    aria-label="Saved for offline use in this browser"
                                    class="text-muted-foreground size-4 shrink-0"
                                />
                            </TableCell>
                            <TableCell>
                                <div class="flex min-w-0 items-center gap-2">
                                    <input
                                        v-if="!filters.trash"
                                        v-model="selectedIds"
                                        type="checkbox"
                                        :value="file.id"
                                        :aria-label="'Select ' + file.name"
                                        class="size-4"
                                    />
                                    <component
                                        :is="fileTypeIcon(file)"
                                        class="text-muted-foreground size-4 shrink-0"
                                    />
                                    <button
                                        class="truncate hover:underline"
                                        @click="openDetails(file)"
                                    >
                                        {{ file.name
                                        }}{{
                                            file.status !== 'uploaded'
                                                ? ' · ' + file.status
                                                : ''
                                        }}
                                    </button>
                                    <TriangleAlert
                                        v-if="file.flagged"
                                        class="size-3.5 shrink-0 text-amber-500"
                                    />
                                    <Badge
                                        v-for="tag in file.tags"
                                        :key="tag.id"
                                        variant="outline"
                                        class="shrink-0 text-xs"
                                    >
                                        {{ tag.name }}
                                    </Badge>
                                </div>
                            </TableCell>
                            <TableCell data-test="file-chat-cell">
                                <Link
                                    v-if="file.connection_id && props.filter_connections.some((chat) => chat.id === file.connection_id)"
                                    :href="filesRoutes.index({ query: { telegram_connection_id: file.connection_id } })"
                                    class="text-muted-foreground hover:text-foreground text-sm hover:underline"
                                >{{ fileChatName(file) }}</Link>
                                <span v-else class="text-muted-foreground text-sm">{{ fileChatName(file) }}</span>
                            </TableCell>
                            <TableCell class="hidden lg:table-cell">{{ file.file_type }}</TableCell>
                            <TableCell class="hidden sm:table-cell">{{
                                formatSize(file.size)
                            }}</TableCell>
                            <TableCell class="hidden xl:table-cell">{{
                                formatDate(file.created_at)
                            }}</TableCell>
                            <TableCell class="hidden md:table-cell">{{
                                formatDate(file.updated_at)
                            }}</TableCell>
                            <TableCell>
                                <div class="flex items-center gap-1">
                                    <div
                                        v-if="!filters.trash"
                                        class="flex items-center gap-1"
                                        data-test="file-actions"
                                    >
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            class="size-8"
                                            aria-label="Download"
                                            title="Download"
                                            @click="download(file)"
                                        >
                                            <Download class="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            class="size-8"
                                            aria-label="Save offline"
                                            title="Save offline"
                                            @click="saveBrowserDownload(file)"
                                        >
                                            <CloudDownload class="size-4" />
                                        </Button>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            class="text-destructive size-8"
                                            aria-label="Delete"
                                            title="Delete"
                                            @click="deleteEntry(file, 'file')"
                                        >
                                            <Trash2 class="size-4" />
                                        </Button>
                                    </div>
                                    <EntryMenu
                                        kind="file"
                                        :in-trash="filters.trash"
                                        @download="download(file)"
                                        @browser-download="
                                            saveBrowserDownload(file)
                                        "
                                        @preview="preview(file)"
                                        @copy="openMove(file, 'file', true)"
                                        @rename="openRename(file, 'file')"
                                        @move="openMove(file, 'file')"
                                        @delete="deleteEntry(file, 'file')"
                                        @restore="restoreEntry(file, 'file')"
                                        @purge="purgeEntry(file, 'file')"
                                    />
                                </div>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>

                <!-- Listing (grid view) -->
                <div
                    v-else
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                    data-test="grid-view"
                >
                    <div
                        v-for="dir in directories"
                        :key="'gd' + dir.id"
                        class="bg-card hover:border-primary/40 flex cursor-pointer flex-col gap-2 rounded-xl border p-4 transition-colors"
                        data-test="directory-card"
                        @click="openDirectory(dir.id)"
                    >
                        <div class="flex items-start justify-between">
                            <Folder
                                class="size-8 fill-amber-400 text-amber-500"
                            />
                            <span @click.stop>
                                <EntryMenu
                                    kind="directory"
                                    :in-trash="filters.trash"
                                    @open="openDirectory(dir.id)"
                                    @rename="openRename(dir, 'directory')"
                                    @move="openMove(dir, 'directory')"
                                    @delete="deleteEntry(dir, 'directory')"
                                    @restore="restoreEntry(dir, 'directory')"
                                    @purge="purgeEntry(dir, 'directory')"
                                />
                            </span>
                        </div>
                        <span
                            class="truncate text-sm font-medium"
                            :title="dir.name"
                            >{{ dir.name }}</span
                        >
                        <span class="text-muted-foreground text-xs">{{
                            formatDate(dir.updated_at)
                        }}</span>
                    </div>

                    <div
                        v-for="file in fileListing.data"
                        :key="'gf' + file.id"
                        class="bg-card hover:border-primary/40 flex cursor-pointer flex-col gap-2 rounded-xl border p-4 transition-colors"
                        data-test="file-card"
                        @click="openDetails(file)"
                    >
                        <input
                            v-if="!filters.trash"
                            v-model="selectedIds"
                            type="checkbox"
                            :value="file.id"
                            :aria-label="'Select ' + file.name"
                            class="size-4"
                            @click.stop
                        />
                        <div class="flex items-start justify-between">
                            <component
                                :is="fileTypeIcon(file)"
                                class="text-muted-foreground size-8"
                            />
                            <span @click.stop>
                                <EntryMenu
                                    kind="file"
                                    :in-trash="filters.trash"
                                    @download="download(file)"
                                    @browser-download="
                                        saveBrowserDownload(file)
                                    "
                                    @preview="preview(file)"
                                    @copy="openMove(file, 'file', true)"
                                    @rename="openRename(file, 'file')"
                                    @move="openMove(file, 'file')"
                                    @delete="deleteEntry(file, 'file')"
                                    @restore="restoreEntry(file, 'file')"
                                    @purge="purgeEntry(file, 'file')"
                                />
                            </span>
                        </div>
                        <span
                            class="flex items-center gap-1 text-sm font-medium"
                        >
                            <CloudDownload
                                v-if="browserCopyIds.has(file.id)"
                                role="img"
                                aria-label="Saved for offline use in this browser"
                                class="text-muted-foreground size-4 shrink-0"
                            />
                            <span class="truncate" :title="file.name">{{
                                file.name
                            }}</span>
                            <TriangleAlert
                                v-if="file.flagged"
                                class="size-3.5 shrink-0 text-amber-500"
                            />
                        </span>
                        <span class="text-muted-foreground text-xs">
                            {{ formatSize(file.size) }} ·
                            {{ formatDate(file.updated_at) }}
                        </span>
                        <span class="text-muted-foreground truncate text-xs" data-test="grid-chat-label">
                            {{ fileChatName(file) }}
                        </span>
                        <div
                            v-if="file.tags.length"
                            class="flex flex-wrap gap-1"
                        >
                            <Badge
                                v-for="tag in file.tags"
                                :key="tag.id"
                                variant="outline"
                                class="text-xs"
                            >
                                {{ tag.name }}
                            </Badge>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div
                    v-if="
                        fileListing.data.length === 0 &&
                        directories.length === 0
                    "
                    class="border-border/60 bg-muted/30 flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed p-16 text-center"
                >
                    <span
                        class="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl"
                    >
                        <Upload class="size-6" />
                    </span>
                    <div class="max-w-sm space-y-1">
                        <h3 class="font-medium">
                            {{
                                filters.trash
                                    ? 'Trash is empty'
                                    : 'Nothing here yet'
                            }}
                        </h3>
                        <p class="text-muted-foreground text-sm">
                            {{
                                filters.trash
                                    ? 'Deleted files will appear here.'
                                    : 'Upload files or create a folder to get started. Files are stored privately on Telegram.'
                            }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pagination -->
        <div
            v-if="fileListing.last_page > 1"
            class="flex items-center justify-end gap-2"
        >
            <Button
                variant="outline"
                size="sm"
                :disabled="fileListing.current_page <= 1"
                @click="changePage(fileListing.current_page - 1)"
            >
                <ChevronLeft class="size-4" />
            </Button>
            <span class="text-muted-foreground text-sm">
                Page {{ fileListing.current_page }} of
                {{ fileListing.last_page }}
            </span>
            <Button
                variant="outline"
                size="sm"
                :disabled="fileListing.current_page >= fileListing.last_page"
                @click="changePage(fileListing.current_page + 1)"
            >
                <ChevronRight class="size-4" />
            </Button>
        </div>

        <!-- Dropzone overlay -->
        <div
            v-if="dragging"
            class="bg-primary/5 pointer-events-none fixed inset-0 z-50 flex items-center justify-center border-4 border-dashed"
        >
            <p class="text-lg font-medium">Drop files to upload</p>
        </div>

        <!-- New folder dialog -->
        <Dialog :open="newFolderOpen" @update:open="newFolderOpen = $event">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="createFolder">
                    <DialogHeader>
                        <DialogTitle>{{ t('New folder') }}</DialogTitle>
                        <DialogDescription>
                            Create a folder{{
                                directory ? ` inside “${directory.name}”` : ''
                            }}.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="mt-4 space-y-2">
                        <Label for="folder-name">{{ t('Name') }}</Label>
                        <Input
                            id="folder-name"
                            v-model="newFolderForm.name"
                            autofocus
                            maxlength="128"
                        />
                        <p
                            v-if="newFolderForm.errors.name"
                            class="text-destructive text-sm"
                        >
                            {{ newFolderForm.errors.name }}
                        </p>
                    </div>
                    <DialogFooter class="mt-4">
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">{{
                                t('Cancel')
                            }}</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="newFolderForm.processing"
                            >Create</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Rename dialog -->
        <Dialog :open="renameOpen" @update:open="renameOpen = $event">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="submitRename">
                    <DialogHeader>
                        <DialogTitle>Rename {{ renameKind }}</DialogTitle>
                    </DialogHeader>
                    <div class="mt-4 space-y-2">
                        <Label for="rename-name">{{ t('Name') }}</Label>
                        <Input
                            id="rename-name"
                            v-model="renameForm.name"
                            autofocus
                            maxlength="255"
                        />
                        <p
                            v-if="renameForm.errors.name"
                            class="text-destructive text-sm"
                        >
                            {{ renameForm.errors.name }}
                        </p>
                    </div>
                    <DialogFooter class="mt-4">
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">{{
                                t('Cancel')
                            }}</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="renameForm.processing"
                            >{{ t('Save') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Move dialog -->
        <Dialog :open="moveOpen" @update:open="moveOpen = $event">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="submitMove">
                    <DialogHeader>
                        <DialogTitle
                            >{{ copyMode ? 'Copy' : 'Move' }}
                            {{ moveKind }}</DialogTitle
                        >
                        <DialogDescription>
                            Choose a destination folder for “{{
                                moveTarget?.name
                            }}”.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="mt-4 space-y-2">
                        <Label v-if="copyMode" for="copy-name">Copy name</Label>
                        <Input
                            v-if="copyMode"
                            id="copy-name"
                            v-model="copyForm.name"
                            maxlength="255"
                        />
                        <p v-if="copyForm.errors.name" class="text-destructive">
                            {{ copyForm.errors.name }}
                        </p>
                        <Label for="move-directory">Destination</Label>
                        <Select v-model="moveDirectoryId">
                            <SelectTrigger id="move-directory">
                                <SelectValue placeholder="My Files (root)" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem :value="null"
                                    >My Files (root)</SelectItem
                                >
                                <SelectItem
                                    v-for="dir in allDirectories"
                                    :key="dir.id"
                                    :value="dir.id"
                                >
                                    {{ dir.path || dir.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <DialogFooter class="mt-4">
                        <DialogClose as-child>
                            <Button type="button" variant="ghost">{{
                                t('Cancel')
                            }}</Button>
                        </DialogClose>
                        <Button
                            type="submit"
                            :disabled="
                                moveForm.processing || copyForm.processing
                            "
                            >{{ copyMode ? 'Copy' : 'Move' }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- File preview and details -->
        <MediaViewer
            v-model:open="previewOpen"
            :file="previewFile"
            :url="previewUrl"
            :stream="previewStream"
            :error="previewError"
            @retry="previewFile && preview(previewFile)"
            @download="previewFile && download(previewFile)"
        >
            <div
                v-if="detailsFile"
                class="space-y-6 border-t pt-6"
                data-test="file-details"
            >
                <p class="text-muted-foreground text-sm">
                    {{ formatSize(detailsFile.size) }} ·
                    {{ detailsFile.mime_type }}
                </p>
                <div
                    v-if="detailsFile.flagged"
                    class="flex items-start gap-2 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-600"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                    Telegram reports the underlying message is missing. Download
                    may fail.
                </div>

                <dl class="space-y-2 text-sm">
                    <div
                        v-if="detailsFile.directory_path"
                        class="flex justify-between gap-4"
                    >
                        <dt class="text-muted-foreground">Folder</dt>
                        <dd class="break-all">
                            {{ detailsFile.directory_path }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Created</dt>
                        <dd>{{ formatDate(detailsFile.created_at) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-muted-foreground">Updated</dt>
                        <dd>{{ formatDate(detailsFile.updated_at) }}</dd>
                    </div>
                </dl>

                <BrowserDownloads
                    :account="Number(usePage().props.auth.user.id)"
                    :file-id="detailsFile.id"
                />

                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-sm font-medium">
                        Telegram storage
                    </div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                {{ t('Account') }}
                            </dt>
                            <dd>
                                {{ detailsFile.telegram.account ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Storage chat</dt>
                            <dd>
                                {{ detailsFile.telegram.connection ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">
                                {{ t('Method') }}
                            </dt>
                            <dd class="capitalize">
                                {{ detailsFile.telegram.method ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Chat ID</dt>
                            <dd class="font-mono text-xs">
                                {{ detailsFile.telegram.chat_id ?? '—' }}
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Message ID</dt>
                            <dd class="font-mono text-xs">
                                {{ detailsFile.telegram.message_id ?? '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="space-y-2">
                    <Label for="description">Description</Label>
                    <textarea
                        id="description"
                        v-model="descriptionDraft"
                        rows="3"
                        maxlength="5000"
                        class="border-input bg-background placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 flex w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:outline-none"
                        @blur="saveDescription"
                    />
                    <p class="text-muted-foreground text-xs">
                        Saved automatically when you click outside.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label>{{ t('Tags') }}</Label>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge
                            v-for="tag in detailsFile.tags"
                            :key="tag.id"
                            variant="outline"
                        >
                            {{ tag.name }}
                            <button class="ml-1" @click="detachTag(tag)">
                                <X class="size-3" />
                            </button>
                        </Badge>
                        <span
                            v-if="detailsFile.tags.length === 0"
                            class="text-muted-foreground text-sm"
                        >
                            No tags yet.
                        </span>
                    </div>
                    <form class="flex gap-2" @submit.prevent="attachTag">
                        <Input
                            v-model="tagDraft"
                            placeholder="Add a tag…"
                            maxlength="32"
                        />
                        <Button type="submit" variant="outline" size="sm"
                            >Add</Button
                        >
                    </form>
                </div>
            </div>
        </MediaViewer>
    </div>
</template>
