import { useEcho } from '@laravel/echo-vue';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export type AccountStatusPayload = {
    account_id: number;
    method: string;
    status: string;
};

export type ConnectionCreatedPayload = {
    connection_id: number;
    account_id: number;
    method: string;
    name: string;
    status: string;
};

export type ConnectionStatusPayload = {
    connection_id: number;
    status: string;
    reason: string;
};

export type RoxiProvisioningPayload = {
    connection_id: number;
    deep_link?: string;
    expires_at?: string;
    chat_title?: string | null;
    error?: string;
};

export type FileOperationPayload = {
    operation_id: number;
    file_id: number | null;
    status: string;
    progress: number;
};

export type TelegramEventHandlers = {
    onAccountStatus?: (payload: AccountStatusPayload) => void;
    onConnectionCreated?: (payload: ConnectionCreatedPayload) => void;
    onConnectionStatus?: (payload: ConnectionStatusPayload) => void;
    onRoxiStarted?: (payload: RoxiProvisioningPayload) => void;
    onRoxiChatDetected?: (payload: RoxiProvisioningPayload) => void;
    onRoxiConnected?: (payload: RoxiProvisioningPayload) => void;
    onRoxiFailed?: (payload: RoxiProvisioningPayload) => void;
    onUploadStarted?: (payload: FileOperationPayload) => void;
    onUploadProgress?: (payload: FileOperationPayload) => void;
    onUploadCompleted?: (payload: FileOperationPayload) => void;
    onUploadFailed?: (payload: FileOperationPayload) => void;
    onDeleteStarted?: (payload: FileOperationPayload) => void;
    onDeleteCompleted?: (payload: FileOperationPayload) => void;
    onDeleteFailed?: (payload: FileOperationPayload) => void;
};

/**
 * Subscribe to the user's private channel for all Telegram/file lifecycle
 * events (spec §34). Subscriptions are cleaned up automatically on unmount
 * and re-subscribed when the user id changes.
 */
export function useTelegramEvents(handlers: TelegramEventHandlers): void {
    const page = usePage();

    const channel = computed(
        () => `App.Models.User.${page.props.auth.user.id}`,
    );

    const userId = computed(() => page.props.auth.user.id);

    if (handlers.onAccountStatus) {
        useEcho<AccountStatusPayload>(
            channel.value,
            '.account.status.changed',
            handlers.onAccountStatus,
            [userId],
            'private',
        );
    }
    if (handlers.onConnectionCreated) {
        useEcho<ConnectionCreatedPayload>(
            channel.value,
            '.connection.created',
            handlers.onConnectionCreated,
            [userId],
            'private',
        );
    }
    if (handlers.onConnectionStatus) {
        useEcho<ConnectionStatusPayload>(
            channel.value,
            '.connection.status.changed',
            handlers.onConnectionStatus,
            [userId],
            'private',
        );
    }
    if (handlers.onRoxiStarted) {
        useEcho<RoxiProvisioningPayload>(
            channel.value,
            '.roxi.connection.started',
            handlers.onRoxiStarted,
            [userId],
            'private',
        );
    }
    if (handlers.onRoxiChatDetected) {
        useEcho<RoxiProvisioningPayload>(
            channel.value,
            '.roxi.chat.detected',
            handlers.onRoxiChatDetected,
            [userId],
            'private',
        );
    }
    if (handlers.onRoxiConnected) {
        useEcho<RoxiProvisioningPayload>(
            channel.value,
            '.roxi.connection.connected',
            handlers.onRoxiConnected,
            [userId],
            'private',
        );
    }
    if (handlers.onRoxiFailed) {
        useEcho<RoxiProvisioningPayload>(
            channel.value,
            '.roxi.connection.failed',
            handlers.onRoxiFailed,
            [userId],
            'private',
        );
    }
    if (handlers.onUploadStarted) {
        useEcho<FileOperationPayload>(
            channel.value,
            '.file.upload.started',
            handlers.onUploadStarted,
            [userId],
            'private',
        );
    }
    if (handlers.onUploadProgress) {
        useEcho<FileOperationPayload>(
            channel.value,
            '.file.upload.progress',
            handlers.onUploadProgress,
            [userId],
            'private',
        );
    }
    if (handlers.onUploadCompleted) {
        useEcho<FileOperationPayload>(
            channel.value,
            '.file.upload.completed',
            handlers.onUploadCompleted,
            [userId],
            'private',
        );
    }
    if (handlers.onUploadFailed) {
        useEcho<FileOperationPayload>(
            channel.value,
            '.file.upload.failed',
            handlers.onUploadFailed,
            [userId],
            'private',
        );
    }
    if (handlers.onDeleteStarted) {
        useEcho<FileOperationPayload>(
            channel.value,
            '.file.delete.started',
            handlers.onDeleteStarted,
            [userId],
            'private',
        );
    }
    if (handlers.onDeleteCompleted) {
        useEcho<FileOperationPayload>(
            channel.value,
            '.file.delete.completed',
            handlers.onDeleteCompleted,
            [userId],
            'private',
        );
    }
    if (handlers.onDeleteFailed) {
        useEcho<FileOperationPayload>(
            channel.value,
            '.file.delete.failed',
            handlers.onDeleteFailed,
            [userId],
            'private',
        );
    }
}
