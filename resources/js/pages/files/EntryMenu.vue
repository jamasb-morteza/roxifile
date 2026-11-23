<script setup lang="ts">
import {
    Copy,
    Download,
    Eye,
    FolderOpen,
    MoreHorizontal,
    Pencil,
    Move,
    RotateCcw,
    Trash2,
    X,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

defineProps<{
    kind: 'file' | 'directory';
    inTrash: boolean;
}>();

defineEmits<{
    open: [];
    download: [];
    browserDownload: [];
    preview: [];
    copy: [];
    rename: [];
    move: [];
    delete: [];
    restore: [];
    purge: [];
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="size-8"
                data-test="entry-menu-button"
            >
                <MoreHorizontal class="size-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <template v-if="inTrash">
                <DropdownMenuItem @select="$emit('restore')">
                    <RotateCcw class="size-4" />
                    Restore
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    class="text-destructive"
                    @select="$emit('purge')"
                >
                    <X class="size-4" />
                    Delete permanently
                </DropdownMenuItem>
            </template>
            <template v-else>
                <DropdownMenuItem
                    v-if="kind === 'directory'"
                    @select="$emit('open')"
                >
                    <FolderOpen class="size-4" />
                    Open
                </DropdownMenuItem>
                <template v-else>
                    <DropdownMenuItem @select="$emit('preview')">
                        <Eye class="size-4" />
                        Details &amp; preview
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="$emit('download')">
                        <Download class="size-4" />
                        Download
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="$emit('browserDownload')">
                        <Download class="size-4" />
                        Browser download
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="$emit('copy')">
                        <Copy class="size-4" />
                        Copy
                    </DropdownMenuItem>
                </template>
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="$emit('rename')">
                    <Pencil class="size-4" />
                    Rename
                </DropdownMenuItem>
                <DropdownMenuItem @select="$emit('move')">
                    <Move class="size-4" />
                    Move
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    class="text-destructive"
                    @select="$emit('delete')"
                >
                    <Trash2 class="size-4" />
                    Delete
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
