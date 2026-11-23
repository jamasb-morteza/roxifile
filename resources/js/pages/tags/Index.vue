<script setup lang="ts">
import { useLocalization } from '@/composables/useLocalization';
import { Head, router, useHttp } from '@inertiajs/vue3';
import {
    FolderOpen,
    Pencil,
    Plus,
    Tags as TagsIcon,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as filesIndex } from '@/routes/files';
import * as tagsRoutes from '@/routes/tags';

type Tag = {
    id: number;
    name: string;
    files_count: number;
};

type Props = {
    tags: Tag[];
};

const props = defineProps<Props>();

// ── Create ──────────────────────────────────────────────────────────

// JSON endpoints — standalone HTTP, not Inertia visits.
const http = useHttp();

const createOpen = ref(false);
const createForm = useHttp({ name: '' });

function openCreate(): void {
    createForm.reset();
    createOpen.value = true;
}

function submitCreate(): void {
    createForm.post(tagsRoutes.store.url(), {
        onSuccess: () => {
            createOpen.value = false;
            toast.success('Tag created');
            router.reload();
        },
    });
}

// ── Rename ──────────────────────────────────────────────────────────

const renameOpen = ref(false);
const renameTarget = ref<Tag | null>(null);
const renameForm = useHttp({ name: '' });

function openRename(tag: Tag): void {
    renameTarget.value = tag;
    renameForm.name = tag.name;
    renameOpen.value = true;
}

function submitRename(): void {
    if (!renameTarget.value) {
        return;
    }

    renameForm.patch(tagsRoutes.update({ tag: renameTarget.value.id }).url, {
        onSuccess: () => {
            renameOpen.value = false;
            toast.success('Tag renamed');
            router.reload();
        },
    });
}

// ── Delete ──────────────────────────────────────────────────────────

const deleteOpen = ref(false);
const deleteTarget = ref<Tag | null>(null);

function openDelete(tag: Tag): void {
    deleteTarget.value = tag;
    deleteOpen.value = true;
}

function submitDelete(): void {
    if (!deleteTarget.value) {
        return;
    }

    http.delete(tagsRoutes.destroy({ tag: deleteTarget.value.id }).url, {
        onSuccess: () => {
            deleteOpen.value = false;
            toast.success('Tag deleted');
            router.reload();
        },
    });
}

function filterFiles(tag: Tag): void {
    router.get(filesIndex.url(), { tag_ids: [tag.id] });
}

const { t } = useLocalization();
</script>

<template>
    <Head title="Tags" />

    <div class="flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <Heading
                variant="small"
                title="Tags"
                description="Organize files with your own labels"
            />

            <Button size="sm" data-test="create-tag-button" @click="openCreate">
                <Plus class="size-4" />
                New tag
            </Button>
        </div>

        <!-- Empty state -->
        <div
            v-if="tags.length === 0"
            class="border-border/60 bg-muted/30 flex flex-col items-center justify-center gap-3 rounded-2xl border border-dashed p-16 text-center"
        >
            <span
                class="bg-primary/10 text-primary flex size-14 items-center justify-center rounded-2xl"
            >
                <TagsIcon class="size-6" />
            </span>
            <div class="max-w-sm space-y-1">
                <h3 class="font-medium">No tags yet</h3>
                <p class="text-muted-foreground text-sm">
                    Tags let you group files across folders — attach them from a
                    file's details panel, then filter by tag on the Files page.
                </p>
            </div>
            <Button
                class="mt-2"
                data-test="empty-create-tag-button"
                @click="openCreate"
            >
                <Plus />
                Create your first tag
            </Button>
        </div>

        <!-- Listing -->
        <Table v-else>
            <TableHeader>
                <TableRow>
                    <TableHead>{{ t('Name') }}</TableHead>
                    <TableHead class="hidden sm:table-cell">{{ t('Files') }}</TableHead>
                    <TableHead class="w-24 text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                <TableRow v-for="tag in tags" :key="tag.id" data-test="tag-row">
                    <TableCell>
                        <button
                            class="flex items-center gap-2 font-medium hover:underline"
                            :title="'Show files tagged “' + tag.name + '”'"
                            @click="filterFiles(tag)"
                        >
                            <TagsIcon class="text-muted-foreground size-4" />
                            {{ tag.name }}
                        </button>
                    </TableCell>
                    <TableCell class="hidden sm:table-cell">
                        <Badge variant="secondary">
                            {{ tag.files_count }}
                            {{ tag.files_count === 1 ? 'file' : 'files' }}
                        </Badge>
                    </TableCell>
                    <TableCell class="text-right">
                        <div class="flex justify-end gap-1">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :title="'Browse files with this tag'"
                                @click="filterFiles(tag)"
                            >
                                <FolderOpen class="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                data-test="rename-tag-button"
                                @click="openRename(tag)"
                            >
                                <Pencil class="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="text-destructive hover:text-destructive size-8"
                                data-test="delete-tag-button"
                                @click="openDelete(tag)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </TableCell>
                </TableRow>
            </TableBody>
        </Table>

        <!-- Create dialog -->
        <Dialog :open="createOpen" @update:open="createOpen = $event">
            <DialogContent class="sm:max-w-sm">
                <form @submit.prevent="submitCreate">
                    <DialogHeader>
                        <DialogTitle>New tag</DialogTitle>
                        <DialogDescription>
                            Tag names are unique per account, up to 32
                            characters.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="mt-4 space-y-2">
                        <Label for="tag-name">{{ t('Name') }}</Label>
                        <Input
                            id="tag-name"
                            v-model="createForm.name"
                            maxlength="32"
                            autofocus
                        />
                        <p
                            v-if="createForm.errors.name"
                            class="text-destructive text-sm"
                        >
                            {{ createForm.errors.name }}
                        </p>
                    </div>
                    <DialogFooter class="mt-4">
                        <DialogClose as-child>
                            <Button type="button" variant="ghost"
                                >{{ t('Cancel') }}</Button
                            >
                        </DialogClose>
                        <Button type="submit" :disabled="createForm.processing"
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
                        <DialogTitle>Rename tag</DialogTitle>
                    </DialogHeader>
                    <div class="mt-4 space-y-2">
                        <Label for="rename-tag-name">{{ t('Name') }}</Label>
                        <Input
                            id="rename-tag-name"
                            v-model="renameForm.name"
                            maxlength="32"
                            autofocus
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
                            <Button type="button" variant="ghost"
                                >{{ t('Cancel') }}</Button
                            >
                        </DialogClose>
                        <Button type="submit" :disabled="renameForm.processing"
                            >{{ t('Save') }}</Button
                        >
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Delete dialog -->
        <Dialog :open="deleteOpen" @update:open="deleteOpen = $event">
            <DialogContent class="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>Delete tag</DialogTitle>
                    <DialogDescription>
                        Delete “{{ deleteTarget?.name }}”? It will be removed
                        from {{ deleteTarget?.files_count ?? 0 }} file(s). The
                        files themselves are not affected.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter class="mt-4">
                    <DialogClose as-child>
                        <Button variant="ghost">{{ t('Cancel') }}</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        data-test="confirm-delete-tag-button"
                        @click="submitDelete"
                    >
                        Delete tag
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
